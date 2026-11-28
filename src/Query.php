<?php

declare(strict_types = 1);

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
 *
 * 使用约束：
 *  - 链式调用：条件类方法均返回 $this，可以连续书写；
 *    但实例本身是**可变**的，重复调用条件方法会在同一实例上累积结果。
 *  - 派生查询：需要「同一组基础条件 + 不同附加条件」时，
 *    先 copy() 得到独立副本再修改，避免两个查询互相污染。
 *  - 过滤条件一律以字符串表达，最终由 toArray() 用 ' and ' 连接；
 *    条件中不要自行再拼 ' and '，否则语义会与 SDK 拼装的连接符混在一起。
 *  - 分页上限：每页行数最多 2000（page() / perPage() 会自动收敛到该范围）。
 *  - 字段去重：fields() 会去掉重复字段并保留首次出现的顺序，
 *    避免金蝶返回重复列、列数与字段数不一致导致 mapRows() 错位。
 */
final class Query
{
    /**
     * 查询字段
     *
     * 参与 FieldKeys 的字段名列表，顺序即返回列的顺序。
     *
     * @var array<int, string>
     */
    private array $fields = [];

    /**
     * 过滤条件
     *
     * 每个元素是一条完整的条件表达式（不含 ' and ' 连接符）。
     *
     * @var array<int, string>
     */
    private array $filters = [];

    /**
     * 排序
     *
     * 形如 'FCreateDate desc' 的排序表达式，空串表示不排序。
     */
    private string $orderBy = '';

    /**
     * 起始行
     *
     * 分页起始下标（0 基），由 page() / offset() 维护。
     */
    private int $startRow = 0;

    /**
     * 每页行数
     *
     * 实际取值被收敛到 1~2000。
     */
    private int $limit = 100;

    /**
     * 单据对象标识（FormId）
     *
     * @var string
     */
    private $formId;

    /**
     * @param  string  $formId  单据对象标识（FormId），如 'UWAZ_OutStock'；
     *                       不可为空字符串，否则 toArray() 会抛 ConfigurationException
     */
    public function __construct(string $formId)
    {
        $this->formId = $formId;
    }

    /**
     * 创建查询
     *
     * @param  string  $formId  单据对象标识（FormId），如 'UWAZ_OutStock'
     *
     * @return self 新建查询实例
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
     * 识别的键：FormId、FieldKeys（逗号分隔）、FilterString、OrderString、
     * StartRow、Limit；缺失时使用默认值（StartRow=0、Limit=100）。
     * 注意：FilterString 会**原样**作为一条完整条件保留，不按 ' and ' 拆分——
     * 拆分可能切坏取值中恰好含有该文本的条件。
     *
     * @param  array<string, mixed>  $params  金蝶查询参数数组（键名同金蝶接口）
     *
     * @return self 等价于逐项调用 fields()/filter()/orderBy()/offset()/perPage() 的查询
     */
    public static function fromArray(array $params): self
    {
        $query = new self((string) ($params['FormId'] ?? ''));

        $query->fields((string) ($params['FieldKeys'] ?? ''));

        $filter = (string) ($params['FilterString'] ?? '');
        if ($filter !== '') {
            // 原样保留为单个条件：按 ' and ' 拆分会切坏值中含该文本的条件
            $query->filter($filter);
        }

        $query->orderBy((string) ($params['OrderString'] ?? ''));
        $query->offset((int) ($params['StartRow'] ?? 0));
        $query->perPage((int) ($params['Limit'] ?? 100));

        return $query;
    }

    /**
     * 查询字段
     *
     * 整体覆盖字段集合（不是追加）。字段顺序决定返回列的顺序，
     * 后续 mapRows() 也按该顺序把扁平行数组还原为对象行。
     *
     * 重复字段会被去掉（保留首次出现的顺序）：重复列会让金蝶返回重复列，
     * 且使列数与字段数不一致，mapRows() 还原时错位。
     *
     * @param  array<int, string>|string  $fields  字段名集合：数组形式，或以 ',' 分隔的字符串；
     *                                           空白项会被去除
     *
     * @return self $this，可继续链式调用
     */
    public function fields($fields): self
    {
        $list = is_array($fields)
            ? array_map(static fn($f): string => trim((string) $f), $fields)
            : array_map('trim', explode(',', (string) $fields));

        // 去重但保留首次出现的顺序（array_unique 保留首次出现的键与顺序）
        $this->fields = array_values(array_unique(array_filter(
            $list,
            static fn(string $f): bool => $f !== ''
        )));

        return $this;
    }

    /**
     * 追加过滤条件
     *
     * 每条条件是一条完整的金蝶过滤表达式（不含 ' and ' 连接符），
     * 例如 "FStatus='A'"。空白条件会被忽略，多条条件按追加顺序由
     * toArray() 用 ' and ' 连接。
     *
     * @param  string  ...$filters  过滤表达式，可传多条
     *
     * @return self $this，可继续链式调用
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
     *
     * 先清空已累积的条件，再追加本次传入的条件。
     *
     * @param  string  ...$filters  过滤表达式，可传多条
     *
     * @return self $this，可继续链式调用
     */
    public function only(string ...$filters): self
    {
        $this->filters = [];

        return $this->filter(...$filters);
    }

    /**
     * 等值条件
     *
     * 条件形如 `字段='值'`；值会被加上单引号，字面量中的单引号按 SQL 惯例
     * 翻倍转义。布尔值写作 'true' / 'false'。
     *
     * @param  string  $field  字段名（如 FStatus）
     * @param  string|int|float|bool  $value  字段值，非标量会抛异常
     *
     * @return self $this，可继续链式调用
     *
     * @throws InvalidArgumentException $value 不是标量
     */
    public function whereEquals(string $field, $value): self
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('等值条件的值必须是标量');
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        return $this->filter(sprintf('%s=%s', $field, self::literal((string) $value)));
    }

    /**
     * 组织条件
     *
     * 金蝶为多组织 ERP，不同单据使用不同的组织维度字段（见 OrgFields）。
     * 本方法只负责按金蝶的写法组装条件，具体同步哪些组织由调用方决定。
     *
     * 条件形如 `组织字段.FNumber in('编码1','编码2')`：
     * 金蝶组织字段按组织编码（FNumber）匹配，而非组织主键（FOrgId）。
     *
     * @param  string  $field  组织维度字段，如 OrgFields::USE
     * @param  string|array<int,string>  $orgIds  组织编码，单个编码或编码数组
     *
     * @return self $this，可继续链式调用
     *
     * @throws InvalidArgumentException $orgIds 为空数组
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
     * 条件形如 `字段 in('值1','值2')`，值逐个加单引号并对单引号做翻倍转义。
     * 传空数组时**不追加任何条件**（直接返回原查询），
     * 因此「没有符合项」与「不限」需由调用方自行区分。
     *
     * @param  array<int, string|int|float>  $values  取值集合，元素必须是标量
     *
     * @return self $this，可继续链式调用
     *
     * @throws InvalidArgumentException $values 中存在非标量元素
     */
    public function whereIn(string $field, array $values): self
    {
        if ($values === []) {
            return $this;
        }

        $quoted = [];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('集合条件的值必须是标量');
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $quoted[] = self::literal((string) $value);
        }

        return $this->filter(sprintf('%s in(%s)', $field, implode(',', $quoted)));
    }

    /**
     * 构造字面量
     *
     * 值中的单引号按 SQL 惯例翻倍转义，避免截断金蝶过滤表达式。
     *
     * @param  string  $value  字面量原值（不含引号）
     *
     * @return string 带单引号包裹、且内部单引号已翻倍转义的字面量
     */
    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * 排序
     *
     * 整体覆盖排序表达式，空字符串表示不排序。
     *
     * @param  string  $orderBy  排序表达式，如 'FCreateDate desc, FId asc'
     *
     * @return self $this，可继续链式调用
     */
    public function orderBy(string $orderBy): self
    {
        $this->orderBy = $orderBy;

        return $this;
    }

    /**
     * 分页
     *
     * 同时设置起始行与每页行数：起始行 = (页码 - 1) * 每页行数。
     * 页码最小为 1，每页行数收敛到 1~2000。
     *
     * @param  int  $page  页码，从 1 开始；小于 1 时按 1 处理
     * @param  int  $limit  每页行数，收敛到 1~2000
     *
     * @return self $this，可继续链式调用
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
     *
     * 只改起始行，不动每页行数；负值按 0 处理。
     *
     * @param  int  $startRow  起始行下标（0 基）
     *
     * @return self $this，可继续链式调用
     */
    public function offset(int $startRow): self
    {
        $this->startRow = max(0, $startRow);

        return $this;
    }

    /**
     * 设置每页行数
     *
     * 取值收敛到 1~2000（金蝶 BillQuery 单页上限）。
     *
     * @param  int  $limit  每页行数
     *
     * @return self $this，可继续链式调用
     */
    public function perPage(int $limit): self
    {
        $this->limit = max(1, min(2000, $limit));

        return $this;
    }

    /**
     * 复制一份（便于派生不同条件的查询）
     *
     * 复制表单Id、字段、过滤条件、排序、起始行与每页行数；
     * 数组按值复制，之后修改副本不会影响原查询（反之亦然）。
     *
     * @return self 与当前查询内容一致的新实例
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
     *
     * @return string 单据对象标识（FormId）
     */
    public function formId(): string
    {
        return $this->formId;
    }

    /**
     * 查询字段列表（与 FieldKeys 顺序一致）
     *
     * @return array<int, string> 字段名列表，下标顺序即返回列顺序
     */
    public function fieldKeys(): array
    {
        return $this->fields;
    }

    /**
     * 起始行
     *
     * @return int 起始行下标（0 基）
     */
    public function startRow(): int
    {
        return $this->startRow;
    }

    /**
     * 每页行数
     *
     * @return int 每页行数（1~2000）
     */
    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * 转为金蝶查询参数
     *
     * 输出 FormId、FieldKeys、FilterString（条件以 ' and ' 连接）、
     * OrderString、TopRowCount（固定 0）、StartRow、Limit、SubSystemId（固定空串）。
     *
     * 该数组与 fromArray() 的入参键名一致，可直接回灌 fromArray()；
     * 但多个条件在 toArray() 中会被合并为一段 FilterString，
     * 回灌后成为一条整体条件——文本等价，条件条目数不可逆。
     *
     * @return array<string, mixed> 金蝶查询参数数组
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
