<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Exception;

/**
 * 业务异常：金蝶返回 IsSuccess=false
 *
 * 表示请求已被服务端接收并按业务规则拒绝，重试无意义。
 */
class BusinessException extends KingdeeException
{
    /**
     * @var array<int, array<string, mixed>>
     */
    protected $errors;

    /**
     * @param array<int, array<string, mixed>> $errors 金蝶返回的错误明细
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);

        $this->errors = $errors;
    }

    /**
     * 错误明细
     *
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
