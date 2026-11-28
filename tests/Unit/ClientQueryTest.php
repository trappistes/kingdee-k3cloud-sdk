<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Exception\BusinessException;
use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;
use Trappistes\Kingdee\K3Cloud\Tests\Support\RecordingLogger;

/**
 * 客户端能力测试：自动翻页、取行、分页结构、存在性判断、失败语义
 */
final class ClientQueryTest extends TestCase
{
    private const CONFIG = [
        'auth_type' => 1,
        'host_url' => 'https://k3cloud.example.com/k3cloud/',
        'acct_id' => 'acct',
        'username' => 'user',
        'password' => 'secret',
    ];

    public function test_query_all_paginates_until_exhausted(): void
    {
        $page1 = array_map(static fn(int $i): array => ['FNumber' => 'A'.$i], range(1, 3));
        $page2 = array_map(static fn(int $i): array => ['FNumber' => 'B'.$i], range(1, 2));

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, json_encode($page1)),
            new HttpResponse(200, json_encode($page2)),
            new HttpResponse(200, '[]'),
        );

        $logger = new RecordingLogger();
        $client = new Client(Config::fromArray(self::CONFIG), $transport, $logger);

        $rows = iterator_to_array($client->queryAll(Query::make('BD_MATERIAL')->fields('FNumber'), 3, 500), false);

        self::assertCount(5, $rows);
        self::assertSame('A1', $rows[0]['FNumber']);
        self::assertSame('B2', $rows[4]['FNumber']);
        // 1 次登录 + 2 次查询（第 2 页未满，即末页，不再请求）
        self::assertSame(3, $transport->requestCount());
        // 数据已拉完，不该有截断告警
        self::assertFalse($logger->hasRecord('kingdee.query.truncated'));
    }

    public function test_query_all_stops_when_page_is_not_full(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $rows = iterator_to_array($client->queryAll(Query::make('BD_MATERIAL')->fields('FNumber'), 100, 500));

        self::assertCount(1, $rows);
        self::assertSame(2, $transport->requestCount());
    }

    public function test_query_all_respects_max_pages_guard(): void
    {
        $full = array_map(static fn(int $i): array => ['FNumber' => 'A'.$i], range(1, 2));

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, json_encode($full)),
            new HttpResponse(200, json_encode($full)),
            new HttpResponse(200, json_encode($full)),
        );

        $logger = new RecordingLogger();
        $client = new Client(Config::fromArray(self::CONFIG), $transport, $logger);

        $rows = iterator_to_array($client->queryAll(Query::make('BD_MATERIAL')->fields('FNumber'), 2, 2));

        self::assertCount(4, $rows);
        // 数据是被 maxPages 截断的，必须留痕
        self::assertTrue($logger->hasRecord('kingdee.query.truncated'));
    }

    public function test_query_all_passes_page_start_row(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        iterator_to_array($client->queryAll(Query::make('BD_MATERIAL')->fields('FNumber'), 10, 500));

        self::assertSame(0, $transport->requests[1]['payload']['data']['StartRow']);
        self::assertSame(10, $transport->requests[1]['payload']['data']['Limit']);
    }

    public function test_query_rows_returns_decoded_rows(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"01000004","FName":"液氨"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $rows = $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertSame('液氨', $rows[0]['FName']);
    }

    /**
     * 查询响应为信封形态时也必须取到数据行
     *
     * 旧实现只认「顶层 JSON 数组」，信封形态会被静默读成「查到 0 行」。
     */
    public function test_query_rows_accepts_envelope_shape(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200,
                '{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]},"BillQueryResult":[{"FNumber":"01000004","FName":"液氨"}]}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $rows = $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));

        self::assertCount(1, $rows);
        self::assertSame('液氨', $rows[0]['FName']);
    }

    /**
     * 响应结构无法识别时必须报错，而不是静默返回 0 行
     */
    public function test_query_rows_throws_on_unrecognized_shape(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '{"Result":{}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('缺少数据行');

        $client->queryRows(Query::make('BD_MATERIAL')->fields('FNumber'));
    }

    /**
     * 分页同样兼容信封形态
     */
    public function test_paginate_accepts_envelope_shape(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200,
                '{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]},"BillQueryResult":[{"FNumber":"A"}]}}'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $result = $client->paginate(Query::make('BD_MATERIAL')->fields('FNumber'), 1, 20);

        self::assertCount(1, $result);
        self::assertSame('A', $result->rows[0]['FNumber']);
    }

    public function test_exists_returns_true_when_row_found(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertTrue($client->exists(Query::make('BD_MATERIAL')->fields('FNumber')->whereEquals('FNumber', 'A')));
        // 存在性查询只取 1 行
        self::assertSame(1, $transport->requests[1]['payload']['data']['Limit']);
    }

    public function test_exists_returns_false_when_empty(): void
    {
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertFalse($client->exists(Query::make('BD_MATERIAL')->fields('FNumber')->whereEquals('FNumber', 'X')));
    }

    public function test_exists_resets_offset(): void
    {
        // 存在性判断与分页无关：复用了 offset() 的查询必须从第一页开始，
        // 否则会拿第 N 页的第一行当存在性结论
        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, '[{"FNumber":"A"}]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $client->exists(Query::make('BD_MATERIAL')->fields('FNumber')->offset(500));

        self::assertSame(0, $transport->requests[1]['payload']['data']['StartRow']);
        self::assertSame(1, $transport->requests[1]['payload']['data']['Limit']);
    }

    /**
     * 分页返回结构化结果，并按请求的页码与行数传参
     */
    public function test_paginate_builds_query_result(): void
    {
        $rows = array_map(static fn(int $i): array => ['FNumber' => 'A'.$i], range(1, 20));

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, json_encode($rows, JSON_UNESCAPED_UNICODE)),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $result = $client->paginate(Query::make('BD_MATERIAL')->fields('FNumber'), 2, 20);

        self::assertCount(20, $result);
        self::assertSame(2, $result->page);
        self::assertSame(3, $result->nextPage);
        self::assertTrue($result->hasNextPage());
        self::assertSame('A1', $result->rows[0]['FNumber']);

        // 校验 StartRow / Limit 传参
        self::assertSame(20, $transport->requests[1]['payload']['data']['StartRow']);
        self::assertSame(20, $transport->requests[1]['payload']['data']['Limit']);
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

    public function test_login_http_4xx_is_reported_as_unavailable_endpoint(): void
    {
        $transport = new FakeTransport(new HttpResponse(404, 'Not Found'));

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $this->expectException(\Trappistes\Kingdee\K3Cloud\Exception\TransportException::class);
        $this->expectExceptionMessage('金蝶接口不可用');

        $client->query(Query::make('BD_MATERIAL')->fields('FNumber'));
    }
}
