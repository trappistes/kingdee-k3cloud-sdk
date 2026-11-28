<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud;

use Trappistes\Kingdee\K3Cloud\Exception\BusinessException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Support\Arr;

/**
 * 金蝶响应解析
 *
 * 金蝶 WebAPI 有两种响应形态：
 *  - 查询类：扁平行数组，例如 [{"FNumber":"01000004"}]
 *  - 操作类：信封结构，例如 {"Result":{"ResponseStatus":{"IsSuccess":true}}}
 *
 * 本类把形态知识集中收敛，避免业务层把「错误信封」误当成「一行数据」。
 *
 * 核心约定：**严格解析失败要抛异常，而不是当成空数据**。
 *  - normalize()：严格模式。业务失败抛 BusinessException，
 *    报文结构异常（非 JSON、缺 IsSuccess、既非行数组也非信封）抛 TransportException。
 *  - resultRows()：查询路径专用。兼容「顶层行数组」与「信封 Result.BillQueryResult」
 *    两种形态，都不是则抛 TransportException——查询结果缺失必须报错。
 *  - rows()：宽松模式。仅做取值，不校验业务状态；
 *    错误信封一律返回空数组，因此不能用它判断「查询是否成功」。
 *
 * 其它方法（isList()、isSuccess()、errors()、errorMessage()、successEntities()、
 * loginResultType()、isSessionExpired()）都是**只读探测**：不抛业务异常，
 * 只根据报文内容给出结论。
 */
final class Response
{
    /**
     * 会话失效的特征
     *
     * 只接受**语义短语**，不得使用字段名或标识符：
     * 金蝶的业务错误完全可能提到某个字段（如「单据 KSessionId 字段不合法」），
     * 用字段名做特征会误伤业务错误——真实原因被伪装成会话失效，排查方向被带偏。
     *
     * 注意：不能使用「未登录」等宽松关键词——业务文案（如「客户未登录订单」）
     * 会造成误判，触发不必要的重新登录与重试。
     */
    private const SESSION_MARKERS = [
        '请重新登录',
        '登录已失效',
        '登录失效',
        '会话已失效',
        '会话失效',
        '会话已过期',
        'session expired',
        'not logged in',
    ];

    /**
     * 解码为数组
     *
     * 以关联数组形式解码 JSON 文本；只做语法校验，不关心业务状态。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return array<mixed> 解码后的数组；JSON 合法但顶层不是对象或数组（如 null、标量）时也抛异常
     *
     * @throws TransportException 响应不是合法 JSON，或顶层不是对象/数组
     */
    public static function decode(string $raw): array
    {
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new TransportException('金蝶响应不是合法 JSON：'.substr($raw, 0, 200));
        }

        return $data;
    }

    /**
     * 是否为查询类的行数组
     *
     * 必须是 JSON 数组：JSON 对象（如 {}）即使解码后是空数组，也不视为行数组。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return bool 顶层为 JSON 数组且解码后为连续索引列表时为 true
     */
    public static function isList(string $raw): bool
    {
        $data = json_decode($raw, true);

        return self::isJsonArray($raw) && is_array($data) && Arr::isList($data);
    }

    /**
     * 响应顶层是否为 JSON 数组
     *
     * json_decode('{}', true) 与 json_decode('[]', true) 都是空数组，
     * 只能靠原始文本的首字符区分，否则空对象会被误读成「查到 0 行」。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return bool 去除前导空白后首字符为 '[' 时为 true
     */
    private static function isJsonArray(string $raw): bool
    {
        $trimmed = ltrim($raw);

        return $trimmed !== '' && $trimmed[0] === '[';
    }

    /**
     * 归一化响应
     *
     * - 查询类 → 返回行数组
     * - 操作类 → 返回信封数组（业务失败时抛出 BusinessException）
     *
     * 严格模式：宁可抛异常，也不把异常报文当作「空数据」交给上层。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return array<mixed> 查询类返回行数组；操作类返回完整信封数组
     *
     * @throws BusinessException  金蝶返回业务失败（IsSuccess=false），异常内含错误明细
     * @throws TransportException 响应不是合法 JSON、缺少 IsSuccess，或既非行数组也非信封结构
     */
    public static function normalize(string $raw): array
    {
        $data = self::decode($raw);

        // 查询类：行数组（必须是 JSON 数组，空对象不视为空结果）
        if (self::isJsonArray($raw) && Arr::isList($data)) {
            return $data;
        }

        // 操作类：信封
        if (isset($data['Result']) && is_array($data['Result'])) {
            $status = $data['Result']['ResponseStatus'] ?? null;

            if (is_array($status)) {
                if (!array_key_exists('IsSuccess', $status)) {
                    // 状态位缺失：报文结构不完整，不能当作成功
                    throw new TransportException('金蝶响应结构异常：缺少 IsSuccess：'.substr($raw, 0, 200));
                }

                if ($status['IsSuccess'] === false) {
                    throw new BusinessException(self::errorMessage($raw), self::errors($raw));
                }
            }

            return $data;
        }

        throw new TransportException('金蝶响应结构异常：'.substr($raw, 0, 200));
    }

    /**
     * 查询数据行（严格解析）
     *
     * 兼容金蝶查询响应的两种形态：
     *  - 顶层 JSON 数组：[{...}, {...}]（BillQuery）
     *  - 信封：{"Result":{"ResponseStatus":{...},"BillQueryResult":[...]}}
     *
     * 两者都不是时报结构异常（TransportException）——查询结果缺失必须报错，
     * 不能当成「查到 0 行」。
     *
     * 注意：`Result.BillQueryResult` 的键名尚未在真实帐套复核，
     * 键名不符时会抛异常而不是静默返回空数组（宁可报错，也不要静默丢数）。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return array<int, mixed> 数据行；合法空结果为空数组
     *
     * @throws BusinessException  金蝶返回业务失败（IsSuccess=false）
     * @throws TransportException 响应不是合法 JSON、结构异常，或找不到数据行
     */
    public static function resultRows(string $raw): array
    {
        // 严格解析：业务失败抛 BusinessException；非 JSON / 缺 IsSuccess 抛 TransportException
        $data = self::normalize($raw);

        // 形态一：顶层 JSON 数组（BillQuery）
        if (self::isJsonArray($raw) && Arr::isList($data)) {
            return $data;
        }

        // 形态二：信封里的数据行（ExecuteBillQuery）
        $result = $data['Result'] ?? null;

        if (is_array($result)) {
            $inner = $result['BillQueryResult'] ?? null;

            if (is_array($inner) && Arr::isList($inner)) {
                return $inner;
            }
        }

        throw new TransportException(
            '金蝶响应结构异常：查询结果缺少数据行：'.substr($raw, 0, 200)
        );
    }

    /**
     * 查询结果行（纯取值，不做业务校验）
     *
     * 响应为错误信封时返回空数组；需要「失败即抛异常」语义请使用 normalize()。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return array<int, array<string, mixed>> 行数组；报文不是查询类行数组时返回空数组
     */
    public static function rows(string $raw): array
    {
        $data = json_decode($raw, true);

        return self::isJsonArray($raw) && is_array($data) && Arr::isList($data) ? $data : [];
    }

    /**
     * 将「数组行」映射为「对象行」
     *
     * 金蝶 ExecuteBillQuery 返回的行是**按 FieldKeys 顺序的数组**
     * （如 ["01000004","液氨"]），而 BillQuery 返回的是对象行。
     * 本方法把两者统一为对象行，便于上层一致处理。
     *
     * 非列表的行（如已是对象行）原样保留，不做转换。
     *
     * @param  array<int, mixed>  $rows  行数据
     * @param  array<int, string>  $fields  字段名（与 FieldKeys 顺序一致）
     *
     * @return array<int, array<string, mixed>> 字段名 => 值 的对象行列表
     */
    public static function mapRows(array $rows, array $fields): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !Arr::isList($row)) {
                // 已是对象行，原样保留
                $result[] = $row;

                continue;
            }

            $mapped = [];

            foreach ($fields as $index => $field) {
                $mapped[$field] = $row[$index] ?? null;
            }

            // 行内元素多于字段时，保留多余项，避免丢数据（键为绝对列号）
            $offset = count($fields);

            foreach (array_slice($row, $offset) as $index => $extra) {
                $mapped[(string) ($offset + $index)] = $extra;
            }

            $result[] = $mapped;
        }

        return $result;
    }

    /**
     * 是否业务成功
     *
     * 无失败标记（无 ResponseStatus 的信封，如元数据查询）视为成功。
     *
     * 注意：这是**只读探测**，不会抛异常；报文非法时返回 false。
     * 需要「失败即抛异常」语义请使用 normalize()。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return bool 业务成功为 true，业务失败或报文无法解析为 false
     */
    public static function isSuccess(string $raw): bool
    {
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return false;
        }

        $status = $data['Result']['ResponseStatus'] ?? null;

        if (is_array($status)) {
            return ($status['IsSuccess'] ?? false) === true;
        }

        // 查询类无状态位，按是否为行数组判断
        return (self::isJsonArray($raw) && Arr::isList($data)) || isset($data['Result']);
    }

    /**
     * 错误明细
     *
     * 取自信封的 Result.ResponseStatus.Errors。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return array<int, array<string, mixed>> 错误明细列表；报文不是信封或无 Errors 时返回空数组
     */
    public static function errors(string $raw): array
    {
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $errors = $data['Result']['ResponseStatus']['Errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }

    /**
     * 错误信息
     *
     * 优先拼接 Errors 中各条的 Message（以 '; ' 分隔）；
     * 没有 Errors 时回退到信封顶层 Message；再没有则返回固定文案「金蝶操作失败」。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return string 错误文案
     */
    public static function errorMessage(string $raw): string
    {
        $messages = [];

        foreach (self::errors($raw) as $error) {
            if (isset($error['Message']) && $error['Message'] !== '') {
                $messages[] = (string) $error['Message'];
            }
        }

        if ($messages === []) {
            $data = json_decode($raw, true);
            $message = is_array($data) ? ($data['Message'] ?? null) : null;

            return is_string($message) && $message !== '' ? $message : '金蝶操作失败';
        }

        return implode('; ', $messages);
    }

    /**
     * 成功实体ID（保存后返回的单据ID）
     *
     * 取自信封的 Result.ResponseStatus.SuccessEntitys。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return array<int, array<string, mixed>> 成功实体列表；报文不是信封或无该字段时返回空数组
     */
    public static function successEntities(string $raw): array
    {
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [];
        }

        $entities = $data['Result']['ResponseStatus']['SuccessEntitys'] ?? [];

        return is_array($entities) ? $entities : [];
    }

    /**
     * 登录结果码：1=成功，0=失败
     *
     * 取自报文顶层的 LoginResultType 字段。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return int 结果码；报文无法解析或字段缺失时返回 0（即失败）
     */
    public static function loginResultType(string $raw): int
    {
        $data = json_decode($raw, true);

        return is_array($data) ? (int) ($data['LoginResultType'] ?? 0) : 0;
    }

    /**
     * 是否会话失效
     *
     * 仅依据错误信息判断，避免误伤正常业务文案。
     * 命中会话失效特征词（不区分大小写）时为 true，
     * 用于触发重新登录，而不是直接重试。
     *
     * @param  string  $raw  原始响应报文
     *
     * @return bool 错误信息命中会话失效特征词时为 true
     */
    public static function isSessionExpired(string $raw): bool
    {
        $message = self::errorMessage($raw);

        if ($message === '') {
            return false;
        }

        foreach (self::SESSION_MARKERS as $marker) {
            if (stripos($message, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
