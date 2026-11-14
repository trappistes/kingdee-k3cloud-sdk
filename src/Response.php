<?php

declare(strict_types=1);

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
 */
final class Response
{
    /**
     * 会话失效的特征
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
        'KSessionId',
        'session expired',
        'not logged in',
    ];

    /**
     * 解码为数组
     *
     * @return array<mixed>
     *
     * @throws TransportException 响应不是合法 JSON
     */
    public static function decode(string $raw): array
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new TransportException('金蝶响应不是合法 JSON：'.substr($raw, 0, 200));
        }

        return $data;
    }

    /**
     * 是否为查询类的行数组
     */
    public static function isList(string $raw): bool
    {
        $data = json_decode($raw, true);

        return is_array($data) && Arr::isList($data);
    }

    /**
     * 归一化响应
     *
     * - 查询类 → 返回行数组
     * - 操作类 → 返回信封数组（业务失败时抛出 BusinessException）
     *
     * @return array<mixed>
     *
     * @throws BusinessException  金蝶返回业务失败
     * @throws TransportException 响应结构异常
     */
    public static function normalize(string $raw): array
    {
        $data = self::decode($raw);

        // 查询类：行数组
        if (Arr::isList($data)) {
            return $data;
        }

        // 操作类：信封
        if (isset($data['Result']) && is_array($data['Result'])) {
            $status = $data['Result']['ResponseStatus'] ?? null;

            if (is_array($status) && ($status['IsSuccess'] ?? true) === false) {
                throw new BusinessException(self::errorMessage($raw), self::errors($raw));
            }

            return $data;
        }

        throw new TransportException('金蝶响应结构异常：'.substr($raw, 0, 200));
    }

    /**
     * 查询结果行（纯取值，不做业务校验）
     *
     * 响应为错误信封时返回空数组；需要「失败即抛异常」语义请使用 normalize()。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(string $raw): array
    {
        $data = json_decode($raw, true);

        return is_array($data) && Arr::isList($data) ? $data : [];
    }

    /**
     * 将「数组行」映射为「对象行」
     *
     * 金蝶 ExecuteBillQuery 返回的行是**按 FieldKeys 顺序的数组**
     * （如 ["01000004","液氨"]），而 BillQuery 返回的是对象行。
     * 本方法把两者统一为对象行，便于上层一致处理。
     *
     * @param array<int, mixed>  $rows   行数据
     * @param array<int, string> $fields 字段名（与 FieldKeys 顺序一致）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function mapRows(array $rows, array $fields): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! Arr::isList($row)) {
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
     */
    public static function isSuccess(string $raw): bool
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return false;
        }

        $status = $data['Result']['ResponseStatus'] ?? null;

        if (is_array($status)) {
            return ($status['IsSuccess'] ?? false) === true;
        }

        // 查询类无状态位，按是否有数据判断
        return Arr::isList($data) || isset($data['Result']);
    }

    /**
     * 错误明细
     *
     * @return array<int, array<string, mixed>>
     */
    public static function errors(string $raw): array
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return [];
        }

        $errors = $data['Result']['ResponseStatus']['Errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }

    /**
     * 错误信息
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
     * @return array<int, array<string, mixed>>
     */
    public static function successEntities(string $raw): array
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return [];
        }

        $entities = $data['Result']['ResponseStatus']['SuccessEntitys'] ?? [];

        return is_array($entities) ? $entities : [];
    }

    /**
     * 登录结果码：1=成功，0=失败
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
