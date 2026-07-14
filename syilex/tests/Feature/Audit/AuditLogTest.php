<?php

namespace Tests\Feature\Audit;

use App\Models\DocPromo;
use App\Models\MasterProduk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test untuk verify audit log via Spatie ActivityLog tracking perubahan critical models.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function makePromo(array $overrides = []): DocPromo
    {
        return DocPromo::create(array_merge([
            'ulid'          => (string) Str::ulid(),
            'kode_promo'    => 'PRM-' . Str::random(6),
            'nama_promo'    => 'Test Promo',
            'tanggal_mulai' => today()->toDateString(),
            'status'        => 'draft',
            'created_by'    => $this->user->id,
        ], $overrides));
    }
    #[Test]
    public function creating_promo_logs_activity(): void
    {
        $promo = $this->makePromo();

        $activity = Activity::where('subject_id', $promo->id)
            ->where('subject_type', DocPromo::class)
            ->first();

        $this->assertNotNull($activity, 'Harus ada activity log saat create promo');
        $this->assertEquals('created', $activity->event);
        $this->assertEquals($this->user->id, $activity->causer_id);
    }
    #[Test]
    public function updating_promo_logs_dirty_fields_only(): void
    {
        $promo = $this->makePromo(['nama_promo' => 'Original']);

        // Clear initial create log untuk isolation
        Activity::query()->delete();

        $promo->update(['nama_promo' => 'Updated']);

        $activity = Activity::where('subject_id', $promo->id)->latest()->first();

        $this->assertNotNull($activity);
        $this->assertEquals('updated', $activity->event);

        // Properties hanya berisi field yang berubah
        $properties = $activity->properties;
        $this->assertArrayHasKey('attributes', $properties->toArray());
        $this->assertEquals('Updated', $properties['attributes']['nama_promo']);
        $this->assertEquals('Original', $properties['old']['nama_promo']);
    }
    #[Test]
    public function deleting_promo_logs_activity(): void
    {
        $promo = $this->makePromo();
        Activity::query()->delete();

        $promo->delete();

        $activity = Activity::where('subject_id', $promo->id)->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals('deleted', $activity->event);
    }
    #[Test]
    public function log_name_matches_class_basename(): void
    {
        $promo = $this->makePromo();

        $activity = Activity::where('subject_id', $promo->id)
            ->where('subject_type', DocPromo::class)
            ->first();

        $this->assertEquals('DocPromo', $activity->log_name);
    }
    #[Test]
    public function no_log_when_nothing_changed(): void
    {
        $promo = $this->makePromo();
        Activity::query()->delete();

        // Save tanpa perubahan field
        $promo->save();

        $this->assertEquals(
            0,
            Activity::count(),
            'Tidak boleh ada log kalau tidak ada field yang berubah'
        );
    }
    #[Test]
    public function causer_is_authenticated_user(): void
    {
        $promo = $this->makePromo();

        $activity = Activity::where('subject_id', $promo->id)
            ->where('subject_type', DocPromo::class)
            ->first();

        $this->assertEquals(User::class, $activity->causer_type);
        $this->assertEquals($this->user->id, $activity->causer_id);
    }

    // ==================== EDGE CASE GALAK ====================
    #[Test]
    public function update_hanya_mencatat_field_yang_berubah_bukan_seluruh_fillable(): void
    {
        // logOnlyDirty: kalau ubah 1 field, hanya field itu yang masuk attributes/old.
        $promo = $this->makePromo([
            'nama_promo' => 'Awal',
            'deskripsi' => 'Deskripsi tetap',
        ]);
        Activity::query()->delete();

        $promo->update(['nama_promo' => 'Berubah']); // deskripsi TIDAK diubah

        $activity = Activity::where('subject_id', $promo->id)->latest()->first();
        $attrs = $activity->properties['attributes'];

        // Hanya nama_promo yang tercatat
        $this->assertArrayHasKey('nama_promo', $attrs);
        $this->assertEquals('Berubah', $attrs['nama_promo']);

        // Field yang tidak berubah TIDAK boleh ikut tercatat (galak)
        $this->assertArrayNotHasKey('deskripsi', $attrs);
        $this->assertArrayNotHasKey('kode_promo', $attrs);

        // 'old' juga hanya berisi nama_promo lama
        $old = $activity->properties['old'];
        $this->assertEquals('Awal', $old['nama_promo']);
        $this->assertArrayNotHasKey('deskripsi', $old);
    }
    #[Test]
    public function update_beruntun_menghasilkan_log_terpisah_per_perubahan(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);
        Activity::query()->delete();

        $promo->update(['status' => 'approved']);
        $promo->update(['status' => 'inactive']);

        $logs = Activity::where('subject_id', $promo->id)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get();

        // Tepat 2 log update, masing-masing menangkap transisi status EKSAK
        $this->assertCount(2, $logs);
        $this->assertEquals('draft', $logs[0]->properties['old']['status']);
        $this->assertEquals('approved', $logs[0]->properties['attributes']['status']);
        $this->assertEquals('approved', $logs[1]->properties['old']['status']);
        $this->assertEquals('inactive', $logs[1]->properties['attributes']['status']);
    }
    #[Test]
    public function causer_null_saat_tidak_ada_user_terautentikasi(): void
    {
        // Logout: aksi sistem tanpa user → causer_id null tapi event tetap tercatat.
        auth()->logout();

        $promo = $this->makePromo(['created_by' => $this->user->id]);

        $activity = Activity::where('subject_id', $promo->id)
            ->where('subject_type', DocPromo::class)
            ->first();

        $this->assertNotNull($activity);
        $this->assertEquals('created', $activity->event);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
    }
    #[Test]
    public function master_produk_juga_teraudit_create_update_delete(): void
    {
        // Verifikasi HasAuditLog bekerja lintas model (bukan cuma DocPromo).
        // MasterProduk pakai SoftDeletes + HasAuditLog.
        $produk = MasterProduk::create([
            'kode_produk' => 'AUDIT-001',
            'nama_produk' => 'Produk Audit',
            'unit_1' => 'PCS', 'konversi_1' => 1, 'harga_1' => 1000,
            'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 1000,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 1000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 1000,
            'minimum_stok' => 0,
            'avg_cost' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $created = Activity::where('subject_type', MasterProduk::class)
            ->where('subject_id', $produk->id)
            ->where('event', 'created')->first();
        $this->assertNotNull($created);
        $this->assertEquals('MasterProduk', $created->log_name);

        // Update
        $produk->update(['nama_produk' => 'Produk Audit Ubah']);
        $updated = Activity::where('subject_type', MasterProduk::class)
            ->where('subject_id', $produk->id)
            ->where('event', 'updated')->latest()->first();
        $this->assertNotNull($updated);
        $this->assertEquals('Produk Audit Ubah', $updated->properties['attributes']['nama_produk']);

        // Soft delete tetap menghasilkan event 'deleted'
        $produk->delete();
        $deleted = Activity::where('subject_type', MasterProduk::class)
            ->where('subject_id', $produk->id)
            ->where('event', 'deleted')->latest()->first();
        $this->assertNotNull($deleted);
    }
    #[Test]
    public function retensi_membersihkan_log_lebih_tua_dari_365_hari_dan_menyisakan_yang_baru(): void
    {
        // Retensi: config activitylog.delete_records_older_than_days = 365.
        $this->assertEquals(365, config('activitylog.delete_records_older_than_days'));

        // Buat log baru (created saat makePromo) → harus tetap.
        $promo = $this->makePromo();
        $recent = Activity::where('subject_id', $promo->id)->first();
        $this->assertNotNull($recent);

        // Buat satu log "lama" dengan created_at > 365 hari lalu.
        $old = Activity::create([
            'log_name' => 'DocPromo',
            'description' => 'old record',
            'subject_type' => DocPromo::class,
            'subject_id' => $promo->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'properties' => [],
            'event' => 'updated',
        ]);
        // Backdate melewati ambang retensi (366 hari).
        $old->created_at = now()->subDays(366);
        $old->save();

        $totalBefore = Activity::count();

        // Jalankan command retensi.
        Artisan::call('activitylog:clean');

        // Log lama TERHAPUS, log baru TETAP ada.
        $this->assertNull(Activity::find($old->id), 'Log > 365 hari harus dihapus');
        $this->assertNotNull(Activity::find($recent->id), 'Log baru harus tetap ada');
        $this->assertEquals($totalBefore - 1, Activity::count());
    }
    #[Test]
    public function deskripsi_event_promo_sesuai_konvensi_spatie(): void
    {
        // Description default Spatie = nama event (created/updated/deleted).
        $promo = $this->makePromo();
        $activity = Activity::where('subject_id', $promo->id)
            ->where('subject_type', DocPromo::class)
            ->first();

        $this->assertEquals('created', $activity->description);
        $this->assertEquals('DocPromo', $activity->log_name);
        $this->assertEquals(DocPromo::class, $activity->subject_type);
        $this->assertEquals($promo->id, $activity->subject_id);
    }
}
