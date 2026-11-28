<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud;

use InvalidArgumentException;

/**
 * 客户端配置
 *
 * 框架无关的值对象：所有配置由调用方显式注入，SDK 不读取任何全局环境。
 *
 * 关键约束：
 *  - 这是一个**可变的活对象**：字段都是 public，调用方可以在运行期改写；
 *    Client 在每次请求前都会重新调用 assertValid()，因此改坏了会立刻暴露。
 *  - 已知字段可直接赋值；**未知字段会抛异常**（见 __set），
 *    避免「$config->appid = 'x'」这种拼写错误造成「已切换账套」的假象。
 *  - 字段之间存在依赖关系（授权类型决定哪些字段必填），完整性由 missing() / assertValid() 判定。
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
     * 授权类型，取值见 AUTH_PASSWORD / AUTH_APP_SECRET / AUTH_SIGNATURE
     *
     * @var int
     */
    public $authType;

    /**
     * 金蝶接口根地址，如 https://xxx.kingdee.com/k3cloud/
     *
     * 由 fromArray()/fromEnvironment() 装配时会自动补齐结尾的 '/'。
     *
     * @var string
     */
    public $hostUrl;

    /**
     * 账套 ID（金蝶的数据库账套编号），所有授权类型都需要
     *
     * @var string
     */
    public $acctId;

    /**
     * 登录用户名
     *
     * AUTH_PASSWORD 用于登录；AUTH_SIGNATURE 用于拼装签名头的 AppData。
     *
     * @var string
     */
    public $username;

    /**
     * 登录密码，仅 AUTH_PASSWORD 使用
     *
     * @var string
     */
    public $password;

    /**
     * 应用 ID
     *
     * AUTH_APP_SECRET：开放平台应用的 AppId；
     * AUTH_SIGNATURE：必须是 clientId_secret 形式（否则 Signer 构造时报错）。
     *
     * @var string
     */
    public $appId;

    /**
     * 应用密钥，AUTH_APP_SECRET / AUTH_SIGNATURE 使用
     *
     * @var string
     */
    public $appSecret;

    /**
     * 语言 ID，2052 = 简体中文（默认）
     *
     * @var int
     */
    public $lcid;

    /**
     * 单次请求的总超时时间（秒），必须 > 0
     *
     * @var int
     */
    public $timeout;

    /**
     * 建立连接的超时时间（秒），必须 > 0
     *
     * @var int
     */
    public $connectTimeout;

    /**
     * 读请求的最大尝试次数（含首次请求），写请求固定不重试
     *
     * 必须 >= 1：1 表示只发一次（不重试）。写操作不受此值影响，
     * 一律不自动重发（见 Client::request()）。
     *
     * @var int
     */
    public $maxAttempts;

    /**
     * 组织编号（签名认证的 AppData 组成项，默认 0）
     *
     * 0 表示不限定组织——与官方参考实现一致，不强制必填：
     * 金蝶是否接受 org_num=0 未实机验证，让服务端报错比凭空设必填更可靠。
     *
     * @var int
     */
    public $orgNum;

    /**
     * 构造配置
     *
     * 这里只装配字段、不做完整性校验；需要立即校验请调用 assertValid()。
     *
     * @param  int  $authType  授权类型：AUTH_PASSWORD / AUTH_APP_SECRET / AUTH_SIGNATURE
     * @param  string  $hostUrl  金蝶接口根地址，如 https://xxx.kingdee.com/k3cloud/（末尾 '/' 可省略）
     * @param  string  $acctId  账套 ID
     * @param  string  $username  登录用户名
     * @param  string  $password  登录密码（仅 AUTH_PASSWORD）
     * @param  string  $appId  应用 ID（AUTH_SIGNATURE 时须为 clientId_secret 形式）
     * @param  string  $appSecret  应用密钥（AUTH_APP_SECRET / AUTH_SIGNATURE）
     * @param  int  $lcid  语言 ID，2052 = 简体中文
     * @param  int  $timeout  请求总超时（秒），必须 > 0
     * @param  int  $connectTimeout  连接超时（秒），必须 > 0
     * @param  int  $maxAttempts  读请求的最大尝试次数（含首次）
     * @param  int  $orgNum  组织编号，签名认证的 AppData 组成项
     *
     * @return void
     */
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
     * 兼容下列键名（括号内为别名，均可选）：
     *  auth_type(authType) / host_url(hostUrl) / acct_id(acctId) / username / password
     *  appid(appId) / appsecret(appSecret) / lcid / timeout
     *  connect_timeout(connectTimeout) / max_attempts(maxAttempts) / org_num(orgNum)
     *
     * 缺省值与构造函数一致（authType=AUTH_PASSWORD、lcid=2052、timeout=30、
     * connectTimeout=10、maxAttempts=3、orgNum=0）。
     * 值会被强制转换为字符串/整数；host_url 会自动补齐结尾的 '/'。
     * 本方法不做必填校验，请另行调用 assertValid()。
     *
     * 注意：查询范围类参数（如同步哪些组织、哪些单据）不属于连接配置，
     * 应由调用方通过 Query 的条件组合表达。
     *
     * @param  array<string, mixed>  $config  配置键值对，键名见上
     *
     * @return self 新建的配置实例
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
     * 读取的环境变量（前缀 + 名称）：
     *  KINGDEE_AUTH_TYPE / KINGDEE_HOST_URL / KINGDEE_ACCT_ID / KINGDEE_USERNAME /
     *  KINGDEE_PASSWORD / KINGDEE_APPID / KINGDEE_APPSECRET / KINGDEE_LCID /
     *  KINGDEE_TIMEOUT / KINGDEE_CONNECT_TIMEOUT / KINGDEE_MAX_ATTEMPTS / KINGDEE_ORG_NUM
     *
     * 变量不存在时回退到与构造函数一致的缺省值；
     * host_url 会自动补齐结尾的 '/'，其余值强制转换为字符串/整数。
     * 本方法不做必填校验，请另行调用 assertValid()。
     *
     * @param  callable|null  $getenv  读取环境变量的回调，接收带前缀的变量名，
     *                               返回字符串或 false（表示未设置）；默认使用 PHP 内置 getenv
     * @param  string  $prefix  环境变量前缀，默认 'KINGDEE_'
     *
     * @return self 新建的配置实例
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
     * 合法配置项名（用于拼写错误提示）
     */
    private const PROPERTY_NAMES = [
        'authType',
        'hostUrl',
        'acctId',
        'username',
        'password',
        'appId',
        'appSecret',
        'lcid',
        'timeout',
        'connectTimeout',
        'maxAttempts',
        'orgNum',
    ];

    /**
     * 拦截不存在的配置项
     *
     * PHP 允许给对象动态添加属性，写错名字时不会报错：
     *   $config->appid = 'x';   // 拼写错误，实际生效的是 $config->appId
     * 这会让「已切换账套」的假象成立（旧会话继续被使用），
     * 所以这里直接报错，把错误暴露在发生的那一刻。
     *
     * 注意：已存在的合法配置项是 public 属性，直接赋值不会走这里；
     * 本方法只拦截**不存在的**属性名。
     *
     * @param  string  $name  被赋值的属性名
     * @param  mixed  $value  被赋的值（不会生效，调用即抛异常）
     *
     * @return void
     *
     * @throws InvalidArgumentException 属性名不在合法配置项列表内
     */
    public function __set(string $name, $value): void
    {
        throw new InvalidArgumentException(sprintf(
            '配置项 %s 不存在（合法配置项：%s）；请检查属性名拼写',
            $name,
            implode('、', self::PROPERTY_NAMES)
        ));
    }

    /**
     * 规范化接口地址（保证以 / 结尾；空值保持为空）
     *
     * @param  string  $hostUrl  原始接口根地址
     *
     * @return string 以 '/' 结尾的接口根地址；入参为空时返回空字符串
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
     *
     * 参与指纹的字段（表示「我是谁、连哪个账套」）：
     *  authType / hostUrl / acctId / username / password（哈希后）/ appSecret（哈希后）/
     *  appId / lcid / orgNum
     * 不参与指纹的字段（属于传输行为，改了不需要重新认证）：
     *  timeout / connectTimeout / maxAttempts
     *
     * @return string 64 位十六进制 sha256 指纹
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('', [
            self::fingerprintPart((string) $this->authType),
            self::fingerprintPart($this->hostUrl),
            self::fingerprintPart($this->acctId),
            self::fingerprintPart($this->username),
            self::fingerprintPart(self::secretHash($this->password, 'password')),
            self::fingerprintPart(self::secretHash($this->appSecret, 'appSecret')),
            self::fingerprintPart($this->appId),
            self::fingerprintPart((string) $this->lcid),
            // 签名认证的组织维度参与签名串，不同组织不可视为同一实例
            self::fingerprintPart((string) $this->orgNum),
        ]));
    }

    /**
     * 密钥类字段的哈希：非标量直接报错，避免静默算出一个错误指纹
     *
     * @param  mixed  $value  待哈希的密钥值，允许标量（字符串/数字/布尔）
     * @param  string  $name  字段名，仅用于异常信息
     *
     * @return string 32 位十六进制 sha256 哈希
     *
     * @throws InvalidArgumentException 值不是标量（如数组/对象），无法得出可信指纹
     */
    private static function secretHash($value, string $name): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException(sprintf(
                '配置项 %s 必须是标量值（当前类型：%s）',
                $name,
                gettype($value)
            ));
        }

        return hash('sha256', (string) $value);
    }

    /**
     * 指纹片段：长度前缀，避免不同字段组合出相同字符串
     *
     * 例：acctId='x|y' + username='z' 与 acctId='x' + username='y|z'
     * 直接用分隔符拼接会得到同一结果，长度前缀可彻底避免。
     *
     * @param  string  $value  参与指纹的字段值
     *
     * @return string 形如 "长度:内容" 的指纹片段
     */
    private static function fingerprintPart(string $value): string
    {
        return strlen($value).':'.$value;
    }

    /**
     * 脱敏副本：用于日志与异常输出
     *
     * 键名统一为 snake_case；password 与 appsecret 非空时替换为 '***'，为空时保持空串。
     * 该副本仅供展示，不能用于还原真实凭据。
     *
     * @return array<string, mixed> 脱敏后的配置快照
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
     * 必填项随授权类型而定：
     *  - 公共：host_url、acct_id
     *  - AUTH_PASSWORD：username、password
     *  - AUTH_APP_SECRET / AUTH_SIGNATURE：appid、appsecret
     *
     * @return string[] 缺失项名称（snake_case，与 fromArray 的键名一致），空数组表示配置完整
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
     * 校验项：授权类型合法、必填项齐全（见 missing()）、
     * timeout > 0 且 connectTimeout > 0（0 在 cURL 中表示「永不超时」，属于误配）、
     * maxAttempts >= 1（0 会让读操作连一次都不发）。
     * Client 在每次请求前调用本方法，因此配置被外部改坏会立即暴露。
     *
     * @return void
     * @throws InvalidArgumentException 授权类型不支持、必填项缺失或超时/重试次数配置非法
     *
     */
    public function assertValid(): void
    {
        if (!in_array($this->authType, [self::AUTH_PASSWORD, self::AUTH_APP_SECRET, self::AUTH_SIGNATURE], true)) {
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

        // cURL 的 CURLOPT_TIMEOUT=0 表示「永不超时」，误配会变成无限等待
        if ($this->timeout < 1) {
            throw new InvalidArgumentException(sprintf('超时时间必须大于 0（当前 %d）', $this->timeout));
        }

        if ($this->connectTimeout < 1) {
            throw new InvalidArgumentException(sprintf('连接超时时间必须大于 0（当前 %d）', $this->connectTimeout));
        }

        // 重试次数为 0 会让读操作连一次都不发，变成「静默不请求」
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException(sprintf('最大尝试次数必须大于等于 1（当前 %d）', $this->maxAttempts));
        }
    }
}
