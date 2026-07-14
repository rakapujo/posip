<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'settings.reset', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['password' => bcrypt('secret123')]);
        $this->admin->givePermissionTo('settings.reset');

        $this->kasir = User::factory()->create(['password' => bcrypt('secret123')]);
    }

    public function test_info_requires_permission(): void
    {
        $this->actingAs($this->kasir)
            ->getJson('/api/v1/backup/info')
            ->assertForbidden();
    }

    public function test_info_returns_database_and_uploads_size(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('info() reads information_schema, requires MySQL connection');
        }

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/backup/info')
            ->assertOk();

        $response->assertJsonStructure([
            'success',
            'data' => ['database', 'tables', 'uploads_size_bytes'],
        ]);
    }

    public function test_download_requires_permission(): void
    {
        $this->actingAs($this->kasir)
            ->postJson('/api/v1/backup/download', ['password' => 'secret123'])
            ->assertForbidden();
    }

    public function test_download_rejects_wrong_password(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/download', ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Password salah');
    }

    public function test_download_requires_password(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/download', [])
            ->assertStatus(422);
    }

    public function test_restore_requires_permission(): void
    {
        $file = UploadedFile::fake()->create('backup.sql', 10, 'application/sql');

        $this->actingAs($this->kasir)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertForbidden();
    }

    public function test_restore_rejects_wrong_password(): void
    {
        $file = UploadedFile::fake()->create('backup.sql', 10, 'application/sql');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'wrong',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Password salah');
    }

    public function test_restore_rejects_unsupported_extension(): void
    {
        $file = UploadedFile::fake()->create('backup.txt', 10, 'text/plain');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File harus berformat .zip atau .sql');
    }

    public function test_restore_rejects_invalid_sql_content(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'backup.sql',
            'just random text, not sql'
        );

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File bukan SQL dump yang valid');
    }

    public function test_restore_rejects_zip_without_database_sql(): void
    {
        $tmpZip = storage_path('app/test_empty_' . uniqid() . '.zip');
        $zip = new \ZipArchive();
        $zip->open($tmpZip, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'not a backup');
        $zip->close();

        $file = new UploadedFile($tmpZip, 'backup.zip', 'application/zip', null, true);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Arsip tidak berisi database.sql');

        @unlink($tmpZip);
    }

    public function test_restore_validates_sql_file_markers(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'backup.sql',
            "-- foo\nCREATE TABLE foo (id int);\nINSERT INTO foo VALUES (1);"
        );

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File bukan backup database POSIP yang valid. Tabel POSIP tidak ditemukan.');
    }

    // ====================================================================
    // EDGE CASE tambahan — galak, fokus lapisan validasi (tanpa mysql client)
    // ====================================================================

    /** info() menolak request tanpa password? Tidak — info tidak butuh password, hanya permission. */
    public function test_info_allowed_for_permitted_user_is_not_forbidden(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('info() membaca information_schema, butuh MySQL');
        }

        $this->actingAs($this->admin)
            ->getJson('/api/v1/backup/info')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /** download tanpa permission TIDAK boleh kebobolan walau password benar. */
    public function test_download_forbidden_takes_precedence_over_valid_password(): void
    {
        // kasir password sama 'secret123' tapi tanpa permission → tetap 403, bukan lanjut dump.
        $this->actingAs($this->kasir)
            ->postJson('/api/v1/backup/download', ['password' => 'secret123'])
            ->assertForbidden();
    }

    /** restore: file wajib ada (required) → 422 dengan error field 'file'. */
    public function test_restore_requires_file(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', ['password' => 'secret123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /** restore: password wajib ada → 422 dengan error field 'password'. */
    public function test_restore_requires_password_field(): void
    {
        $file = UploadedFile::fake()->create('backup.sql', 10, 'application/sql');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /**
     * ZIP berisi database.sql tetapi isinya bukan dump SQL (tak ada CREATE/INSERT).
     * Harus gagal di validateSqlFile dengan pesan 'File bukan SQL dump yang valid'
     * — SEBELUM menyentuh mysql client.
     */
    public function test_restore_zip_with_nonsql_database_entry_is_rejected(): void
    {
        $tmpZip = storage_path('app/test_bad_sql_' . uniqid() . '.zip');
        $zip = new \ZipArchive();
        $zip->open($tmpZip, \ZipArchive::CREATE);
        $zip->addFromString('database.sql', 'ini cuma teks biasa tanpa statement SQL');
        $zip->close();

        $file = new UploadedFile($tmpZip, 'backup.zip', 'application/zip', null, true);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File bukan SQL dump yang valid');

        @unlink($tmpZip);
    }

    /**
     * ZIP berisi database.sql dengan CREATE/INSERT tapi nama tabel BUKAN tabel POSIP
     * (< 3 tabel POSIP) → ditolak dengan pesan tabel POSIP tidak ditemukan.
     */
    public function test_restore_zip_with_foreign_schema_is_rejected(): void
    {
        $tmpZip = storage_path('app/test_foreign_' . uniqid() . '.zip');
        $zip = new \ZipArchive();
        $zip->open($tmpZip, \ZipArchive::CREATE);
        $zip->addFromString(
            'database.sql',
            "CREATE TABLE wp_posts (id int);\nINSERT INTO wp_posts VALUES (1);\nCREATE TABLE wp_users (id int);"
        );
        $zip->close();

        $file = new UploadedFile($tmpZip, 'backup.zip', 'application/zip', null, true);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File bukan backup database POSIP yang valid. Tabel POSIP tidak ditemukan.');

        @unlink($tmpZip);
    }

    /**
     * Tepat 2 tabel POSIP (di bawah ambang 3) → masih ditolak.
     * Mengunci boundary foundCount < 3.
     */
    public function test_restore_sql_with_two_posip_tables_below_threshold_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'backup.sql',
            "CREATE TABLE master_produk (id int);\nINSERT INTO settings VALUES (1);"
        );

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File bukan backup database POSIP yang valid. Tabel POSIP tidak ditemukan.');
    }

    /**
     * Korup ZIP (bytes acak ber-ekstensi .zip) → 'File ZIP tidak valid atau rusak'.
     */
    public function test_restore_corrupt_zip_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('backup.zip', 'PK-bukan-zip-sungguhan-rusak');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/backup/restore', [
                'password' => 'secret123',
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File ZIP tidak valid atau rusak');
    }
}
