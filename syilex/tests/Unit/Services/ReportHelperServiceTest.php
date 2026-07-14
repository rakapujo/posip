<?php

namespace Tests\Unit\Services;

use App\Services\ReportHelperService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ReportHelperServiceTest extends TestCase
{
    #[Test]
    public function parse_date_range_appends_end_of_day_to_date_to(): void
    {
        $request = Request::create('/test', 'GET', [
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
        ]);

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);

        $this->assertEquals('2026-04-01', $dateFrom);
        $this->assertEquals('2026-04-30 23:59:59', $dateToEnd);
    }
    #[Test]
    public function parse_date_range_same_day_menghasilkan_rentang_penuh_satu_hari(): void
    {
        // date_from == date_to → batas bawah date saja, batas atas 23:59:59 (inklusif penuh)
        $request = Request::create('/test', 'GET', [
            'date_from' => '2026-04-15',
            'date_to' => '2026-04-15',
        ]);

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);

        $this->assertSame('2026-04-15', $dateFrom);
        $this->assertSame('2026-04-15 23:59:59', $dateToEnd);
    }
    #[Test]
    public function parse_date_range_tidak_menyentuh_date_from(): void
    {
        // date_from dikembalikan apa adanya (tanpa 00:00:00) — kontrak service
        $request = Request::create('/test', 'GET', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]);

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);

        $this->assertSame('2026-01-01', $dateFrom);
        $this->assertSame('2026-12-31 23:59:59', $dateToEnd);
    }
    #[Test]
    public function parse_date_range_dengan_date_to_null_tetap_menempel_suffix(): void
    {
        // date_to absen → '' . ' 23:59:59' = ' 23:59:59' (date_from null juga)
        $request = Request::create('/test', 'GET', []);

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);

        $this->assertNull($dateFrom);
        $this->assertSame(' 23:59:59', $dateToEnd);
    }
    #[Test]
    public function apply_sort_whitelist_uses_requested_field_when_valid(): void
    {
        $request = Request::create('/test', 'GET', [
            'sort_field' => 'nama_supplier',
            'sort_order' => 'asc',
        ]);

        $query = $this->makeMockQuery();
        ReportHelperService::applySortWhitelist(
            $query,
            $request,
            ['nama_supplier', 'kode_supplier'],
            'created_at'
        );

        $this->assertCount(1, $query->orderByCalls);
        $this->assertEquals(['nama_supplier', 'asc'], $query->orderByCalls[0]);
    }
    #[Test]
    public function apply_sort_whitelist_uses_default_when_field_invalid(): void
    {
        $request = Request::create('/test', 'GET', [
            'sort_field' => 'malicious_field; DROP TABLE users;--',
            'sort_order' => 'desc',
        ]);

        $query = $this->makeMockQuery();
        ReportHelperService::applySortWhitelist(
            $query,
            $request,
            ['nama_supplier'],
            'created_at'
        );

        $this->assertCount(1, $query->orderByCalls);
        $this->assertEquals(['created_at', 'desc'], $query->orderByCalls[0]);
    }
    #[Test]
    public function apply_sort_whitelist_defaults_to_desc_when_order_not_provided(): void
    {
        $request = Request::create('/test', 'GET', [
            'sort_field' => 'nama_supplier',
        ]);

        $query = $this->makeMockQuery();
        ReportHelperService::applySortWhitelist(
            $query,
            $request,
            ['nama_supplier'],
            'created_at'
        );

        $this->assertEquals(['nama_supplier', 'desc'], $query->orderByCalls[0]);
    }
    #[Test]
    public function apply_sort_whitelist_sanitizes_invalid_order_direction(): void
    {
        $request = Request::create('/test', 'GET', [
            'sort_field' => 'nama_supplier',
            'sort_order' => 'ascending; DROP TABLE users;--',
        ]);

        $query = $this->makeMockQuery();
        ReportHelperService::applySortWhitelist(
            $query,
            $request,
            ['nama_supplier'],
            'created_at'
        );

        // Invalid order → fallback ke 'desc' (bukan string user-controlled)
        $this->assertEquals(['nama_supplier', 'desc'], $query->orderByCalls[0]);
    }
    #[Test]
    public function build_paginated_response_formats_standard_structure(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [['id' => 1], ['id' => 2]],
            total: 50,
            perPage: 10,
            currentPage: 2
        );
        $summary = ['total' => 100000];

        $response = ReportHelperService::buildPaginatedResponse($paginator, $summary);

        $this->assertArrayHasKey('items', $response);
        $this->assertArrayHasKey('pagination', $response);
        $this->assertArrayHasKey('summary', $response);

        $this->assertEquals([['id' => 1], ['id' => 2]], $response['items']);
        $this->assertEquals([
            'current_page' => 2,
            'last_page' => 5,
            'per_page' => 10,
            'total' => 50,
        ], $response['pagination']);
        $this->assertEquals($summary, $response['summary']);
    }
    #[Test]
    public function build_paginated_response_merges_extras(): void
    {
        $paginator = new LengthAwarePaginator([], 0, 10, 1);

        $response = ReportHelperService::buildPaginatedResponse(
            $paginator,
            ['jumlah_po' => 0],
            ['can_view_harga' => true, 'additional_key' => 'value']
        );

        $this->assertTrue($response['can_view_harga']);
        $this->assertEquals('value', $response['additional_key']);
        $this->assertEquals(['jumlah_po' => 0], $response['summary']);
    }
    #[Test]
    public function date_range_rules_contain_required_fields(): void
    {
        $rules = ReportHelperService::dateRangeRules();

        $this->assertArrayHasKey('date_from', $rules);
        $this->assertArrayHasKey('date_to', $rules);
        $this->assertStringContainsString('required', $rules['date_from']);
        $this->assertStringContainsString('after_or_equal:date_from', $rules['date_to']);
    }
    #[Test]
    public function apply_sort_whitelist_uppercase_asc_jatuh_ke_default_order(): void
    {
        // Perbandingan '=== asc' case-sensitive → 'ASC' bukan 'asc' → dir = 'desc'
        $request = Request::create('/test', 'GET', [
            'sort_field' => 'nama_supplier',
            'sort_order' => 'ASC',
        ]);

        $query = $this->makeMockQuery();
        ReportHelperService::applySortWhitelist($query, $request, ['nama_supplier'], 'created_at');

        $this->assertEquals(['nama_supplier', 'desc'], $query->orderByCalls[0]);
    }
    #[Test]
    public function apply_sort_whitelist_field_invalid_pakai_default_order_param(): void
    {
        // Field invalid → pakai $defaultField + $defaultOrder (param, default 'desc').
        // Set defaultOrder eksplisit 'asc' untuk pastikan cabang else memakai param ini.
        $request = Request::create('/test', 'GET', [
            'sort_field' => 'kolom_tidak_ada',
            'sort_order' => 'asc',
        ]);

        $query = $this->makeMockQuery();
        ReportHelperService::applySortWhitelist($query, $request, ['nama_supplier'], 'created_at', 'asc');

        // Cabang else: orderBy(defaultField, defaultOrder) — bukan $dir hasil request
        $this->assertEquals(['created_at', 'asc'], $query->orderByCalls[0]);
    }
    #[Test]
    public function build_paginated_response_halaman_kosong_eksak(): void
    {
        $paginator = new LengthAwarePaginator(items: [], total: 0, perPage: 25, currentPage: 1);

        $response = ReportHelperService::buildPaginatedResponse($paginator);

        $this->assertSame([], $response['items']);
        $this->assertEquals([
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 25,
            'total' => 0,
        ], $response['pagination']);
        $this->assertSame([], $response['summary']);
    }
    #[Test]
    public function build_paginated_response_extras_tidak_menimpa_kunci_inti(): void
    {
        // Kontrak array_merge: extras digabung TERAKHIR, jadi bisa override 'summary'.
        // Verifikasi perilaku eksak (bukan asumsi).
        $paginator = new LengthAwarePaginator([], 0, 10, 1);

        $response = ReportHelperService::buildPaginatedResponse(
            $paginator,
            ['total' => 999],
            ['summary' => ['override' => true]]
        );

        $this->assertSame(['override' => true], $response['summary'], 'extras menimpa summary (array_merge order)');
    }
    #[Test]
    public function resolve_source_mengembalikan_po_atau_serial_jika_valid(): void
    {
        $this->assertSame('po', ReportHelperService::resolveSource(
            Request::create('/test', 'GET', ['source' => 'po'])
        ));
        $this->assertSame('serial', ReportHelperService::resolveSource(
            Request::create('/test', 'GET', ['source' => 'serial'])
        ));
    }
    #[Test]
    public function resolve_source_default_all_untuk_nilai_tidak_dikenal_atau_kosong(): void
    {
        $this->assertSame('all', ReportHelperService::resolveSource(
            Request::create('/test', 'GET', ['source' => 'all'])
        ));
        $this->assertSame('all', ReportHelperService::resolveSource(
            Request::create('/test', 'GET', ['source' => 'hacker; DROP TABLE'])
        ));
        $this->assertSame('all', ReportHelperService::resolveSource(
            Request::create('/test', 'GET', [])
        ));
    }

    #[Test]
    public function sales_receipt_qty_sql_embeds_trusted_column_ref(): void
    {
        $bought = ReportHelperService::sqlSalesBoughtBase('ds.id');
        $returned = ReportHelperService::sqlSalesReturnedBase('doc_sales.id');

        $this->assertStringContainsString('doc_sales_detail', $bought);
        $this->assertStringContainsString('d.sales_id = ds.id', $bought);
        $this->assertStringContainsString('doc_sales_return_detail', $returned);
        $this->assertStringContainsString('r.sales_id = doc_sales.id', $returned);
        $this->assertStringContainsString('qty_base', $bought);
        $this->assertStringContainsString('qty_base', $returned);
    }

    /**
     * Create mock query object yang track orderBy() calls.
     */
    private function makeMockQuery(): object
    {
        return new class {
            public array $orderByCalls = [];

            public function orderBy(string $field, string $direction): self
            {
                $this->orderByCalls[] = [$field, $direction];
                return $this;
            }
        };
    }
}
