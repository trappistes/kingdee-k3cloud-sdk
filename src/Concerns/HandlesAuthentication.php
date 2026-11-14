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
     * @throws AuthenticationException
     * @throws TransportException
     */
    public function login(): void
    {
        // 签名认证不建立会话：每个请求自带签名
        if ($this->config->authType === Config::AUTH_SIGNATURE) {
            $this->authenticated = true;

            return;
        }

        [$endpoint, $payload] = $this->authPayload();

        $url = $this->config->hostUrl.$endpoint;

        $response = $this->transport->post(
            $url,
            $this->headers($url),
            $payload,
            [
                'timeout' => $this->config->timeout,
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
    }

    /**
     * 重新登录：清空会话后再次认证
     *
     * @throws AuthenticationException
     * @throws TransportException
     */
    public function relogin(): void
    {
        $this->transport->resetSession();
        $this->authenticated = false;
        $this->login();
    }

    /**
     * 认证请求参数
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function authPayload(): array
    {
        if ($this->config->authType === Config::AUTH_APP_SECRET) {
            return [Endpoints::LOGIN_BY_APP_SECRET, [
                'acctid' => $this->config->acctId,
                'username' => $this->config->username,
                'appid' => $this->config->appId,
                'appsecret' => $this->config->appSecret,
                'lcid' => $this->config->lcid,
            ]];
        }

        return [Endpoints::LOGIN, [
            'acctid' => $this->config->acctId,
            'username' => $this->config->username,
            'password' => $this->config->password,
            'lcid' => $this->config->lcid,
        ]];
    }
}
