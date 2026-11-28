<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Support;

/**
 * 日志脱敏
 *
 * 避免附件二进制、密码等敏感内容进入日志。
 */
final class Redactor
{
    /**
     * 需要整体屏蔽的键
     */
    private const HIDDEN_KEYS = ['SendByte', 'password', 'appsecret', 'appSecret'];

    /**
     * 文本最大保留长度
     */
    private const MAX_TEXT_LENGTH = 2000;

    /**
     * Base64 内容的占位符
     */
    private const BASE64_PLACEHOLDER = '<base64 string>';

    /**
     * 疑似 Base64 片段的最小长度（约 48 字节原文）
     */
    private const BASE64_MIN_LENGTH = 64;

    /**
     * 大片段阈值：超过该长度基本可以认定是附件字节流
     */
    private const BASE64_LARGE_LENGTH = 256;

    /**
     * 短片段中 '/' 允许的最大密度（分母）
     */
    private const BASE64_MAX_SLASH_DENSITY = 8;

    /**
     * 大片段中 '/' 允许的最大密度（分母）
     */
    private const BASE64_MAX_SLASH_DENSITY_LARGE = 16;

    /**
     * 候选片段字符集
     *
     * 标准 Base64 字符、填充 '='，以及反斜杠。
     * 含反斜杠是为了让 JSON 转义后的 "\/" 留在同一个片段里，判定前再还原。
     */
    private const BASE64_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=\\';

    /**
     * 脱敏请求体
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function payload(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::HIDDEN_KEYS, true)) {
                $result[$key] = '[omitted:'.strlen((string) $value).' bytes]';

                continue;
            }

            if (is_array($value)) {
                $result[$key] = self::payload($value);

                continue;
            }

            // 除已知敏感键外，其它字段也可能塞进 Base64 字节流（如附件内容）
            $result[$key] = is_string($value) ? self::redactBase64($value) : $value;
        }

        return $result;
    }

    /**
     * 脱敏并截断文本
     */
    public static function text(string $text, int $maxLength = self::MAX_TEXT_LENGTH): string
    {
        // 先替换再截断：否则 Base64 被截成半截，既识别不出来，又会留下原文片段
        $text = self::redactBase64($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength).'...[truncated]';
    }

    /**
     * 替换文本中的 Base64 片段
     *
     * 用 strcspn/strspn 逐段扫描，不用正则：
     * 正则的连续匹配在超长文本上会触发 PCRE 回溯上限并整段失败，
     * 结果是大附件原样写进日志，而且没有任何报错。
     */
    private static function redactBase64(string $text): string
    {
        $length = strlen($text);

        if ($length < self::BASE64_MIN_LENGTH) {
            return $text;
        }

        $result = '';
        $cursor = 0;

        while ($cursor < $length) {
            // 跳过非候选字符，定位下一个候选片段的起点
            $gap = strcspn($text, self::BASE64_CHARS, $cursor);
            $start = $cursor + $gap;

            if ($start >= $length) {
                break;
            }

            $run = strspn($text, self::BASE64_CHARS, $start);
            $candidate = substr($text, $start, $run);

            $result .= substr($text, $cursor, $gap);
            $result .= self::isBase64($candidate) ? self::BASE64_PLACEHOLDER : $candidate;

            $cursor = $start + $run;
        }

        return $result.substr($text, $cursor);
    }

    /**
     * 判断候选片段是否为 Base64
     *
     * @param string $candidate 候选片段
     */
    private static function isBase64(string $candidate): bool
    {
        // JSON 转义过的 '/' 先还原再判断
        $candidate = str_replace('\/', '/', $candidate);

        $length = strlen($candidate);

        if ($length < self::BASE64_MIN_LENGTH || $length % 4 !== 0) {
            return false;
        }

        if (base64_decode($candidate, true) === false) {
            return false;
        }

        // 大小写都要有：排除纯小写路径、纯大写标识符
        if (preg_match('/[A-Z]/', $candidate) !== 1 || preg_match('/[a-z]/', $candidate) !== 1) {
            return false;
        }

        // 十六进制摘要也能通过严格解码，需单独排除
        if (preg_match('/^[0-9a-fA-F]+$/', $candidate) === 1) {
            return false;
        }

        // '/' 密度：路径里 '/' 很密，Base64 里很稀疏
        $density = $length < self::BASE64_LARGE_LENGTH
            ? self::BASE64_MAX_SLASH_DENSITY
            : self::BASE64_MAX_SLASH_DENSITY_LARGE;

        if (substr_count($candidate, '/') * $density > $length) {
            return false;
        }

        // 至少要有数字、'+' 或填充 '='：排除不含数字的长路径与标识符
        return preg_match('/[0-9]/', $candidate) === 1
            || strpos($candidate, '+') !== false
            || strpos($candidate, '=') !== false;
    }
}
