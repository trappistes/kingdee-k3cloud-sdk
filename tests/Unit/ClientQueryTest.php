<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Client;
use Trappistes\Kingdee\K3Cloud\Config;
use Trappistes\Kingdee\K3Cloud\Http\HttpResponse;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\Tests\Support\FakeTransport;

/**
 * 客户端能力测试：自动翻页、取行、存在性判断
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
        $page1 = array_map(static fn (int $i): array => ['FNumber' => 'A'.$i], range(1, 3));
        $page2 = array_map(static fn (int $i): array => ['FNumber' => 'B'.$i], range(1, 2));

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, json_encode($page1)),
            new HttpResponse(200, json_encode($page2)),
            new HttpResponse(200, '[]'),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $rows = iterator_to_array($client->queryAll(Query::make('BD_MATERIAL')->fields('FNumber'), 3, 500), false);

        self::assertCount(5, $rows);
        self::assertSame('A1', $rows[0]['FNumber']);
        self::assertSame('B2', $rows[4]['FNumber']);
        // 1 次登录 + 2 次查询（第 2 页未满，即末页，不再请求）
        self::assertSame(3, $transport->requestCount());
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
        $full = array_map(static fn (int $i): array => ['FNumber' => 'A'.$i], range(1, 2));

        $transport = new FakeTransport(
            new HttpResponse(200, '{"LoginResultType":1}'),
            new HttpResponse(200, json_encode($full)),
            new HttpResponse(200, json_encode($full)),
            new HttpResponse(200, json_encode($full)),
        );

        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        $rows = iterator_to_array($client->queryAll(Query::make('BD_MATERIAL')->fields('FNumber'), 2, 2));

        self::assertCount(4, $rows);
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

    public function test_transport_is_accessible(): void
    {
        $transport = new FakeTransport();
        $client = new Client(Config::fromArray(self::CONFIG), $transport);

        self::assertSame($transport, $client->transport());
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
