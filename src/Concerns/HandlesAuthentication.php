<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Concerns;

use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Response;

/**
 * HandlesAuthentication —— Client 的职责分组之一（仅内部组织，不改变公开 API）
 *
 * 职责：认证与会话保持，包括登录、重新登录、登录报文组装。
 *
 * 关键约束：
 *  - 本 trait 中的 public 方法属于 SDK 已冻结的公开 API，只做注释层面的澄清；
 *  - 认证方式由 Config::authType 决定：账套+用户名+密码、账套+应用ID+密钥、
 *    开放平台签名；签名认证不建立会话，每个请求自带签名；
 *  - 登录失败分「可重试」与「不可重试」两类：凭据错误（LoginResultType=0）不可重试，
 *    服务端/数据中心类瞬时故障（LoginResultType=-1 或响应无法解析）可重试。
 */
trait HandlesAuthentication
{
    /**
     * 是否已登录
     *
     * 签名认证不建立会话，构造后即为 true。
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * 登录：向金蝶发起认证并建立会话
     *
     * 无论当前是否已登录都会真正发起一次登录请求；
     * 与 relogin() 的区别是本方法不强制丢弃旧会话
     * （只有身份指纹发生变化时才会重置会话）。
     *
     * @param  int|null  $timeout  本次请求的超时预算（秒），为空时用全局配置
     *
     * @throws AuthenticationException 凭据错误或金蝶返回登录失败
     * @throws TransportException      网络故障、超时或登录服务返回错误状态
     */
    public function login(?int $timeout = null): void
    {
        $this->performLogin($timeout, false);
    }

    /**
     * 重新登录：清空会话后再次认证
     *
     * 会话失效或凭据切换后使用：旧 Cookie 被丢弃，确保新会话属于当前凭据。
     *
     * @param  int|null  $timeout  本次请求的超时预算（秒），为空时用全局配置
     *
     * @throws AuthenticationException 凭据错误或金蝶返回登录失败
     * @throws TransportException      网络故障、超时或登录服务返回错误状态
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
     * @param  int|null  $timeout  本次请求的超时预算（秒），为空时用全局配置
     * @param  bool  $freshSession  是否强制丢弃旧会话（true 时无论指纹是否变化都重置）
     *
     * @throws AuthenticationException 凭据错误或金蝶返回登录失败
     * @throws TransportException      网络故障、超时或登录服务返回错误状态
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

        $this->log('info', 'kingdee.login', [
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
     * 按认证方式选择端点与载荷：
     *  - 应用ID+密钥：Endpoints::LOGIN_BY_APP_SECRET，载荷含 acctid/appid/appsecret/lcid，
     *    用户名为空时不下发该字段（避免服务端把空串当作非法用户名）；
     *  - 用户名+密码：Endpoints::LOGIN，载荷含 acctid/username/password/lcid。
     *
     * @return array{0: string, 1: array<string, mixed>} 端点与请求体
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

        return [
            Endpoints::LOGIN, [
                'acctid' => $this->config->acctId,
                'username' => $this->config->username,
                'password' => $this->config->password,
                'lcid' => $this->config->lcid,
            ]
        ];
    }
}
