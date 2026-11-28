<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Http;

use Trappistes\Kingdee\K3Cloud\Exception\TransportException;

/**
 * HTTP 传输接口
 *
 * 默认实现为零依赖的 CurlTransport（内置 Cookie 引擎）。
 * 需要接入其他 HTTP 客户端（如 Guzzle / Symfony HttpClient）时实现本接口即可，
 * 由实现方自行管理会话与重置语义。
 *
 * 实现约束：
 *  - post() 抛出的异常必须是 TransportException，且用其 retryable 标记
 *    区分「瞬时网络故障（可重试）」与「其它情况」；
 *  - resetSession() 必须**真正丢弃**会话凭据（Cookie 等），否则身份切换的安全保证失效。
 */
interface Transport
{
    /**
     * 发送 POST 请求
     *
     * @param  string  $url  完整请求地址
     * @param  array<string, string>  $headers  请求头
     * @param  array<string, mixed>  $payload  请求体（将被 JSON 编码）
     * @param  array{timeout?: int, connect_timeout?: int}  $options  传输选项
     *
     * @return HttpResponse 状态码与原始响应体
     *
     * @throws TransportException 网络故障或超时
     */
    public function post(string $url, array $headers, array $payload, array $options = []): HttpResponse;

    /**
     * 重置会话（清除 Cookie，用于重新登录）
     *
     * 实现必须**真正丢弃**已有的会话凭据（Cookie 等）：
     * 身份变更时 SDK 依赖它作废旧身份会话；
     * 若自定义实现这里是空操作，切换账套时可能继续用旧账号发请求。
     *
     * @return void
     *
     * @throws TransportException 重建会话失败（如底层客户端初始化失败）
     */
    public function resetSession(): void;
}
