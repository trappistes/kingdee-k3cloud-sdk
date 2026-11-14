<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud;

use InvalidArgumentException;
use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;

/**
 * 单据查询构造器
 *
 * 集中承载金蝶 BillQuery 的查询参数：单据对象、字段、过滤条件、排序、分页。
 *
 * 本类只提供通用的条件组合能力，不内置任何业务口径
 * （同步范围、单据类型、字段选取等均由调用方决定）。
 */
final class Query
{
    /**
     * 查询字段
     *
     * @var array<int, string>
     */
    private array $fields = [];

    /**
     * 过滤条件
     *
     * @var array<int, string>
     */
    private array $filters = [];

    /**
     * 排序
     */
    private string $orderBy = '';

    /**
     * 起始行
     */
    private int $startRow = 0;

    /**
     * 每页行数
     */
    private int $limit = 100;

    /**
     * @var string
     */
    private $formId;

    public function __construct(string $formId)
    {
        $this->formId = $formId;
    }

    /**
     * 创建查询
     */
    public static function make(string $formId): self
    {
        return new self($formId);
    }

    /**
     * 从金蝶查询参数数组构造
     *
     * 便于从旧式参数数组迁移。
     *
     * @param array<string, mixed> $params
     */
    public static function fromArray(array $params): self
    {
        $query = new self((string) ($params['FormId'] ?? ''));

        $query->fields((string) ($params['FieldKeys'] ?? ''));

        $filter = (string) ($params['FilterString'] ?? '');
        if ($filter !== '') {
            $query->filter(...array_map('trim', explode(' and ', $filter)));
        }

        $query->orderBy((string) ($params['OrderString'] ?? ''));
        $query->offset((int) ($params['StartRow'] ?? 0));
        $query->perPage((int) ($params['Limit'] ?? 100));

        return $query;
    }

    /**
     * 查询字段
     *
     * @param array<int, string>|string $fields
     */
    public function fields($fields): self
    {
        $this->fields = is_array($fields)
            ? array_values(array_filter(array_map(static fn ($f): string => trim((string) $f), $fields), static fn (string $f): bool => $f !== ''))
            : array_values(array_filter(array_map('trim', explode(',', $fields)), static fn (string $f): bool => $f !== ''));

        return $this;
    }

    /**
     * 追加过滤条件
     */
    public function filter(string ...$filters): self
    {
        foreach ($filters as $filter) {
            $filter = trim($filter);

            if ($filter !== '') {
                $this->filters[] = $filter;
            }
        }

        return $this;
    }

    /**
     * 覆盖过滤条件（清空后重新设置）
     */
    public function only(string ...$filters): self
    {
        $this->filters = [];

        return $this->filter(...$filters);
    }

    /**
     * 等值条件
     *
     * @param string                $field 字段名
     * @param string|int|float|bool $value 字段值
     */
    public function whereEquals(string $field, $value): self
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('等值条件的值必须是标量');
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return $this->filter(sprintf("%s='%s'", $field, $value));
    }

    /**
     * 组织条件
     *
     * 金蝶为多组织 ERP，不同单据使用不同的组织维度字段（见 OrgFields）。
     * 本方法只负责按金蝶的写法组装条件，具体同步哪些组织由调用方决定。
     *
     * @param string                   $field  组织维度字段，如 OrgFields::USE
     * @param string|array<int,string> $orgIds 组织编码
     */
    public function whereOrg(string $field, $orgIds): self
    {
        $orgIds = is_string($orgIds) ? [$orgIds] : (array) $orgIds;

        if ($orgIds === []) {
            throw new InvalidArgumentException('组织条件的组织编码不能为空');
        }

        return $this->whereIn($field.'.FNumber', $orgIds);
    }

    /**
     * 集合条件
     *
     * @param array<int, string|int|float> $values
     */
    public function whereIn(string $field, array $values): self
    {
        if ($values === []) {
            return $this;
        }

        $formatted = implode(',', array_map(
            static fn ($value): string => is_float($value) ? (string) $value : (string) $value,
            $values,
        ));

        return $this->filter(sprintf("%s in('%s')", $field, $formatted));
    }

    /**
     * 排序
     */
    public function orderBy(string $orderBy): self
    {
        $this->orderBy = $orderBy;

        return $this;
    }

    /**
     * 分页
     */
    public function page(int $page, int $limit = 100): self
    {
        $page = max(1, $page);
        $limit = max(1, min(2000, $limit));

        $this->limit = $limit;
        $this->startRow = ($page - 1) * $limit;

        return $this;
    }

    /**
     * 设置起始行
     */
    public function offset(int $startRow): self
    {
        $this->startRow = max(0, $startRow);

        return $this;
    }

    /**
     * 设置每页行数
     */
    public function perPage(int $limit): self
    {
        $this->limit = max(1, min(2000, $limit));

        return $this;
    }

    /**
     * 复制一份（便于派生不同条件的查询）
     */
    public function copy(): self
    {
        $clone = new self($this->formId);
        $clone->fields = $this->fields;
        $clone->filters = $this->filters;
        $clone->orderBy = $this->orderBy;
        $clone->startRow = $this->startRow;
        $clone->limit = $this->limit;

        return $clone;
    }

    /**
     * 单据对象
     */
    public function formId(): string
    {
        return $this->formId;
    }

    /**
     * 查询字段列表（与 FieldKeys 顺序一致）
     *
     * @return array<int, string>
     */
    public function fieldKeys(): array
    {
        return $this->fields;
    }

    /**
     * 起始行
     */
    public function startRow(): int
    {
        return $this->startRow;
    }

    /**
     * 每页行数
     */
    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * 转为金蝶查询参数
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException 缺少金蝶必填参数（FormId / FieldKeys）
     */
    public function toArray(): array
    {
        // 金蝶 BillQuery 要求 FormId 与 FieldKeys 均非空：本地先校验，避免无效往返
        if ($this->formId === '') {
            throw new ConfigurationException('查询缺少业务对象表单Id（FormId）');
        }

        if ($this->fields === []) {
            throw new ConfigurationException('查询缺少查询字段集合（FieldKeys）');
        }

        $filters = $this->filters;

        return [
            'FormId' => $this->formId,
            'FieldKeys' => implode(',', $this->fields),
            'FilterString' => implode(' and ', $filters),
            'OrderString' => $this->orderBy,
            'TopRowCount' => 0,
            'StartRow' => $this->startRow,
            'Limit' => $this->limit,
            'SubSystemId' => '',
        ];
    }
}
