<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud\Concerns;

use Trappistes\Kingdee\K3Cloud\Endpoints;
use Trappistes\Kingdee\K3Cloud\Exception\AuthenticationException;
use Trappistes\Kingdee\K3Cloud\Exception\TransportException;
use Trappistes\Kingdee\K3Cloud\Policy;

/**
 * HandlesPlatformOperations —— Client 的职责分组之一（仅内部组织，不改变公开 API）
 *
 * 职责：金蝶平台类接口（发送消息、查询分组信息、数据中心列表、附件上传）的对外入口。
 *
 * 关键约束：
 *  - 本 trait 中的 public 方法属于 SDK 已冻结的公开 API，只做注释层面的澄清；
 *  - 平台类报文不带 formid 节点，请求体只有 data（或直接是业务参数）；
 *  - 附件上传的报文由调用方整体提供（SDK 原样透传，不自动包裹 data），
 *    超时按 max(120, Config::timeout) 放宽，且不重试（重试会产生冗余附件）；
 *  - 平台类接口（sendMsg / queryGroupInfo / getDataCenterList）目前只验证过契约，
 *    真实帐套行为以金蝶接口文档为准。
 */
trait HandlesPlatformOperations
{
    /**
     * 发送消息（写操作，不自动重试）
     *
     * 会向真实接收人发送通知，调用前请确认接收人与测试环境。
     *
     * @param  array<string, mixed>  $payload  消息参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException 网络故障、超时或服务端返回错误状态
     */
    public function sendMsg(array $payload): string
    {
        return $this->writePlatformOperation(Endpoints::SEND_MSG, $payload);
    }

    /**
     * 查询分组信息（读操作，可重试）
     *
     * @param  array<string, mixed>  $payload  查询参数（data 节点内容）
     *
     * @return string 响应体原文
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException 网络故障、超时或服务端返回错误状态
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
     *
     * 无请求参数；能正常返回即说明连接与认证正常，可用于排查「没有指定数据中心」。
     *
     * @return string 响应体原文
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException 网络故障、超时或服务端返回错误状态
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
     * 附件靠 FormId / BillNo / InterId / EntryKey / EntryInterId 定位归属单据；
     * 文件内容以 Base64 放在 SendByte 字段。SDK 原样透传请求体（不自动包裹 data），
     * 请自行把参数整体放进 data 节点。超时取 max(120, Config::timeout) 秒。
     *
     * 金蝶没有删除附件的接口，重复上传会留下独立附件记录，请谨慎重试。
     *
     * @param  array<string, mixed>  $payload  附件参数（需整体放在 data 节点下）
     *
     * @return string 响应体原文（文件信息在 Result.FileId）
     *
     * @throws AuthenticationException 登录失败或会话失效
     * @throws TransportException 网络故障、超时或服务端返回错误状态
     */
    public function uploadAttachment(array $payload): string
    {
        return $this->request(Endpoints::ATTACHMENT_UPLOAD, $payload, Policy::attachment(
            max(120, $this->config->timeout)
        ));
    }
}
