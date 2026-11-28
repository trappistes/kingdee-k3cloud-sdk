<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Http;

/**
 * HTTP 响应
 *
 * 与具体 HTTP 客户端解耦，调用方无需感知 Guzzle / cURL 的类型。
 *
 * 只保留 SDK 关心的两个信息：状态码与原始响应体（未做解码/反序列化）。
 */
final class HttpResponse
{
    /**
     * HTTP 状态码（如 200、404、500）
     *
     * @var int
     */
    public int $status;

    /**
     * 原始响应体（未做任何解析；金蝶的 JSON 报文由 Response 负责解析）
     *
     * @var string
     */
    public string $body;

    /**
     * 构造响应
     *
     * @param  int  $status  HTTP 状态码
     * @param  string  $body  原始响应体
     *
     * @return void
     */
    public function __construct(int $status, string $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    /**
     * 是否 2xx
     *
     * @return bool true 表示状态码在 [200, 300) 区间
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
