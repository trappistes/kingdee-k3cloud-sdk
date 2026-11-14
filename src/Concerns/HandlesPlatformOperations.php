<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Concerns;

use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Policy;

/**
 * HandlesPlatformOperations —— Client 的职责分组之一（仅内部组织，不改变公开 API）
 */
trait HandlesPlatformOperations
{
    /**
     * 发送消息（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function sendMsg(array $payload): string
    {
        return $this->writePlatformOperation(Endpoints::SEND_MSG, $payload);
    }

    /**
     * 查询分组信息（读操作，可重试）
     *
     * @param array<string, mixed> $payload
     */
    public function queryGroupInfo(array $payload): string
    {
        return $this->dataRequest(Endpoints::QUERY_GROUP_INFO, $payload, Policy::read(
            $this->config->timeout,
            $this->config->maxAttempts
        ));
    }

    /**
     * 获取数据中心列表（读操作，可重试）
     */
    public function getDataCenterList(): string
    {
        return $this->request(Endpoints::GET_DATA_CENTER_LIST, [], Policy::read(
            $this->config->timeout,
            $this->config->maxAttempts
        ));
    }

    /**
     * 上传附件（写操作，不自动重试；超时更宽松）
     *
     * @param array<string, mixed> $payload
     */
    public function uploadAttachment(array $payload): string
    {
        return $this->request(Endpoints::ATTACHMENT_UPLOAD, $payload, Policy::attachment(
            max(120, $this->config->timeout)
        ));
    }
}
