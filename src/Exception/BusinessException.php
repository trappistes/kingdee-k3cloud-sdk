<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Exception;

/**
 * 业务异常：金蝶返回 IsSuccess=false
 *
 * 表示请求已被服务端接收并按业务规则拒绝，重试无意义。
 */
class BusinessException extends KingdeeException
{
    /**
     * 金蝶返回的错误明细，每项通常包含错误码与错误信息
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $errors;

    /**
     * 构造业务异常
     *
     * @param  string  $message  异常信息（通常是金蝶返回的失败原因）
     * @param  array<int, array<string, mixed>>  $errors  金蝶返回的错误明细，可为空
     *
     * @return void
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);

        $this->errors = $errors;
    }

    /**
     * 错误明细
     *
     * @return array<int, array<string, mixed>> 金蝶返回的错误明细，无错误时为空数组
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
