<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Http;

use Trappistes\Kingdee\K3Cloud\Exception\TransportException;

/**
 * 默认传输实现：基于 cURL，零第三方依赖
 *
 * 会话与连接：
 *  - Cookie 由 cURL 内置引擎管理（遵循 Domain/Path 作用域匹配，比自行实现更正确）；
 *  - 同一实例内复用句柄，保留连接缓存，避免每次请求重复 TCP + TLS 握手；
 *  - resetSession() 重建句柄，用于重新登录时丢弃旧会话。
 *
 * 约束：
 *  - Cookie 只存在于内存中（COOKIEFILE 传空串），不会在磁盘留下 cookie 文件；
 *  - 线程/协程安全：本类不共享句柄，一个实例同一时刻只应有一个请求在飞。
 */
final class CurlTransport implements Transport
{
    /**
     * cURL 句柄
     *
     * PHP 8.0 起 `curl_init()` 返回 `CurlHandle` 对象，7.4 及以下返回 `resource`。
     *
     * 这里不写死联合类型：静态分析运行时（PHP 8.4）只能证明 `CurlHandle`，
     * 标注 `resource` 会被判为不可达类型而在 PHPStan 2.x 下报错。
     */
    private $handle;

    /**
     * 默认的请求总超时（秒），对应 CURLOPT_TIMEOUT
     *
     * @var int
     */
    private int $timeout;

    /**
     * 默认的连接超时（秒），对应 CURLOPT_CONNECTTIMEOUT
     *
     * @var int
     */
    private int $connectTimeout;

    /**
     * 构造传输实例
     *
     * 句柄在构造时创建，Cookie 引擎随后即可用于会话保持。
     *
     * @param  int  $timeout  请求总超时（秒），被每次请求的 options['timeout'] 覆盖；必须 > 0
     * @param  int  $connectTimeout  连接超时（秒），被每次请求的 options['connect_timeout'] 覆盖；必须 > 0
     *
     * @throws TransportException cURL 初始化失败（扩展不可用等）
     */
    public function __construct(int $timeout = 30, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;

        $this->handle = $this->createHandle();
    }

    /**
     * 析构：释放 cURL 句柄
     *
     * @return void
     */
    public function __destruct()
    {
        // 构造失败时 PHP 不会调用析构函数，因此句柄必定已初始化，无需判空
        curl_close($this->handle);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $url  完整请求地址
     * @param  array<string, string>  $headers  请求头；缺省的 Content-Type / Accept 会自动补齐
     * @param  array<string, mixed>  $payload  请求体（将被 JSON 编码）
     * @param  array{timeout?: int, connect_timeout?: int}  $options  传输选项，可覆盖本次请求的超时
     *
     * @return HttpResponse 状态码与响应体
     *
     * @throws TransportException 请求体 JSON 编码失败、网络故障或超时
     */
    public function post(string $url, array $headers, array $payload, array $options = []): HttpResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new TransportException('请求体 JSON 编码失败');
        }

        $headerLines = [];
        $hasContentType = false;
        $hasAccept = false;

        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;

            $lower = strtolower($name);

            if ($lower === 'content-type') {
                $hasContentType = true;
            }

            if ($lower === 'accept') {
                $hasAccept = true;
            }
        }

        // 默认头只在调用方未提供时补充，避免同一头发送两次
        if (!$hasContentType) {
            $headerLines[] = 'Content-Type: application/json';
        }

        if (!$hasAccept) {
            $headerLines[] = 'Accept: application/json';
        }

        $headerLines[] = 'User-Agent: Kingdee/Php WebApi SDK (compatible: K3Cloud 7.3+)';

        // 重置选项但保留连接缓存，实现连接复用
        curl_reset($this->handle);

        curl_setopt_array($this->handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            // 声明接受压缩：服务端启用 gzip 时自动解码，避免拿到二进制乱码
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? $this->timeout),
            CURLOPT_CONNECTTIMEOUT => (int) ($options['connect_timeout'] ?? $this->connectTimeout),
            // 启用 cURL 内置 Cookie 引擎（内存模式）：自动收发并按作用域匹配。
            // COOKIEFILE 传空串是官方文档支持的写法——启用 Cookie 引擎但不读写任何文件，
            // 因此会话仅存在于内存中，不会在磁盘留下 cookie 文件。
            // （无需再显式设置 COOKIEJAR：未指定文件名时 curl 同样不会写盘。）
            CURLOPT_COOKIEFILE => '',
        ]);

        $responseBody = curl_exec($this->handle);

        if ($responseBody === false) {
            $errno = curl_errno($this->handle);
            $error = curl_error($this->handle);

            throw new TransportException(
                sprintf('HTTP 请求失败（cURL %d）：%s', $errno, $error),
                0,
                self::isRetryableError($errno),
            );
        }

        $status = (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, (string) $responseBody);
    }

    /**
     * {@inheritDoc}
     *
     * 重建句柄：丢弃 Cookie 会话（连接缓存也随之失效，下次请求重新握手）。
     *
     * @return void
     *
     * @throws TransportException 新句柄创建失败（cURL 初始化失败）
     */
    public function resetSession(): void
    {
        // 重建句柄：丢弃 Cookie 会话（连接缓存也随之失效，下次请求重新握手）
        curl_close($this->handle);
        $this->handle = $this->createHandle();
    }

    /**
     * 创建 cURL 句柄
     *
     * 新建的 cURL 句柄：PHP 8.0+ 为 `CurlHandle` 对象，7.4 及以下为 `resource`。
     *
     * @throws TransportException cURL 初始化失败
     */
    private function createHandle()
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new TransportException('cURL 初始化失败');
        }

        return $handle;
    }

    /**
     * 判断 cURL 错误码是否属于可重试的网络故障
     *
     * 可重试：DNS 解析失败、连接失败、超时、TLS 握手失败、
     * 对端无响应、接收中断、发送中断。
     *
     * @param  int  $errno  cURL 错误码（curl_errno）
     *
     * @return bool true 表示属于可重试的瞬时网络故障
     */
    private static function isRetryableError(int $errno): bool
    {
        return in_array($errno, [
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEOUTED,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_GOT_NOTHING,
            CURLE_RECV_ERROR,
            CURLE_SEND_ERROR,
        ], true);
    }
}
