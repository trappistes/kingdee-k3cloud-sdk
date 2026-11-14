<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
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

    public function __construct(
        Config $config,
        ?Transport $transport = null,
        ?LoggerInterface $logger = null,
        ?callable $clock = null
    ) {
        $this->config = $config;

        $this->config->assertValid();

        $this->clock = $clock ?? static function (): int {
            return time();
        };

        if ($this->config->authType === Config::AUTH_SIGNATURE) {
            // 签名认证不建立会话，构造后即为可用状态
            $this->authenticated = true;

            $this->signer = new Signer(
                $this->config->appId,
                $this->config->appSecret,
                $this->config->acctId,
                $this->config->username,
                $this->config->lcid,
                $this->config->orgNum
            );
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
     */
    public function request(string $endpoint, array $payload, ?Policy $policy = null): string
    {
        $policy ??= Policy::read($this->config->timeout, $this->config->maxAttempts);

        $url = $this->config->hostUrl.$endpoint;
        $attempt = 0;
        $reAuthenticated = false;

        while (true) {
            $attempt++;

            try {
                // 认证置于重试范围内：登录阶段的网络抖动同样可重试
                $this->ensureAuthenticated();

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

            // 会话失效：重新登录后重试一次（认证层失败，业务未执行，重试安全）
            if (Response::isSessionExpired($response->body)) {
                if ($reAuthenticated) {
                    throw new AuthenticationException('金蝶会话失效，重新登录后仍无法访问');
                }

                $reAuthenticated = true;
                $attempt--;

                $this->logger->warning('kingdee.session.expired', ['endpoint' => $endpoint]);
                $this->relogin();

                continue;
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
     */
    private function ensureAuthenticated(): void
    {
        if (! $this->authenticated) {
            $this->login();
        }
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
            'payload' => Redactor::payload($payload),
            'body' => Redactor::text($response->body),
        ]);
    }
}
