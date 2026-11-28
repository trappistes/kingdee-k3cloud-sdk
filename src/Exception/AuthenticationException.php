<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Exception;

/**
 * 认证异常：登录失败、会话失效
 *
 * 认证失败发生在业务执行之前，重新登录后重试是安全的。
 *
 * 可重试性由构造参数指定：凭据错误不可重试，
 * 而服务端/数据中心类的瞬时故障（如「没有指定数据中心」）可重试。
 */
class AuthenticationException extends KingdeeException
{
    /**
     * 是否属于可重试的瞬时故障
     *
     * 例如金蝶返回「没有指定数据中心」：并非凭据错误，而是服务端
     * 数据中心解析的瞬时故障，稍后重试通常即可成功。
     *
     * @var bool
     */
    protected bool $retryable;

    /**
     * 构造认证异常
     *
     * @param  string  $message  异常信息
     * @param  bool  $retryable  是否可重试；凭据类错误传 false，瞬时故障传 true
     *
     * @return void
     */
    public function __construct(string $message, bool $retryable = false)
    {
        parent::__construct($message);

        $this->retryable = $retryable;
    }

    /**
     * 是否可重试
     *
     * @return bool true 表示该失败属于瞬时故障，稍后重试有意义
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
