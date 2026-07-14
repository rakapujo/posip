<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 4 — laporan analitik Sprint 1–3: permission matrix HTTP (23 endpoints).
 * Pola mirror PembelianAccessCoverageTest + SalesReportCoverageTest.
 */
class LaporanAnalitikAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = 'date_from=2026-01-01&date_to=2026-12-31';

    /** @var list<string> */
    private static array $allPermissions = [
        'laporan.view',
        'laporan.keuangan',
        'laporan.performa',
        'laporan.promo',
        'laporan.inventory',
        'stok.view_hpp',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::$allPermissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    /**
     * @return array<string, array{uri: string, permission: string, extra?: list<string>}>
     */
    public static function analitikEndpointsProvider(): array
    {
        $promoUlid = '01JAAAAAAAAAAAAAAAAAAAAAAA';
        $customerUlid = '01JBBBBBBBBBBBBBBBBBBBBBBB';

        return [
            'gross-profit summary' => [
                'uri' => '/api/v1/reports/gross-profit/summary?'.self::PERIOD,
                'permission' => 'laporan.keuangan',
                'extra' => ['stok.view_hpp'],
            ],
            'gross-profit daily' => [
                'uri' => '/api/v1/reports/gross-profit/daily?'.self::PERIOD,
                'permission' => 'laporan.keuangan',
                'extra' => ['stok.view_hpp'],
            ],
            'gross-profit by-kategori' => [
                'uri' => '/api/v1/reports/gross-profit/by-kategori?'.self::PERIOD,
                'permission' => 'laporan.keuangan',
                'extra' => ['stok.view_hpp'],
            ],
            'gross-profit top-products' => [
                'uri' => '/api/v1/reports/gross-profit/top-products?'.self::PERIOD,
                'permission' => 'laporan.keuangan',
                'extra' => ['stok.view_hpp'],
            ],
            'margin-per-barang summary' => [
                'uri' => '/api/v1/reports/margin-per-barang/summary',
                'permission' => 'laporan.keuangan',
                'extra' => ['stok.view_hpp'],
            ],
            'margin-per-barang index' => [
                'uri' => '/api/v1/reports/margin-per-barang',
                'permission' => 'laporan.keuangan',
                'extra' => ['stok.view_hpp'],
            ],
            'cash-flow summary' => [
                'uri' => '/api/v1/reports/cash-flow/summary?'.self::PERIOD,
                'permission' => 'laporan.keuangan',
            ],
            'cash-flow daily' => [
                'uri' => '/api/v1/reports/cash-flow/daily?'.self::PERIOD,
                'permission' => 'laporan.keuangan',
            ],
            'kasir-performance' => [
                'uri' => '/api/v1/reports/kasir-performance?'.self::PERIOD,
                'permission' => 'laporan.performa',
            ],
            'promo-usage summary' => [
                'uri' => '/api/v1/reports/promo-usage/summary?'.self::PERIOD,
                'permission' => 'laporan.promo',
            ],
            'promo-usage index' => [
                'uri' => '/api/v1/reports/promo-usage?'.self::PERIOD,
                'permission' => 'laporan.promo',
            ],
            'promo-usage show' => [
                'uri' => "/api/v1/reports/promo-usage/{$promoUlid}?".self::PERIOD,
                'permission' => 'laporan.promo',
            ],
            'product-promo by-product' => [
                'uri' => '/api/v1/reports/product-promo/by-product?'.self::PERIOD,
                'permission' => 'laporan.promo',
            ],
            'product-promo by-promo' => [
                'uri' => '/api/v1/reports/product-promo/by-promo?'.self::PERIOD,
                'permission' => 'laporan.promo',
            ],
            'customer-promo summary' => [
                'uri' => '/api/v1/reports/customer-promo/summary',
                'permission' => 'laporan.promo',
            ],
            'customer-promo by-tipe' => [
                'uri' => '/api/v1/reports/customer-promo/by-tipe',
                'permission' => 'laporan.promo',
            ],
            'customer-promo by-kategori' => [
                'uri' => '/api/v1/reports/customer-promo/by-kategori',
                'permission' => 'laporan.promo',
            ],
            'customer-promo by-customer' => [
                'uri' => '/api/v1/reports/customer-promo/by-customer',
                'permission' => 'laporan.promo',
            ],
            'customer-promo show-customer' => [
                'uri' => "/api/v1/reports/customer-promo/customer/{$customerUlid}",
                'permission' => 'laporan.promo',
            ],
            'payment-method breakdown' => [
                'uri' => '/api/v1/reports/payment-method/breakdown?'.self::PERIOD,
                'permission' => 'laporan.performa',
            ],
            'customer top' => [
                'uri' => '/api/v1/reports/customer/top?'.self::PERIOD,
                'permission' => 'laporan.performa',
            ],
            'retur pattern' => [
                'uri' => '/api/v1/reports/retur/pattern?'.self::PERIOD,
                'permission' => 'laporan.inventory',
            ],
            'dead-stock' => [
                'uri' => '/api/v1/reports/inventory/dead-stock',
                'permission' => 'laporan.inventory',
            ],
        ];
    }

    #[DataProvider('analitikEndpointsProvider')]
    public function test_forbidden_without_category_permission(string $uri, string $permission, array $extra = []): void
    {
        $wrongPerm = match ($permission) {
            'laporan.keuangan' => 'laporan.performa',
            'laporan.performa' => 'laporan.promo',
            'laporan.promo' => 'laporan.inventory',
            default => 'laporan.keuangan',
        };

        $user = User::factory()->create();
        $user->givePermissionTo($wrongPerm);

        $this->actingAs($user)
            ->getJson($uri)
            ->assertForbidden();
    }

    #[DataProvider('analitikEndpointsProvider')]
    public function test_allowed_with_correct_permission(string $uri, string $permission, array $extra = []): void
    {
        $perms = array_merge([$permission], $extra);
        $user = User::factory()->create();
        $user->givePermissionTo($perms);

        $response = $this->actingAs($user)->getJson($uri);

        $this->assertTrue(
            in_array($response->status(), [200, 404], true),
            "Expected 200 or 404 for {$uri}, got {$response->status()}"
        );
    }

    public function test_laporan_view_does_not_grant_analitik_access(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.view');

        foreach (self::analitikEndpointsProvider() as $case) {
            $this->actingAs($user)
                ->getJson($case['uri'])
                ->assertForbidden();
        }
    }

    public function test_unauthenticated_denied(): void
    {
        $this->getJson('/api/v1/reports/gross-profit/summary?'.self::PERIOD)
            ->assertUnauthorized();
    }

    public function test_gross_profit_all_endpoints_require_stok_view_hpp(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.keuangan');

        foreach ([
            '/api/v1/reports/gross-profit/summary?'.self::PERIOD,
            '/api/v1/reports/gross-profit/daily?'.self::PERIOD,
            '/api/v1/reports/gross-profit/by-kategori?'.self::PERIOD,
            '/api/v1/reports/gross-profit/top-products?'.self::PERIOD,
        ] as $uri) {
            $this->actingAs($user)
                ->getJson($uri)
                ->assertForbidden();
        }
    }

    public function test_margin_per_barang_requires_stok_view_hpp(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.keuangan');

        foreach ([
            '/api/v1/reports/margin-per-barang/summary',
            '/api/v1/reports/margin-per-barang',
        ] as $uri) {
            $this->actingAs($user)
                ->getJson($uri)
                ->assertForbidden();
        }
    }

    public function test_cash_flow_ok_with_keuangan_only_without_hpp(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.keuangan');

        $this->actingAs($user)
            ->getJson('/api/v1/reports/cash-flow/summary?'.self::PERIOD)
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/v1/reports/cash-flow/daily?'.self::PERIOD)
            ->assertOk();
    }

    public function test_dead_stock_ok_without_hpp_but_strips_value_fields(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.inventory');

        $data = $this->actingAs($user)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['can_view_hpp']);
        $this->assertNull($data['total_value']);
    }

    public function test_promo_user_cannot_access_performa_endpoint(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.promo');

        $this->actingAs($user)
            ->getJson('/api/v1/reports/kasir-performance?'.self::PERIOD)
            ->assertForbidden();
    }

    public function test_inventory_user_cannot_access_promo_endpoint(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.inventory');

        $this->actingAs($user)
            ->getJson('/api/v1/reports/promo-usage/summary?'.self::PERIOD)
            ->assertForbidden();
    }
}
