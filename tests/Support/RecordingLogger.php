<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * 记录型日志器：把 PSR-3 记录存下来供断言
 *
 * 跨 psr/log 1.x / 2.x / 3.x 兼容：
 *  - 不给 $message 声明类型（2.x/3.x 的父类是 string|Stringable，放宽参数类型是允许的）；
 *  - 返回类型写 void（1.x 的父类没有返回类型，子类补返回类型是允许的）。
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * 记录下来的日志条目
     *
     * @var array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * {@inheritDoc}
     *
     * @param  mixed  $level
     * @param  mixed  $message
     * @param  array<string, mixed>  $context
     *
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * 指定级别的记录
     *
     * @param  string  $level  PSR-3 级别名，如 'warning'、'debug'
     *
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    public function recordsOfLevel(string $level): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $record): bool => $record['level'] === $level
        ));
    }

    /**
     * 是否记录过指定事件
     *
     * @param  string  $message  事件名
     *
     * @return bool
     */
    public function hasRecord(string $message): bool
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return true;
            }
        }

        return false;
    }
}
