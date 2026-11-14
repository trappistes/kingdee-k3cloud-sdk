<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\BusinessException;
use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Response;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 精简后的语义契约测试
 */
final class ContractTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
    ];

    /**
     * rows() 是纯取值：错误信封返回空数组，不抛异常
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
     * 查询失败必须抛出，不能被当成「无数据」
     */
    public function test_query_rows_raises_on_failed_query(): void
    {
        $error = '{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"参数错误"}]}}}';

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, $error),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(BusinessException::class);

        $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));
    }

    /**
     * 缺失必填参数在本地即拦截，不发起请求
     */
    public function test_missing_fields_fails_without_request(): void
    {
        $transport = new FakeTransport();

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        try {
            $client->queryRows(Query::make('BD_MATERIAL'));
            self::fail('应当抛出配置异常');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('FieldKeys', $e->getMessage());
        }

        self::assertSame(0, $transport->requestCount());
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
     * 官方标准单据操作应完整提供（协议表面完整性）
     */
    public function test_official_bill_operations_are_available(): void
    {
        foreach ([
            'save', 'batchSave', 'delete', 'unAudit', 'audit', 'submit', 'draft',
            'push', 'view', 'allocate', 'flexSave', 'groupSave', 'groupDelete',
            'disassembly', 'workflowAudit', 'operation',
        ] as $operation) {
            self::assertTrue(
                method_exists(Client::class, $operation),
                sprintf('Client 应提供官方操作 %s()', $operation),
            );
        }
    }

    /**
     * 组织维度常量只保留已在真实响应中验证过的项
     */
    public function test_org_fields_only_contains_verified_dimensions(): void
    {
        $constants = (new \ReflectionClass(\Trappistes\Kingdee\K3Cloud\OrgFields::class))->getConstants();

        self::assertSame(['USE', 'PURCHASE', 'SALE'], array_keys($constants));
    }
}
