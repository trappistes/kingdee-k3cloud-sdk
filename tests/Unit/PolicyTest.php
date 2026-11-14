<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Policy;

/**
 * 操作策略测试
 */
final class PolicyTest extends TestCase
{
    public function test_read_policy_is_retryable(): void
    {
        $policy = Policy::read();

        self::assertTrue($policy->retryable);
        self::assertSame(3, $policy->maxAttempts);
    }

    public function test_write_policy_never_retries(): void
    {
        $policy = Policy::write();

        self::assertFalse($policy->retryable);
        self::assertSame(1, $policy->maxAttempts);
    }

    public function test_attachment_policy_has_larger_timeout(): void
    {
        $policy = Policy::attachment();

        self::assertFalse($policy->retryable);
        self::assertSame(120, $policy->timeout);
    }

    public function test_backoff_grows_and_carries_jitter(): void
    {
        $policy = Policy::read(30, 3, 100);

        $first = $policy->backoffFor(1);
        $second = $policy->backoffFor(2);

        self::assertGreaterThanOrEqual(100, $first);
        self::assertLessThanOrEqual(150, $first);
        self::assertGreaterThanOrEqual(200, $second);
        self::assertLessThanOrEqual(300, $second);
    }

    public function test_backoff_of_non_retryable_policy_is_zero(): void
    {
        self::assertSame(0, Policy::write()->backoffFor(3));
    }
}
