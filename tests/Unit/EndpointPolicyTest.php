<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Policy;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 端点级请求策略：写端点不得使用可重试策略
 */
final class EndpointPolicyTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
    ];

    public function test_write_endpoint_rejects_retryable_policy(): void
    {
        $client = new Client(Config::fromArray(self::CONFIG), new FakeTransport());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('写端点不允许可重试策略');

        $client->request(Endpoints::SAVE, ['Model' => 'X'], Policy::read());
    }

    public function test_write_endpoint_defaults_to_non_retryable_policy(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->request(Endpoints::SAVE, ['Model' => 'X']);
            self::fail('写操作的网络异常应直接抛出，不重试');
        } catch (TransportException $e) {
            self::assertSame('连接超时', $e->getMessage());
        }

        // 1 次登录 + 1 次写入，未重试
        self::assertSame(2, $transport->requestCount());
    }

    public function test_read_endpoint_accepts_retryable_policy(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
            new HttpResponse(200, '[]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame('[]', $client->request(Endpoints::BILL_QUERY, [], Policy::read()));
        // 1 次登录 + 1 次失败 + 1 次重试成功
        self::assertSame(3, $transport->requestCount());
    }

    public function test_unknown_endpoint_keeps_caller_policy(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
            new HttpResponse(200, '[]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame('[]', $client->request('Custom.Read.kdsvc', [], Policy::read()));
        self::assertSame(3, $transport->requestCount());
    }

    public function test_write_endpoint_list_covers_bill_operations(): void
    {
        self::assertTrue(Endpoints::isWrite(Endpoints::SAVE));
        self::assertTrue(Endpoints::isWrite(Endpoints::AUDIT));
        self::assertTrue(Endpoints::isWrite(Endpoints::ATTACHMENT_UPLOAD));
        self::assertTrue(Endpoints::isWrite(Endpoints::SEND_MSG));

        self::assertFalse(Endpoints::isWrite(Endpoints::BILL_QUERY));
        self::assertFalse(Endpoints::isWrite(Endpoints::VIEW));
        self::assertFalse(Endpoints::isWrite(Endpoints::QUERY_BUSINESS_INFO));
    }

    public function test_write_policy_on_unregistered_endpoint_is_rejected(): void
    {
        $client = new Client(Config::fromArray(self::CONFIG), new FakeTransport());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('写策略不能用于非写端点');

        $client->request('Custom.NewWrite.kdsvc', [], Policy::write());
    }

    public function test_policy_rejects_non_positive_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('超时时间必须大于 0');

        Policy::read(0);
    }
}
