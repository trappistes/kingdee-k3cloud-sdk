<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * 查询结果
 *
 * 以普通 PHP 对象承载分页信息，避免向 Collection 之类的容器写入动态属性。
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class QueryResult implements Countable, IteratorAggregate
{
    /**
     * 当前页数据行
     *
     * @var array<int, array<string, mixed>>
     */
    public $rows;

    /**
     * 当前页行数
     *
     * @var int
     */
    public $pageCount;

    /**
     * 当前页码
     *
     * @var int
     */
    public $page;

    /**
     * 每页行数
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
     * @param array<int, array<string, mixed>> $rows      当前页数据行
     * @param int                              $pageCount 当前页行数
     * @param int                              $page      当前页码
     * @param int                              $pageSize  每页行数
     * @param int                              $nextPage  下一页页码
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
     */
    public function hasNextPage(): bool
    {
        return $this->nextPage > $this->page;
    }

    /**
     * 是否为空
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return ArrayIterator<int, array<string, mixed>>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
