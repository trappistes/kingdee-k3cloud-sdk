<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 应用密钥认证：登录载荷
 */
final class AppSecretLoginTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => Config::AUTH_APP_SECRET,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'appid' => 'myclient_secret',
        'appsecret' => 'secret',
    ];

    public function test_login_payload_omits_empty_username(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);
        $client->login();

        self::assertSame(1, $transport->requestCount());
        self::assertStringContainsString('LoginByAppSecret', $transport->requests[0]['url']);

        $payload = $transport->requests[0]['payload'];
        self::assertArrayNotHasKey('username', $payload);
        self::assertSame('acct', $payload['acctid']);
        self::assertSame('myclient_secret', $payload['appid']);
        self::assertSame('secret', $payload['appsecret']);
    }

    public function test_login_payload_keeps_username_when_configured(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG + ['username' => 'user']), $transport);
        $client->login();

        self::assertSame('user', $transport->requests[0]['payload']['username']);
    }

    public function test_app_secret_login_uses_dedicated_endpoint(): void
    {
        self::assertStringContainsString(
            'LoginByAppSecret',
            Endpoints::LOGIN_BY_APP_SECRET
        );
    }
}
