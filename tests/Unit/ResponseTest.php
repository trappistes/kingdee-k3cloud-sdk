<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Exception\BusinessException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Response;

/**
 * 响应解析测试
 */
final class ResponseTest extends TestCase
{
    public function test_rows_returns_list_payload(): void
    {
        $raw = '[{"FNumber":"01000004","FName":"液氨"}]';

        self::assertTrue(Response::isList($raw));
        self::assertSame('01000004', Response::rows($raw)[0]['FNumber']);
    }

    public function test_normalize_returns_envelope_for_save_response(): void
    {
        $raw = '{"Result":{"ResponseStatus":{"IsSuccess":true,"SuccessEntitys":[{"Id":123,"Number":"XSDD001"}]}}}';

        $data = Response::normalize($raw);

        self::assertArrayHasKey('Result', $data);
        self::assertTrue(Response::isSuccess($raw));
        self::assertSame(123, Response::successEntities($raw)[0]['Id']);
    }

    public function test_business_failure_throws_with_error_detail(): void
    {
        $raw = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"编码重复","Code":1001}]}}}';

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('编码重复');

        Response::normalize($raw);
    }

    public function test_invalid_json_throws_transport_exception(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('不是合法 JSON');

        Response::normalize('<html>502 Bad Gateway</html>');
    }

    public function test_unexpected_structure_throws_transport_exception(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('结构异常');

        Response::normalize('{"unexpected":true}');
    }

    public function test_empty_list_is_valid(): void
    {
        self::assertSame([], Response::rows('[]'));
        self::assertTrue(Response::isList('[]'));
    }

    public function test_login_result_type(): void
    {
        self::assertSame(1, Response::loginResultType('{"LoginResultType":1,"Context":{"SessionId":"x"}}'));
        self::assertSame(0, Response::loginResultType('{"LoginResultType":0,"Message":"密码错误"}'));
    }

    public function test_session_expiry_detection(): void
    {
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"用户登录已失效，请重新登录"}]}}}';
        $business = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"单据已关闭"}]}}}';

        self::assertTrue(Response::isSessionExpired($expired));
        self::assertFalse(Response::isSessionExpired($business));
    }

    public function test_error_message_falls_back_to_top_level_message(): void
    {
        self::assertSame('登录失败', Response::errorMessage('{"Message":"登录失败"}'));
    }
}
