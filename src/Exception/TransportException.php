<?php

declare(strict_types=1);

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
     * @var int
     */
    protected $statusCode;

    /**
     * @var bool
     */
    protected $retryable;

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
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * HTTP 状态码（网络层异常为 0）
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
