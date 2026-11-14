<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Http;

/**
 * HTTP 响应
 *
 * 与具体 HTTP 客户端解耦，调用方无需感知 Guzzle / cURL 的类型。
 */
final class HttpResponse
{
    /**
     * @var int
     */
    public $status;

    /**
     * @var string
     */
    public $body;

    public function __construct(int $status, string $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    /**
     * 是否 2xx
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
