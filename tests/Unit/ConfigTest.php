<?php

declare(strict_types = 1);

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

    public function test_timeout_must_be_positive(): void
    {
        // cURL 的 CURLOPT_TIMEOUT=0 表示「永不超时」，误配会变成无限等待
        $config = Config::fromArray([
            'auth_type' => 1,
            'host_url' => 'https://k3cloud.example.com/k3cloud',
            'acct_id' => 'acct',
            'username' => 'user',
            'password' => 'secret',
            'timeout' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('超时时间必须大于 0');

        $config->assertValid();
    }

    public function test_connect_timeout_must_be_positive(): void
    {
        $config = Config::fromArray([
            'auth_type' => 1,
            'host_url' => 'https://k3cloud.example.com/k3cloud',
            'acct_id' => 'acct',
            'username' => 'user',
            'password' => 'secret',
            'connect_timeout' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('连接超时时间必须大于 0');

        $config->assertValid();
    }

    /**
     * 重试次数为 0 会让读操作连一次都不发，必须报错
     */
    public function test_max_attempts_must_be_at_least_one(): void
    {
        $config = Config::fromArray([
            'auth_type' => 1,
            'host_url' => 'https://k3cloud.example.com/k3cloud',
            'acct_id' => 'acct',
            'username' => 'user',
            'password' => 'secret',
            'max_attempts' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('最大尝试次数必须大于等于 1');

        $config->assertValid();
    }

    /**
     * 1 表示只发一次（不重试），是合法取值
     */
    public function test_max_attempts_of_one_is_valid(): void
    {
        $config = Config::fromArray([
            'auth_type' => 1,
            'host_url' => 'https://k3cloud.example.com/k3cloud',
            'acct_id' => 'acct',
            'username' => 'user',
            'password' => 'secret',
            'max_attempts' => 1,
        ]);

        $config->assertValid();

        self::assertSame(1, $config->maxAttempts);
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
        $diff = Config::fromArray([
            'host_url' => 'https://a/', 'acct_id' => 'x', 'username' => 'u', 'password' => 'other'
        ]);

        self::assertSame($base->fingerprint(), $same->fingerprint());
        self::assertNotSame($base->fingerprint(), $diff->fingerprint());
    }

    public function test_fingerprint_changes_with_org_num(): void
    {
        $base = Config::fromArray([
            'host_url' => 'https://a/',
            'acct_id' => 'x',
            'username' => 'u',
            'password' => 'p',
            'org_num' => 0,
        ]);
        $other = Config::fromArray([
            'host_url' => 'https://a/',
            'acct_id' => 'x',
            'username' => 'u',
            'password' => 'p',
            'org_num' => 101,
        ]);

        // 组织维度参与签名串，不同组织不可视为同一实例
        self::assertNotSame($base->fingerprint(), $other->fingerprint());
    }

    public function test_unknown_property_name_is_rejected(): void
    {
        $config = Config::fromArray(['host_url' => 'https://a/']);

        // 属性名拼写错误必须报错：否则会静默创建动态属性，
        // 而 SDK 读的是正确属性名，导致“已切换账套”的假象
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('配置项 appid 不存在');

        $config->appid = 'x';
    }

    public function test_fingerprint_distinguishes_delimiter_inside_values(): void
    {
        $first = Config::fromArray(['host_url' => 'https://a/', 'acct_id' => 'x|y', 'username' => 'z']);
        $second = Config::fromArray(['host_url' => 'https://a/', 'acct_id' => 'x', 'username' => 'y|z']);

        // 分隔符拼接会产生相同指纹，长度前缀后必须不同
        self::assertNotSame($first->fingerprint(), $second->fingerprint());
    }

    public function test_fingerprint_rejects_non_scalar_secret(): void
    {
        $config = Config::fromArray(['host_url' => 'https://a/']);

        $config->password = ['not', 'scalar'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('password');

        $config->fingerprint();
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

    /**
     * 凭据不得出现在脱敏配置中
     */
    public function test_config_redacted_hides_secrets(): void
    {
        $config = Config::fromArray([
            'auth_type' => 1,
            'host_url' => 'https://k3cloud.example.com/k3cloud',
            'acct_id' => 'acct',
            'username' => 'user',
            'password' => 'secret',
            'appid' => 'app',
            'appsecret' => 'app-secret',
        ]);

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
        $config = Config::fromArray([
            'auth_type' => 1,
            'host_url' => 'https://k3cloud.example.com/k3cloud',
            'acct_id' => 'acct',
            'username' => 'user',
            'password' => 'secret',
        ]);

        self::assertSame(64, strlen($config->fingerprint()));
        self::assertStringNotContainsString(md5('secret'), $config->fingerprint());
    }
}
