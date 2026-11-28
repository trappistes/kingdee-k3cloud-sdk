<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Policy;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * HTTP 状态码分支测试
 *
 * 金蝶业务错误一律走 200 + 业务错误报文，4xx / 5xx 只在
 * 接口地址、网关、代理等基础设施层出现。这里用假传输直接给出状态码，
 * 锁定 SDK 的重试与报错行为（不依赖真实服务器）。
 */
final class HttpStatusHandlingTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
        'timeout' => 5,
        'connect_timeout' => 2,
        'max_attempts' => 3,
    ];

    private const LOGIN_OK = '{"LoginResultType":1,"Context":{"SessionId":"x"}}';

    public function test_read_retries_on_server_error_until_attempt_budget_is_used(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(500, 'server error'),
            new HttpResponse(500, 'server error'),
            new HttpResponse(500, 'server error'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->request(Endpoints::BILL_QUERY, ['formid' => 'BD_Customer'], Policy::read(5, 3, 1));
            self::fail('应当抛出传输异常');
        } catch (TransportException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
            self::assertTrue($e->isRetryable());
        }

        // 登录 1 次 + 业务 3 次（max_attempts 用满）
        self::assertSame(4, $transport->requestCount());
    }

    public function test_read_recovers_when_server_error_is_followed_by_success(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(500, 'server error'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]},"BillQueryResult":[]}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $raw = $client->request(Endpoints::BILL_QUERY, ['formid' => 'BD_Customer'], Policy::read(5, 3, 1));

        self::assertStringContainsString('IsSuccess', $raw);
        self::assertSame(3, $transport->requestCount());
    }

    public function test_client_error_is_not_retried(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(400, 'bad request'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->request(Endpoints::BILL_QUERY, ['formid' => 'BD_Customer'], Policy::read(5, 3, 1));
            self::fail('应当抛出传输异常');
        } catch (TransportException $e) {
            self::assertStringContainsString('HTTP 400', $e->getMessage());
            self::assertFalse($e->isRetryable());
        }

        // 登录 1 次 + 业务 1 次：4xx 直接报错，不重试
        self::assertSame(2, $transport->requestCount());
    }

    public function test_write_is_not_retried_on_server_error(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, self::LOGIN_OK),
            new HttpResponse(500, 'server error'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->save('BD_Customer', ['Model' => ['FNumber' => 'X']]);
            self::fail('应当抛出传输异常');
        } catch (TransportException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
        }

        // 登录 1 次 + 业务 1 次：写操作不重试，避免重复单据
        self::assertSame(2, $transport->requestCount());
    }

    public function test_login_client_error_reports_configuration_problem(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(404, 'not found'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->request(Endpoints::BILL_QUERY, ['formid' => 'BD_Customer'], Policy::read(5, 3, 1));
            self::fail('应当抛出传输异常');
        } catch (TransportException $e) {
            self::assertStringContainsString('HTTP 404', $e->getMessage());
            self::assertStringContainsString('接口地址', $e->getMessage());
            self::assertFalse($e->isRetryable());
        }

        // 登录不可用时不重试
        self::assertSame(1, $transport->requestCount());
    }
}
