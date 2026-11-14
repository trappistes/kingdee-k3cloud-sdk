<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 官方操作全集的契约测试
 *
 * 校验三件事：命中的端点、报文形态、失败时的重试策略。
 */
final class OperationContractTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
    ];

    /**
     * 写操作：端点 + formid/data 报文
     */
    public function test_write_operation_targets_endpoint_and_payload(): void
    {
        foreach (self::writeOperations() as $case) {
            [$method, $endpoint] = $case;

            $transport = new FakeTransport(
                new HttpResponse(200, '{"LoginResultType":1}'),
                new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
            );

            $client = new Client(Config::fromArray(self::CONFIG), $transport);

            $client->{$method}('FORM_ID', ['Model' => ['FNumber' => 'X']]);

            $request = $transport->requests[1];

            self::assertStringContainsString($endpoint, $request['url'], $method);
            self::assertSame('FORM_ID', $request['payload']['formid'], $method);
            self::assertSame(['Model' => ['FNumber' => 'X']], $request['payload']['data'], $method);
        }
    }

    /**
     * 写操作清单：方法名 => 端点片段
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function writeOperations(): array
    {
        return [
            'save' => ['save', 'DynamicFormService.save.'],
            'batchSave' => ['batchSave', 'DynamicFormService.batchSave.'],
            'submit' => ['submit', 'DynamicFormService.Submit.'],
            'audit' => ['audit', 'DynamicFormService.Audit.'],
            'unAudit' => ['unAudit', 'DynamicFormService.UnAudit.'],
            'delete' => ['delete', 'DynamicFormService.delete.'],
            'draft' => ['draft', 'DynamicFormService.draft.'],
            'push' => ['push', 'DynamicFormService.Push.'],
            'allocate' => ['allocate', 'DynamicFormService.Allocate.'],
            'flexSave' => ['flexSave', 'DynamicFormService.FlexSave.'],
            'groupSave' => ['groupSave', 'DynamicFormService.GroupSave.'],
            'groupDelete' => ['groupDelete', 'DynamicFormService.GroupDelete.'],
            'disassembly' => ['disassembly', 'DynamicFormService.Disassembly.'],
            'workflowAudit' => ['workflowAudit', 'DynamicFormService.WorkflowAudit.'],
        ];
    }

    /**
     * 写操作遇网络故障不重试（避免重复单据）
     */
    public function test_write_operation_does_not_retry(): void
    {
        foreach (self::writeOperations() as $case) {
            $method = $case[0];

            $transport = new FakeTransport(
                new HttpResponse(200, '{"LoginResultType":1}'),
                new TransportException('连接超时', 0, true),
            );

            $client = new Client(Config::fromArray(self::CONFIG), $transport);

            try {
                $client->{$method}('FORM_ID', ['Model' => []]);
                self::fail($method.' 应当抛出传输异常');
            } catch (TransportException $e) {
                // 1 次登录 + 1 次写入（未重试）
                self::assertSame(2, $transport->requestCount(), $method);
            }
        }
    }

    /**
     * 读操作可重试
     */
    public function test_view_is_retryable(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new TransportException('连接超时', 0, true),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->view('FORM_ID', ['Model' => []]);

        self::assertSame(3, $transport->requestCount());
    }

    /**
     * 自定义操作：报文含 opNumber
     */
    public function test_operation_payload_contains_operation_number(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->operation('FORM_ID', 'ReAudit', ['Model' => []]);

        $request = $transport->requests[1];

        self::assertStringContainsString('ExcuteOperation', $request['url']);
        self::assertSame('FORM_ID', $request['payload']['formid']);
        self::assertSame('ReAudit', $request['payload']['opNumber']);
        self::assertSame(['Model' => []], $request['payload']['data']);
    }

    /**
     * 无 formid 的平台类操作：报文仅含 data
     */
    public function test_platform_operations_use_data_only_payload(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
            new HttpResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true}}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->sendMsg(['data' => ['FMessage' => 'hi']]);
        $client->queryGroupInfo(['data' => ['FGroupId' => 1]]);
        $client->getDataCenterList();

        self::assertArrayHasKey('data', $transport->requests[1]['payload']);
        self::assertArrayNotHasKey('formid', $transport->requests[1]['payload']);
        self::assertArrayHasKey('data', $transport->requests[2]['payload']);
        self::assertSame([], $transport->requests[3]['payload']);
        self::assertStringContainsString('GetDataCenterList', $transport->requests[3]['url']);
    }

    /**
     * 执行式单据查询：独立端点
     */
    public function test_execute_bill_query_uses_its_own_endpoint(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[["01000004","液氨"]]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->executeBillQuery(\Trappistes\Kingdee\K3Cloud\Query::make('BD_MATERIAL')->fields('FNumber,FName'));

        self::assertStringContainsString('ExecuteBillQuery', $transport->requests[1]['url']);
    }

    /**
     * ExecuteBillQuery 的行是数组（按 FieldKeys 顺序），需映射为对象行
     */
    public function test_execute_bill_query_rows_are_mapped_to_objects(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[["01000004","液氨"],["01000098","煤"]]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $rows = $client->executeBillQueryRows(
            \Trappistes\Kingdee\K3Cloud\Query::make('BD_MATERIAL')->fields('FNumber,FName')
        );

        self::assertSame('01000004', $rows[0]['FNumber']);
        self::assertSame('液氨', $rows[0]['FName']);
        self::assertSame('煤', $rows[1]['FName']);
    }
}
