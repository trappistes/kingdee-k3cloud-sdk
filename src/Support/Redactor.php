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

            $result[$key] = is_array($value) ? self::payload($value) : $value;
        }

        return $result;
    }

    /**
     * 截断文本
     */
    public static function text(string $text, int $maxLength = self::MAX_TEXT_LENGTH): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength).'...[truncated]';
    }
}
