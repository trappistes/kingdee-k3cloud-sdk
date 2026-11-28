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

    public function test_base64_value_is_replaced(): void
    {
        $payload = Redactor::payload([
            'FileName' => 'a.png',
            'AttachmentByte' => self::base64Binary(),
        ]);

        self::assertSame('a.png', $payload['FileName']);
        self::assertSame('<base64 string>', $payload['AttachmentByte']);
    }

    public function test_base64_value_is_replaced_in_nested_payload(): void
    {
        $payload = Redactor::payload([
            'data' => ['AttachmentByte' => self::base64Binary()],
        ]);

        self::assertSame('<base64 string>', $payload['data']['AttachmentByte']);
    }

    public function test_base64_in_response_body_is_replaced(): void
    {
        $body = json_encode(['Result' => ['AttachmentByte' => self::base64Binary()]]);

        $text = Redactor::text($body);

        self::assertStringContainsString('<base64 string>', $text);
        self::assertStringNotContainsString(self::base64Binary(), $text);
    }

    public function test_base64_with_escaped_slash_is_replaced(): void
    {
        $body = json_encode(['Result' => ['AttachmentByte' => self::base64Binary()]]);

        // json_encode 默认会把 '/' 转义成 "\/"，真实响应体里就是这种形态
        self::assertStringContainsString('\/', $body);
        self::assertStringContainsString('<base64 string>', Redactor::text($body));
    }

    public function test_base64_data_uri_is_replaced(): void
    {
        $text = Redactor::text('data:image/png;base64,'.self::base64Binary());

        self::assertStringContainsString('<base64 string>', $text);
    }

    public function test_base64_in_plain_text_is_replaced(): void
    {
        $text = Redactor::text('附件内容：'.self::base64Binary().' 结束');

        self::assertStringContainsString('<base64 string>', $text);
        self::assertStringContainsString('附件内容：', $text);
    }

    public function test_short_base64_is_untouched(): void
    {
        $short = base64_encode('hello');

        self::assertSame($short, Redactor::text($short));
    }

    public function test_hex_digest_is_untouched(): void
    {
        $hex = '79711a430c8b4f2bb72bbd43059ea687';

        self::assertSame($hex, Redactor::text($hex));
    }

    public function test_long_path_is_untouched(): void
    {
        $path = '/var/www/html/app/vendor/laravel/framework/src/Illuminate/Database/Query/Builder';

        self::assertSame($path, Redactor::text($path));
    }

    public function test_base64_is_replaced_before_truncation(): void
    {
        $text = Redactor::text(json_encode([
            'AttachmentByte' => self::base64Binary(),
            'Note' => str_repeat('y', 3000),
        ]));

        self::assertStringNotContainsString(self::base64Binary(), $text);
        self::assertStringContainsString('<base64 string>', $text);
        self::assertStringContainsString('...[truncated]', $text);
    }

    /**
     * 超长响应体也必须脱敏
     *
     * 早期实现用正则匹配连续字符，超过 PCRE 回溯上限（pcre.recursion_limit，默认 100000）
     * 时整段匹配失败并返回原始文本，大附件因此原样写进日志。
     */
    public function test_base64_in_huge_body_is_replaced(): void
    {
        $binary = random_bytes(2 * 1024 * 1024);
        $body = json_encode([
            'AttachmentByte' => base64_encode($binary),
            'Name' => 'a.png',
        ]);

        $text = Redactor::text($body);

        self::assertStringContainsString('<base64 string>', $text);
        self::assertStringNotContainsString(substr(base64_encode($binary), 0, 64), $text);
    }

    /**
     * 超长连续片段不应让整段脱敏失效
     */
    public function test_long_unstructured_run_does_not_break_redaction(): void
    {
        $body = str_repeat('A', 300 * 1024).base64_encode(random_bytes(64));

        $text = Redactor::text($body);

        self::assertStringContainsString('<base64 string>', $text);
    }

    /**
     * 固定样本：含大小写、数字、'/' 与 '+'，且长度为 4 的倍数
     */
    private static function base64Binary(): string
    {
        return 'rC91wEP7w2cJ0xXyJFdG2FiMOsHmJ1euWFGlGU1ICZRz0PPF/k+vwFJj2LHzHZOT';
    }
}
