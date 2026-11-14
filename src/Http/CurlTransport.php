<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Http;

use Trappistes\Kingdee\K3Cloud\Exception\TransportException;

/**
 * 默认传输实现：基于 cURL，零第三方依赖
 *
 * 会话与连接：
 *  - Cookie 由 cURL 内置引擎管理（遵循 Domain/Path 作用域匹配，比自行实现更正确）；
 *  - 同一实例内复用句柄，保留连接缓存，避免每次请求重复 TCP + TLS 握手；
 *  - resetSession() 重建句柄，用于重新登录时丢弃旧会话。
 */
final class CurlTransport implements Transport
{
    /**
     * cURL 句柄
     *
     * PHP 8.0 起 `curl_init()` 返回 `CurlHandle` 对象，7.4 及以下返回 `resource`。
     *
     * @var resource|\CurlHandle
     */
    private $handle;

    /**
     * @var int
     */
    private $timeout;

    /**
     * @var int
     */
    private $connectTimeout;

    public function __construct(int $timeout = 30, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;

        $this->handle = $this->createHandle();
    }

    public function __destruct()
    {
        // 构造失败时 PHP 不会调用析构函数，因此句柄必定已初始化，无需判空
        curl_close($this->handle);
    }

    /**
     * {@inheritDoc}
     */
    public function post(string $url, array $headers, array $payload, array $options = []): HttpResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new TransportException('请求体 JSON 编码失败');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $headerLines[] = 'Content-Type: application/json';
        $headerLines[] = 'Accept: application/json';
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
            // 启用 cURL 内置 Cookie 引擎（内存模式）：自动收发并按作用域匹配
            CURLOPT_COOKIEFILE => '',
            CURLOPT_COOKIEJAR => '',
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
     */
    public function resetSession(): void
    {
        // 重建句柄：丢弃 Cookie 会话（连接缓存也随之失效，下次请求重新握手）
        curl_close($this->handle);
        $this->handle = $this->createHandle();
    }

    /**
     * 创建 cURL 句柄
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
