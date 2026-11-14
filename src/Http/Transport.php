<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Http;

use Trappistes\Kingdee\K3Cloud\Exception\TransportException;

/**
 * HTTP 传输接口
 *
 * 默认实现为零依赖的 CurlTransport（内置 Cookie 引擎）。
 * 需要接入其他 HTTP 客户端（如 Guzzle / Symfony HttpClient）时实现本接口即可，
 * 由实现方自行管理会话与重置语义。
 */
interface Transport
{
    /**
     * 发送 POST 请求
     *
     * @param string                                      $url     完整请求地址
     * @param array<string, string>                       $headers 请求头
     * @param array<string, mixed>                        $payload 请求体（将被 JSON 编码）
     * @param array{timeout?: int, connect_timeout?: int} $options 传输选项
     *
     * @throws TransportException 网络故障或超时
     */
    public function post(string $url, array $headers, array $payload, array $options = []): HttpResponse;

    /**
     * 重置会话（清除 Cookie，用于重新登录）
     */
    public function resetSession(): void;
}
