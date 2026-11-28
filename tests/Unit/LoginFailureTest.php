<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 登录失败分级测试
 *
 * 金蝶 ValidateUser 的 LoginResultType：
 *  1 = 成功；0 = 凭据错误（不可重试）；-1 = 服务/数据中心类瞬时故障（可重试）
 *
 * 另含登录阶段的网络故障（TransportException）路径——它不经过登录报文，
 * 但同样属于「登录失败该重试还是该报错」的判定。
 */
final class LoginFailureTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
    ];

    public function test_credential_failure_is_not_retryable(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":0,"Message":"用户名或密码错误！"}'),
            new HttpResponse(200, '{"LoginResultType":0,"Message":"用户名或密码错误！"}'),
            new HttpResponse(200, '{"LoginResultType":0,"Message":"用户名或密码错误！"}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));
            self::fail('应当抛出认证异常');
        } catch (AuthenticationException $e) {
            self::assertFalse($e->isRetryable());
            self::assertStringContainsString('用户名或密码错误', $e->getMessage());
        }

        // 凭据错误不重试：仅 1 次登录
        self::assertSame(1, $transport->requestCount());
    }

    public function test_transient_data_center_failure_is_retryable(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"Message":"没有指定数据中心。","LoginResultType":-1,"Context":null}'),
            new HttpResponse(200, '{"Message":"没有指定数据中心。","LoginResultType":-1,"Context":null}'),
            new HttpResponse(200, '{"LoginResultType":1,"Context":{"SessionId":"x"}}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $rows = $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertCount(1, $rows);
        // 2 次失败登录 + 1 次成功登录 + 1 次查询
        self::assertSame(4, $transport->requestCount());
    }

    public function test_non_kingdee_response_is_treated_as_transient(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(502, '<html>502 Bad Gateway</html>'),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame(3, $transport->requestCount());
    }

    public function test_retry_budget_is_respected(): void
    {
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = new HttpResponse(200, '{"Message":"没有指定数据中心。","LoginResultType":-1}');
        }

        $transport = new FakeTransport(...$responses);

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));
            self::fail('应当抛出认证异常');
        } catch (AuthenticationException $e) {
            self::assertTrue($e->isRetryable());
        }

        // max_attempts=3 → 最多 3 次尝试
        self::assertSame(3, $transport->requestCount());
    }

    /**
     * 登录阶段的网络故障应纳入读操作的重试范围
     */
    public function test_login_transport_failure_is_retried(): void
    {
        $transport = new FakeTransport(
            new TransportException('连接失败', 0, true),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame('[{"FNumber":"A"}]', $client->query(Query::make('BD_MATERIAL')->fields('FNumber')));
        // 失败登录 1 次 + 成功登录 1 次 + 查询 1 次
        self::assertSame(3, $transport->requestCount());
    }
}
