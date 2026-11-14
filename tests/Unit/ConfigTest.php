<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Config;

/**
 * 配置对象测试
 */
final class ConfigTest extends TestCase
{
    public function test_from_array_maps_snake_case_keys(): void
    {
        $config = Config::fromArray([
            'auth_type' => 1,
            'host_url' => 'https://k3cloud.example.com/k3cloud',
            'acct_id' => 'acct',
            'username' => 'user',
            'password' => 'secret',
            'lcid' => 2052,
            'timeout' => 45,
            'connect_timeout' => 8,
            'max_attempts' => 5,
        ]);

        self::assertSame(1, $config->authType);
        self::assertSame('https://k3cloud.example.com/k3cloud/', $config->hostUrl);
        self::assertSame('acct', $config->acctId);
        self::assertSame('user', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame(45, $config->timeout);
        self::assertSame(8, $config->connectTimeout);
        self::assertSame(5, $config->maxAttempts);
        self::assertSame([], $config->missing());
    }

    public function test_missing_items_are_reported(): void
    {
        $config = Config::fromArray([]);

        self::assertContains('host_url', $config->missing());
        self::assertContains('acct_id', $config->missing());
        self::assertContains('username', $config->missing());
        self::assertContains('password', $config->missing());
    }

    public function test_assert_valid_throws_when_incomplete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('host_url');

        Config::fromArray([])->assertValid();
    }

    public function test_signature_auth_requires_app_credentials(): void
    {
        $config = Config::fromArray([
            'auth_type' => Config::AUTH_SIGNATURE,
            'host_url' => 'https://k3cloud.example.com/k3cloud/',
            'acct_id' => 'acct',
            'username' => 'user',
        ]);

        self::assertContains('appid', $config->missing());
        self::assertContains('appsecret', $config->missing());
    }

    public function test_app_secret_auth_requires_app_credentials(): void
    {
        $config = Config::fromArray([
            'auth_type' => Config::AUTH_APP_SECRET,
            'host_url' => 'https://k3cloud.example.com/k3cloud/',
            'acct_id' => 'acct',
            'username' => 'user',
        ]);

        self::assertContains('appid', $config->missing());
        self::assertContains('appsecret', $config->missing());
        self::assertNotContains('password', $config->missing());
    }

    public function test_unsupported_auth_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不支持的授权类型');

        Config::fromArray([
            'auth_type' => 9,
            'host_url' => 'https://k3cloud.example.com/k3cloud/',
            'acct_id' => 'acct',
            'username' => 'user',
            'password' => 'secret',
        ])->assertValid();
    }

    public function test_fingerprint_changes_with_credentials(): void
    {
        $base = Config::fromArray(['host_url' => 'https://a/', 'acct_id' => 'x', 'username' => 'u', 'password' => 'p']);
        $same = Config::fromArray(['host_url' => 'https://a/', 'acct_id' => 'x', 'username' => 'u', 'password' => 'p']);
        $diff = Config::fromArray(['host_url' => 'https://a/', 'acct_id' => 'x', 'username' => 'u', 'password' => 'other']);

        self::assertSame($base->fingerprint(), $same->fingerprint());
        self::assertNotSame($base->fingerprint(), $diff->fingerprint());
    }

    public function test_from_environment_reads_prefixed_variables(): void
    {
        $vars = [
            'KINGDEE_HOST_URL' => 'https://k3cloud.example.com/k3cloud/',
            'KINGDEE_ACCT_ID' => 'acct',
            'KINGDEE_USERNAME' => 'user',
            'KINGDEE_PASSWORD' => 'secret',
            'KINGDEE_TIMEOUT' => '60',
        ];

        $config = Config::fromEnvironment(static function (string $key) use ($vars) {
            return $vars[$key] ?? false;
        });

        self::assertSame(60, $config->timeout);

        self::assertSame('https://k3cloud.example.com/k3cloud/', $config->hostUrl);
        self::assertSame('acct', $config->acctId);
        self::assertSame([], $config->missing());
    }
}
