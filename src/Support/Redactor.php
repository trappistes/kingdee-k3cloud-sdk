<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Support;

/**
 * 日志脱敏
 *
 * 避免附件二进制、密码等敏感内容进入日志。
 *
 * 两类处理：
 *  - 已知敏感键（见 HIDDEN_KEYS）整体屏蔽，只保留规模描述：
 *    标量为 `[omitted:N bytes]`，数组为 `[omitted:array(N)]`，
 *    对象为 `[omitted:object(Class)]`，资源为 `[omitted:resource]`；
 *  - 其它字段里的 Base64 片段（附件字节流）替换为占位符，避免大段二进制写入日志。
 */
final class Redactor
{
    /**
     * 需要整体屏蔽的键
     *
     * 键名**精确匹配**（区分大小写），同时包含 appsecret 与 appSecret 两种写法。
     *
     * @var array<int, string>
     */
    private const HIDDEN_KEYS = ['SendByte', 'password', 'appsecret', 'appSecret'];

    /**
     * 文本最大保留长度
     *
     * 单位：字符（按 mb 字符计数，非字节）。
     *
     * @var int
     */
    private const MAX_TEXT_LENGTH = 2000;

    /**
     * Base64 内容的占位符
     *
     * @var string
     */
    private const BASE64_PLACEHOLDER = '<base64 string>';

    /**
     * 疑似 Base64 片段的最小长度（约 48 字节原文）
     *
     * @var int
     */
    private const BASE64_MIN_LENGTH = 64;

    /**
     * 大片段阈值：超过该长度基本可以认定是附件字节流
     *
     * @var int
     */
    private const BASE64_LARGE_LENGTH = 256;

    /**
     * 短片段中 '/' 允许的最大密度（分母）
     *
     * @var int
     */
    private const BASE64_MAX_SLASH_DENSITY = 8;

    /**
     * 大片段中 '/' 允许的最大密度（分母）
     *
     * @var int
     */
    private const BASE64_MAX_SLASH_DENSITY_LARGE = 16;

    /**
     * 候选片段字符集
     *
     * 标准 Base64 字符、填充 '='，以及反斜杠。
     * 含反斜杠是为了让 JSON 转义后的 "\/" 留在同一个片段里，判定前再还原。
     *
     * @var string
     */
    private const BASE64_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=\\';

    /**
     * 脱敏请求体
     *
     * 递归处理：嵌套数组同样脱敏；命中 HIDDEN_KEYS 的键整体替换为
     * '[omitted:N bytes]'；其它字符串值中的 Base64 片段替换为占位符。
     *
     * @param  array<string, mixed>  $payload  原始请求体
     *
     * @return array<string, mixed> 脱敏后的请求体副本（不修改入参）
     */
    public static function payload(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, self::HIDDEN_KEYS, true)) {
                $result[$key] = self::hiddenMarker($value);

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
     * 敏感值的占位描述
     *
     * 标量与 null 沿用既有的字节数格式（`[omitted:1024 bytes]`）；
     * 非标量只描述类型与规模，**绝不把内容换算成字符串**——
     * 数组强转会触发 Warning（框架的错误处理器下变成 ErrorException），
     * 对象强转会抛原生 Error，两者都会中断**已成功**的业务请求。
     *
     * @param  mixed  $value  待描述的敏感值
     *
     * @return string 占位描述
     */
    private static function hiddenMarker($value): string
    {
        if (is_array($value)) {
            return '[omitted:array('.count($value).')]';
        }

        if (is_object($value)) {
            return '[omitted:object('.get_class($value).')]';
        }

        if (is_resource($value)) {
            return '[omitted:resource]';
        }

        // 走到这里只剩标量与 null：与既有行为完全一致
        return '[omitted:'.strlen((string) $value).' bytes]';
    }

    /**
     * 脱敏并截断文本
     *
     * 先做 Base64 替换再截断：否则 Base64 被截成半截，既识别不出来，又会留下原文片段。
     *
     * @param  string  $text  原始文本（如响应体）
     * @param  int  $maxLength  最大保留长度，单位为字符（按 mb 字符计数）；超长时末尾追加 '...[truncated]'
     *
     * @return string 脱敏并按需截断后的文本
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
     *
     * @param  string  $text  原始文本
     *
     * @return string 其中 Base64 片段已被替换为占位符的文本
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
     * 判定条件：长度 ≥ BASE64_MIN_LENGTH 且为 4 的倍数、可严格 base64 解码、
     * 同时含大小写字母、不是纯十六进制串、'/' 密度不过高，
     * 且至少含数字、'+' 或填充 '=' 之一。
     *
     * @param  string  $candidate  候选片段
     *
     * @return bool true 表示该片段按 Base64 处理并替换为占位符
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
