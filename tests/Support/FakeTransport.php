<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Support;

use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Http\Transport;

/**
 * 内存假传输：记录请求、按预设队列返回响应，可模拟异常
 */
final class FakeTransport implements Transport
{
    /**
     * @var array<int, array{url: string, headers: array<string, string>, payload: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * @var array<int, HttpResponse|TransportException>
     */
    private array $queue = [];

    private int $sessionResets = 0;

    /**
     * @param  HttpResponse|TransportException  ...$responses
     */
    public function __construct(...$responses)
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }
    }

    /**
     * 追加预设响应
     *
     * @param  mixed  $response
     */
    /**
     * @param  HttpResponse|TransportException  $response
     */
    public function push($response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function post(string $url, array $headers, array $payload, array $options = []): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
            'options' => $options,
        ];

        if ($this->queue === []) {
            return new HttpResponse(200, '[]');
        }

        $next = array_shift($this->queue);

        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /**
     * {@inheritDoc}
     */
    public function resetSession(): void
    {
        $this->sessionResets++;
    }

    /**
     * 会话重置次数
     */
    public function sessionResets(): int
    {
        return $this->sessionResets;
    }

    /**
     * 请求次数
     */
    public function requestCount(): int
    {
        return count($this->requests);
    }
}
