<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud;

/**
 * 金蝶开放平台接口端点
 *
 * 路径与金蝶官方 WebAPI 规范一致（与 MIT 许可的 kingdee-php/k3cloud 等价）。
 */
final class Endpoints
{
    /**
     * 登录（用户名 + 密码）
     */
    public const LOGIN = 'Kingdee.BOS.WebApi.ServicesStub.AuthService.ValidateUser.common.kdsvc';

    /**
     * 登录（第三方授权应用ID + 应用密钥）
     */
    public const LOGIN_BY_APP_SECRET = 'Kingdee.BOS.WebApi.ServicesStub.AuthService.LoginByAppSecret.common.kdsvc';

    /**
     * 获取数据中心列表
     */
    public const GET_DATA_CENTER_LIST = 'Kingdee.BOS.ServiceFacade.ServicesStub.Account.AccountService.GetDataCenterList.common.kdsvc';

    /**
     * 单据查询（返回扁平行数组）
     */
    public const BILL_QUERY = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.BillQuery.common.kdsvc';

    /**
     * 执行式单据查询（返回信封结构）
     */
    public const EXECUTE_BILL_QUERY = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.ExecuteBillQuery.common.kdsvc';

    /**
     * 元数据查询
     */
    public const QUERY_BUSINESS_INFO = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.QueryBusinessInfo.common.kdsvc';

    /**
     * 查看单据
     */
    public const VIEW = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.view.common.kdsvc';

    /**
     * 保存
     */
    public const SAVE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.save.common.kdsvc';

    /**
     * 批量保存
     */
    public const BATCH_SAVE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.batchSave.common.kdsvc';

    /**
     * 提交
     */
    public const SUBMIT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Submit.common.kdsvc';

    /**
     * 审核
     */
    public const AUDIT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Audit.common.kdsvc';

    /**
     * 反审核
     */
    public const UNAUDIT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.UnAudit.common.kdsvc';

    /**
     * 暂存
     */
    public const DRAFT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.draft.common.kdsvc';

    /**
     * 下推
     */
    public const PUSH = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Push.common.kdsvc';

    /**
     * 删除
     */
    public const DELETE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.delete.common.kdsvc';

    /**
     * 分配
     */
    public const ALLOCATE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Allocate.common.kdsvc';

    /**
     * 弹性域保存
     */
    public const FLEX_SAVE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.FlexSave.common.kdsvc';

    /**
     * 分组保存
     */
    public const GROUP_SAVE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.GroupSave.common.kdsvc';

    /**
     * 分组删除
     */
    public const GROUP_DELETE = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.GroupDelete.common.kdsvc';

    /**
     * 拆单
     */
    public const DISASSEMBLY = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Disassembly.common.kdsvc';

    /**
     * 自定义操作（变更、关闭、重新审核等）
     */
    public const OPERATION = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.ExcuteOperation.common.kdsvc';

    /**
     * 工作流审核
     */
    public const WORKFLOW_AUDIT = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.WorkflowAudit.common.kdsvc';

    /**
     * 发送消息
     */
    public const SEND_MSG = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.SendMsg.common.kdsvc';

    /**
     * 查询分组信息
     */
    public const QUERY_GROUP_INFO = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.QueryGroupInfo.common.kdsvc';

    /**
     * 附件上传
     */
    public const ATTACHMENT_UPLOAD = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.AttachmentUpLoad.common.kdsvc';

    /**
     * 会改变服务端状态的端点（写操作）
     *
     * 这些端点不幂等：网络异常后重试可能产生重复单据、重复消息或冗余附件。
     * SDK 依据这张表决定请求策略，不依赖调用方自觉。
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
     * 是否为写端点
     *
     * 未知端点按读处理（保留调用方显式指定的策略），已知写端点则禁止可重试策略。
     */
    public static function isWrite(string $endpoint): bool
    {
        return in_array($endpoint, self::WRITE_ENDPOINTS, true);
    }
}
