<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Policy;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 客户端行为测试：认证、重试策略、会话失效、幂等边界
 */
final class ClientTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
        'max_attempts' => 3,
    ];

    public function test_login_is_lazy_and_only_once(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1,"Context":{"SessionId":"s"}}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, '[{"FNumber":"B"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertFalse($client->isAuthenticated());

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertTrue($client->isAuthenticated());
        // 1 次登录 + 2 次查询
        self::assertSame(3, $transport->requestCount());
    }

    public function test_login_failure_raises_authentication_exception(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":0,"Message":"密码错误"}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('密码错误');

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
    }

    public function test_read_operation_retries_on_retryable_transport_failure(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
            new TransportException('连接超时', 0, true),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame('[{"FNumber":"A"}]', $client->query(Query::make('BD_MATERIAL')->fields('FNumber')));
        self::assertSame(4, $transport->requestCount());
    }

    public function test_write_operation_does_not_retry(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(TransportException::class);

        try {
            $client->save('UWAZ_OutStock', ['Model' => []]);
        } finally {
            // 1 次登录 + 1 次保存（不重试）
            self::assertSame(2, $transport->requestCount());
        }
    }

    public function test_write_operation_does_not_retry_on_server_error(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(503, 'Service Unavailable'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(TransportException::class);

        try {
            $client->save('UWAZ_OutStock', ['Model' => []]);
        } finally {
            self::assertSame(2, $transport->requestCount());
        }
    }

    public function test_session_expiry_triggers_relogin_and_retry(): void
    {
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"用户登录已失效，请重新登录"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame('[{"FNumber":"A"}]', $client->query(Query::make('BD_MATERIAL')->fields('FNumber')));
        self::assertSame(1, $transport->sessionResets());
    }

    public function test_session_expiry_twice_raises_authentication_exception(): void
    {
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"会话已失效"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(AuthenticationException::class);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
    }

    public function test_write_is_not_resent_after_session_expiry(): void
    {
        // 写操作遇到会话失效时必须停下：SDK 无法确认业务是否已执行，重发可能产生重复单据
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"用户登录已失效，请重新登录"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->save('UWAZ_OutStock', ['Model' => []]);

            self::fail('写操作不应自动重发');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('写操作不自动重发', $e->getMessage());
        }

        // 1 次登录 + 1 次写请求：没有重登、没有重发
        self::assertSame(2, $transport->requestCount());
        self::assertSame(0, $transport->sessionResets());
    }

    public function test_session_retry_consumes_retry_budget(): void
    {
        // 会话失效后的重发同样占用重试预算：max_attempts=1 时不得再发第二次业务请求
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"会话已失效"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(array_merge(self::CONFIG, ['max_attempts' => 1])), $transport);

        try {
            $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

            self::fail('已达重试上限时应报错');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('已达最大尝试次数', $e->getMessage());
        }

        // 只有 1 次业务请求（登录请求不计入）
        self::assertSame(1, count(array_filter(
            $transport->requests,
            static fn (array $request): bool => strpos($request['url'], 'AuthService') === false
        )));
    }

    public function test_client_error_status_raises_transport_exception(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(404, '<html>Not Found</html>'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('HTTP 404');

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
    }

    public function test_login_timeout_follows_request_policy(): void
    {
        // 单次请求指定短超时，登录阶段不应仍按全局超时阻塞
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->request('k3cloud/BillQuery', [], Policy::read(5, 3));

        self::assertSame(5, $transport->requests[0]['options']['timeout']);
        self::assertSame(5, $transport->requests[1]['options']['timeout']);
    }

    public function test_paginate_builds_query_result(): void
    {
        $rows = array_map(static fn (int $i): array => ['FNumber' => 'A'.$i], range(1, 20));

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, json_encode($rows, JSON_UNESCAPED_UNICODE)),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $result = $client->paginate(Query::make('BD_MATERIAL')->fields('FNumber'), 2, 20);

        self::assertCount(20, $result);
        self::assertSame(2, $result->page);
        self::assertSame(3, $result->nextPage);
        self::assertTrue($result->hasNextPage());
        self::assertSame(20, $result->rows[0]['FNumber'] === 'A1' ? 20 : 20);

        // 校验 StartRow / Limit 传参
        self::assertSame(20, $transport->requests[1]['payload']['data']['StartRow']);
        self::assertSame(20, $transport->requests[1]['payload']['data']['Limit']);
    }

    public function test_attachment_upload_uses_larger_timeout(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"FileId":"f1"}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->uploadAttachment(['SendByte' => 'base64']);

        self::assertGreaterThanOrEqual(120, $transport->requests[1]['options']['timeout']);
    }
}
