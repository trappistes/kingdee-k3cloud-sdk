<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Concerns;

use Generator;
use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Policy;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\QueryResult;
use Trappistes\Kingdee\K3Cloud\Response;
use Trappistes\Kingdee\K3Cloud\Support\Arr;

/**
 * HandlesQueries —— Client 的职责分组之一（仅内部组织，不改变公开 API）
 */
trait HandlesQueries
{
    /**
     * 单据查询（读操作，可重试）
     *
     * 只接受 Query：查询范围与条件完全由调用方决定，
     * 不提供绕过条件组合的数组入口。
     *
     * @param Query $query 查询条件
     *
     * @return string 原始响应
     */
    public function query($query): string
    {
        $query = $this->assertQuery($query);

        return $this->request(Endpoints::BILL_QUERY, ['data' => $query->toArray()], Policy::read(
            $this->config->timeout,
            $this->config->maxAttempts
        ));
    }

    /**
     * 分页查询，返回结构化结果
     *
     * @param Query $query
     */
    public function paginate($query, int $page = 1, int $pageSize = 20): QueryResult
    {
        $query = $this->assertQuery($query)->copy()->page($page, $pageSize);

        // 严格解析：查询失败必须抛出，不能被当成「无数据」
        $data = Response::normalize($this->query($query));
        $rows = Arr::isList($data) ? $data : [];

        return new QueryResult(
            $rows,
            count($rows),
            $page,
            $pageSize,
            count($rows) >= $pageSize ? $page + 1 : $page
        );
    }

    /**
     * 查询并直接返回数据行（一次解码，避免重复解析）
     *
     * @param Query $query
     *
     * @return array<int, array<string, mixed>>
     */
    public function queryRows($query): array
    {
        // 严格解析：查询失败必须抛出，不能被当成「无数据」
        $data = Response::normalize($this->query($this->assertQuery($query)));

        return Arr::isList($data) ? $data : [];
    }

    /**
     * 自动翻页拉取全部数据
     *
     * 金蝶 BillQuery 为分页接口，一次最多返回 Limit 行。
     * 本方法按协议自动推进页码，逐行产出，避免调用方遗漏翻页导致数据截断。
     *
     * @param Query $query
     * @param int   $pageSize 每页行数（上限 2000）
     * @param int   $maxPages 最大页数保护
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function queryAll($query, int $pageSize = 100, int $maxPages = 500): Generator
    {
        $query = $this->assertQuery($query);

        $page = 1;

        while ($page <= $maxPages) {
            $result = $this->paginate($query, $page, $pageSize);

            if ($result->rows === []) {
                return;
            }

            foreach ($result->rows as $row) {
                yield $row;
            }

            if (! $result->hasNextPage()) {
                return;
            }

            $page++;
        }
    }

    /**
     * 判断记录是否存在（通用存在性查询，供业务侧做幂等核对）
     *
     * @param Query $query
     */
    public function exists($query): bool
    {
        return $this->queryRows($this->assertQuery($query)->copy()->perPage(1)) !== [];
    }

    /**
     * 执行式单据查询（读操作，可重试）
     *
     * 与 query() 的 BillQuery 有两点差异：
     *  1. 支持在 data 中传入更丰富的参数；
     *  2. 返回的行是**按 FieldKeys 顺序的数组**（如 ["01000004","液氨"]），
     *     而 BillQuery 返回对象行 —— 需要对象行请用 executeBillQueryRows()。
     *
     * @param Query $query 查询条件
     */
    public function executeBillQuery($query): string
    {
        $query = $this->assertQuery($query);

        return $this->request(Endpoints::EXECUTE_BILL_QUERY, ['data' => $query->toArray()], Policy::read(
            $this->config->timeout,
            $this->config->maxAttempts
        ));
    }

    /**
     * 执行式单据查询并返回对象行（与 queryRows() 形态一致）
     *
     * @param Query $query 查询条件
     *
     * @return array<int, array<string, mixed>>
     */
    public function executeBillQueryRows($query): array
    {
        $query = $this->assertQuery($query);

        $raw = $this->executeBillQuery($query);

        $data = Response::normalize($raw);

        return Response::mapRows(Arr::isList($data) ? $data : [], $query->fieldKeys());
    }

    /**
     * 元数据查询（读操作）
     *
     * @param array<string, mixed> $params
     */
    public function metadata(array $params): string
    {
        return $this->request(Endpoints::QUERY_BUSINESS_INFO, ['data' => $params], Policy::read(
            $this->config->timeout,
            $this->config->maxAttempts
        ));
    }
}
