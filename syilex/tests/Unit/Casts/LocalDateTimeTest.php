<?php

namespace Tests\Unit\Casts;

use App\Casts\LocalDateTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class LocalDateTimeTest extends TestCase
{
    private LocalDateTime $cast;
    private Model $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cast = new LocalDateTime();
        $this->model = new class extends Model {};
    }
    #[Test]
    public function get_returns_carbon_instance_from_database_string()
    {
        $result = $this->cast->get($this->model, 'tanggal', '2026-04-12 14:30:00', []);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('2026-04-12 14:30:00', $result->format('Y-m-d H:i:s'));
    }
    #[Test]
    public function get_returns_null_for_null_value()
    {
        $this->assertNull($this->cast->get($this->model, 'tanggal', null, []));
    }
    #[Test]
    public function set_formats_carbon_as_datetime_string_for_storage()
    {
        $carbon = Carbon::parse('2026-04-12 14:30:00');
        $result = $this->cast->set($this->model, 'tanggal', $carbon, []);

        $this->assertEquals('2026-04-12 14:30:00', $result);
    }
    #[Test]
    public function set_parses_string_input()
    {
        $result = $this->cast->set($this->model, 'tanggal', '2026-04-12 14:30:00', []);

        $this->assertEquals('2026-04-12 14:30:00', $result);
    }
    #[Test]
    public function set_returns_null_for_null()
    {
        $this->assertNull($this->cast->set($this->model, 'tanggal', null, []));
    }
    #[Test]
    public function serialize_outputs_iso8601_with_timezone_offset()
    {
        // KEY: this ensures JS new Date() parses with correct timezone
        $carbon = Carbon::parse('2026-04-12 14:30:00', 'Asia/Jakarta');
        $result = $this->cast->serialize($this->model, 'tanggal', $carbon, []);

        // Format: 2026-04-12T14:30:00+07:00
        $this->assertStringContainsString('T', $result);
        $this->assertMatchesRegularExpression('/[+-]\d{2}:\d{2}$/', $result, 'Should end with timezone offset');
        $this->assertStringStartsWith('2026-04-12T14:30:00', $result);
    }
    #[Test]
    public function serialize_does_not_use_z_utc_marker()
    {
        // Avoid '2026-04-12T14:30:00Z' which causes timezone interpretation issues
        $carbon = Carbon::parse('2026-04-12 14:30:00', 'Asia/Jakarta');
        $result = $this->cast->serialize($this->model, 'tanggal', $carbon, []);

        $this->assertNotEquals('Z', substr($result, -1), 'Should not end with Z UTC marker');
    }
    #[Test]
    public function serialize_returns_null_for_null()
    {
        $this->assertNull($this->cast->serialize($this->model, 'tanggal', null, []));
    }

    // ───────────────────────── EDGE CASE TAMBAHAN (galak) ─────────────────────────
    #[Test]
    public function get_mempertahankan_komponen_jam_penuh(): void
    {
        // Beda dengan DateOnly: LocalDateTime WAJIB mempertahankan jam:menit:detik.
        $result = $this->cast->get($this->model, 'tanggal', '2026-04-12 23:59:59', []);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertSame('2026-04-12 23:59:59', $result->format('Y-m-d H:i:s'));
    }
    #[Test]
    public function get_pada_tengah_malam_tidak_dipotong_seperti_dateonly(): void
    {
        $result = $this->cast->get($this->model, 'tanggal', '2026-04-12 00:00:01', []);

        $this->assertSame('2026-04-12 00:00:01', $result->format('Y-m-d H:i:s'));
    }
    #[Test]
    public function set_dengan_carbon_offset_disimpan_sebagai_jam_dinding_tanpa_konversi(): void
    {
        // set() hanya format('Y-m-d H:i:s') — TIDAK mengonversi timezone.
        // Carbon +07:00 14:30 → tersimpan "14:30:00" (wall-clock), bukan di-shift ke UTC.
        $carbon = Carbon::parse('2026-04-12 14:30:00', 'Asia/Jakarta');
        $result = $this->cast->set($this->model, 'tanggal', $carbon, []);

        $this->assertSame('2026-04-12 14:30:00', $result);
    }
    #[Test]
    public function serialize_offset_eksak_plus_tujuh_untuk_asia_jakarta(): void
    {
        // Asia/Jakarta = UTC+7, offset eksak +07:00 (bukan Z, bukan +00:00).
        $carbon = Carbon::parse('2026-04-12 14:30:00', 'Asia/Jakarta');
        $result = $this->cast->serialize($this->model, 'tanggal', $carbon, []);

        $this->assertSame('2026-04-12T14:30:00+07:00', $result);
    }
    #[Test]
    public function serialize_offset_eksak_utc_menggunakan_plus_nol_nol_bukan_z(): void
    {
        // KEY: zona UTC harus tampil "+00:00", BUKAN "Z" (toIso8601String tidak pakai Z).
        $carbon = Carbon::parse('2026-04-12 14:30:00', 'UTC');
        $result = $this->cast->serialize($this->model, 'tanggal', $carbon, []);

        $this->assertSame('2026-04-12T14:30:00+00:00', $result);
        $this->assertStringNotContainsString('Z', $result);
    }
    #[Test]
    public function serialize_offset_negatif_dipertahankan(): void
    {
        // Zona Amerika (negatif) → offset negatif eksak.
        $carbon = Carbon::parse('2026-04-12 09:00:00', 'America/New_York'); // EDT = -04:00 pada April
        $result = $this->cast->serialize($this->model, 'tanggal', $carbon, []);

        $this->assertSame('2026-04-12T09:00:00-04:00', $result);
    }
    #[Test]
    public function serialize_dengan_string_database_tetap_iso8601_dengan_offset(): void
    {
        // serialize() menerima string mentah → di-parse lalu di-ISO8601-kan.
        $result = $this->cast->serialize($this->model, 'tanggal', '2026-04-12 14:30:00', []);

        // Format wajib ISO 8601 dengan offset (offset bergantung tz default proses).
        $this->assertMatchesRegularExpression(
            '/^2026-04-12T14:30:00[+-]\d{2}:\d{2}$/',
            $result
        );
        $this->assertStringNotContainsString('Z', $result);
    }
    #[Test]
    public function round_trip_set_lalu_get_mempertahankan_detik(): void
    {
        $stored = $this->cast->set($this->model, 'tanggal', '2026-02-28 17:45:09', []);
        $this->assertSame('2026-02-28 17:45:09', $stored);

        $back = $this->cast->get($this->model, 'tanggal', $stored, []);
        $this->assertSame('2026-02-28 17:45:09', $back->format('Y-m-d H:i:s'));
    }
}
