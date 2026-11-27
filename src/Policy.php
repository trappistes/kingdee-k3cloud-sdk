<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud;

use InvalidArgumentException;

/**
 * 操作策略：超时与重试行为
 *
 * 核心原则：读操作幂等可重试；写操作不幂等，网络异常时不得自动重试，
 * 否则会在金蝶侧产生重复单据。
 */
final class Policy
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var int
     */
    public $timeout;

    /**
     * @var int
     */
    public $maxAttempts;

    /**
     * @var bool
     */
    public $retryable;

    /**
     * @var int
     */
    public $backoffMs;

    private function __construct(
        string $name,
        int $timeout,
        int $maxAttempts,
        bool $retryable,
        int $backoffMs
    ) {
        // CURLOPT_TIMEOUT=0 表示「永不超时」，误配会变成无限等待
        if ($timeout < 1) {
            throw new InvalidArgumentException(sprintf('超时时间必须大于 0（当前 %d）', $timeout));
        }

        $this->name = $name;
        $this->timeout = $timeout;
        $this->maxAttempts = $maxAttempts;
        $this->retryable = $retryable;
        $this->backoffMs = $backoffMs;
    }

    /**
     * 读操作（查询、元数据）：可重试
     */
    public static function read(int $timeout = 30, int $maxAttempts = 3, int $backoffMs = 500): self
    {
        return new self('read', $timeout, max(1, $maxAttempts), true, $backoffMs);
    }

    /**
     * 写操作（保存、删除、反审核）：不重试
     */
    public static function write(int $timeout = 30): self
    {
        return new self('write', $timeout, 1, false, 0);
    }

    /**
     * 附件上传：文件较大，超时更宽松；不重试（重试会产生冗余附件）
     */
    public static function attachment(int $timeout = 120): self
    {
        return new self('attachment', $timeout, 1, false, 0);
    }

    /**
     * 退避时长（毫秒）
     *
     * 采用线性退避 + 随机抖动：避免多实例同步同时失败时形成「惊群」，
     * 反复冲击同一个 ERP 端点。
     */
    public function backoffFor(int $attempt): int
    {
        if ($this->backoffMs <= 0) {
            return 0;
        }

        $base = $this->backoffMs * max(1, $attempt);

        // 抖动幅度：基值的 0~50%
        $jitter = $base > 1 ? random_int(0, intdiv($base, 2)) : 0;

        return $base + $jitter;
    }
}
