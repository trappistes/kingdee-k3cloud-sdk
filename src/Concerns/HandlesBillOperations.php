<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Concerns;

use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Policy;

/**
 * HandlesBillOperations —— Client 的职责分组之一（仅内部组织，不改变公开 API）
 */
trait HandlesBillOperations
{
    /**
     * 保存单据（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function save(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::SAVE, $formId, $payload);
    }

    /**
     * 删除单据（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function delete(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::DELETE, $formId, $payload);
    }

    /**
     * 反审核（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function unAudit(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::UNAUDIT, $formId, $payload);
    }

    /**
     * 查看单据（读操作，可重试）
     *
     * @param array<string, mixed> $payload
     */
    public function view(string $formId, array $payload): string
    {
        return $this->formRequest(Endpoints::VIEW, $formId, $payload, Policy::read(
            $this->config->timeout,
            $this->config->maxAttempts
        ));
    }

    /**
     * 批量保存单据（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function batchSave(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::BATCH_SAVE, $formId, $payload);
    }

    /**
     * 提交单据（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function submit(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::SUBMIT, $formId, $payload);
    }

    /**
     * 审核单据（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function audit(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::AUDIT, $formId, $payload);
    }

    /**
     * 暂存单据（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function draft(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::DRAFT, $formId, $payload);
    }

    /**
     * 下推单据（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function push(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::PUSH, $formId, $payload);
    }

    /**
     * 分配单据（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function allocate(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::ALLOCATE, $formId, $payload);
    }

    /**
     * 弹性域保存（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function flexSave(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::FLEX_SAVE, $formId, $payload);
    }

    /**
     * 分组保存（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function groupSave(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::GROUP_SAVE, $formId, $payload);
    }

    /**
     * 分组删除（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function groupDelete(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::GROUP_DELETE, $formId, $payload);
    }

    /**
     * 拆单（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function disassembly(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::DISASSEMBLY, $formId, $payload);
    }

    /**
     * 工作流审核（写操作，不自动重试）
     *
     * @param array<string, mixed> $payload
     */
    public function workflowAudit(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::WORKFLOW_AUDIT, $formId, $payload);
    }

    /**
     * 自定义操作（变更、关闭、重新审核等，写操作，不自动重试）
     *
     * @param string               $opNumber 操作编号
     * @param array<string, mixed> $payload
     */
    public function operation(string $formId, string $opNumber, array $payload): string
    {
        return $this->request(Endpoints::OPERATION, [
            'formid' => $formId,
            'opNumber' => $opNumber,
            'data' => $payload,
        ], Policy::write($this->config->timeout));
    }
}
