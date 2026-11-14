<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud;

use InvalidArgumentException;

/**
 * 客户端配置
 *
 * 框架无关的值对象：所有配置由调用方显式注入，SDK 不读取任何全局环境。
 */
final class Config
{
    /**
     * 授权类型：用户名 + 密码
     */
    public const AUTH_PASSWORD = 1;

    /**
     * 授权类型：第三方授权应用ID + 应用密钥
     */
    public const AUTH_APP_SECRET = 2;

    /**
     * 授权类型：开放平台签名认证（请求级签名，无需建立会话）
     */
    public const AUTH_SIGNATURE = 3;

    /**
     * @var int
     */
    public $authType;

    /**
     * @var string
     */
    public $hostUrl;

    /**
     * @var string
     */
    public $acctId;

    /**
     * @var string
     */
    public $username;

    /**
     * @var string
     */
    public $password;

    /**
     * @var string
     */
    public $appId;

    /**
     * @var string
     */
    public $appSecret;

    /**
     * @var int
     */
    public $lcid;

    /**
     * @var int
     */
    public $timeout;

    /**
     * @var int
     */
    public $connectTimeout;

    /**
     * @var int
     */
    public $maxAttempts;

    /**
     * 组织编号（签名认证的 AppData 组成项，默认 0）
     *
     * @var int
     */
    public $orgNum;

    public function __construct(
        int $authType = self::AUTH_PASSWORD,
        string $hostUrl = '',
        string $acctId = '',
        string $username = '',
        string $password = '',
        string $appId = '',
        string $appSecret = '',
        int $lcid = 2052,
        int $timeout = 30,
        int $connectTimeout = 10,
        int $maxAttempts = 3,
        int $orgNum = 0
    ) {
        $this->authType = $authType;
        $this->hostUrl = $hostUrl;
        $this->acctId = $acctId;
        $this->username = $username;
        $this->password = $password;
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->lcid = $lcid;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxAttempts = $maxAttempts;
        $this->orgNum = $orgNum;
    }

    /**
     * 从数组构造
     *
     * 兼容下列键名（也兼容 snake_case 的常见别名）：
     *  auth_type / host_url / acct_id / username / password / appid / appsecret
     *  lcid / timeout / connect_timeout / max_attempts
     *
     * 注意：查询范围类参数（如同步哪些组织、哪些单据）不属于连接配置，
     * 应由调用方通过 Query 的条件组合表达。
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (int) ($config['auth_type'] ?? $config['authType'] ?? self::AUTH_PASSWORD),
            self::normalizeHostUrl((string) ($config['host_url'] ?? $config['hostUrl'] ?? '')),
            (string) ($config['acct_id'] ?? $config['acctId'] ?? ''),
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            (string) ($config['appid'] ?? $config['appId'] ?? ''),
            (string) ($config['appsecret'] ?? $config['appSecret'] ?? ''),
            (int) ($config['lcid'] ?? 2052),
            (int) ($config['timeout'] ?? 30),
            (int) ($config['connect_timeout'] ?? $config['connectTimeout'] ?? 10),
            (int) ($config['max_attempts'] ?? $config['maxAttempts'] ?? 3),
            (int) ($config['org_num'] ?? $config['orgNum'] ?? 0)
        );
    }

    /**
     * 从环境变量构造
     *
     * 便于非框架环境（CLI、Worker）快速使用，键名默认 KINGDEE_ 前缀。
     *
     * @param callable|null $getenv 读取环境变量的回调，默认使用 PHP 内置 getenv
     * @param string        $prefix 环境变量前缀
     */
    public static function fromEnvironment(?callable $getenv = null, string $prefix = 'KINGDEE_'): self
    {
        // PHP 7.4 兼容：不使用 string|false 联合返回类型
        $getenv = $getenv ?? static function (string $key) {
            return getenv($key);
        };

        $read = static function (string $key, string $default = '') use ($getenv, $prefix): string {
            $value = $getenv($prefix.$key);

            return $value === false ? $default : (string) $value;
        };

        return new self(
            (int) $read('AUTH_TYPE', (string) self::AUTH_PASSWORD),
            self::normalizeHostUrl($read('HOST_URL')),
            $read('ACCT_ID'),
            $read('USERNAME'),
            $read('PASSWORD'),
            $read('APPID'),
            $read('APPSECRET'),
            (int) $read('LCID', '2052'),
            (int) $read('TIMEOUT', '30'),
            (int) $read('CONNECT_TIMEOUT', '10'),
            (int) $read('MAX_ATTEMPTS', '3'),
            (int) $read('ORG_NUM', '0')
        );
    }

    /**
     * 规范化接口地址（保证以 / 结尾；空值保持为空）
     */
    private static function normalizeHostUrl(string $hostUrl): string
    {
        $hostUrl = trim($hostUrl);

        if ($hostUrl === '') {
            return '';
        }

        return rtrim($hostUrl, '/').'/';
    }

    /**
     * 配置指纹：用于实例复用（同一指纹视为同一套凭据与端点）
     *
     * 使用 sha256 且不直接包含明文密码，避免指纹成为密码的弱哈希。
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->authType,
            $this->hostUrl,
            $this->acctId,
            $this->username,
            hash('sha256', $this->password),
            hash('sha256', $this->appSecret),
            $this->appId,
            $this->lcid,
        ]));
    }

    /**
     * 脱敏副本：用于日志与异常输出
     *
     * @return array<string, mixed>
     */
    public function redacted(): array
    {
        return [
            'auth_type' => $this->authType,
            'host_url' => $this->hostUrl,
            'acct_id' => $this->acctId,
            'username' => $this->username,
            'password' => $this->password === '' ? '' : '***',
            'appid' => $this->appId,
            'appsecret' => $this->appSecret === '' ? '' : '***',
            'lcid' => $this->lcid,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'max_attempts' => $this->maxAttempts,
            'org_num' => $this->orgNum,
        ];
    }

    /**
     * 缺失项检查
     *
     * @return string[] 缺失项说明，空数组表示配置完整
     */
    public function missing(): array
    {
        $missing = [];

        if ($this->hostUrl === '') {
            $missing[] = 'host_url';
        }

        if ($this->acctId === '') {
            $missing[] = 'acct_id';
        }

        if ($this->authType === self::AUTH_PASSWORD) {
            if ($this->username === '') {
                $missing[] = 'username';
            }
            if ($this->password === '') {
                $missing[] = 'password';
            }
        }

        if ($this->authType === self::AUTH_APP_SECRET || $this->authType === self::AUTH_SIGNATURE) {
            if ($this->appId === '') {
                $missing[] = 'appid';
            }
            if ($this->appSecret === '') {
                $missing[] = 'appsecret';
            }
        }

        return $missing;
    }

    /**
     * 断言配置完整
     *
     * @throws InvalidArgumentException
     */
    public function assertValid(): void
    {
        if (! in_array($this->authType, [self::AUTH_PASSWORD, self::AUTH_APP_SECRET, self::AUTH_SIGNATURE], true)) {
            throw new InvalidArgumentException(sprintf(
                '不支持的授权类型：%d（支持 %d 用户名+密码、%d 应用ID+应用密钥、%d 开放平台签名）',
                $this->authType,
                self::AUTH_PASSWORD,
                self::AUTH_APP_SECRET,
                self::AUTH_SIGNATURE
            ));
        }

        $missing = $this->missing();

        if ($missing !== []) {
            throw new InvalidArgumentException('金蝶客户端配置缺失：'.implode('、', $missing));
        }
    }
}
