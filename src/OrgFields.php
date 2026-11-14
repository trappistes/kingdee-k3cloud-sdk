<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud;

/**
 * 金蝶组织维度字段
 *
 * 金蝶是典型的多组织 ERP：同一单据可能带多个组织维度，
 * 不同单据使用不同的组织字段。SDK 负责建模这些维度（协议事实），
 * 具体使用哪个组织、同步哪些组织，由调用方按业务口径决定。
 */
final class OrgFields
{
    /**
     * 使用组织（物料、客户、供应商等基础资料）
     */
    public const USE = 'FUseOrgId';

    /**
     * 采购组织（采购订单、入库单）
     */
    public const PURCHASE = 'FPurchaseOrgId';

    /**
     * 销售组织（销售订单、出库单）
     */
    public const SALE = 'FSaleOrgId';

    /**
     * 全部组织维度
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'USE' => self::USE,
            'PURCHASE' => self::PURCHASE,
            'SALE' => self::SALE,
        ];
    }
}
