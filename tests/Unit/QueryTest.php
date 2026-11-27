<?php

declare(strict_types=1);

namespace Trappistes\Kingdee\K3Cloud\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trappistes\Kingdee\K3Cloud\Exception\ConfigurationException;
use Trappistes\Kingdee\K3Cloud\OrgFields;
use Trappistes\Kingdee\K3Cloud\Query;

/**
 * 查询构造器测试：组织过滤是核心职责
 */
final class QueryTest extends TestCase
{
    public function test_from_array_keeps_filter_string_intact(): void
    {
        $filter = "FName='a and b'";

        $query = Query::fromArray([
            'FormId' => 'BD_MATERIAL',
            'FieldKeys' => 'FNumber,FName',
            'FilterString' => $filter,
        ]);

        // 值中含 ' and ' 时不得被拆坏，且 toArray/fromArray 互逆
        self::assertSame($filter, $query->toArray()['FilterString']);
    }

    public function test_filters_are_combined_with_and(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')
            ->filter("FModifyDate >= '2026-01-01T00:00:00.000'")
            ->filter("FForbidStatus='A'");

        self::assertSame(
            "FModifyDate >= '2026-01-01T00:00:00.000' and FForbidStatus='A'",
            $query->toArray()['FilterString'],
        );
    }

    public function test_where_equals_and_where_in_are_generic_primitives(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')
            ->whereEquals('FForbidStatus', 'A')
            ->whereIn('FUseOrgId.FNumber', ['101', '102']);

        self::assertSame(
            "FForbidStatus='A' and FUseOrgId.FNumber in('101','102')",
            $query->toArray()['FilterString'],
        );
    }

    public function test_where_org_builds_kingdee_org_condition(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')->whereOrg(OrgFields::USE, '101');

        self::assertSame("FUseOrgId.FNumber in('101')", $query->toArray()['FilterString']);
    }

    public function test_where_org_supports_multiple_orgs(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')->whereOrg(OrgFields::PURCHASE, ['101', '102']);

        self::assertSame("FPurchaseOrgId.FNumber in('101','102')", $query->toArray()['FilterString']);
    }

    public function test_where_in_quotes_every_value_separately(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')
            ->whereIn('FNumber', ['A001', 'B002', 'C003']);

        self::assertSame("FNumber in('A001','B002','C003')", $query->toArray()['FilterString']);
    }

    public function test_where_equals_escapes_single_quote_in_value(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')
            ->whereEquals('FName', "O'Brien");

        self::assertSame("FName='O''Brien'", $query->toArray()['FilterString']);
    }

    public function test_where_in_escapes_single_quote_in_value(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')
            ->whereIn('FName', ["O'Brien"]);

        self::assertSame("FName in('O''Brien')", $query->toArray()['FilterString']);
    }

    public function test_where_in_keeps_comma_inside_value(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')
            ->whereIn('FName', ['A,B']);

        self::assertSame("FName in('A,B')", $query->toArray()['FilterString']);
    }

    public function test_where_org_rejects_empty_org_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Query::make('BD_MATERIAL')->fields('FNumber')->whereOrg(OrgFields::USE, []);
    }

    public function test_missing_form_id_throws_before_request(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('FormId');

        Query::make('')->fields('FNumber')->toArray();
    }

    public function test_missing_fields_throws_before_request(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('FieldKeys');

        Query::make('BD_MATERIAL')->toArray();
    }

    public function test_org_fields_expose_known_dimensions(): void
    {
        self::assertSame('FUseOrgId', OrgFields::USE);
        self::assertSame('FPurchaseOrgId', OrgFields::PURCHASE);
        self::assertSame('FSaleOrgId', OrgFields::SALE);
        self::assertSame(['USE', 'PURCHASE', 'SALE'], array_keys(OrgFields::all()));
    }

    public function test_where_in_with_empty_values_is_ignored(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')->whereIn('FNumber', []);

        self::assertSame('', $query->toArray()['FilterString']);
    }

    public function test_only_replaces_existing_filters(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')
            ->filter("FForbidStatus='A'")
            ->only("FNumber='01000004'");

        self::assertSame("FNumber='01000004'", $query->toArray()['FilterString']);
    }

    public function test_page_sets_start_row_and_limit(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')->page(3, 100);

        self::assertSame(200, $query->startRow());
        self::assertSame(100, $query->limit());
        self::assertSame(200, $query->toArray()['StartRow']);
    }

    public function test_limit_is_capped_at_2000(): void
    {
        $query = Query::make('BD_MATERIAL')->fields('FNumber')->perPage(9999);

        self::assertSame(2000, $query->limit());
    }

    public function test_copy_is_independent(): void
    {
        $base = Query::make('BD_MATERIAL')->fields('FNumber')->filter("FForbidStatus='A'");
        $copy = $base->copy()->only("FNumber='X'");

        self::assertSame("FForbidStatus='A'", $base->toArray()['FilterString']);
        self::assertSame("FNumber='X'", $copy->toArray()['FilterString']);
        self::assertSame('BD_MATERIAL', $copy->formId());
    }

    public function test_fields_accepts_string_or_array(): void
    {
        self::assertSame('FNumber,FName', Query::make('X')->fields('FNumber, FName')->toArray()['FieldKeys']);
        self::assertSame('FNumber,FName', Query::make('X')->fields(['FNumber', ' FName '])->toArray()['FieldKeys']);
    }
}
