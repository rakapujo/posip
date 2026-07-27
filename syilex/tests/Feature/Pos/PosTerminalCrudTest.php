<?php

namespace Tests\Feature\Pos;

use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterWarehouse;
use App\Models\DocSales;
use App\Models\PosTerminalShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 1 — POS Terminal CRUD via API.
 */
class PosTerminalCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterWarehouse $warehouse;

    protected MasterCustomer $walkInCustomer;

    protected MasterMetodePembayaran $cash;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'terminal.view', 'terminal.create', 'terminal.edit', 'terminal.toggle-status',
            'terminal.delete', 'terminal.force-release', 'pos.access',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'terminal.view', 'terminal.create', 'terminal.edit', 'terminal.toggle-status',
            'terminal.delete', 'terminal.force-release', 'pos.access',
        ]);

        $this->warehouse = MasterWarehouse::factory()->create([
            'status' => 'active',
            'is_saleable' => true,
            'created_by' => $this->user->id,
        ]);

        $this->walkInCustomer = MasterCustomer::create([
            'kode_customer' => 'WALKIN-TRM',
            'nama' => 'Walk In Terminal',
            'telepon' => '08000',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->cash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-TRM',
            'nama_pembayaran' => 'Tunai Terminal',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
    }

    private function terminalPayload(array $overrides = []): array
    {
        return array_merge([
            'kode_terminal' => 'TRM_01',
            'nama_terminal' => 'Kasir Depan',
            'warehouse_id' => $this->warehouse->id,
            'default_customer_id' => $this->walkInCustomer->id,
            'default_metode_pembayaran_id' => $this->cash->id,
            'auto_open_tray' => false,
            'izinkan_retur' => true,
            'status' => 'active',
            'user_ids' => [$this->user->id],
            'metode_pembayaran_ids' => [$this->cash->id],
        ], $overrides);
    }

    public function test_index_forbidden_without_terminal_view(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/v1/pos-terminals')
            ->assertForbidden();
    }

    public function test_store_forbidden_without_terminal_create(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('terminal.view');

        $this->actingAs($viewer)
            ->postJson('/api/v1/pos-terminals', $this->terminalPayload())
            ->assertForbidden();
    }

    public function test_store_rejects_user_without_pos_access(): void
    {
        $noAccessUser = User::factory()->create();

        $this->actingAs($this->user)
            ->postJson('/api/v1/pos-terminals', $this->terminalPayload([
                'user_ids' => [$noAccessUser->id],
            ]))
            ->assertStatus(422);
    }

    public function test_terminal_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/pos-terminals', $this->terminalPayload())
            ->assertCreated()
            ->assertJsonPath('data.terminal.kode_terminal', 'TRM_01');

        $ulid = MasterPosTerminal::where('kode_terminal', 'TRM_01')->first()->ulid;

        $this->actingAs($this->user)
            ->getJson('/api/v1/pos-terminals')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user)
            ->getJson("/api/v1/pos-terminals/{$ulid}")
            ->assertOk()
            ->assertJsonPath('data.terminal.kode_terminal', 'TRM_01');

        $this->actingAs($this->user)
            ->putJson("/api/v1/pos-terminals/{$ulid}", $this->terminalPayload([
                'nama_terminal' => 'Kasir Depan Updated',
            ]))
            ->assertOk()
            ->assertJsonPath('data.terminal.nama_terminal', 'Kasir Depan Updated');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/pos-terminals/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.terminal.status', 'inactive');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/pos-terminals/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.terminal.status', 'active');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/pos-terminals/{$ulid}")
            ->assertOk();

        $this->assertDatabaseMissing('master_pos_terminal', ['ulid' => $ulid]);
    }

    public function test_update_forbidden_without_terminal_edit(): void
    {
        $terminal = $this->createTerminal();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('terminal.view');

        $this->actingAs($viewer)
            ->putJson("/api/v1/pos-terminals/{$terminal->ulid}", $this->terminalPayload())
            ->assertForbidden();
    }

    public function test_toggle_status_forbidden_without_permission(): void
    {
        $terminal = $this->createTerminal();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('terminal.view');

        $this->actingAs($viewer)
            ->patchJson("/api/v1/pos-terminals/{$terminal->ulid}/toggle-status")
            ->assertForbidden();
    }

    public function test_delete_forbidden_without_terminal_delete(): void
    {
        $terminal = $this->createTerminal();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('terminal.view');

        $this->actingAs($viewer)
            ->deleteJson("/api/v1/pos-terminals/{$terminal->ulid}")
            ->assertForbidden();
    }

    public function test_update_blocked_when_terminal_in_use(): void
    {
        $terminal = $this->createTerminal();
        $terminal->update(['active_user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/pos-terminals/{$terminal->ulid}", $this->terminalPayload())
            ->assertStatus(422);
    }

    public function test_delete_blocked_when_terminal_in_use(): void
    {
        $terminal = $this->createTerminal();
        $terminal->update(['active_user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/pos-terminals/{$terminal->ulid}")
            ->assertStatus(422);
    }

    public function test_force_release_forbidden_without_permission(): void
    {
        $terminal = $this->createTerminal();
        $terminal->update(['active_user_id' => $this->user->id]);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('terminal.view');

        $this->actingAs($viewer)
            ->postJson("/api/v1/pos-terminals/{$terminal->ulid}/force-release")
            ->assertForbidden();
    }

    public function test_force_release_clears_active_user(): void
    {
        $terminal = $this->createTerminal();
        $terminal->update(['active_user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/pos-terminals/{$terminal->ulid}/force-release")
            ->assertOk();

        $this->assertNull($terminal->fresh()->active_user_id);
    }

    public function test_email_receipt_rejects_terminal_without_mail_configuration(): void
    {
        $terminal = $this->createTerminal();
        $shift = PosTerminalShift::create([
            'terminal_id' => $terminal->id,
            'user_id' => $this->user->id,
            'started_at' => now(),
        ]);
        $sale = DocSales::create([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'POS-MAIL-001',
            'tanggal' => now(),
            'source' => 'pos',
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->walkInCustomer->id,
            'subtotal' => 10000,
            'total_diskon' => 0,
            'total_setelah_diskon' => 10000,
            'dpp' => 10000,
            'pajak_nominal' => 0,
            'pembulatan' => 0,
            'grand_total' => 10000,
            'total_bayar' => 10000,
            'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->post("/api/v1/pos/sales/{$sale->ulid}/email-receipt", [
                'to_email' => 'customer@example.com',
                'pdf' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_mail_test_rejects_terminal_without_mail_configuration(): void
    {
        $terminal = $this->createTerminal();

        $this->actingAs($this->user)
            ->postJson("/api/v1/pos-terminals/{$terminal->ulid}/mail-test", [
                'to_email' => 'admin@example.com',
            ])
            ->assertStatus(422);
    }

    private function createTerminal(): MasterPosTerminal
    {
        $terminal = MasterPosTerminal::create([
            'kode_terminal' => 'TRM-EXIST',
            'nama_terminal' => 'Kasir Existing',
            'warehouse_id' => $this->warehouse->id,
            'default_customer_id' => $this->walkInCustomer->id,
            'default_metode_pembayaran_id' => $this->cash->id,
            'auto_open_tray' => false,
            'izinkan_retur' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $terminal->users()->attach($this->user->id);
        $terminal->allowedPaymentMethods()->attach($this->cash->id);

        return $terminal;
    }
}
