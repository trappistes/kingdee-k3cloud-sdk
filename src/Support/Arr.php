<?php

declare(strict_types=1);

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
     * @param array<mixed> $array
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
