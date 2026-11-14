<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud;

/**
 * 金蝶开放平台签名认证（第三方授权应用的请求签名）
 *
 * 与「账套+用户名+密码」「账套+应用ID+密钥」两种登录式认证不同：
 * 签名认证不需要建立会话，而是**每个请求都携带签名头**。
 *
 * 签名规则与金蝶官方 Java / Python SDK 示例一致，
 * 实现参考了 MIT 许可的 kingdee-php/k3cloud（https://github.com/kingdee-php/k3cloud）。
 */
final class Signer
{
    /**
     * 应用密钥的异或解码密钥（金蝶官方 SDK 示例中的常量）
     */
    private const XOR_KEY = '0054f397c6234378b09ca7d3e5debce7';

    /**
     * 应用密钥长度（标准密钥为 32 位，需异或解码）
     */
    private const SECRET_LENGTH = 32;

    /**
     * @var string
     */
    private $appId;

    /**
     * @var string
     */
    private $appSecret;

    /**
     * @var string
     */
    private $acctId;

    /**
     * @var string
     */
    private $username;

    /**
     * @var int
     */
    private $lcid;

    /**
     * @var int
     */
    private $orgNum;

    public function __construct(
        string $appId,
        string $appSecret,
        string $acctId,
        string $username,
        int $lcid = 2052,
        int $orgNum = 0
    ) {
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
     * @param string $url       完整请求地址
     * @param int    $timestamp 时间戳（秒），同时用作 nonce
     *
     * @return array<string, string>
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
     */
    private static function hmacBase64(string $content, string $key): string
    {
        return base64_encode(bin2hex(hash_hmac('sha256', $content, $key, true)));
    }
}
