<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Support;

/**
 * 数组工具
 *
 * PHP 8.1 才提供原生 array_is_list()，此处提供等价实现以兼容更低版本。
 */
final class Arr
{
    /**
     * 判断数组是否为连续索引的列表
     *
     * 等价于 PHP 8.1 的 array_is_list()：键必须从 0 开始且连续递增。
     * 空数组视为列表。
     *
     * @param  array  $array  待判断的数组
     *
     * @return bool true 表示键为 0,1,2... 的连续列表
     */
    public static function isList(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        $expected = 0;

        foreach ($array as $key => $value) {
            if ($key !== $expected) {
                return false;
            }

            $expected++;
        }

        return true;
    }
}
