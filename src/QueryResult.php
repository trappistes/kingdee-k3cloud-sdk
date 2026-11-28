<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * 查询结果
 *
 * 以普通 PHP 对象承载分页信息，避免向 Collection 之类的容器写入动态属性。
 *
 * 语义要点：
 *  - rows 为**当前页**的数据行，不是全量结果；
 *  - pageCount 是「当前页的行数」，不是总记录数——金蝶 BillQuery 不返回总数；
 *  - nextPage 是下一页页码，无下一页时等于当前页，
 *    是否还有下一页由 hasNextPage() 判断（nextPage > page）。
 *
 * 本类可被 foreach 遍历（迭代当前页的行），count() 返回当前页行数。
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class QueryResult implements Countable, IteratorAggregate
{
    /**
     * 当前页数据行
     *
     * 每行是「字段名 => 值」的对象行；空数组表示当前页无数据。
     *
     * @var array<int, array<string, mixed>>
     */
    public $rows;

    /**
     * 当前页行数
     *
     * 等于 count(rows)，不是全部命中的记录总数。
     *
     * @var int
     */
    public $pageCount;

    /**
     * 当前页码
     *
     * 从 1 开始。
     *
     * @var int
     */
    public $page;

    /**
     * 每页行数
     *
     * 本次请求使用的分页大小（上限 2000）。
     *
     * @var int
     */
    public $pageSize;

    /**
     * 下一页页码（无下一页时等于当前页）
     *
     * @var int
     */
    public $nextPage;

    /**
     * @param  array<int, array<string, mixed>>  $rows  当前页数据行
     * @param  int  $pageCount  当前页行数
     * @param  int  $page  当前页码
     * @param  int  $pageSize  每页行数
     * @param  int  $nextPage  下一页页码（无下一页时等于当前页）
     */
    public function __construct(
        array $rows,
        int $pageCount,
        int $page,
        int $pageSize,
        int $nextPage
    ) {
        $this->rows = $rows;
        $this->pageCount = $pageCount;
        $this->page = $page;
        $this->pageSize = $pageSize;
        $this->nextPage = $nextPage;
    }

    /**
     * 是否还有下一页
     *
     * @return bool nextPage 大于当前页时为 true
     */
    public function hasNextPage(): bool
    {
        return $this->nextPage > $this->page;
    }

    /**
     * 是否为空
     *
     * @return bool 当前页没有任何数据行时为 true
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * 迭代当前页数据行
     *
     * @return ArrayIterator<int, array<string, mixed>> 当前页行的迭代器
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->rows);
    }

    /**
     * 当前页行数
     *
     * @return int 当前页数据行数量
     */
    public function count(): int
    {
        return count($this->rows);
    }
}
