<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 回归测试：评审中发现的缺陷
 */
final class RegressionTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
        'max_attempts' => 3,
    ];

    /**
     * 业务文案含「未登录」不应被误判为会话失效（否则会触发无谓的重新登录与重试）
     */
    public function test_business_message_containing_unlogin_is_not_session_expiry(): void
    {
        $business = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"客户未登录订单，请处理"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $business),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        // query() 只返回原始响应，业务错误由 Response::normalize() 判定
        $raw = $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame($business, $raw);
        // 关键回归点：未触发重新登录
        self::assertSame(0, $transport->sessionResets());
        self::assertSame(2, $transport->requestCount());
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

    /**
     * 登录失败（密码错误）属认证异常，不重试
     */
    public function test_login_failure_is_not_retried(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":0,"Message":"密码错误"}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(\Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException::class);

        try {
            $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
        } finally {
            self::assertSame(1, $transport->requestCount());
        }
    }

    /**
     * 查询入口只接受 Query —— 组织过滤无法被数组入口绕过
     */
    public function test_query_rejects_raw_array(): void
    {
        $client = new Client(Config::fromArray(self::CONFIG), new FakeTransport());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Query');

        $client->query(['FormId' => 'BD_MATERIAL']);
    }

    /**
     * 凭据不得出现在脱敏配置中
     */
    public function test_config_redacted_hides_secrets(): void
    {
        $config = Config::fromArray(self::CONFIG + ['appid' => 'app', 'appsecret' => 'app-secret']);

        $redacted = $config->redacted();

        self::assertSame('***', $redacted['password']);
        self::assertSame('***', $redacted['appsecret']);
        // 原对象仍保留真值（供实际请求使用）
        self::assertSame('secret', $config->password);
    }

    /**
     * 指纹不应是密码的弱哈希
     */
    public function test_fingerprint_is_not_a_plain_md5_of_password(): void
    {
        $config = Config::fromArray(self::CONFIG);

        self::assertSame(64, strlen($config->fingerprint()));
        self::assertStringNotContainsString(md5('secret'), $config->fingerprint());
    }
}
