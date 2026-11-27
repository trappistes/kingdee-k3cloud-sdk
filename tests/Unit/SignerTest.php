<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Signer;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 签名认证测试
 */
final class SignerTest extends TestCase
{
    /**
     * 应用ID 格式：clientId_secret
     */
    private const APP_ID = 'myclient_abcdefghijklmnopqrstuvwxyz012345';

    private const APP_SECRET = 'secret-secret-secret-secret-1234';

    public function test_signature_headers_are_deterministic_with_fixed_clock(): void
    {
        $signer = new Signer(self::APP_ID, self::APP_SECRET, 'acct', 'user', 2052, 0);

        $url = 'https://k3cloud.example.com/k3cloud/Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.BillQuery.common.kdsvc';

        $first = $signer->headers($url, 1700000000);
        $second = $signer->headers($url, 1700000000);

        self::assertSame($first, $second);
        self::assertSame('2.0', $first['X-Api-Auth-Version']);
        self::assertSame('myclient', $first['X-Api-ClientID']);
        self::assertSame('1700000000', $first['x-api-timestamp']);
        self::assertSame('1700000000', $first['x-api-nonce']);
        // 头顺序与金蝶官方示例一致（签名串中 nonce 在前、timestamp 在后）
        self::assertSame('x-api-timestamp,x-api-nonce', $first['x-api-signheaders']);
        self::assertSame(self::APP_ID, $first['X-Kd-Appkey']);
        self::assertNotEmpty($first['X-Api-Signature']);
        self::assertNotEmpty($first['X-Kd-Signature']);
    }

    public function test_app_data_contains_account_user_locale_and_org(): void
    {
        $signer = new Signer(self::APP_ID, self::APP_SECRET, 'acct-id', '张三', 2052, 101);

        $headers = $signer->headers('https://x/k3cloud/a.b.c', 1700000000);

        self::assertSame(base64_encode('acct-id,张三,2052,101'), $headers['X-Kd-Appdata']);
    }

    public function test_signature_changes_with_timestamp(): void
    {
        $signer = new Signer(self::APP_ID, self::APP_SECRET, 'acct', 'user');

        $a = $signer->headers('https://x/k3cloud/a.b.c', 1700000000);
        $b = $signer->headers('https://x/k3cloud/a.b.c', 1700000001);

        self::assertNotSame($a['X-Api-Signature'], $b['X-Api-Signature']);
    }

    public function test_app_secret_decoding(): void
    {
        // 非 32 位密钥原样返回
        self::assertSame('short', Signer::decodeAppSecret('short'));

        // 32 位标准密钥需异或解码
        $encoded = base64_encode('0123456789abcdef01234567');
        $decoded = Signer::decodeAppSecret($encoded);

        self::assertNotSame($encoded, $decoded);
        self::assertSame(32, strlen($decoded));
    }

    public function test_signature_auth_does_not_call_login_endpoint(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(
            Config::fromArray([
                'auth_type' => Config::AUTH_SIGNATURE,
                'host_url' => 'https://k3cloud.example.com/k3cloud/',
                'acct_id' => 'acct',
                'username' => 'user',
                'appid' => self::APP_ID,
                'appsecret' => self::APP_SECRET,
            ]),
            $transport,
            null,
            static function (): int {
                return 1700000000;
            },
        );

        $client->queryRows(\Trappistes\Kingdee\K3Cloud\Query::make('BD_MATERIAL')->fields('FNumber'));

        // 仅一次请求（无登录）
        self::assertSame(1, $transport->requestCount());
        self::assertStringContainsString('BillQuery', $transport->requests[0]['url']);

        // 请求头应带签名
        self::assertArrayHasKey('X-Api-Signature', $transport->requests[0]['headers']);
        self::assertArrayHasKey('X-Kd-Signature', $transport->requests[0]['headers']);
    }

    public function test_signature_auth_is_reported_as_authenticated_without_login(): void
    {
        $client = new Client(
            Config::fromArray([
                'auth_type' => Config::AUTH_SIGNATURE,
                'host_url' => 'https://k3cloud.example.com/k3cloud/',
                'acct_id' => 'acct',
                'username' => 'user',
                'appid' => self::APP_ID,
                'appsecret' => self::APP_SECRET,
            ]),
            new FakeTransport(),
        );

        self::assertTrue($client->isAuthenticated());
    }

    public function test_unsupported_auth_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('不支持的授权类型');

        new Client(
            Config::fromArray([
                'auth_type' => 9,
                'host_url' => 'https://x/',
                'acct_id' => 'a',
                'username' => 'u',
                'password' => 'p',
            ]),
            new FakeTransport(),
        );
    }

    public function test_appid_without_secret_part_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('clientId_secret');

        new Signer('myclient', self::APP_SECRET, 'acct', 'user');
    }

    public function test_empty_app_secret_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('appsecret');

        new Signer(self::APP_ID, '', 'acct', 'user');
    }

    public function test_client_rejects_invalid_signature_appid(): void
    {
        $this->expectException(ConfigurationException::class);

        new Client(
            Config::fromArray([
                'auth_type' => Config::AUTH_SIGNATURE,
                'host_url' => 'https://x/',
                'acct_id' => 'a',
                'username' => 'u',
                'appid' => 'no-secret-part',
                'appsecret' => self::APP_SECRET,
            ]),
            new FakeTransport(),
        );
    }
}
