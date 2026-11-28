<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Exception\BusinessException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Response;

/**
 * 响应解析测试：形态收敛、严格/宽松语义、会话失效判定
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

    public function test_empty_object_is_not_treated_as_empty_rows(): void
    {
        // json_decode('{}', true) 也是空数组，若不区分会把异常报文读成「查到 0 行」
        self::assertFalse(Response::isList('{}'));
        self::assertSame([], Response::rows('{}'));
        self::assertFalse(Response::isSuccess('{}'));
    }

    public function test_missing_is_success_is_not_treated_as_success(): void
    {
        // normalize() 与 isSuccess() 对「缺少 IsSuccess」的结论必须一致：不成功
        $raw = '{"Result":{"ResponseStatus":{"Errors":[{"Message":"编码重复"}]}}}';

        self::assertFalse(Response::isSuccess($raw));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('缺少 IsSuccess');

        Response::normalize($raw);
    }

    public function test_empty_object_throws_transport_exception(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('结构异常');

        Response::normalize('{}');
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

    /**
     * 业务错误提到字段名 KSessionId 时，不得被误判成会话失效
     *
     * 误判会让真实原因（字段校验不通过）被伪装成会话失效：
     * 写操作会报「不自动重发」，读操作白白重登并重试，排查方向被带偏。
     */
    public function test_business_error_mentioning_field_name_is_not_session_expiry(): void
    {
        $business = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"单据 KSessionId 字段不合法，请检查"}]}}}';

        self::assertFalse(Response::isSessionExpired($business));
        self::assertSame('单据 KSessionId 字段不合法，请检查', Response::errorMessage($business));
    }

    /**
     * 真实帐套确认的会话失效文案仍须命中
     */
    public function test_real_session_expiry_message_is_still_detected(): void
    {
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"会话信息已丢失，请重新登录"}]}}}';

        self::assertTrue(Response::isSessionExpired($expired));
    }

    public function test_error_message_falls_back_to_top_level_message(): void
    {
        self::assertSame('登录失败', Response::errorMessage('{"Message":"登录失败"}'));
    }

    /**
     * 查询数据行：顶层数组形态
     */
    public function test_result_rows_accepts_top_level_array(): void
    {
        $rows = Response::resultRows('[{"FNumber":"01000004"}]');

        self::assertCount(1, $rows);
        self::assertSame('01000004', $rows[0]['FNumber']);
    }

    /**
     * 查询数据行：信封形态（ExecuteBillQuery）
     */
    public function test_result_rows_accepts_envelope_shape(): void
    {
        $raw = '{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]},"BillQueryResult":[{"FNumber":"01000004"}]}}';

        $rows = Response::resultRows($raw);

        self::assertCount(1, $rows);
        self::assertSame('01000004', $rows[0]['FNumber']);
    }

    /**
     * 信封里没有数据行：结构异常必须报错，不得当成「查到 0 行」
     */
    public function test_result_rows_rejects_envelope_without_rows(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('缺少数据行');

        Response::resultRows('{"Result":{}}');
    }

    /**
     * 合法空结果：返回空数组，不抛异常
     */
    public function test_result_rows_accepts_empty_envelope_rows(): void
    {
        $raw = '{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]},"BillQueryResult":[]}}';

        self::assertSame([], Response::resultRows($raw));
    }

    /**
     * 无法识别的响应结构：报错而不是返回空数组
     *
     * {"unexpected":true} 在 normalize() 阶段即被判定为结构异常。
     */
    public function test_result_rows_rejects_unknown_shape(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('结构异常');

        Response::resultRows('{"unexpected":true}');
    }

    /**
     * 业务失败：抛 BusinessException 而不是返回空数组
     */
    public function test_result_rows_raises_business_exception_on_failure(): void
    {
        $raw = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"编码重复"}]}}}';

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('编码重复');

        Response::resultRows($raw);
    }

    /**
     * rows() 是纯取值：错误信封返回空数组，不抛异常
     *
     * 与 normalize() 的严格语义成对，锁定两者不会互相靠拢。
     */
    public function test_rows_is_pure_accessor(): void
    {
        $error = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"单据已关闭"}]}}}';

        self::assertSame([], Response::rows($error));
        self::assertSame([['FNumber' => 'A']], Response::rows('[{"FNumber":"A"}]'));
    }

    /**
     * normalize() 是严格校验：错误信封抛业务异常
     */
    public function test_normalize_throws_on_business_error(): void
    {
        $error = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"单据已关闭"}]}}}';

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('单据已关闭');

        Response::normalize($error);
    }

    /**
     * 业务文案含「未登录」不应被误判为会话失效（否则会触发无谓的重新登录与重试）
     */
    public function test_business_message_containing_unlogin_is_not_session_expiry(): void
    {
        $business = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"客户未登录订单，请处理"}]}}}';

        self::assertFalse(Response::isSessionExpired($business));
    }
}
