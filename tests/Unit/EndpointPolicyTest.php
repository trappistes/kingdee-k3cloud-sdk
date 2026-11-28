<?php

declare(strict_types = 1);

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
use Trappistes\Kingdee\K3Cloud\Tests\Support\RecordingLogger;

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

    /**
     * 端点常量必须恰好归类为写或读
     *
     * 新增端点常量时若忘记归类，这条规则会立即失败——
     * 漏归类的端点会落到「性质未知」分支，行为与登记为写端点不同。
     */
    public function test_every_endpoint_constant_is_classified_exactly_once(): void
    {
        $constants = (new \ReflectionClass(Endpoints::class))->getConstants();

        // 端点路径是字符串常量；两张清单本身是数组常量
        $endpoints = array_filter($constants, 'is_string');

        self::assertNotSame([], $endpoints);

        foreach ($endpoints as $name => $endpoint) {
            self::assertTrue(
                Endpoints::isWrite($endpoint) xor Endpoints::isRead($endpoint),
                sprintf('端点常量 %s 必须恰好归类为写端点或读端点', $name)
            );
        }
    }

    /**
     * 两张清单内部不得有重复项
     */
    public function test_endpoint_lists_have_no_duplicates(): void
    {
        self::assertCount(
            count(Endpoints::WRITE_ENDPOINTS),
            array_unique(Endpoints::WRITE_ENDPOINTS)
        );
        self::assertCount(
            count(Endpoints::READ_ENDPOINTS),
            array_unique(Endpoints::READ_ENDPOINTS)
        );

        // 写与读不得交叉
        self::assertSame([], array_intersect(Endpoints::WRITE_ENDPOINTS, Endpoints::READ_ENDPOINTS));
    }

    /**
     * 未登记端点 + 未指定策略 → 按不重试处理
     *
     * SDK 无法判断未登记端点是否会改变服务端状态，「不确定」放在安全侧：
     * 自动重试可能产生重复单据。确认是只读接口请显式传 Policy::read()。
     */
    public function test_unknown_endpoint_defaults_to_non_retryable_policy(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
            new HttpResponse(200, '[]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->request('Custom.Unknown.kdsvc', []);

            self::fail('未登记端点不应自动重试');
        } catch (TransportException $e) {
            self::assertSame('连接超时', $e->getMessage());
        }

        // 1 次登录 + 1 次业务请求：未重试
        self::assertSame(2, $transport->requestCount());
    }

    /**
     * 未登记端点 → 记一条 warning，说明为何不重试
     */
    public function test_unknown_endpoint_is_logged_as_warning(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[]'),
        );

        $logger = new RecordingLogger();
        $client = new Client(Config::fromArray(self::CONFIG), $transport, $logger);

        $client->request('Custom.Unknown.kdsvc', []);

        self::assertTrue($logger->hasRecord('kingdee.endpoint.unregistered'));
    }

    /**
     * 已知读端点 + 未指定策略 → 仍然可重试（行为不变的回归锁）
     */
    public function test_known_read_endpoint_still_retries_without_explicit_policy(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
            new HttpResponse(200, '[]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame('[]', $client->request(Endpoints::BILL_QUERY, []));
        // 1 次登录 + 1 次失败 + 1 次重试成功
        self::assertSame(3, $transport->requestCount());
    }
}
