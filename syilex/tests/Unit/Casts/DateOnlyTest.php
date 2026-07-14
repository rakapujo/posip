<?php

namespace Tests\Unit\Casts;

use App\Casts\DateOnly;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DateOnlyTest extends TestCase
{
    private DateOnly $cast;
    private Model $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cast = new DateOnly();
        $this->model = new class extends Model {};
    }
    #[Test]
    public function get_returns_carbon_at_start_of_day_from_database_string()
    {
        $result = $this->cast->get($this->model, 'tanggal', '2026-04-12 14:30:00', []);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('2026-04-12 00:00:00', $result->format('Y-m-d H:i:s'));
    }
    #[Test]
    public function get_returns_null_for_null_value()
    {
        $this->assertNull($this->cast->get($this->model, 'tanggal', null, []));
    }
    #[Test]
    public function set_formats_carbon_as_date_only_string()
    {
        $carbon = Carbon::parse('2026-04-12 14:30:00');
        $result = $this->cast->set($this->model, 'tanggal', $carbon, []);

        $this->assertEquals('2026-04-12', $result);
    }
    #[Test]
    public function set_parses_string_input_to_date_only()
    {
        $result = $this->cast->set($this->model, 'tanggal', '2026-04-12 14:30:00', []);

        $this->assertEquals('2026-04-12', $result);
    }
    #[Test]
    public function set_returns_null_for_null_input()
    {
        $this->assertNull($this->cast->set($this->model, 'tanggal', null, []));
    }
    #[Test]
    public function serialize_returns_date_only_format_no_timezone_offset()
    {
        // This is the KEY fix: prevent "2026-04-12T00:00:00.000000Z" UTC interpretation issue
        $carbon = Carbon::parse('2026-04-12');
        $result = $this->cast->serialize($this->model, 'tanggal', $carbon, []);

        $this->assertEquals('2026-04-12', $result);
        $this->assertStringNotContainsString('T', $result, 'Should NOT contain T separator');
        $this->assertStringNotContainsString('Z', $result, 'Should NOT contain Z UTC marker');
    }
    #[Test]
    public function serialize_returns_null_for_null_value()
    {
        $this->assertNull($this->cast->serialize($this->model, 'tanggal', null, []));
    }

    // ───────────────────────── EDGE CASE TAMBAHAN (galak) ─────────────────────────
    #[Test]
    public function get_dari_string_tanggal_only_tetap_start_of_day(): void
    {
        // Input tanpa komponen jam → tetap 00:00:00, bukan jam sekarang.
        $result = $this->cast->get($this->model, 'tanggal', '2026-04-12', []);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertSame('2026-04-12 00:00:00', $result->format('Y-m-d H:i:s'));
    }
    #[Test]
    public function get_membuang_komponen_jam_pada_batas_satu_detik_sebelum_tengah_malam(): void
    {
        // Boundary: 23:59:59 harus tetap dipotong ke 00:00:00 hari yang SAMA (tidak loncat ke besok).
        $result = $this->cast->get($this->model, 'tanggal', '2026-04-12 23:59:59', []);

        $this->assertSame('2026-04-12 00:00:00', $result->format('Y-m-d H:i:s'));
    }
    #[Test]
    public function get_pada_tepat_tengah_malam_tidak_bergeser_hari(): void
    {
        // Boundary: tepat 00:00:00 tetap tanggal yang sama.
        $result = $this->cast->get($this->model, 'tanggal', '2026-04-12 00:00:00', []);

        $this->assertSame('2026-04-12 00:00:00', $result->format('Y-m-d H:i:s'));
    }
    #[Test]
    public function set_dengan_carbon_membuang_komponen_jam(): void
    {
        // set() dengan Carbon ber-jam → tersimpan hanya tanggal (DATE column).
        $carbon = Carbon::parse('2026-12-31 23:59:59');
        $result = $this->cast->set($this->model, 'tanggal', $carbon, []);

        $this->assertSame('2026-12-31', $result);
    }
    #[Test]
    public function set_dengan_string_tanggal_only_dipertahankan(): void
    {
        $result = $this->cast->set($this->model, 'tanggal', '2026-01-01', []);

        $this->assertSame('2026-01-01', $result);
    }
    #[Test]
    public function serialize_dengan_string_database_menghasilkan_tanggal_only(): void
    {
        // serialize() menerima string mentah (bukan Carbon) → tetap dipotong ke tanggal.
        $result = $this->cast->serialize($this->model, 'tanggal', '2026-04-12 14:30:00', []);

        $this->assertSame('2026-04-12', $result);
        $this->assertStringNotContainsString('T', $result);
        $this->assertStringNotContainsString('Z', $result);
        $this->assertStringNotContainsString(':', $result);
    }
    #[Test]
    public function serialize_format_eksak_sepuluh_karakter_iso_date(): void
    {
        $carbon = Carbon::parse('2026-04-12 14:30:00');
        $result = $this->cast->serialize($this->model, 'tanggal', $carbon, []);

        // Persis "YYYY-MM-DD" — 10 karakter, tanpa offset/timezone.
        $this->assertSame('2026-04-12', $result);
        $this->assertSame(10, strlen($result));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);
    }
    #[Test]
    public function round_trip_set_lalu_get_konsisten_tanggal(): void
    {
        // set (PHP→DB) lalu get (DB→PHP) harus konsisten pada komponen tanggal.
        $stored = $this->cast->set($this->model, 'tanggal', '2026-02-28 17:45:00', []);
        $this->assertSame('2026-02-28', $stored);

        $back = $this->cast->get($this->model, 'tanggal', $stored, []);
        $this->assertSame('2026-02-28 00:00:00', $back->format('Y-m-d H:i:s'));
    }
}
