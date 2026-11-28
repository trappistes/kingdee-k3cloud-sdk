<?php

declare(strict_types = 1);

namespace Trappistes\Kingdee\K3Cloud;

/**
 * 金蝶开放平台接口端点
 *
 * 路径与金蝶官方 WebAPI 规范一致（与 MIT 许可的 kingdee-php/k3cloud 等价）。
 *
 * 职责边界：
 *  - 这里只描述「哪个金蝶接口」，不含任何请求参数或业务语义；
 *  - 写端点清单（WRITE_ENDPOINTS）是**安全约束**，不是优化建议：
 *    写操作不幂等，异常后重试会产生重复单据/消息/附件。
 *    Client 依据本表决定请求策略，新增写端点必须同步登记。
 *  - 写清单与读清单（READ_ENDPOINTS）构成**完整分类**：
 *    每个端点常量必须恰好归类为写或读，由契约测试守住。
 *    未登记的端点按不重试处理（Client::request() 走安全侧）。
 */
final class Endpoints
{
    /**
     * 登录（用户名 + 密码）：AuthService.ValidateUser
     *
     * 金蝶认证服务 AuthService 的用户校验接口，路径后缀 .common.kdsvc。
     *
     * @var string
     */
    public const LOGIN = 'Kingdee.BOS.WebApi.ServicesStub.AuthService.ValidateUser.common.kdsvc';

    /**
     * 登录（第三方授权应用ID + 应用密钥）：AuthService.LoginByAppSecret
     *
     * 金蝶认证服务的第三方应用登录接口。
     *
     * @var string
     */
    public const LOGIN_BY_APP_SECRET = 'Kingdee.BOS.WebApi.ServicesStub.AuthService.LoginByAppSecret.common.kdsvc';

    /**
     * 获取数据中心列表：AccountService.GetDataCenterList
     *
     * 账号服务 AccountService 的接口，用于查询可用数据中心（多数据中心部署时定位账套）。
     *
     * @var string
     */
    public const GET_DATA_CENTER_LIST = 'Kingdee.BOS.ServiceFacade.ServicesStub.Account.AccountService.GetDataCenterList.common.kdsvc';

    /**
     * 单据查询：DynamicFormService.BillQuery
     *
     * 动态表单服务的单据查询接口，返回扁平行数组（适合导出/对账类批量取数）。
     *
     * @var string
     */
    public const BILL_QUERY = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.BillQuery.common.kdsvc';

    /**
     * 执行式单据查询：DynamicFormService.ExecuteBillQuery
     *
     * 动态表单服务的执行式查询接口，响应为**信封**（含 ResponseStatus 等元信息），
     * 数据行位于 `Result.BillQueryResult`。
     * SDK 同时兼容顶层数组形态（见 Response::resultRows()）。
     *
     * 注意：响应信封形态尚未在真实帐套复核，键名不符时会抛异常而非静默返回空数组。
     *
     * @var string
     */
    public const EXECUTE_BILL_QUERY = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.ExecuteBillQuery.common.kdsvc';

    /**
     * 元数据查询：DynamicFormService.QueryBusinessInfo
     *
     * 动态表单服务的业务对象元数据查询接口，用于获取单据的字段定义。
     *
     * @var string
     */
    public const QUERY_BUSINESS_INFO = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.QueryBusinessInfo.common.kdsvc';

    /**
     * 查看单据：DynamicFormService.view
     *
     * 动态表单服务的单据查看接口，按单据标识读取完整单据内容。
     *
     * @var string
     */
    public const VIEW = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.view.common.kdsvc';

    /**
     * 保存：DynamicFormService.save
     *
     * 动态表单服务的单据保存接口（新增/修改），会改变服务端状态。
     *
     * @var string
     */
    public const SAVE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.save.common.kdsvc';

    /**
     * 批量保存：DynamicFormService.batchSave
     *
     * 动态表单服务的批量保存接口，一次提交多张单据。
     *
     * @var string
     */
    public const BATCH_SAVE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.batchSave.common.kdsvc';

    /**
     * 提交：DynamicFormService.Submit
     *
     * 动态表单服务的提交接口，将单据从草稿态提交为正式流程状态。
     *
     * @var string
     */
    public const SUBMIT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Submit.common.kdsvc';

    /**
     * 审核：DynamicFormService.Audit
     *
     * 动态表单服务的审核接口。
     *
     * @var string
     */
    public const AUDIT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Audit.common.kdsvc';

    /**
     * 反审核：DynamicFormService.UnAudit
     *
     * 动态表单服务的反审核接口，撤销已审核单据的审核状态。
     *
     * @var string
     */
    public const UNAUDIT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.UnAudit.common.kdsvc';

    /**
     * 暂存：DynamicFormService.draft
     *
     * 动态表单服务的暂存接口，保存为草稿态而不进入正式流程。
     *
     * @var string
     */
    public const DRAFT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.draft.common.kdsvc';

    /**
     * 下推：DynamicFormService.Push
     *
     * 动态表单服务的下推接口，由上游单据生成下游单据。
     *
     * @var string
     */
    public const PUSH = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Push.common.kdsvc';

    /**
     * 删除：DynamicFormService.delete
     *
     * 动态表单服务的删除接口，删除指定单据。
     *
     * @var string
     */
    public const DELETE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.delete.common.kdsvc';

    /**
     * 分配：DynamicFormService.Allocate
     *
     * 动态表单服务的分配接口（如把单据分配给下级组织/用户）。
     *
     * @var string
     */
    public const ALLOCATE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Allocate.common.kdsvc';

    /**
     * 弹性域保存：DynamicFormService.FlexSave
     *
     * 动态表单服务的弹性域保存接口，写入单据的弹性域（自定义扩展字段）值。
     *
     * @var string
     */
    public const FLEX_SAVE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.FlexSave.common.kdsvc';

    /**
     * 分组保存：DynamicFormService.GroupSave
     *
     * 动态表单服务的分组保存接口，维护单据的分组关系。
     *
     * @var string
     */
    public const GROUP_SAVE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.GroupSave.common.kdsvc';

    /**
     * 分组删除：DynamicFormService.GroupDelete
     *
     * 动态表单服务的分组删除接口，解除单据的分组关系。
     *
     * @var string
     */
    public const GROUP_DELETE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.GroupDelete.common.kdsvc';

    /**
     * 拆单：DynamicFormService.Disassembly
     *
     * 动态表单服务的拆单接口，把一张单据拆分为多张。
     *
     * @var string
     */
    public const DISASSEMBLY = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Disassembly.common.kdsvc';

    /**
     * 自定义操作（变更、关闭、重新审核等）：DynamicFormService.ExcuteOperation
     *
     * 动态表单服务的自定义操作接口，用 OperateType 区分变更/关闭/重新审核等动作。
     *
     * @var string
     */
    public const OPERATION = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.ExcuteOperation.common.kdsvc';

    /**
     * 工作流审核：DynamicFormService.WorkflowAudit
     *
     * 动态表单服务的工作流审核接口，走工作流引擎而非单据直接审核。
     *
     * @var string
     */
    public const WORKFLOW_AUDIT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.WorkflowAudit.common.kdsvc';

    /**
     * 发送消息：DynamicFormService.SendMsg
     *
     * 动态表单服务的消息发送接口，向金蝶用户推送消息。
     *
     * @var string
     */
    public const SEND_MSG = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.SendMsg.common.kdsvc';

    /**
     * 查询分组信息：DynamicFormService.QueryGroupInfo
     *
     * 动态表单服务的分组信息查询接口。
     *
     * @var string
     */
    public const QUERY_GROUP_INFO = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.QueryGroupInfo.common.kdsvc';

    /**
     * 附件上传：DynamicFormService.AttachmentUpLoad
     *
     * 动态表单服务的附件上传接口，请求体中携带 Base64 编码的二进制内容。
     *
     * @var string
     */
    public const ATTACHMENT_UPLOAD = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.AttachmentUpLoad.common.kdsvc';

    /**
     * 会改变服务端状态的端点（写操作）
     *
     * 这些端点不幂等：网络异常后重试可能产生重复单据、重复消息或冗余附件。
     * SDK 依据这张表决定请求策略，不依赖调用方自觉。
     *
     * 注意：这里的每个常量都是端点字符串，键为 0 起的连续索引。
     *
     * @var array<int, string>
     */
    public const WRITE_ENDPOINTS = [
        self::SAVE,
        self::BATCH_SAVE,
        self::SUBMIT,
        self::AUDIT,
        self::UNAUDIT,
        self::DRAFT,
        self::PUSH,
        self::DELETE,
        self::ALLOCATE,
        self::FLEX_SAVE,
        self::GROUP_SAVE,
        self::GROUP_DELETE,
        self::DISASSEMBLY,
        self::OPERATION,
        self::WORKFLOW_AUDIT,
        self::SEND_MSG,
        self::ATTACHMENT_UPLOAD,
    ];

    /**
     * 只读端点（幂等，可重试）
     *
     * 与 WRITE_ENDPOINTS 构成**完整分类**：本类的每个端点常量必须恰好落在
     * 其中之一（由契约测试守住）。
     *
     * 未登记的端点既不是读也不是写——SDK 无法判断它是否会改变服务端状态，
     * 因此按不重试处理（见 Client::request()）。
     *
     * 登录类端点归入读：登录重试是安全的（SDK 本就重试登录阶段的瞬时故障）。
     *
     * @var array<int, string>
     */
    public const READ_ENDPOINTS = [
        self::LOGIN,
        self::LOGIN_BY_APP_SECRET,
        self::GET_DATA_CENTER_LIST,
        self::BILL_QUERY,
        self::EXECUTE_BILL_QUERY,
        self::QUERY_BUSINESS_INFO,
        self::VIEW,
        self::QUERY_GROUP_INFO,
    ];

    /**
     * 是否为写端点
     *
     * 已知写端点则禁止可重试策略；未登记端点返回 false，
     * 由调用方按「性质未知」处理（Client::request() 走安全侧）。
     *
     * @param  string  $endpoint  端点字符串，如 Endpoints::SAVE
     *
     * @return bool true 表示该端点会改变服务端状态（不可重试）
     */
    public static function isWrite(string $endpoint): bool
    {
        return in_array($endpoint, self::WRITE_ENDPOINTS, true);
    }

    /**
     * 是否为只读端点
     *
     * @param  string  $endpoint  端点字符串，如 Endpoints::BILL_QUERY
     *
     * @return bool true 表示该端点是已知只读端点（幂等，可重试）
     */
    public static function isRead(string $endpoint): bool
    {
        return in_array($endpoint, self::READ_ENDPOINTS, true);
    }
}
