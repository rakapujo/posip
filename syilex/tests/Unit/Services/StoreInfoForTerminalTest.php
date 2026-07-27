<?php

namespace Tests\Unit\Services;

use App\Models\MasterPosTerminal;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreInfoForTerminalTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_global_when_terminal_null(): void
    {
        SettingService::set('store.name', 'Global Toko');
        SettingService::set('store.address', 'Jl Global');
        SettingService::set('store.phone', '0811');
        SettingService::set('store.email', 'g@example.com');
        SettingService::set('store.npwp', '10.0.0.1-000.000');
        SettingService::set('store.receipt_footer', 'Thanks Global');

        $info = SettingService::getStoreInfoForTerminal(null);

        $this->assertSame('Global Toko', $info['name']);
        $this->assertSame('Jl Global', $info['address']);
        $this->assertSame('0811', $info['phone']);
        $this->assertSame('g@example.com', $info['email']);
        $this->assertSame('10.0.0.1-000.000', $info['npwp']);
        $this->assertSame('Thanks Global', $info['receipt_footer']);
    }

    public function test_coalesces_non_empty_terminal_overrides(): void
    {
        SettingService::set('store.name', 'Global Toko');
        SettingService::set('store.address', 'Jl Global');
        SettingService::set('store.phone', '0811');
        SettingService::set('store.email', 'g@example.com');
        SettingService::set('store.npwp', 'NPWP-G');
        SettingService::set('store.receipt_footer', 'Thanks Global');

        $terminal = new MasterPosTerminal([
            'store_name' => 'Outlet A',
            'store_address' => '',
            'store_phone' => '  ',
            'store_email' => 'a@outlet.com',
            'store_npwp' => null,
            'receipt_footer' => 'Thanks Outlet',
        ]);

        $info = SettingService::getStoreInfoForTerminal($terminal);

        $this->assertSame('Outlet A', $info['name']);
        $this->assertSame('Jl Global', $info['address']); // empty override → global
        $this->assertSame('0811', $info['phone']); // whitespace → global
        $this->assertSame('a@outlet.com', $info['email']);
        $this->assertSame('NPWP-G', $info['npwp']); // null → global
        $this->assertSame('Thanks Outlet', $info['receipt_footer']);
    }
}
