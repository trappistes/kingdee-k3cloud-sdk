<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Exception;

use Throwable;

/**
 * 传输异常：网络故障、超时、服务端错误
 *
 * 传输异常意味着「无法确定服务端是否已执行该操作」，
 * 因此写操作遇到此异常时不得盲目重试。
 */
class TransportException extends KingdeeException
{
    /**
     * HTTP 状态码；纯网络层异常（未拿到响应）为 0
     *
     * @var int
     */
    protected int $statusCode;

    /**
     * 是否属于可重试的传输故障
     *
     * @var bool
     */
    protected bool $retryable;

    /**
     * 构造传输异常
     *
     * @param  string  $message  异常信息
     * @param  int  $statusCode  HTTP 状态码；网络层异常传 0
     * @param  bool  $retryable  是否可重试（连接失败、超时、5xx 等瞬时故障传 true）
     * @param  Throwable|null  $previous  前一个异常（用于异常链），可为 null
     *
     * @return void
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        bool $retryable = false,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);

        $this->statusCode = $statusCode;
        $this->retryable = $retryable;
    }

    /**
     * 是否属于可重试的传输故障（连接失败、超时、5xx）
     *
     * @return bool true 表示属于瞬时故障，重试有可能成功
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * HTTP 状态码（网络层异常为 0）
     *
     * @return int 状态码；未拿到 HTTP 响应时为 0
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
