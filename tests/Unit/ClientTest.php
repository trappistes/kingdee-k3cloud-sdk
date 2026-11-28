<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Policy;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;
use Trappistes\Kingdee\K3Cloud\Tests\Support\RecordingLogger;

/**
 * 客户端行为测试：认证、重试策略、会话失效、幂等边界、API 表面
 */
final class ClientTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
        'max_attempts' => 3,
    ];

    public function test_login_is_lazy_and_only_once(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1,"Context":{"SessionId":"s"}}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
            new HttpResponse(200, '[{"FNumber":"B"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertFalse($client->isAuthenticated());

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertTrue($client->isAuthenticated());
        // 1 次登录 + 2 次查询
        self::assertSame(3, $transport->requestCount());
    }

    public function test_login_failure_raises_authentication_exception(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":0,"Message":"密码错误"}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('密码错误');

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
    }

    public function test_read_operation_retries_on_retryable_transport_failure(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
            new TransportException('连接超时', 0, true),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame('[{"FNumber":"A"}]', $client->query(Query::make('BD_MATERIAL')->fields('FNumber')));
        self::assertSame(4, $transport->requestCount());
    }

    /**
     * 保存遇网络故障不重试（避免重复单据）
     *
     * 全集覆盖见 OperationContractTest；这里锁住单个典型写操作的客户端行为。
     */
    public function test_save_does_not_retry_on_transport_failure(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(TransportException::class);

        try {
            $client->save('UWAZ_OutStock', ['Model' => []]);
        } finally {
            // 1 次登录 + 1 次保存（不重试）
            self::assertSame(2, $transport->requestCount());
        }
    }

    public function test_write_operation_does_not_retry_on_server_error(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(503, 'Service Unavailable'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(TransportException::class);

        try {
            $client->save('UWAZ_OutStock', ['Model' => []]);
        } finally {
            self::assertSame(2, $transport->requestCount());
        }
    }

    public function test_session_expiry_triggers_relogin_and_retry(): void
    {
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"用户登录已失效，请重新登录"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame('[{"FNumber":"A"}]', $client->query(Query::make('BD_MATERIAL')->fields('FNumber')));
        self::assertSame(1, $transport->sessionResets());
    }

    public function test_session_expiry_twice_raises_authentication_exception(): void
    {
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"会话已失效"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(AuthenticationException::class);

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
    }

    public function test_write_is_not_resent_after_session_expiry(): void
    {
        // 写操作遇到会话失效时必须停下：SDK 无法确认业务是否已执行，重发可能产生重复单据
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"用户登录已失效，请重新登录"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->save('UWAZ_OutStock', ['Model' => []]);

            self::fail('写操作不应自动重发');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('写操作不自动重发', $e->getMessage());
        }

        // 1 次登录 + 1 次写请求：没有重登、没有重发
        self::assertSame(2, $transport->requestCount());
        self::assertSame(0, $transport->sessionResets());
    }

    public function test_session_retry_consumes_retry_budget(): void
    {
        // 会话失效后的重发同样占用重试预算：max_attempts=1 时不得再发第二次业务请求
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"会话已失效"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(array_merge(self::CONFIG, ['max_attempts' => 1])), $transport);

        try {
            $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

            self::fail('已达重试上限时应报错');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('已达最大尝试次数', $e->getMessage());
        }

        // 只有 1 次业务请求（登录请求不计入）
        self::assertSame(1, count(array_filter(
            $transport->requests,
            static fn(array $request): bool => strpos($request['url'], 'AuthService') === false
        )));
    }

    public function test_client_error_status_raises_transport_exception(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(404, '<html>Not Found</html>'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('HTTP 404');

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
    }

    public function test_login_timeout_follows_request_policy(): void
    {
        // 单次请求指定短超时，登录阶段不应仍按全局超时阻塞
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->request('k3cloud/BillQuery', [], Policy::read(5, 3));

        self::assertSame(5, $transport->requests[0]['options']['timeout']);
        self::assertSame(5, $transport->requests[1]['options']['timeout']);
    }

    /**
     * 敏感字段传数组时，写操作仍须成功返回
     *
     * 旧实现把数组强转成字符串，脱敏时抛 Warning/ErrorException，
     * 而脱敏位于业务成功路径上 —— 金蝶侧已建单，调用方却收到异常。
     */
    public function test_non_scalar_attachment_value_does_not_break_write(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $logger = new RecordingLogger();
        $client = new Client(Config::fromArray(self::CONFIG), $transport, $logger);

        $raw = $client->uploadAttachment(['SendByte' => ['x', 'y'], 'FileName' => 'a.pdf']);

        self::assertStringContainsString('IsSuccess', $raw);
        // 1 次登录 + 1 次上传：请求已到达服务端，业务结果不受日志影响
        self::assertSame(2, $transport->requestCount());
        self::assertTrue($logger->hasRecord('kingdee.request.detail'));
    }

    /**
     * 日志器自身故障不得改变业务结果
     *
     * 日志是旁路：日志器抛异常时，已成功的写操作仍须把结果返回给调用方。
     */
    public function test_logger_failure_does_not_break_successful_write(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $logger = new class extends AbstractLogger {
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('日志器故障');
            }
        };

        $client = new Client(Config::fromArray(self::CONFIG), $transport, $logger);

        $raw = $client->uploadAttachment(['SendByte' => 'base64']);

        self::assertStringContainsString('IsSuccess', $raw);
        self::assertSame(2, $transport->requestCount());
    }

    /**
     * 未注入日志器（SDK 默认配置）时，写操作同样不受影响
     */
    public function test_write_succeeds_without_logger(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $raw = $client->uploadAttachment(['SendByte' => ['x', 'y'], 'FileName' => 'a.pdf']);

        self::assertStringContainsString('IsSuccess', $raw);
        self::assertSame(2, $transport->requestCount());
    }

    /**
     * 业务错误提到字段名 KSessionId 时，不得被当成会话失效
     *
     * 误判的后果：写操作会报「不自动重发」（方向带偏），读操作白白重登并重试。
     */
    public function test_business_error_mentioning_field_name_is_not_treated_as_session_expiry(): void
    {
        $business = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"单据 KSessionId 字段不合法，请检查"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $business),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $raw = $client->save('UWAZ_OutStock', ['Model' => []]);

        // 写操作返回原始报文，业务失败由调用方按 Response::isSuccess() 判断
        self::assertStringContainsString('KSessionId', $raw);
        // 没有重登、没有重发：1 次登录 + 1 次写请求
        self::assertSame(2, $transport->requestCount());
        self::assertSame(0, $transport->sessionResets());
    }

    /**
     * 读操作遇到同类业务错误时，抛业务异常而不是会话失效
     */
    public function test_read_with_field_name_business_error_raises_business_exception(): void
    {
        $business = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"单据 KSessionId 字段不合法，请检查"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $business),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));

            self::fail('业务失败应抛 BusinessException');
        } catch (\Trappistes\Kingdee\K3Cloud\Exception\BusinessException $e) {
            self::assertStringContainsString('KSessionId', $e->getMessage());
        }

        // 不重登、不重试：1 次登录 + 1 次查询
        self::assertSame(2, $transport->requestCount());
        self::assertSame(0, $transport->sessionResets());
    }

    /**
     * 会话失效的异常须携带金蝶原始错误文案
     */
    public function test_session_expiry_exception_carries_original_message(): void
    {
        $expired = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"会话信息已丢失，请重新登录"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $expired),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->save('UWAZ_OutStock', ['Model' => []]);

            self::fail('写操作遇到会话失效应报错');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('写操作不自动重发', $e->getMessage());
            self::assertStringContainsString('会话信息已丢失，请重新登录', $e->getMessage());
        }
    }

    public function test_attachment_upload_uses_larger_timeout(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"FileId":"f1"}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->uploadAttachment(['SendByte' => 'base64']);

        self::assertGreaterThanOrEqual(120, $transport->requests[1]['options']['timeout']);
    }

    /**
     * 传输层可被取出（便于应用侧注入自定义实现或检查会话）
     */
    public function test_transport_is_accessible(): void
    {
        $transport = new FakeTransport();
        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame($transport, $client->transport());
    }

    /**
     * 构造客户端时校验授权类型，不支持的类型直接报错
     */
    public function test_client_constructor_rejects_unsupported_auth_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('不支持的授权类型');

        new Client(
            Config::fromArray([
                'auth_type' => 9,
                'host_url' => 'https://k3cloud.example.com/k3cloud/',
                'acct_id' => 'acct',
                'username' => 'user',
                'password' => 'secret',
            ]),
            new FakeTransport(),
        );
    }

    /**
     * SDK 不再保留与旧 vendor 同名的兼容别名
     *
     * 旧 vendor 的 billQuery/getFields/uploadAttach 只是 query/metadata/uploadAttachment 的别名，
     * 承接旧命名属于应用适配层的职责。
     */
    public function test_compatibility_aliases_are_removed(): void
    {
        foreach (['billQuery', 'getFields', 'uploadAttach'] as $alias) {
            self::assertFalse(
                method_exists(Client::class, $alias),
                sprintf('Client 不应再保留别名方法 %s()', $alias),
            );
        }
    }

    /**
     * 查询入口只接受 Query —— 组织过滤无法被数组入口绕过
     */
    public function test_query_rejects_raw_array(): void
    {
        $client = new Client(Config::fromArray(self::CONFIG), new FakeTransport());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Query');

        $client->query(['FormId' => 'BD_MATERIAL']);
    }

    /**
     * 业务文案含「未登录」不应被误判为会话失效
     *
     * 误判会触发无谓的重新登录与重试，把真实原因（业务规则拒绝）掩盖掉。
     * 判定层（Response::isSessionExpired）见 ResponseTest 的同名场景。
     */
    public function test_unlogin_business_message_does_not_trigger_relogin(): void
    {
        $business = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"客户未登录订单，请处理"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $business),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        // query() 只返回原始响应，业务错误由 Response::normalize() 判定
        $raw = $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame($business, $raw);
        // 关键回归点：未触发重新登录
        self::assertSame(0, $transport->sessionResets());
        self::assertSame(2, $transport->requestCount());
    }
}
