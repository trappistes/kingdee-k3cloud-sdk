<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Support\Redactor;

/**
 * 日志脱敏测试
 */
final class RedactorTest extends TestCase
{
    public function test_attachment_binary_is_hidden(): void
    {
        $payload = Redactor::payload([
            'FormId' => 'SAL_SaleOrder',
            'SendByte' => str_repeat('A', 1024),
        ]);

        self::assertSame('SAL_SaleOrder', $payload['FormId']);
        self::assertSame('[omitted:1024 bytes]', $payload['SendByte']);
    }

    public function test_credentials_are_hidden(): void
    {
        $payload = Redactor::payload([
            'password' => 'secret',
            'appsecret' => 'secret2',
            'data' => ['password' => 'nested-secret'],
        ]);

        self::assertSame('[omitted:6 bytes]', $payload['password']);
        self::assertSame('[omitted:7 bytes]', $payload['appsecret']);
        self::assertSame('[omitted:13 bytes]', $payload['data']['password']);
    }

    public function test_long_text_is_truncated(): void
    {
        $text = Redactor::text(str_repeat('x', 3000));

        self::assertStringContainsString('...[truncated]', $text);
        self::assertLessThan(2100, strlen($text));
    }

    public function test_short_text_is_untouched(): void
    {
        self::assertSame('{"ok":true}', Redactor::text('{"ok":true}'));
    }
}
