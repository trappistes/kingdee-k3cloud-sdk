<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\CurlTransport;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Http\Transport;
use Trappistes\Kingdee\K3Cloud\Support\Redactor;

/**
 * 金蝶开放平台客户端
 *
 * 职责边界：
 *  - 负责认证、会话保持、传输超时、分级重试、响应记录
 *  - 不负责业务语义（单据字段、映射规则），由调用方处理
 *
 * 关键约束：
 *  - 公开 API（方法名、签名、语义）已冻结；内部按职责拆分为 Concerns 下的
 *    四个 trait（认证 / 单据操作 / 平台操作 / 查询），trait 只是代码组织手段，
 *    不引入额外状态，所有状态都留在本类；
 *  - Config 是「活对象」：调用方可在运行中改它的属性，身份类字段（凭据、账套、
 *    认证方式）变更会让旧会话立即作废并在下次请求前重新认证；超时、重试次数等
 *    行为类字段变更立即生效，不触发重新认证；
 *  - 重试安全由端点性质决定：读操作可重试，写操作（会改变服务端状态）不自动重发，
 *    以免在金蝶侧产生重复单据；未登记端点按不重试处理（性质未知 → 安全侧）；
 *  - 未注入日志器时不记录任何日志；注入后遵循 PSR-3，敏感内容（附件字节流、
 *    密码、应用密钥）自动脱敏。
 *
 * 典型用法：
 *
 *     $client = new Client(Config::fromArray($config));
 *     $rows  = $client->queryRows($params);
 *     $client->save('UWAZ_OutStock', $payload);
 */
final class Client
{
    use Concerns\HandlesAuthentication;
    use Concerns\HandlesBillOperations;
    use Concerns\HandlesPlatformOperations;
    use Concerns\HandlesQueries;

    /**
     * 传输层（承载会话 Cookie）
     */
    private Transport $transport;

    /**
     * 日志器（PSR-3，未注入时为 NullLogger）
     */
    private LoggerInterface $logger;

    /**
     * 是否已登录（签名认证下构造后即为 true）
     */
    private bool $authenticated = false;

    /**
     * 客户端配置（活对象，调用方可修改）
     *
     * @var Config
     */
    private $config;

    /**
     * 时钟（秒级时间戳），签名认证使用；测试可注入固定值
     *
     * @var callable
     */
    private $clock;

    /**
     * 签名器（仅签名认证使用）
     *
     * @var Signer|null
     */
    private $signer;

    /**
     * 身份指纹（凭据/账套/认证方式等）
     *
     * 与 Config::fingerprint() 同一口径：只覆盖「我是谁、连哪个账套」，
     * 不含超时、重试次数等传输行为参数——那些改了不需要重新认证。
     *
     * @var string
     */
    private $identityFingerprint;

    /**
     * 创建客户端
     *
     * 构造阶段只做配置校验与依赖装配，不发起任何网络请求；
     * 真正的登录发生在第一次请求之前（由 request() 自动触发）。
     *
     * @param  Config  $config  客户端配置，SDK 不读取任何全局环境变量
     * @param  Transport|null  $transport  传输层，为空时使用内置 CurlTransport（自带 Cookie 会话引擎）
     * @param  LoggerInterface|null  $logger  日志器，为空时使用 NullLogger（不产生任何日志）
     * @param  callable|null  $clock  返回秒级时间戳的回调（签名认证取 timestamp/nonce 用），
     *                                      为空时使用当前时间；测试可注入固定值
     *
     * @throws InvalidArgumentException 配置缺失、必填项为空或超时参数非法
     * @throws ConfigurationException  签名认证的 appid（须为 clientId_secret 形式）或 appsecret 不合法
     */
    public function __construct(
        Config $config,
        ?Transport $transport = null,
        ?LoggerInterface $logger = null,
        ?callable $clock = null
    ) {
        $this->config = $config;

        $this->config->assertValid();

        $this->identityFingerprint = $this->config->fingerprint();

        $this->clock = $clock ?? static function (): int {
            return time();
        };

        if ($this->config->authType === Config::AUTH_SIGNATURE) {
            // 签名认证不建立会话，构造后即为可用状态
            $this->authenticated = true;

            $this->signer = $this->createSigner();
        }

        $this->transport = $transport ?? new CurlTransport(
            $this->config->timeout,
            $this->config->connectTimeout
        );

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * 当前配置
     *
     * 返回的是内部那一份「活对象」：修改它的属性会影响后续请求。
     * 身份类字段变更会让旧会话作废并重新认证，行为类字段（超时、重试次数）立即生效。
     *
     * @return Config
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * 当前传输层
     *
     * @return Transport 承载会话（Cookie）的传输实现
     */
    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * 通用请求：按端点性质选定策略，完成认证、发送与重试
     *
     * 策略默认值：未显式传入 $policy 时，按端点性质**三态**推导——
     *  - 写端点（Endpoints::WRITE_ENDPOINTS）→ Policy::write（不重试）；
     *  - 读端点（Endpoints::READ_ENDPOINTS）→ Policy::read（最多 Config::maxAttempts 次尝试）；
     *  - 未登记端点 → Policy::once（不重试）。SDK 无法判断它是否会改变服务端状态，
     *    「不确定」一律放在安全侧；确认是只读接口请显式传 Policy::read() 以恢复自动重试。
     *
     * 重试语义：
     *  - 传输层可重试故障（连接失败、超时、5xx）在读策略下按退避重试；
     *  - 登录阶段的瞬时故障（如「没有指定数据中心」）同样可重试，凭据错误不重试；
     *  - 会话失效时重新登录后再试一次，且仅限读操作；写操作不自动重发
     *    （无法确认业务是否已执行），直接抛异常交由调用方决定。
     *
     * 重试预算：一次循环 = 一次登录 + 一次业务请求，因此登录阶段的瞬时故障
     * 也会消耗业务重试预算（max_attempts=3 时，两次登录抖动会让业务只剩 1 次机会）。
     *
     * @param  string  $endpoint  金蝶 WebAPI 端点路径（见 Endpoints 中的常量）
     * @param  array<string, mixed>  $payload  请求体，会被 JSON 编码后发送
     * @param  Policy|null  $policy  超时与重试策略，为空时按端点性质推导
     *
     * @return string 响应体原文（JSON 字符串，不做解析）
     *
     * @throws InvalidArgumentException 写端点使用了可重试策略，或写策略被用于非写端点
     * @throws AuthenticationException  登录失败，或会话失效且无法恢复
     * @throws TransportException       网络故障、超时，或服务端返回错误状态
     * @throws ConfigurationException   签名认证配置不合法
     */
    public function request(string $endpoint, array $payload, ?Policy $policy = null): string
    {
        // Config 是活对象，每次请求前重新校验，避免被外部改坏后静默发请求
        $this->config->assertValid();

        $isWrite = Endpoints::isWrite($endpoint);
        $isRead = Endpoints::isRead($endpoint);

        if ($policy === null) {
            // 未指定策略时按端点性质推导，三态：
            //  - 写端点 → 不重试（重试会产生重复单据）
            //  - 读端点 → 可重试
            //  - 未登记端点 → 性质未知，按不重试处理（不确定的一律放在安全侧）
            if ($isWrite) {
                $policy = Policy::write($this->config->timeout);
            } elseif ($isRead) {
                $policy = Policy::read($this->config->timeout, $this->config->maxAttempts);
            } else {
                $this->log('warning', 'kingdee.endpoint.unregistered', ['endpoint' => $endpoint]);

                $policy = Policy::once($this->config->timeout);
            }
        }

        if ($isWrite && $policy->retryable) {
            throw new InvalidArgumentException(sprintf(
                '写端点不允许可重试策略：%s（重试可能产生重复单据），请使用 Policy::write()',
                $endpoint,
            ));
        }

        // 写策略只能用于写端点（含未登记端点）：
        // 新增写端点时若忘记登记，这条规则会立即报错，避免它被当成读端点重试
        if (!$isWrite && $policy->name === 'write') {
            throw new InvalidArgumentException(sprintf(
                '写策略不能用于非写端点：%s（请先登记到 Endpoints::WRITE_ENDPOINTS）',
                $endpoint,
            ));
        }

        $url = self::buildUrl($this->config->hostUrl, $endpoint);
        $attempt = 0;
        $reAuthenticated = false;

        while (true) {
            $attempt++;

            try {
                // 身份（凭据/账套/认证方式）变了：旧会话属于旧身份，必须先作废
                $this->syncIdentity($policy->timeout);

                // 认证置于重试范围内：登录阶段的网络抖动同样可重试
                // 登录超时跟随本次请求的策略，避免单次请求的实际耗时远超预期
                $this->ensureAuthenticated($policy->timeout);

                $response = $this->transport->post($url, $this->headers($url), $payload, [
                    'timeout' => $policy->timeout,
                    'connect_timeout' => $this->config->connectTimeout,
                ]);
            } catch (TransportException $e) {
                $this->log('warning', 'kingdee.transport.failed', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'retryable' => $e->isRetryable(),
                    'error' => $e->getMessage(),
                ]);

                if ($e->isRetryable() && $policy->retryable && $attempt < $policy->maxAttempts) {
                    $this->backoff($policy, $attempt);

                    continue;
                }

                throw $e;
            } catch (AuthenticationException $e) {
                // 登录阶段的瞬时故障（如「没有指定数据中心」）可重试；
                // 凭据错误不可重试
                $this->log('warning', 'kingdee.auth.failed', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'retryable' => $e->isRetryable(),
                    'error' => $e->getMessage(),
                ]);

                if ($e->isRetryable() && $policy->retryable && $attempt < $policy->maxAttempts) {
                    $this->authenticated = false;
                    $this->backoff($policy, $attempt);

                    continue;
                }

                throw $e;
            }

            $this->logResponse($endpoint, $payload, $response);

            // 服务端错误：读操作可重试，写操作不重试
            if ($response->status >= 500) {
                $exception = new TransportException(
                    '金蝶服务异常：HTTP '.$response->status,
                    $response->status,
                    true,
                );

                if ($policy->retryable && $attempt < $policy->maxAttempts) {
                    $this->backoff($policy, $attempt);

                    continue;
                }

                throw $exception;
            }

            // 会话失效：重新登录后重试一次（仅限读操作）
            //
            // 写操作不重发：SDK 无法确认「会话失效发生在业务执行之前」，
            // 重发可能造成重复单据，因此直接报错交由调用方决定。
            if (Response::isSessionExpired($response->body)) {
                // 保留金蝶原始错误文案：即使是误判，调用方也要能看到真实原因
                $detail = Response::errorMessage($response->body);

                if ($reAuthenticated) {
                    throw new AuthenticationException('金蝶会话失效，重新登录后仍无法访问（金蝶原始信息：'.$detail.'）');
                }

                if (!$policy->retryable) {
                    throw new AuthenticationException('金蝶会话失效：写操作不自动重发，请由调用方确认后重试（金蝶原始信息：'.$detail.'）');
                }

                if ($attempt >= $policy->maxAttempts) {
                    throw new AuthenticationException('金蝶会话失效：已达最大尝试次数（金蝶原始信息：'.$detail.'）');
                }

                $reAuthenticated = true;

                $this->log('warning', 'kingdee.session.expired', ['endpoint' => $endpoint]);
                $this->relogin($policy->timeout);

                continue;
            }

            // 4xx：接口地址、鉴权或权限问题，不重试
            if ($response->status >= 400) {
                throw new TransportException(
                    sprintf('金蝶接口返回 HTTP %d：%s', $response->status, substr($response->body, 0, 200)),
                    $response->status,
                    false,
                );
            }

            return $response->body;
        }
    }

    /**
     * 校验查询参数类型
     *
     * SDK 只接受 Query（不提供绕过条件组合的数组入口）。
     * 运行时校验必须保留：传错类型时立刻报错，而不是等到金蝶返回模糊的失败报文。
     *
     * @param  mixed  $query  待校验的查询对象
     *
     * @return Query 校验通过的原对象
     *
     * @throws InvalidArgumentException 参数类型不正确
     */
    private function assertQuery($query): Query
    {
        if (!$query instanceof Query) {
            throw new InvalidArgumentException('查询参数必须是 '.Query::class.' 实例');
        }

        return $query;
    }

    /**
     * 确保已登录
     *
     * 已登录时什么都不做；未登录时发起一次登录。
     *
     * @param  int|null  $timeout  本次请求的超时预算（秒），为空时用全局配置
     *
     * @throws AuthenticationException 登录失败
     * @throws TransportException      网络故障、超时或登录服务返回错误状态
     */
    private function ensureAuthenticated(?int $timeout = null): void
    {
        if (!$this->authenticated) {
            $this->login($timeout);
        }
    }

    /**
     * 同步身份变更
     *
     * Config 是活对象，调用方可能在运行中改掉凭据/账套/认证方式。
     * 这类改动必须让旧会话失效，否则会出现「凭据已换、Cookie 还是旧账号」
     * 的错账套写入（常驻进程复用同一个客户端切账套时最容易踩到）。
     *
     * 超时、重试次数等传输行为参数不在指纹内，改了立即生效、不触发重新认证。
     *
     * @param  int  $timeout  本次请求的超时预算（秒）
     *
     * @throws AuthenticationException 重新登录失败
     * @throws TransportException      网络故障、超时或登录服务返回错误状态
     * @throws ConfigurationException  签名认证配置不合法
     */
    private function syncIdentity(int $timeout): void
    {
        $current = $this->config->fingerprint();

        if ($current === $this->identityFingerprint) {
            return;
        }

        if ($this->config->authType === Config::AUTH_SIGNATURE) {
            // 签名认证不建立会话：重建签名器即可（密钥/账套/组织都在签名里）
            // 先构造新签名器：配置非法时直接报错，指纹不提交，
            // 下一次请求仍会重新尝试切换（不会卡在「旧签名器 + 新指纹」）
            $signer = $this->createSigner();

            // 旧 Cookie 属于旧身份，一并丢弃
            $this->transport->resetSession();

            $this->signer = $signer;
            $this->authenticated = true;
            $this->identityFingerprint = $current;

            $this->log('info', 'kingdee.identity.changed', [
                'auth_type' => $this->config->authType,
            ]);

            return;
        }

        $this->signer = null;

        if (!$this->authenticated) {
            // 尚未登录：下一次登录直接用新凭据；旧会话 Cookie 仍需丢弃
            $this->transport->resetSession();
            $this->identityFingerprint = $current;

            $this->log('info', 'kingdee.identity.changed', [
                'auth_type' => $this->config->authType,
            ]);

            return;
        }

        // 已登录：relogin() 内部会清空旧会话，再用新凭据登录；
        // 登录成功后才提交新指纹（失败则指纹保持旧值，下次请求会重新尝试）
        $this->relogin($timeout);

        $this->log('info', 'kingdee.identity.changed', [
            'auth_type' => $this->config->authType,
        ]);
    }

    /**
     * 拼接接口地址
     *
     * hostUrl 末尾有没有 / 都能正常工作（手改配置时常见）。
     *
     * @param  string  $hostUrl  金蝶服务地址，如 https://k3cloud.example.com/k3cloud/
     * @param  string  $endpoint  端点路径，如 Endpoints::SAVE
     *
     * @return string 完整请求地址
     */
    private static function buildUrl(string $hostUrl, string $endpoint): string
    {
        return rtrim($hostUrl, '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * 创建签名器
     *
     * @return Signer
     *
     * @throws ConfigurationException 签名认证的 appid（须为 clientId_secret 形式）或 appsecret 不合法
     */
    private function createSigner(): Signer
    {
        return new Signer(
            $this->config->appId,
            $this->config->appSecret,
            $this->config->acctId,
            $this->config->username,
            $this->config->lcid,
            $this->config->orgNum
        );
    }

    /**
     * 判断登录失败是否可重试
     *
     * - 0：凭据错误（用户名/密码），重试无意义
     * - -1：服务端/数据中心类失败（如「没有指定数据中心」），属瞬时故障，可重试
     * - 响应无法解析：网关/代理返回了非金蝶报文，同样按瞬时故障处理
     *
     * @param  int  $resultType  登录结果码（1=成功，0=凭据错误，-1=服务端/数据中心类失败）
     * @param  string  $body  登录响应体原文
     *
     * @return bool 是否值得重试
     */
    private static function isRetryableLoginFailure(int $resultType, string $body): bool
    {
        if ($resultType === 0) {
            return false;
        }

        if ($resultType === -1) {
            return true;
        }

        // 响应体不是金蝶登录报文（网关错误页、空响应等）→ 视作瞬时故障
        $data = json_decode($body, true);

        return !is_array($data) || !array_key_exists('LoginResultType', $data);
    }

    /**
     * 单据类写操作：报文为 formid + data，且不自动重试
     *
     * @param  string  $endpoint  端点路径，如 Endpoints::SAVE
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  array<string, mixed>  $payload  单据体（data 节点内容），按操作不同形态不同
     *
     * @return string 响应体原文
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException      网络故障、超时或服务端返回错误状态
     */
    protected function writeBillOperation(string $endpoint, string $formId, array $payload): string
    {
        return $this->formRequest($endpoint, $formId, $payload, Policy::write($this->config->timeout));
    }

    /**
     * 平台类写操作：报文仅含 data，且不自动重试
     *
     * 与单据类写操作的差别：不带 formid 节点，报文只有 data。
     *
     * @param  string  $endpoint  端点路径，如 Endpoints::SEND_MSG
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException      网络故障、超时或服务端返回错误状态
     */
    protected function writePlatformOperation(string $endpoint, array $payload): string
    {
        return $this->dataRequest($endpoint, $payload, Policy::write($this->config->timeout));
    }

    /**
     * 单据类请求：报文为 formid + data
     *
     * @param  string  $endpoint  端点路径，如 Endpoints::VIEW
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     * @param  Policy  $policy  超时与重试策略
     *
     * @return string 响应体原文
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException      网络故障、超时或服务端返回错误状态
     */
    protected function formRequest(string $endpoint, string $formId, array $payload, Policy $policy): string
    {
        return $this->request($endpoint, $this->formPayload($formId, $payload), $policy);
    }

    /**
     * 平台类请求：报文仅含 data
     *
     * @param  string  $endpoint  端点路径，如 Endpoints::QUERY_GROUP_INFO
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     * @param  Policy  $policy  超时与重试策略
     *
     * @return string 响应体原文
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException      网络故障、超时或服务端返回错误状态
     */
    protected function dataRequest(string $endpoint, array $payload, Policy $policy): string
    {
        return $this->request($endpoint, ['data' => $payload], $policy);
    }

    /**
     * 单据操作请求参数
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  单据体（data 节点内容）
     *
     * @return array<string, mixed> 含 formid 与 data 两个键的请求体
     */
    private function formPayload(string $formId, array $payload): array
    {
        return [
            'formid' => $formId,
            'data' => $payload,
        ];
    }

    /**
     * 请求头
     *
     * 签名认证模式下附加金蝶开放平台签名头。
     *
     * @param  string  $url  完整请求地址（参与签名计算）
     *
     * @return array<string, string> 请求头键值对
     */
    private function headers(string $url): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Accept-Charset' => 'utf-8',
        ];

        if ($this->signer !== null) {
            $clock = $this->clock;
            $headers = array_merge($headers, $this->signer->headers($url, (int) $clock()));
        }

        return $headers;
    }

    /**
     * 退避等待
     *
     * 实际时长由 Policy::backoffFor() 计算：线性退避 + 随机抖动，
     * 避免多实例同时失败时反复冲击同一个 ERP 端点。
     *
     * @param  Policy  $policy  当前策略（决定退避基数）
     * @param  int  $attempt  已尝试的次数（从 1 开始）
     */
    private function backoff(Policy $policy, int $attempt): void
    {
        $milliseconds = $policy->backoffFor($attempt);

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * 记录一条日志（旁路，异常不外泄）
     *
     * 日志是**旁路**：脱敏或日志器自身的异常不得改变业务结果。
     *  - 写操作可能已在金蝶侧成功，此时抛异常会让调用方按失败重试 → 重复单据；
     *  - 在传输失败的捕获分支里抛日志异常，还会把真正的失败原因掩盖掉。
     *
     * 所有日志调用都走本方法，保证该约束在全局成立。
     *
     * @param  string  $level  PSR-3 级别（info / debug / warning）
     * @param  string  $message  事件名
     * @param  array<string, mixed>  $context  事件上下文
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable $e) {
            // 日志是旁路：日志器自身的异常不得冒泡到业务调用方
        }
    }

    /**
     * 记录响应
     *
     * 分两级记录：
     *  - info：端点与状态码（生产环境默认可见，不含业务明细）；
     *  - debug：脱敏后的请求体与响应体（排障时再开启）。
     *
     * @param  string  $endpoint  端点路径
     * @param  array<string, mixed>  $payload  请求体（记录前已脱敏）
     * @param  HttpResponse  $response  响应对象
     */
    private function logResponse(string $endpoint, array $payload, HttpResponse $response): void
    {
        $this->log('info', 'kingdee.request', [
            'endpoint' => $endpoint,
            'status' => $response->status,
        ]);

        // 未注入日志器（NullLogger）时跳过脱敏：脱敏是纯开销，
        // 且大报文是性能热点（实测 2.8MB 报文每次约 2.5ms）
        if ($this->logger instanceof NullLogger) {
            return;
        }

        try {
            $redactedPayload = Redactor::payload($payload);
            $redactedBody = Redactor::text($response->body);
        } catch (\Throwable $e) {
            // 脱敏失败同样不得影响业务结果：退化为只记录端点与状态码
            $this->log('warning', 'kingdee.log.redaction_failed', ['endpoint' => $endpoint]);

            return;
        }

        // 业务明细可能含敏感字段，单独放在 debug 事件：
        // 生产环境用 info 级别记录时不会落库，需要排障时再开 debug
        $this->log('debug', 'kingdee.request.detail', [
            'endpoint' => $endpoint,
            'status' => $response->status,
            'payload' => $redactedPayload,
            'body' => $redactedBody,
        ]);
    }
}
