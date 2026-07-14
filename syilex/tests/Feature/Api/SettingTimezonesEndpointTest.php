<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SettingTimezonesEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Settings endpoint requires settings.view permission
        Permission::create(['name' => 'settings.view']);
        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo('settings.view');

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);
    }
    #[Test]
    public function endpoint_returns_current_timezone_offset_and_groups()
    {
        SettingService::set('regional.timezone', 'Asia/Jakarta', 'string');

        $response = $this->getJson('/api/v1/settings/timezones');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'current',
                'offset',
                'groups' => [
                    '*' => ['region', 'timezones' => [['value', 'label', 'offset']]],
                ],
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals('Asia/Jakarta', $data['current']);
        $this->assertEquals('+07:00', $data['offset']);
    }
    #[Test]
    public function endpoint_returns_all_php_timezone_regions()
    {
        $response = $this->getJson('/api/v1/settings/timezones');

        $regions = collect($response->json('data.groups'))->pluck('region')->all();

        // Should at minimum include these standard regions
        $this->assertContains('Asia', $regions);
        $this->assertContains('Africa', $regions);
        $this->assertContains('America', $regions);
        $this->assertContains('Europe', $regions);
        $this->assertContains('UTC', $regions);
    }
    #[Test]
    public function endpoint_includes_indonesian_friendly_labels()
    {
        $response = $this->getJson('/api/v1/settings/timezones');

        $asia = collect($response->json('data.groups'))->firstWhere('region', 'Asia');
        $this->assertNotNull($asia);

        $jakarta = collect($asia['timezones'])->firstWhere('value', 'Asia/Jakarta');
        $makassar = collect($asia['timezones'])->firstWhere('value', 'Asia/Makassar');
        $jayapura = collect($asia['timezones'])->firstWhere('value', 'Asia/Jayapura');

        $this->assertNotNull($jakarta);
        $this->assertStringContainsString('WIB', $jakarta['label']);
        $this->assertEquals('+07:00', $jakarta['offset']);

        $this->assertNotNull($makassar);
        $this->assertStringContainsString('WITA', $makassar['label']);
        $this->assertEquals('+08:00', $makassar['offset']);

        $this->assertNotNull($jayapura);
        $this->assertStringContainsString('WIT', $jayapura['label']);
        $this->assertEquals('+09:00', $jayapura['offset']);
    }
    #[Test]
    public function endpoint_returns_419_plus_timezones_total()
    {
        $response = $this->getJson('/api/v1/settings/timezones');

        $total = collect($response->json('data.groups'))
            ->sum(fn ($group) => count($group['timezones']));

        $this->assertGreaterThanOrEqual(400, $total, 'PHP should expose 400+ timezones');
    }
    #[Test]
    public function endpoint_reflects_current_timezone_when_changed()
    {
        SettingService::set('regional.timezone', 'Asia/Makassar', 'string');

        $response = $this->getJson('/api/v1/settings/timezones');

        $this->assertEquals('Asia/Makassar', $response->json('data.current'));
        $this->assertEquals('+08:00', $response->json('data.offset'));
    }
    #[Test]
    public function endpoint_requires_authentication()
    {
        // Drop sanctum auth
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/v1/settings/timezones');

        $response->assertUnauthorized();
    }

    // ====================================================================
    // EDGE CASE tambahan — galak & eksak
    // ====================================================================

    /** UTC adalah region tunggal: persis 1 timezone bernilai 'UTC' offset '+00:00'. */
    public function test_utc_region_has_single_zone_with_zero_offset()
    {
        $response = $this->getJson('/api/v1/settings/timezones')->assertOk();

        $utc = collect($response->json('data.groups'))->firstWhere('region', 'UTC');
        $this->assertNotNull($utc);
        $this->assertCount(1, $utc['timezones']);
        $this->assertSame('UTC', $utc['timezones'][0]['value']);
        $this->assertSame('+00:00', $utc['timezones'][0]['offset']);
    }

    /** Setiap entri timezone WAJIB punya 3 key (value/label/offset) non-kosong, label memuat offset. */
    public function test_every_timezone_entry_is_well_formed()
    {
        $response = $this->getJson('/api/v1/settings/timezones')->assertOk();

        $asia = collect($response->json('data.groups'))->firstWhere('region', 'Asia');
        $jakarta = collect($asia['timezones'])->firstWhere('value', 'Asia/Jakarta');

        $this->assertSame(['value', 'label', 'offset'], array_keys($jakarta));
        $this->assertNotEmpty($jakarta['value']);
        $this->assertNotEmpty($jakarta['label']);
        $this->assertMatchesRegularExpression('/^[+-]\d{2}:\d{2}$/', $jakarta['offset']);
        // Label menyertakan offset di dalam kurung: "WIB - Asia/Jakarta (+07:00)"
        $this->assertStringContainsString('(' . $jakarta['offset'] . ')', $jakarta['label']);
    }

    /** Region diurutkan menaik (ksort di service): Africa harus mendahului Asia. */
    public function test_regions_are_sorted_ascending()
    {
        $response = $this->getJson('/api/v1/settings/timezones')->assertOk();

        $regions = collect($response->json('data.groups'))->pluck('region')->all();
        $sorted = $regions;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $regions, 'Region harus terurut alfabetis (ksort)');
        $this->assertLessThan(
            array_search('Asia', $regions, true),
            array_search('Africa', $regions, true),
            'Africa harus sebelum Asia'
        );
    }

    /** Timezone tersimpan invalid → offset fallback ke '+07:00' (guard catch di service). */
    public function test_invalid_stored_timezone_falls_back_to_default_offset()
    {
        SettingService::set('regional.timezone', 'Mars/Olympus', 'string');

        $response = $this->getJson('/api/v1/settings/timezones')->assertOk();

        $this->assertSame('Mars/Olympus', $response->json('data.current'));
        $this->assertSame('+07:00', $response->json('data.offset'));
    }

    /** Tanpa permission settings.view → 403 (konsisten dgn endcpoint settings lain). */
    public function test_endpoint_requires_settings_view_permission()
    {
        // User tanpa role/permission apa pun.
        $noPerm = User::factory()->create();
        Sanctum::actingAs($noPerm);

        $this->getJson('/api/v1/settings/timezones')->assertStatus(403);
    }

    /** Tidak ada duplikasi value timezone dalam satu region. */
    public function test_no_duplicate_timezone_values_within_region()
    {
        $response = $this->getJson('/api/v1/settings/timezones')->assertOk();

        foreach ($response->json('data.groups') as $group) {
            $values = collect($group['timezones'])->pluck('value');
            $this->assertSame(
                $values->count(),
                $values->unique()->count(),
                "Region {$group['region']} tidak boleh punya value duplikat"
            );
        }
    }
}
