<?php

declare(strict_types = 1);

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

    /**
     * 敏感键的值为数组：只描述规模，不得做字符串强转
     *
     * 数组强转会触发 Warning（框架的错误处理器下变成 ErrorException），
     * 而脱敏位于业务成功路径上 —— 抛出去会中断**已成功**的请求。
     */
    public function test_array_value_under_hidden_key_is_summarized(): void
    {
        $payload = Redactor::payload(['SendByte' => ['a', 'b']]);

        self::assertSame('[omitted:array(2)]', $payload['SendByte']);
    }

    /**
     * 敏感键的值为对象：只描述类型，不得做字符串强转
     *
     * 对象强转会抛 PHP 原生 Error（Throwable 但不是 Exception）。
     */
    public function test_object_value_under_hidden_key_is_summarized(): void
    {
        $payload = Redactor::payload(['SendByte' => new \stdClass()]);

        self::assertSame('[omitted:object(stdClass)]', $payload['SendByte']);
    }

    /**
     * 敏感键的值为资源：只描述类型
     */
    public function test_resource_value_under_hidden_key_is_summarized(): void
    {
        $handle = fopen('php://memory', 'rb');

        $payload = Redactor::payload(['SendByte' => $handle]);

        fclose($handle);

        self::assertSame('[omitted:resource]', $payload['SendByte']);
    }

    /**
     * 标量与 null 的占位格式不变（既有日志解析依赖该格式）
     */
    public function test_scalar_hidden_key_format_is_unchanged(): void
    {
        $payload = Redactor::payload([
            'password' => 'secret',
            'appsecret' => '',
            'SendByte' => null,
        ]);

        self::assertSame('[omitted:6 bytes]', $payload['password']);
        self::assertSame('[omitted:0 bytes]', $payload['appsecret']);
        self::assertSame('[omitted:0 bytes]', $payload['SendByte']);
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
