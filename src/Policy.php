<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud;

use InvalidArgumentException;

/**
 * 操作策略：超时与重试行为
 *
 * 核心原则：读操作幂等可重试；写操作不幂等，网络异常时不得自动重试，
 * 否则会在金蝶侧产生重复单据。
 *
 * 四种内置策略的差别：
 *  - read()：幂等的读操作（查询、元数据），可重试，默认超时 30 秒、最多 3 次、退避 500ms。
 *  - write()：写操作（保存、删除、审核），不重试，默认超时 30 秒。
 *  - attachment()：附件上传，文件较大因此默认超时 120 秒；不重试（重试会产生冗余附件）。
 *  - once()：性质未知的端点，不重试，默认超时 30 秒（见 Client::request()）。
 *
 * 退避为**线性**（非指数）：第 n 次重试等待约 backoffMs × n，再叠加 0~50% 的随机抖动。
 */
final class Policy
{
    /**
     * 策略名
     *
     * 取值 'read' / 'write' / 'attachment' / 'once'，用于日志与策略识别。
     *
     * @var string
     */
    public $name;

    /**
     * 单次请求超时时间（秒）
     *
     * @var int
     */
    public $timeout;

    /**
     * 最大尝试次数
     *
     * 含首次请求；write/attachment/once 固定为 1（即不重试）。
     *
     * @var int
     */
    public $maxAttempts;

    /**
     * 是否允许重试
     *
     * @var bool
     */
    public $retryable;

    /**
     * 退避基数（毫秒）
     *
     * 0 表示不等待；实际等待时长由 backoffFor() 计算。
     *
     * @var int
     */
    public $backoffMs;

    /**
     * @param  string  $name  策略名
     * @param  int  $timeout  单次请求超时时间（秒），必须大于 0
     * @param  int  $maxAttempts  最大尝试次数（含首次请求）
     * @param  bool  $retryable  是否允许重试
     * @param  int  $backoffMs  退避基数（毫秒）
     *
     * @throws InvalidArgumentException $timeout 小于 1（0 在 curl 中意为「永不超时」，属误配）
     */
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
     *
     * 读操作幂等，网络异常时重试是安全的。
     *
     * @param  int  $timeout  单次请求超时时间（秒），必须大于 0
     * @param  int  $maxAttempts  最大尝试次数（含首次请求），小于 1 时按 1 处理
     * @param  int  $backoffMs  退避基数（毫秒），0 表示不等待
     *
     * @return self 读策略
     *
     * @throws InvalidArgumentException $timeout 小于 1
     */
    public static function read(int $timeout = 30, int $maxAttempts = 3, int $backoffMs = 500): self
    {
        return new self('read', $timeout, max(1, $maxAttempts), true, $backoffMs);
    }

    /**
     * 写操作（保存、删除、反审核）：不重试
     *
     * 写操作不幂等：网络异常时服务端可能已执行成功，
     * 重试会产生重复单据，因此最大尝试次数固定为 1。
     *
     * @param  int  $timeout  单次请求超时时间（秒），必须大于 0
     *
     * @return self 写策略
     *
     * @throws InvalidArgumentException $timeout 小于 1
     */
    public static function write(int $timeout = 30): self
    {
        return new self('write', $timeout, 1, false, 0);
    }

    /**
     * 单次尝试（不重试）
     *
     * 用于「性质未知」的场景：SDK 无法判断端点是否会改变服务端状态时，
     * 按不重试处理，避免在金蝶侧产生重复单据。
     *
     * 与 write() 的区别只在语义：write() 表示「已知是写操作」，
     * once() 表示「不确定是否为写操作」。两者都不重试。
     *
     * @param  int  $timeout  单次请求超时时间（秒），必须大于 0
     *
     * @return self 单次尝试策略
     *
     * @throws InvalidArgumentException $timeout 小于 1
     */
    public static function once(int $timeout = 30): self
    {
        return new self('once', $timeout, 1, false, 0);
    }

    /**
     * 附件上传：文件较大，超时更宽松；不重试（重试会产生冗余附件）
     *
     * @param  int  $timeout  单次请求超时时间（秒），默认 120，必须大于 0
     *
     * @return self 附件策略
     *
     * @throws InvalidArgumentException $timeout 小于 1
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
     *
     * 计算方式：基数 = backoffMs × max(1, $attempt)（线性，非指数）；
     * 抖动为基值的 0~50% 随机量；backoffMs 不大于 0 时直接返回 0。
     *
     * @param  int  $attempt  已进行的尝试序号（从 1 开始），小于 1 时按 1 计算
     *
     * @return int 本次重试前应等待的毫秒数
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
