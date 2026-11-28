<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Concerns;

use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Policy;

/**
 * HandlesBillOperations —— Client 的职责分组之一（仅内部组织，不改变公开 API）
 *
 * 职责：金蝶单据类操作（保存、提交、审核、删除、查看等）的对外入口。
 *
 * 关键约束：
 *  - 本 trait 中的 public 方法属于 SDK 已冻结的公开 API，只做注释层面的澄清；
 *  - 所有单据操作的信封一致：请求体为 {formid, data}，formid 由 $formId 参数提供，
 *    data 节点内容（即 $payload）按操作而不同：
 *      - save()：Model 为单据体对象（可另带 NeedUpDateFields 等可选键）；
 *      - batchSave() / flexSave()：Model 为数组的数组（[['FNumber' => …], …]）；
 *      - submit() / audit() / unAudit() / delete()：Ids 为单据内码（多个用逗号分隔），
 *        这类操作只按内码定位单据，不接受 Model；
 *      - view()：data 直接放 Id（内码）或 Number（编码），不要套 Model；
 *  - 写操作一律不自动重试（重试可能产生重复单据），读操作（view）可重试；
 *  - 标记为「仅契约测试」的操作（push / allocate / groupSave / groupDelete /
 *    disassembly / workflowAudit）尚未在真实帐套验证，报文键名以金蝶接口文档为准。
 */
trait HandlesBillOperations
{
    /**
     * 保存单据（写操作，不自动重试）
     *
     * 按编码新增单据；同一编码重复保存会被金蝶拒绝（「组织内编码唯一」）。
     * 按内码更新属于金蝶的自定义操作（operation()），具体操作号以金蝶接口文档为准。
     *
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  array<string, mixed>  $payload  单据体，Model 为单据体对象，可另带
     *                                       NeedUpDateFields 等可选键
     *
     * @return string 响应体原文（内码可在 Result.Id 或 Result.ResponseStatus.SuccessEntitys[0].Id）
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function save(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::SAVE, $formId, $payload);
    }

    /**
     * 删除单据（写操作，不自动重试）
     *
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  array<string, mixed>  $payload  定位参数，Ids 为单据内码（多个用逗号分隔）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function delete(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::DELETE, $formId, $payload);
    }

    /**
     * 反审核（写操作，不自动重试）
     *
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  array<string, mixed>  $payload  定位参数，Ids 为单据内码（多个用逗号分隔）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function unAudit(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::UNAUDIT, $formId, $payload);
    }

    /**
     * 查看单据（读操作，可重试）
     *
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  array<string, mixed>  $payload  定位参数，data 直接放 Id（内码）或 Number（编码），
     *                                       不要套 Model
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
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
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  单据体，Model 必须是**数组的数组**
     *                                       （[['FNumber' => …], …]），传对象会报
     *                                       「不能转换为数组类型」
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function batchSave(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::BATCH_SAVE, $formId, $payload);
    }

    /**
     * 提交单据（写操作，不自动重试）
     *
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  array<string, mixed>  $payload  定位参数，Ids 为单据内码（多个用逗号分隔），
     *                                       不接受 Model
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function submit(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::SUBMIT, $formId, $payload);
    }

    /**
     * 审核单据（写操作，不自动重试）
     *
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  array<string, mixed>  $payload  定位参数，Ids 为单据内码（多个用逗号分隔），
     *                                       不接受 Model
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function audit(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::AUDIT, $formId, $payload);
    }

    /**
     * 暂存单据（写操作，不自动重试）
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  单据体（data 节点内容，键名按金蝶对应端点约定）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function draft(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::DRAFT, $formId, $payload);
    }

    /**
     * 下推单据（写操作，不自动重试）
     *
     * 下推会产生衍生单据，报文键名以金蝶接口文档为准（尚未在真实帐套验证）。
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function push(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::PUSH, $formId, $payload);
    }

    /**
     * 分配单据（写操作，不自动重试）
     *
     * 分配报文键名以金蝶接口文档为准（尚未在真实帐套验证）。
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function allocate(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::ALLOCATE, $formId, $payload);
    }

    /**
     * 弹性域保存（写操作，不自动重试）
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  单据体，Model 为**数组的数组**
     *                                       （[['FNumber' => …], …]）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function flexSave(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::FLEX_SAVE, $formId, $payload);
    }

    /**
     * 分组保存（写操作，不自动重试）
     *
     * 分组报文需要 FNumber / FormId 语义（尚未在真实帐套验证）。
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function groupSave(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::GROUP_SAVE, $formId, $payload);
    }

    /**
     * 分组删除（写操作，不自动重试）
     *
     * 实测分组记录没有可用的删除路径，本方法仅验证过契约级调用，
     * 报文键名以金蝶接口文档为准。
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function groupDelete(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::GROUP_DELETE, $formId, $payload);
    }

    /**
     * 拆单（写操作，不自动重试）
     *
     * 拆单报文键名以金蝶接口文档为准（尚未在真实帐套验证）。
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function disassembly(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::DISASSEMBLY, $formId, $payload);
    }

    /**
     * 工作流审核（写操作，不自动重试）
     *
     * 需要流程实例语义，报文键名以金蝶接口文档为准（尚未在真实帐套验证）。
     *
     * @param  string  $formId  单据对象标识（formid）
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
     */
    public function workflowAudit(string $formId, array $payload): string
    {
        return $this->writeBillOperation(Endpoints::WORKFLOW_AUDIT, $formId, $payload);
    }

    /**
     * 自定义操作（变更、关闭、重新审核等，写操作，不自动重试）
     *
     * 与其它单据操作不同：报文由 SDK 直接组装为 {formid, opNumber, data}。
     * 常见的「按内码更新单据」也走这个端点（如 opNumber='ReAudit'）。
     *
     * @param  string  $formId  单据对象标识（formid），如 'SAL_SaleOrder'
     * @param  string  $opNumber  操作编号，如 'ReAudit'（变更/关闭/重新审核等）
     * @param  array<string, mixed>  $payload  业务参数（data 节点内容，如 Model 单据体）
     *
     * @return string 响应体原文
     *
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException 登录失败或会话失效
     * @throws \Trappistes\Kingdee\K3Cloud\Exception\TransportException 网络故障、超时或服务端返回错误状态
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
