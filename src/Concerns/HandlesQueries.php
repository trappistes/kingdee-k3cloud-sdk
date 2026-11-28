<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Concerns;

use Generator;
use InvalidArgumentException;
use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\BusinessException;
use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Policy;
use Trappistes\Kingdee\K3Cloud\Query;
use Trappistes\Kingdee\K3Cloud\QueryResult;
use Trappistes\Kingdee\K3Cloud\Response;

/**
 * HandlesQueries —— Client 的职责分组之一（仅内部组织，不改变公开 API）
 *
 * 职责：金蝶查询类接口（单据查询、分页、自动翻页、执行式查询、元数据）的对外入口。
 *
 * 关键约束：
 *  - 本 trait 中的 public 方法属于 SDK 已冻结的公开 API，只做注释层面的澄清；
 *  - 查询条件只接受 Query 对象，不提供绕过条件组合的数组入口；
 *    类型不符时由 assertQuery() 在运行时校验并抛 InvalidArgumentException；
 *  - 查询失败不会被当成「无数据」：响应按 Response::resultRows() 严格解析，
 *    金蝶返回业务失败（IsSuccess=false）时抛 BusinessException，
 *    响应结构无法识别时抛 TransportException（而不是静默返回 0 行）；
 *  - 查询类请求可重试（最多 Config::maxAttempts 次），会话失效时重新登录后再试一次；
 *  - 分页上限为每页 2000 行（Query 内部强制），自动翻页另有最大页数保护；
 *    触达最大页数说明数据被截断，会记一条 warning（kingdee.query.truncated）。
 */
trait HandlesQueries
{
    /**
     * 单据查询（读操作，可重试）
     *
     * 只接受 Query：查询范围与条件完全由调用方决定，
     * 不提供绕过条件组合的数组入口。
     *
     * @param  Query  $query  查询条件（需含 FormId 与 FieldKeys）
     *
     * @return string 原始响应
     *
     * @throws InvalidArgumentException 参数不是 Query 实例
     * @throws ConfigurationException 查询缺少 FormId 或 FieldKeys
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException 网络故障、超时或服务端返回错误状态
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
     * 在查询条件副本上设置页码与每页行数，不影响调用方传入的 Query。
     *
     * @param  Query  $query  查询条件
     * @param  int  $page  页码（从 1 开始，小于 1 时按 1 处理）
     * @param  int  $pageSize  每页行数（上限 2000，小于 1 时按 1 处理）
     *
     * @return QueryResult 当前页数据行与翻页信息
     *
     * @throws InvalidArgumentException 参数不是 Query 实例
     * @throws ConfigurationException 查询缺少 FormId 或 FieldKeys
     * @throws BusinessException 金蝶返回业务失败（IsSuccess=false）
     * @throws TransportException 网络故障、超时、响应不是合法 JSON 或结构异常
     * @throws AuthenticationException 登录失败或会话失效
     */
    public function paginate($query, int $page = 1, int $pageSize = 20): QueryResult
    {
        $query = $this->assertQuery($query)->copy()->page($page, $pageSize);

        // 严格解析：查询失败或结构异常必须抛出，不能被当成「无数据」
        $rows = Response::resultRows($this->query($query));

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
     * @param  Query  $query  查询条件
     *
     * @return array<int, array<string, mixed>> 数据行（空结果为空数组）
     *
     * @throws InvalidArgumentException 参数不是 Query 实例
     * @throws ConfigurationException 查询缺少 FormId 或 FieldKeys
     * @throws BusinessException 金蝶返回业务失败（IsSuccess=false）
     * @throws TransportException 网络故障、超时、响应不是合法 JSON 或结构异常
     * @throws AuthenticationException 登录失败或会话失效
     */
    public function queryRows($query): array
    {
        // 严格解析：查询失败或结构异常必须抛出，不能被当成「无数据」
        return Response::resultRows($this->query($this->assertQuery($query)));
    }

    /**
     * 自动翻页拉取全部数据
     *
     * 金蝶 BillQuery 为分页接口，一次最多返回 Limit 行。
     * 本方法按协议自动推进页码，逐行产出，避免调用方遗漏翻页导致数据截断。
     *
     * 达到 maxPages 上限且仍有下一页时停止，并记一条 warning
     * （kingdee.query.truncated）：数据是被截断的，不是拉完了。
     * 不抛异常——maxPages 是调用方主动设的安全阀，不是错误。
     *
     * @param  Query  $query  查询条件
     * @param  int  $pageSize  每页行数（上限 2000）
     * @param  int  $maxPages  最大页数保护（达到上限即停止翻页，不会无限循环）
     *
     * @return Generator<int, array<string, mixed>> 逐行产出的数据行
     *
     * @throws InvalidArgumentException 参数不是 Query 实例
     * @throws ConfigurationException 查询缺少 FormId 或 FieldKeys
     * @throws BusinessException 金蝶返回业务失败（IsSuccess=false）
     * @throws TransportException 网络故障、超时、响应不是合法 JSON 或结构异常
     * @throws AuthenticationException 登录失败或会话失效
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

            if (!$result->hasNextPage()) {
                return;
            }

            $page++;
        }

        // 走到这里说明第 maxPages 页仍是满页且还有下一页：数据被截断
        $this->log('warning', 'kingdee.query.truncated', [
            'max_pages' => $maxPages,
            'page_size' => $pageSize,
            'pages_fetched' => $maxPages,
        ]);
    }

    /**
     * 判断记录是否存在（通用存在性查询，供业务侧做幂等核对）
     *
     * 存在性判断与分页无关：总是从第一页取第一行，
     * 否则复用了 offset() 的查询会误判（第 N 页的第一行 ≠ 存在性）。
     *
     * @param  Query  $query  查询条件
     *
     * @return bool 是否查到数据
     *
     * @throws InvalidArgumentException 参数不是 Query 实例
     * @throws ConfigurationException 查询缺少 FormId 或 FieldKeys
     * @throws BusinessException 金蝶返回业务失败（IsSuccess=false）
     * @throws TransportException 网络故障、超时、响应不是合法 JSON 或结构异常
     * @throws AuthenticationException 登录失败或会话失效
     */
    public function exists($query): bool
    {
        return $this->queryRows($this->assertQuery($query)->copy()->offset(0)->perPage(1)) !== [];
    }

    /**
     * 执行式单据查询（读操作，可重试）
     *
     * 与 query() 的 BillQuery 有两点差异：
     *  1. 支持在 data 中传入更丰富的参数；
     *  2. **响应形态**为信封（数据行位于 Result.BillQueryResult），且**行本身**
     *     是按 FieldKeys 顺序的数组（如 ["01000004","液氨"]），
     *     而 BillQuery 的行是对象行 —— 需要对象行请用 executeBillQueryRows()。
     *
     * @param  Query  $query  查询条件
     *
     * @return string 原始响应
     *
     * @throws InvalidArgumentException 参数不是 Query 实例
     * @throws ConfigurationException 查询缺少 FormId 或 FieldKeys
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException 网络故障、超时或服务端返回错误状态
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
     * @param  Query  $query  查询条件
     *
     * @return array<int, array<string, mixed>> 对象行，键为 FieldKeys 中的字段名
     *
     * @throws InvalidArgumentException 参数不是 Query 实例
     * @throws ConfigurationException 查询缺少 FormId 或 FieldKeys
     * @throws BusinessException 金蝶返回业务失败（IsSuccess=false）
     * @throws TransportException 网络故障、超时、响应不是合法 JSON 或结构异常
     * @throws AuthenticationException 登录失败或会话失效
     */
    public function executeBillQueryRows($query): array
    {
        $query = $this->assertQuery($query);

        $raw = $this->executeBillQuery($query);

        return Response::mapRows(Response::resultRows($raw), $query->fieldKeys());
    }

    /**
     * 元数据查询（读操作）
     *
     * 查询业务对象的元数据（字段、分组等），参数形式以金蝶接口文档为准。
     *
     * @param  array<string, mixed>  $params  元数据查询参数
     *
     * @return string 原始响应
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException 网络故障、超时或服务端返回错误状态
     */
    public function metadata(array $params): string
    {
        return $this->request(Endpoints::QUERY_BUSINESS_INFO, ['data' => $params], Policy::read(
            $this->config->timeout,
            $this->config->maxAttempts
        ));
    }
}
