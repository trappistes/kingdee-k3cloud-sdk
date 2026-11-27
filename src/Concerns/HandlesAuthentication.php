<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Concerns;

use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Response;

/**
 * HandlesAuthentication —— Client 的职责分组之一（仅内部组织，不改变公开 API）
 */
trait HandlesAuthentication
{
    /**
     * 是否已登录
     */
    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * 登录（幂等）
     *
     * @param int|null $timeout 本次请求的超时预算（秒），为空时用全局配置
     *
     * @throws AuthenticationException
     * @throws TransportException
     */
    public function login(?int $timeout = null): void
    {
        $this->performLogin($timeout, false);
    }

    /**
     * 重新登录：清空会话后再次认证
     *
     * @param int|null $timeout 本次请求的超时预算（秒），为空时用全局配置
     *
     * @throws AuthenticationException
     * @throws TransportException
     */
    public function relogin(?int $timeout = null): void
    {
        $this->performLogin($timeout, true);
    }

    /**
     * 执行登录
     *
     * 登录成功意味着「当前会话属于当前凭据」，因此：
     *  - 凭据已变（指纹不同）时，登录前先丢弃旧会话，避免新旧会话并存；
     *  - 登录成功后同步身份指纹，避免调用方手动登录后又被判定为「身份已变」
     *    而重复登录一次（那会把刚建好的会话又作废掉）。
     *
     * @param int|null $timeout 本次请求的超时预算（秒）
     * @param bool     $freshSession 是否强制丢弃旧会话
     *
     * @throws AuthenticationException
     * @throws TransportException
     */
    private function performLogin(?int $timeout, bool $freshSession): void
    {
        // 签名认证不建立会话：每个请求自带签名
        if ($this->config->authType === Config::AUTH_SIGNATURE) {
            $this->authenticated = true;

            return;
        }

        $current = $this->config->fingerprint();

        if ($freshSession || $current !== $this->identityFingerprint) {
            $this->transport->resetSession();
        }

        // 登录期间视为未认证：失败时不会带着旧会话继续发请求
        $this->authenticated = false;

        [$endpoint, $payload] = $this->authPayload();

        $url = rtrim($this->config->hostUrl, '/').'/'.ltrim($endpoint, '/');

        $response = $this->transport->post(
            $url,
            $this->headers($url),
            $payload,
            [
                'timeout' => $timeout ?? $this->config->timeout,
                'connect_timeout' => $this->config->connectTimeout,
            ],
        );

        $this->logger->info('kingdee.login', [
            'auth_type' => $this->config->authType,
            'status' => $response->status,
        ]);

        if ($response->status >= 500) {
            throw new TransportException('金蝶登录服务异常：HTTP '.$response->status, $response->status, true);
        }

        // 4xx：接口地址或账套不可用（业务错误金蝶一律返回 200，故此处为配置/网络问题）
        if ($response->status >= 400) {
            throw new TransportException(
                sprintf('金蝶接口不可用（HTTP %d），请检查接口地址与账套配置', $response->status),
                $response->status,
                false,
            );
        }

        $resultType = Response::loginResultType($response->body);

        if ($resultType !== 1) {
            $this->authenticated = false;

            throw new AuthenticationException(
                '金蝶登录失败：'.Response::errorMessage($response->body),
                self::isRetryableLoginFailure($resultType, $response->body)
            );
        }

        $this->authenticated = true;

        // 登录成功：当前会话即当前身份，同步指纹
        $this->identityFingerprint = $current;
    }

    /**
     * 认证请求参数
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function authPayload(): array
    {
        if ($this->config->authType === Config::AUTH_APP_SECRET) {
            $payload = [
                'acctid' => $this->config->acctId,
                'appid' => $this->config->appId,
                'appsecret' => $this->config->appSecret,
                'lcid' => $this->config->lcid,
            ];

            // 用户名可选：为空时不下发该字段，避免服务端把空串当作非法用户名
            if ($this->config->username !== '') {
                $payload['username'] = $this->config->username;
            }

            return [Endpoints::LOGIN_BY_APP_SECRET, $payload];
        }

        return [Endpoints::LOGIN, [
            'acctid' => $this->config->acctId,
            'username' => $this->config->username,
            'password' => $this->config->password,
            'lcid' => $this->config->lcid,
        ]];
    }
}
