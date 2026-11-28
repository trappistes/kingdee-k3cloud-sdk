<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud;

use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;

/**
 * 金蝶开放平台签名认证（第三方授权应用的请求签名）
 *
 * 与「账套+用户名+密码」「账套+应用ID+密钥」两种登录式认证不同：
 * 签名认证不需要建立会话，而是**每个请求都携带签名头**。
 *
 * 签名规则与金蝶官方 Java / Python SDK 示例一致，
 * 实现参考了 MIT 许可的 kingdee-php/k3cloud（https://github.com/kingdee-php/k3cloud）。
 *
 * 已与该参考实现逐项核对一致：nonce 取时间戳、org_num 默认 0 且不强制必填、
 * 密钥长度非 32 时原样使用、异或后再 base64_encode。
 * 唯一差异是异或密钥常量取官方 Java 示例的 32 位版本（Python 示例为 36 位）。
 * 整条链路尚未在真实帐套验证（第三方授权应用未开通）。
 *
 * 签名步骤（与金蝶开放平台一致）：
 *  1. appid 按第一个 '_' 拆为 clientId 与 clientSecret（后者需异或解码）；
 *  2. 拼接待签名串 apiSign = "POST\n" + 转义后的URL + "\n\n" + 签名头列表；
 *  3. 用 clientSecret 对 apiSign 做 HMAC-SHA256，取十六进制后 base64，得到 X-Api-Signature；
 *  4. AppData = 账套ID,用户名,语言ID,组织编号，base64 后放入 X-Kd-Appdata；
 *  5. 用 appSecret 对 appid+AppData 做同样的 HMAC-SHA256 + hex + base64，得到 X-Kd-Signature。
 */
final class Signer
{
    /**
     * 应用密钥的异或解码密钥（金蝶官方 SDK 示例中的常量）
     *
     * @var string
     */
    private const XOR_KEY = '0054f397c6234378b09ca7d3e5debce7';

    /**
     * 应用密钥长度（标准密钥为 32 位，需异或解码）
     *
     * @var int
     */
    private const SECRET_LENGTH = 32;

    /**
     * 应用 ID（clientId_secret 形式）
     *
     * @var string
     */
    private $appId;

    /**
     * 应用密钥（原始密文，用于计算 X-Kd-Signature）
     *
     * @var string
     */
    private $appSecret;

    /**
     * 账套 ID（AppData 的第一段）
     *
     * @var string
     */
    private $acctId;

    /**
     * 用户名（AppData 的第二段）
     *
     * @var string
     */
    private $username;

    /**
     * 语言 ID（AppData 的第三段），2052 = 简体中文
     *
     * @var int
     */
    private $lcid;

    /**
     * 组织编号（AppData 的第四段）
     *
     * @var int
     */
    private $orgNum;

    /**
     * 构造签名器
     *
     * @param  string  $appId  应用 ID，必须为 clientId_secret 形式（否则无法解出密钥）
     * @param  string  $appSecret  应用密钥，不能为空
     * @param  string  $acctId  账套 ID，参与 AppData 与签名
     * @param  string  $username  用户名，参与 AppData 与签名
     * @param  int  $lcid  语言 ID，2052 = 简体中文
     * @param  int  $orgNum  组织编号，参与 AppData 与签名
     *
     * @return void
     *
     * @throws ConfigurationException appid 不含 '_'（无法拆出 clientId/clientSecret）或 appsecret 为空
     */
    public function __construct(
        string $appId,
        string $appSecret,
        string $acctId,
        string $username,
        int $lcid = 2052,
        int $orgNum = 0
    ) {
        // appid 必须是 clientId_secret 形式：否则解不出密钥，签名会静默用空密钥，
        // 最终只表现为服务端拒绝，排查成本高
        if (strpos($appId, '_') === false) {
            throw new ConfigurationException(
                '签名认证的 appid 必须为 clientId_secret 形式（当前：'.$appId.'）'
            );
        }

        if ($appSecret === '') {
            throw new ConfigurationException('签名认证的 appsecret 不能为空');
        }

        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->acctId = $acctId;
        $this->username = $username;
        $this->lcid = $lcid;
        $this->orgNum = $orgNum;
    }

    /**
     * 生成签名请求头
     *
     * 每次请求调用一次；时间戳同时用作 nonce。
     *
     * nonce 取时间戳是**官方参考实现的做法**（金蝶服务端据此判断重放与否，
     * 整条链路未实机验证）——改为随机值属于无依据地偏离官方行为，故保持一致。
     *
     * @param  string  $url  完整请求地址（含查询串与端点路径），签名时斜杠会被转义为 %2F
     * @param  int  $timestamp  时间戳（秒），同时用作 nonce；建议为当前时间
     *
     * @return array<string, string> 请求头名到头值的映射（X-Api-* 与 X-Kd-* 两组）
     */
    public function headers(string $url, int $timestamp): array
    {
        $parts = explode('_', $this->appId, 2);
        $clientId = $parts[0];
        $clientSecret = isset($parts[1]) ? self::decodeAppSecret($parts[1]) : '';

        // 路径中的斜杠需转义后再参与签名
        $pathUrl = str_replace('/', '%2F', $url);
        $nonce = (string) $timestamp;

        $apiSign = "POST\n".$pathUrl."\n\nx-api-nonce:".$nonce."\nx-api-timestamp:".$timestamp."\n";
        $appData = $this->acctId.','.$this->username.','.$this->lcid.','.$this->orgNum;

        return [
            'X-Api-Auth-Version' => '2.0',
            'X-Api-ClientID' => $clientId,
            'x-api-timestamp' => (string) $timestamp,
            'x-api-nonce' => $nonce,
            'x-api-signheaders' => 'x-api-timestamp,x-api-nonce',
            'X-Api-Signature' => self::hmacBase64($apiSign, $clientSecret),
            'X-Kd-Appkey' => $this->appId,
            'X-Kd-Appdata' => base64_encode($appData),
            'X-Kd-Signature' => self::hmacBase64($this->appId.$appData, $this->appSecret),
        ];
    }

    /**
     * 解码应用密钥
     *
     * 标准密钥为 32 位，需先 base64 解码再与固定密钥异或；
     * 非标准长度则原样使用。
     *
     * @param  string  $secret  待解码的密钥片段（通常取自 appid 的 '_' 之后部分）
     *
     * @return string 解码后的密钥（实际存放的是解码结果的 base64 形式）
     */
    public static function decodeAppSecret(string $secret): string
    {
        if (strlen($secret) !== self::SECRET_LENGTH) {
            return $secret;
        }

        $decoded = base64_decode($secret, true);

        if ($decoded === false) {
            return $secret;
        }

        return base64_encode($decoded ^ self::XOR_KEY);
    }

    /**
     * HMAC-SHA256 后取十六进制再 base64
     *
     * @param  string  $content  待签名内容
     * @param  string  $key  签名密钥
     *
     * @return string base64 编码的十六进制 HMAC-SHA256 值
     */
    private static function hmacBase64(string $content, string $key): string
    {
        return base64_encode(bin2hex(hash_hmac('sha256', $content, $key, true)));
    }
}
