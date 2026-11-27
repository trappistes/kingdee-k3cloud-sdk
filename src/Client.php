<?php

declare(strict_types=1);

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

    private Transport $transport;

    private LoggerInterface $logger;

    private bool $authenticated = false;

    /**
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
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * 当前传输层
     */
    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * 通用请求
     *
     * @param array<string, mixed> $payload
     *
     * @throws AuthenticationException
     * @throws TransportException
     * @throws ConfigurationException
     */
    public function request(string $endpoint, array $payload, ?Policy $policy = null): string
    {
        // Config 是活对象，每次请求前重新校验，避免被外部改坏后静默发请求
        $this->config->assertValid();

        $isWrite = Endpoints::isWrite($endpoint);

        if ($policy === null) {
            // 未指定策略时按端点性质推导：写端点默认不重试
            $policy = $isWrite
                ? Policy::write($this->config->timeout)
                : Policy::read($this->config->timeout, $this->config->maxAttempts);
        }

        if ($isWrite && $policy->retryable) {
            throw new InvalidArgumentException(sprintf(
                '写端点不允许可重试策略：%s（重试可能产生重复单据），请使用 Policy::write()',
                $endpoint,
            ));
        }

        // 写端点必须登记在 Endpoints::WRITE_ENDPOINTS：
        // 新增写端点时若忘记登记，这条规则会立即报错，避免它被当成读端点重试
        if (! $isWrite && $policy->name === 'write') {
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
                $this->logger->warning('kingdee.transport.failed', [
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
                $this->logger->warning('kingdee.auth.failed', [
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
                if ($reAuthenticated) {
                    throw new AuthenticationException('金蝶会话失效，重新登录后仍无法访问');
                }

                if (! $policy->retryable) {
                    throw new AuthenticationException('金蝶会话失效：写操作不自动重发，请由调用方确认后重试');
                }

                if ($attempt >= $policy->maxAttempts) {
                    throw new AuthenticationException('金蝶会话失效：已达最大尝试次数');
                }

                $reAuthenticated = true;

                $this->logger->warning('kingdee.session.expired', ['endpoint' => $endpoint]);
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
     *
     * @param mixed $query
     *
     * @throws InvalidArgumentException 参数类型不正确
     */
    private function assertQuery($query): Query
    {
        if (! $query instanceof Query) {
            throw new InvalidArgumentException('查询参数必须是 '.Query::class.' 实例');
        }

        return $query;
    }

    /**
     * 确保已登录
     *
     * @param int|null $timeout 本次请求的超时预算（秒），为空时用全局配置
     */
    private function ensureAuthenticated(?int $timeout = null): void
    {
        if (! $this->authenticated) {
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
     * @param int $timeout 本次请求的超时预算（秒）
     *
     * @throws AuthenticationException
     * @throws TransportException
     * @throws ConfigurationException
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

            $this->logger->info('kingdee.identity.changed', [
                'auth_type' => $this->config->authType,
            ]);

            return;
        }

        $this->signer = null;

        if (! $this->authenticated) {
            // 尚未登录：下一次登录直接用新凭据；旧会话 Cookie 仍需丢弃
            $this->transport->resetSession();
            $this->identityFingerprint = $current;

            $this->logger->info('kingdee.identity.changed', [
                'auth_type' => $this->config->authType,
            ]);

            return;
        }

        // 已登录：relogin() 内部会清空旧会话，再用新凭据登录；
        // 登录成功后才提交新指纹（失败则指纹保持旧值，下次请求会重新尝试）
        $this->relogin($timeout);

        $this->logger->info('kingdee.identity.changed', [
            'auth_type' => $this->config->authType,
        ]);
    }

    /**
     * 拼接接口地址
     *
     * hostUrl 末尾有没有 / 都能正常工作（手改配置时常见）。
     */
    private static function buildUrl(string $hostUrl, string $endpoint): string
    {
        return rtrim($hostUrl, '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * 创建签名器
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

        return ! is_array($data) || ! array_key_exists('LoginResultType', $data);
    }

    /**
     * 单据类写操作：报文为 formid + data，且不自动重试
     *
     * @param array<string, mixed> $payload
     */
    protected function writeBillOperation(string $endpoint, string $formId, array $payload): string
    {
        return $this->formRequest($endpoint, $formId, $payload, Policy::write($this->config->timeout));
    }

    /**
     * 平台类写操作：报文仅含 data，且不自动重试
     *
     * @param array<string, mixed> $payload
     */
    protected function writePlatformOperation(string $endpoint, array $payload): string
    {
        return $this->dataRequest($endpoint, $payload, Policy::write($this->config->timeout));
    }

    /**
     * 单据类请求：报文为 formid + data
     *
     * @param array<string, mixed> $payload
     */
    protected function formRequest(string $endpoint, string $formId, array $payload, Policy $policy): string
    {
        return $this->request($endpoint, $this->formPayload($formId, $payload), $policy);
    }

    /**
     * 平台类请求：报文仅含 data
     *
     * @param array<string, mixed> $payload
     */
    protected function dataRequest(string $endpoint, array $payload, Policy $policy): string
    {
        return $this->request($endpoint, ['data' => $payload], $policy);
    }

    /**
     * 单据操作请求参数
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
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
     * @return array<string, string>
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
     */
    private function backoff(Policy $policy, int $attempt): void
    {
        $milliseconds = $policy->backoffFor($attempt);

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * 记录响应
     *
     * @param array<string, mixed> $payload
     */
    private function logResponse(string $endpoint, array $payload, HttpResponse $response): void
    {
        $this->logger->info('kingdee.request', [
            'endpoint' => $endpoint,
            'status' => $response->status,
        ]);

        // 业务明细可能含敏感字段，单独放在 debug 事件：
        // 生产环境用 info 级别记录时不会落库，需要排障时再开 debug
        $this->logger->debug('kingdee.request.detail', [
            'endpoint' => $endpoint,
            'status' => $response->status,
            'payload' => Redactor::payload($payload),
            'body' => Redactor::text($response->body),
        ]);
    }
}
