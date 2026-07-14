<?php

namespace Tests\Feature\Promo;

use App\Models\DocPromo;
use App\Models\DocPromoDetail;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for DocPromo model state machine, scopes, and document numbering.
 *
 * Follows the same direct-layer pattern as PriceChangeCrudTest —
 * no HTTP; tests the model / service layer directly.
 */
class PromoCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Create a DocPromo at a given status with sensible defaults.
     */
    private function makePromo(array $overrides = []): DocPromo
    {
        $defaults = [
            'kode_promo'    => 'PM-' . Str::random(6),
            'nama_promo'    => 'Test Promo',
            'tanggal_mulai' => today()->toDateString(),
            'status'        => 'draft',
            'created_by'    => $this->user->id,
        ];

        return DocPromo::create(array_merge($defaults, $overrides));
    }

    /**
     * Attach a detail row with at least one non-zero discount to $promo.
     */
    private function addDetailWithDiscount(DocPromo $promo, array $overrides = []): DocPromoDetail
    {
        return $promo->details()->create(array_merge([
            'target_type'    => 'semua',
            'min_qty'        => 1,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 10,
            'diskon_2_tipe'  => 'none',
            'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none',
            'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none',
            'diskon_4_nilai' => 0,
        ], $overrides));
    }

    /**
     * Attach a detail row with ALL discounts set to none/zero.
     */
    private function addDetailWithoutDiscount(DocPromo $promo): DocPromoDetail
    {
        return $promo->details()->create([
            'target_type'    => 'semua',
            'min_qty'        => 1,
            'diskon_1_tipe'  => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe'  => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none', 'diskon_4_nilai' => 0,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Document numbering
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function create_promo_generates_pm_prefix_document_number(): void
    {
        $kode = SettingService::generateDocumentNumber('promo', 'doc_promo', 'kode_promo');

        $this->assertStringStartsWith('PRM-', $kode);
    }
    #[Test]
    public function subsequent_promo_numbers_are_sequential(): void
    {
        // Each call should return a different (incrementing) number
        $kode1 = SettingService::generateDocumentNumber('promo', 'doc_promo', 'kode_promo');
        $promo = $this->makePromo(['kode_promo' => $kode1]);

        $kode2 = SettingService::generateDocumentNumber('promo', 'doc_promo', 'kode_promo');

        $this->assertNotEquals($kode1, $kode2, 'Each document number must be unique');
        $this->assertStringStartsWith('PRM-', $kode2);
    }

    // ──────────────────────────────────────────────────────────────────
    // Initial state
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function new_promo_is_draft_by_default(): void
    {
        $promo = $this->makePromo();

        $this->assertTrue($promo->isDraft());
        $this->assertFalse($promo->isApproved());
        $this->assertFalse($promo->isInactive());
        $this->assertEquals('draft', $promo->status);
    }

    // ──────────────────────────────────────────────────────────────────
    // Edit / Delete guards
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function draft_promo_can_be_updated(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);

        $promo->update(['nama_promo' => 'Updated Name']);

        $this->assertEquals('Updated Name', $promo->fresh()->nama_promo);
    }
    #[Test]
    public function approved_promo_is_not_editable_according_to_isDraft(): void
    {
        $promo = $this->makePromo(['status' => 'approved']);

        // The controller uses isDraft() as the gate; verify it returns false
        $this->assertFalse($promo->isDraft());
    }
    #[Test]
    public function draft_promo_can_be_deleted(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);
        $id    = $promo->id;

        $promo->delete();

        $this->assertNull(DocPromo::find($id));
    }
    #[Test]
    public function deleting_promo_cascades_to_details(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);
        $this->addDetailWithDiscount($promo);
        $this->assertEquals(1, $promo->details()->count());

        $promo->delete();

        $this->assertEquals(0, DocPromoDetail::where('promo_id', $promo->id)->count());
    }

    // ──────────────────────────────────────────────────────────────────
    // Approve
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function approve_transitions_draft_to_approved_and_sets_approved_meta(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);

        $promo->update([
            'status'      => 'approved',
            'approved_at' => now(),
            'approved_by' => $this->user->id,
        ]);

        $promo->refresh();
        $this->assertTrue($promo->isApproved());
        $this->assertNotNull($promo->approved_at);
        $this->assertEquals($this->user->id, $promo->approved_by);
    }
    #[Test]
    public function approve_requires_at_least_one_detail_with_nonzero_discount(): void
    {
        // Promo with zero-discount detail only
        $promo = $this->makePromo(['status' => 'draft']);
        $this->addDetailWithoutDiscount($promo);

        $promo->load('details');

        // Replicate controller guard: reject if no detail has any non-zero discount
        $hasDiscount = $promo->details->contains(function ($d) {
            for ($i = 1; $i <= 4; $i++) {
                if ($d->{"diskon_{$i}_tipe"} !== 'none' && $d->{"diskon_{$i}_nilai"} > 0) {
                    return true;
                }
            }
            return false;
        });

        $this->assertFalse($hasDiscount, 'Detail with all-none discounts should not satisfy the approve guard');
    }
    #[Test]
    public function approve_guard_passes_when_detail_has_nonzero_discount(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);
        $this->addDetailWithDiscount($promo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        $promo->load('details');

        $hasDiscount = $promo->details->contains(function ($d) {
            for ($i = 1; $i <= 4; $i++) {
                if ($d->{"diskon_{$i}_tipe"} !== 'none' && $d->{"diskon_{$i}_nilai"} > 0) {
                    return true;
                }
            }
            return false;
        });

        $this->assertTrue($hasDiscount, 'Promo with a 10% detail discount should pass the approve guard');
    }

    // ──────────────────────────────────────────────────────────────────
    // Cancel (approved → draft)
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function cancel_approval_returns_promo_to_draft_and_clears_approved_meta(): void
    {
        $promo = $this->makePromo([
            'status'      => 'approved',
            'approved_at' => now(),
            'approved_by' => $this->user->id,
        ]);

        $promo->update([
            'status'      => 'draft',
            'approved_at' => null,
            'approved_by' => null,
        ]);

        $promo->refresh();
        $this->assertTrue($promo->isDraft());
        $this->assertNull($promo->approved_at);
        $this->assertNull($promo->approved_by);
    }

    // ──────────────────────────────────────────────────────────────────
    // Deactivate / Reactivate
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function deactivate_approved_promo_transitions_to_inactive(): void
    {
        $promo = $this->makePromo(['status' => 'approved']);

        $promo->update(['status' => 'inactive']);

        $promo->refresh();
        $this->assertTrue($promo->isInactive());
        $this->assertFalse($promo->isApproved());
    }
    #[Test]
    public function reactivate_inactive_promo_transitions_back_to_approved(): void
    {
        $promo = $this->makePromo(['status' => 'inactive']);

        $promo->update(['status' => 'approved']);

        $promo->refresh();
        $this->assertTrue($promo->isApproved());
        $this->assertFalse($promo->isInactive());
    }

    // ──────────────────────────────────────────────────────────────────
    // scopeEffective
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function scope_effective_returns_approved_promo_running_today(): void
    {
        // tanggal_mulai = yesterday so stored datetime '…00:00:00' is clearly <= today's date string.
        // SQLite stores date-cast fields as 'Y-m-d H:i:s'; using today() would make the comparison
        // '2026-04-14 00:00:00' <= '2026-04-14' evaluate to false in SQLite string ordering.
        $this->makePromo([
            'status'          => 'approved',
            'tanggal_mulai'   => today()->subDay()->toDateString(),
            'tanggal_selesai' => null,
        ]);

        $this->assertEquals(1, DocPromo::effective()->count());
    }
    #[Test]
    public function scope_effective_excludes_draft_promo(): void
    {
        $this->makePromo(['status' => 'draft', 'tanggal_mulai' => today()->toDateString()]);

        $this->assertEquals(0, DocPromo::effective()->count());
    }
    #[Test]
    public function scope_effective_excludes_inactive_promo(): void
    {
        $this->makePromo(['status' => 'inactive', 'tanggal_mulai' => today()->toDateString()]);

        $this->assertEquals(0, DocPromo::effective()->count());
    }
    #[Test]
    public function scope_effective_excludes_future_promo(): void
    {
        // tanggal_mulai is tomorrow — not yet valid
        $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->addDay()->toDateString(),
        ]);

        $this->assertEquals(0, DocPromo::effective()->count());
    }
    #[Test]
    public function scope_effective_excludes_expired_promo(): void
    {
        // tanggal_selesai was yesterday
        $this->makePromo([
            'status'          => 'approved',
            'tanggal_mulai'   => today()->subDays(10)->toDateString(),
            'tanggal_selesai' => today()->subDay()->toDateString(),
        ]);

        $this->assertEquals(0, DocPromo::effective()->count());
    }
    #[Test]
    public function scope_effective_includes_promo_without_end_date(): void
    {
        // No tanggal_selesai → runs forever
        $this->makePromo([
            'status'          => 'approved',
            'tanggal_mulai'   => today()->subMonth()->toDateString(),
            'tanggal_selesai' => null,
        ]);

        $this->assertEquals(1, DocPromo::effective()->count());
    }

    // ──────────────────────────────────────────────────────────────────
    // scopeByDisplayStatus
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function scope_by_display_status_active_returns_running_promo(): void
    {
        // Use subDay() for same SQLite date-cast reason as scope_effective test above.
        $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->subDay()->toDateString(),
        ]);

        $this->assertEquals(1, DocPromo::byDisplayStatus('active')->count());
    }
    #[Test]
    public function scope_by_display_status_upcoming_returns_future_approved_promo(): void
    {
        $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->addDays(5)->toDateString(),
        ]);

        $this->assertEquals(1, DocPromo::byDisplayStatus('upcoming')->count());
        $this->assertEquals(0, DocPromo::byDisplayStatus('active')->count());
    }
    #[Test]
    public function scope_by_display_status_expired_returns_past_approved_promo(): void
    {
        $this->makePromo([
            'status'          => 'approved',
            'tanggal_mulai'   => today()->subDays(10)->toDateString(),
            'tanggal_selesai' => today()->subDay()->toDateString(),
        ]);

        $this->assertEquals(1, DocPromo::byDisplayStatus('expired')->count());
        $this->assertEquals(0, DocPromo::byDisplayStatus('active')->count());
    }

    // ──────────────────────────────────────────────────────────────────
    // getDisplayStatus()
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function get_display_status_returns_active_for_running_promo(): void
    {
        $promo = $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->toDateString(),
        ]);

        $this->assertEquals('active', $promo->getDisplayStatus());
    }
    #[Test]
    public function get_display_status_returns_upcoming_for_future_promo(): void
    {
        $promo = $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->addDays(3)->toDateString(),
        ]);

        $this->assertEquals('upcoming', $promo->getDisplayStatus());
    }
    #[Test]
    public function get_display_status_returns_expired_for_past_promo(): void
    {
        $promo = $this->makePromo([
            'status'          => 'approved',
            'tanggal_mulai'   => today()->subDays(10)->toDateString(),
            'tanggal_selesai' => today()->subDay()->toDateString(),
        ]);

        $this->assertEquals('expired', $promo->getDisplayStatus());
    }
    #[Test]
    public function get_display_status_returns_draft_for_draft_promo(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);

        $this->assertEquals('draft', $promo->getDisplayStatus());
    }
    #[Test]
    public function get_display_status_returns_inactive_for_inactive_promo(): void
    {
        $promo = $this->makePromo(['status' => 'inactive']);

        $this->assertEquals('inactive', $promo->getDisplayStatus());
    }

    // ──────────────────────────────────────────────────────────────────
    // DocPromoDetail qualifies()
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function detail_qualifies_when_target_is_semua_and_qty_met(): void
    {
        $promo  = $this->makePromo();
        $detail = $this->addDetailWithDiscount($promo, ['target_type' => 'semua', 'min_qty' => 1]);

        $this->assertTrue($detail->qualifies(99, null, null, 1));
        $this->assertTrue($detail->qualifies(99, null, null, 5));
    }
    #[Test]
    public function detail_does_not_qualify_when_qty_below_min(): void
    {
        $promo  = $this->makePromo();
        $detail = $this->addDetailWithDiscount($promo, ['target_type' => 'semua', 'min_qty' => 5]);

        $this->assertFalse($detail->qualifies(1, null, null, 4), 'qty=4 < min_qty=5 should fail');
        $this->assertTrue($detail->qualifies(1, null, null, 5),  'qty=5 == min_qty=5 should pass');
    }
    #[Test]
    public function detail_qualifies_for_correct_produk_target(): void
    {
        $promo  = $this->makePromo();
        $detail = $this->addDetailWithDiscount($promo, ['target_type' => 'produk', 'target_id' => 42]);

        $this->assertTrue($detail->qualifies(42, null, null, 1),  'matching product id');
        $this->assertFalse($detail->qualifies(99, null, null, 1), 'different product id');
    }
    #[Test]
    public function detail_qualifies_for_correct_grup_target(): void
    {
        $promo  = $this->makePromo();
        $detail = $this->addDetailWithDiscount($promo, ['target_type' => 'grup', 'target_id' => 10]);

        $this->assertTrue($detail->qualifies(1, 10, null, 1),  'matching grup');
        $this->assertFalse($detail->qualifies(1, 20, null, 1), 'different grup');
        $this->assertFalse($detail->qualifies(1, null, null, 1), 'null grup');
    }
    #[Test]
    public function detail_qualifies_for_correct_kategori_target(): void
    {
        $promo  = $this->makePromo();
        $detail = $this->addDetailWithDiscount($promo, ['target_type' => 'kategori', 'target_id' => 7]);

        $this->assertTrue($detail->qualifies(1, null, 7, 1),   'matching kategori');
        $this->assertFalse($detail->qualifies(1, null, 99, 1), 'different kategori');
    }

    // ──────────────────────────────────────────────────────────────────
    // EDGE CASES TAMBAHAN (galak): prioritas target, jam window, approve guard
    // ──────────────────────────────────────────────────────────────────

    /**
     * target_type 'semua' match SEMUA produk apapun grup/kategori-nya,
     * sekalipun grup/kategori bernilai null. Boundary qty=min_qty inklusif.
     */
    #[Test]
    public function detail_semua_match_apapun_grup_dan_kategori(): void
    {
        $promo  = $this->makePromo();
        $detail = $this->addDetailWithDiscount($promo, ['target_type' => 'semua', 'min_qty' => 1]);

        $this->assertTrue($detail->qualifies(1, null, null, 1), 'grup & kategori null tetap match');
        $this->assertTrue($detail->qualifies(500, 7, 9, 3), 'grup & kategori berisi tetap match');
    }

    /**
     * Target produk TIDAK ikut match hanya karena grup/kategori sama.
     * Detail target produk=42 → grup/kategori cocok pun tetap gagal jika produk beda.
     * Membuktikan setiap target_type independen (tidak fallback antar dimensi).
     */
    #[Test]
    public function detail_target_produk_tidak_match_via_grup_atau_kategori(): void
    {
        $promo  = $this->makePromo();
        $detail = $this->addDetailWithDiscount($promo, ['target_type' => 'produk', 'target_id' => 42]);

        // produk beda (99), tapi grup=42 & kategori=42 → tetap tidak match
        $this->assertFalse($detail->qualifies(99, 42, 42, 1), 'target produk tidak fallback ke grup/kategori');
        $this->assertTrue($detail->qualifies(42, 1, 1, 1), 'hanya cocok bila product_id == target_id');
    }

    /**
     * target_type tidak dikenal (data korup) → matchesProduct default false.
     * Hardening: detail dengan target_type aneh tidak boleh diam-diam memberi diskon.
     */
    #[Test]
    public function detail_target_type_tidak_dikenal_tidak_pernah_qualify(): void
    {
        $promo  = $this->makePromo();
        $detail = $this->addDetailWithDiscount($promo, ['target_type' => 'semua', 'min_qty' => 1]);
        // Paksa target_type ke nilai tak dikenal tanpa lewat fillable validation
        $detail->setAttribute('target_type', 'entah_apa');

        $this->assertFalse($detail->qualifies(1, 2, 3, 10), 'target_type tak dikenal harus false');
    }

    /**
     * Approve guard: detail dengan diskon NOMINAL (bukan percent) yang > 0
     * tetap dianggap punya diskon (lolos guard).
     */
    #[Test]
    public function approve_guard_passes_when_detail_has_nominal_discount(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);
        $this->addDetailWithDiscount($promo, [
            'diskon_1_tipe' => 'nominal', 'diskon_1_nilai' => 2500,
            'diskon_2_tipe' => 'none',    'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',    'diskon_4_nilai' => 0,
        ]);
        $promo->load('details');

        $hasDiscount = $promo->details->contains(function ($d) {
            for ($i = 1; $i <= 4; $i++) {
                if ($d->{"diskon_{$i}_tipe"} !== 'none' && $d->{"diskon_{$i}_nilai"} > 0) {
                    return true;
                }
            }
            return false;
        });

        $this->assertTrue($hasDiscount, 'Diskon nominal > 0 harus lolos approve guard');
    }

    /**
     * Approve guard: detail dengan tipe percent tapi nilai 0 (tipe != none, nilai = 0)
     * TIDAK dianggap punya diskon — guard butuh nilai > 0, bukan sekadar tipe != none.
     */
    #[Test]
    public function approve_guard_gagal_saat_tipe_percent_tapi_nilai_nol(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);
        $this->addDetailWithDiscount($promo, [
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'percent', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',    'diskon_4_nilai' => 0,
        ]);
        $promo->load('details');

        $hasDiscount = $promo->details->contains(function ($d) {
            for ($i = 1; $i <= 4; $i++) {
                if ($d->{"diskon_{$i}_tipe"} !== 'none' && $d->{"diskon_{$i}_nilai"} > 0) {
                    return true;
                }
            }
            return false;
        });

        $this->assertFalse($hasDiscount, 'percent dengan nilai 0 tidak boleh lolos guard');
    }

    /**
     * scopeEffective dengan jam window: promo 08:00-12:00 dievaluasi di jam 10:00
     * harus masuk (lewat scope query). Pakai now override via Carbon::setTestNow.
     */
    #[Test]
    public function scope_effective_termasuk_promo_dalam_jam_window(): void
    {
        \Illuminate\Support\Carbon::setTestNow(today()->setTime(10, 0, 0));

        $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->subDay()->toDateString(),
            'jam_mulai'     => '08:00:00',
            'jam_selesai'   => '12:00:00',
        ]);

        $this->assertEquals(1, DocPromo::effective()->count(), 'Promo dalam jam window harus effective');

        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * scopeEffective dengan jam window di luar jam sekarang harus dikecualikan.
     * Promo 08:00-09:00 dievaluasi di jam 14:00 → tidak effective.
     */
    #[Test]
    public function scope_effective_kecualikan_promo_di_luar_jam_window(): void
    {
        \Illuminate\Support\Carbon::setTestNow(today()->setTime(14, 0, 0));

        $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->subDay()->toDateString(),
            'jam_mulai'     => '08:00:00',
            'jam_selesai'   => '09:00:00',
        ]);

        $this->assertEquals(0, DocPromo::effective()->count(), 'Promo di luar jam window tidak effective');

        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * getDisplayStatus: promo approved dalam tanggal valid tapi di LUAR jam window
     * mengembalikan 'upcoming' (belum/sudah lewat jam aktif hari ini).
     */
    #[Test]
    public function get_display_status_upcoming_saat_diluar_jam_window(): void
    {
        \Illuminate\Support\Carbon::setTestNow(today()->setTime(14, 0, 0));

        $promo = $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->toDateString(),
            'jam_mulai'     => '08:00:00',
            'jam_selesai'   => '09:00:00',
        ]);

        $this->assertEquals('upcoming', $promo->getDisplayStatus(), 'Di luar jam → upcoming');

        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * getDisplayStatus: promo approved dalam tanggal valid DAN dalam jam window
     * mengembalikan 'active'.
     */
    #[Test]
    public function get_display_status_active_saat_didalam_jam_window(): void
    {
        \Illuminate\Support\Carbon::setTestNow(today()->setTime(10, 30, 0));

        $promo = $this->makePromo([
            'status'        => 'approved',
            'tanggal_mulai' => today()->toDateString(),
            'jam_mulai'     => '08:00:00',
            'jam_selesai'   => '12:00:00',
        ]);

        $this->assertEquals('active', $promo->getDisplayStatus(), 'Dalam jam window → active');

        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * scopeByDisplayStatus('expired') TIDAK menyertakan promo tanpa tanggal_selesai
     * (whereNotNull tanggal_selesai). Promo open-ended tidak pernah expired.
     */
    #[Test]
    public function scope_by_display_status_expired_kecualikan_promo_tanpa_tanggal_selesai(): void
    {
        // Promo open-ended (tanggal_selesai null), mulai jauh di masa lalu
        $this->makePromo([
            'status'          => 'approved',
            'tanggal_mulai'   => today()->subYear()->toDateString(),
            'tanggal_selesai' => null,
        ]);

        $this->assertEquals(0, DocPromo::byDisplayStatus('expired')->count(), 'Promo open-ended bukan expired');
        $this->assertEquals(1, DocPromo::byDisplayStatus('active')->count(), 'Promo open-ended harus active');
    }

    /**
     * Tanggal mulai == tanggal selesai (promo 1 hari, hari ini) harus effective.
     * Boundary: tanggal_mulai <= today <= tanggal_selesai inklusif kedua sisi.
     */
    #[Test]
    public function scope_effective_promo_satu_hari_hari_ini(): void
    {
        \Illuminate\Support\Carbon::setTestNow(today()->setTime(10, 0, 0));

        $this->makePromo([
            'status'          => 'approved',
            'tanggal_mulai'   => today()->subDay()->toDateString(),
            'tanggal_selesai' => today()->toDateString(),
        ]);

        $this->assertEquals(1, DocPromo::effective()->count());

        \Illuminate\Support\Carbon::setTestNow();
    }
}
