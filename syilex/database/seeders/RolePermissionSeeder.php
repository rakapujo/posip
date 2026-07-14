<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $kasirRole = Role::firstOrCreate(['name' => 'kasir']);
        $gudangRole = Role::firstOrCreate(['name' => 'gudang']);

        $permissions = [
            'user.view', 'user.create', 'user.update', 'user.delete',
            'role.view', 'role.create', 'role.update', 'role.delete',
            'settings.view', 'settings.update', 'settings.reset',
            'warehouse.view', 'warehouse.create', 'warehouse.update', 'warehouse.delete',
            'brand.view', 'brand.create', 'brand.update', 'brand.delete',
            'tipe.view', 'tipe.create', 'tipe.update', 'tipe.delete',
            'kategori.view', 'kategori.create', 'kategori.update', 'kategori.delete',
            'grup.view', 'grup.create', 'grup.update', 'grup.delete',
            'supplier.view', 'supplier.create', 'supplier.update', 'supplier.delete',
            'tipe-customer.view', 'tipe-customer.create', 'tipe-customer.update', 'tipe-customer.delete',
            'kategori-customer.view', 'kategori-customer.create', 'kategori-customer.update', 'kategori-customer.delete',
            'customer-discount.manage',
            'customer.view', 'customer.create', 'customer.update', 'customer.delete',
            'metode-bayar.view', 'metode-bayar.create', 'metode-bayar.update', 'metode-bayar.delete',
            'produk.view', 'produk.create', 'produk.update', 'produk.delete', 'produk.print-barcode',
            'import.master',
            'serial-intake.view', 'serial-intake.view_harga', 'serial-intake.create', 'serial-intake.update', 'serial-intake.delete', 'serial-intake.approve',
            'serial-change.view', 'serial-change.create', 'serial-change.update', 'serial-change.delete', 'serial-change.approve',
            'serial-hpp.view', 'serial-hpp.create', 'serial-hpp.update', 'serial-hpp.delete', 'serial-hpp.approve',
            'stok.view', 'stok.view_hpp',
            'adjustment.view', 'adjustment.create', 'adjustment.update', 'adjustment.delete', 'adjustment.approve',
            'transfer.view', 'transfer.create', 'transfer.update', 'transfer.delete', 'transfer.approve',
            'repack.view', 'repack.create', 'repack.update', 'repack.delete', 'repack.approve',
            'opname.view', 'opname.create', 'opname.update', 'opname.delete', 'opname.approve',
            'hpp.view', 'hpp.create', 'hpp.update', 'hpp.delete', 'hpp.approve',
            'po.view', 'po.view_harga', 'po.create', 'po.edit', 'po.delete', 'po.approve',
            'hutang.view', 'hutang.view_nominal',
            'retur-beli.view', 'retur-beli.create', 'retur-beli.update', 'retur-beli.delete', 'retur-beli.lock', 'retur-beli.approve',
            'deposit-supplier.view', 'deposit-supplier.create', 'deposit-supplier.update', 'deposit-supplier.delete',
            'pembayaran-hutang.view', 'pembayaran-hutang.create', 'pembayaran-hutang.update', 'pembayaran-hutang.delete', 'pembayaran-hutang.complete',
            'price-change.view', 'price-change.create', 'price-change.update', 'price-change.delete', 'price-change.approve', 'price-change.apply',
            'promo.view', 'promo.create', 'promo.update', 'promo.delete', 'promo.approve', 'promo.toggle',
            'terminal.view', 'terminal.create', 'terminal.edit', 'terminal.delete', 'terminal.toggle-status', 'terminal.force-release',
            'pos.access', 'pos.discount', 'pos.void', 'pos.retur',
            'laporan.view', 'laporan.export',
            'laporan.penjualan', 'laporan.pembelian', 'laporan.keuangan', 'laporan.performa', 'laporan.promo', 'laporan.inventory',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdminRole->syncPermissions(Permission::all());

        $adminRole->syncPermissions([
            'user.view', 'settings.view',
            'warehouse.view', 'warehouse.create', 'warehouse.update', 'warehouse.delete',
            'brand.view', 'brand.create', 'brand.update', 'brand.delete',
            'tipe.view', 'tipe.create', 'tipe.update', 'tipe.delete',
            'kategori.view', 'kategori.create', 'kategori.update', 'kategori.delete',
            'grup.view', 'grup.create', 'grup.update', 'grup.delete',
            'supplier.view', 'supplier.create', 'supplier.update', 'supplier.delete',
            'tipe-customer.view', 'tipe-customer.create', 'tipe-customer.update', 'tipe-customer.delete',
            'kategori-customer.view', 'kategori-customer.create', 'kategori-customer.update', 'kategori-customer.delete',
            'customer-discount.manage',
            'customer.view', 'customer.create', 'customer.update', 'customer.delete',
            'metode-bayar.view', 'metode-bayar.create', 'metode-bayar.update', 'metode-bayar.delete',
            'produk.view', 'produk.create', 'produk.update', 'produk.delete', 'produk.print-barcode',
            'import.master',
            'serial-intake.view', 'serial-intake.view_harga', 'serial-intake.create', 'serial-intake.update', 'serial-intake.delete', 'serial-intake.approve',
            'serial-change.view', 'serial-change.create', 'serial-change.update', 'serial-change.delete', 'serial-change.approve',
            'serial-hpp.view', 'serial-hpp.create', 'serial-hpp.update', 'serial-hpp.delete', 'serial-hpp.approve',
            'stok.view', 'stok.view_hpp',
            'adjustment.view', 'adjustment.create', 'adjustment.update', 'adjustment.delete', 'adjustment.approve',
            'transfer.view', 'transfer.create', 'transfer.update', 'transfer.delete', 'transfer.approve',
            'repack.view', 'repack.create', 'repack.update', 'repack.delete', 'repack.approve',
            'opname.view', 'opname.create', 'opname.update', 'opname.delete', 'opname.approve',
            'hpp.view', 'hpp.create', 'hpp.update', 'hpp.delete', 'hpp.approve',
            'po.view', 'po.view_harga', 'po.create', 'po.edit', 'po.delete', 'po.approve',
            'hutang.view', 'hutang.view_nominal',
            'retur-beli.view', 'retur-beli.create', 'retur-beli.update', 'retur-beli.delete', 'retur-beli.lock', 'retur-beli.approve',
            'deposit-supplier.view', 'deposit-supplier.create', 'deposit-supplier.update', 'deposit-supplier.delete',
            'pembayaran-hutang.view', 'pembayaran-hutang.create', 'pembayaran-hutang.update', 'pembayaran-hutang.delete', 'pembayaran-hutang.complete',
            'price-change.view', 'price-change.create', 'price-change.update', 'price-change.delete', 'price-change.approve', 'price-change.apply',
            'promo.view', 'promo.create', 'promo.update', 'promo.delete', 'promo.approve', 'promo.toggle',
            'terminal.view', 'terminal.create', 'terminal.edit', 'terminal.delete', 'terminal.toggle-status', 'terminal.force-release',
            'pos.access', 'pos.discount', 'pos.void', 'pos.retur',
            'laporan.view', 'laporan.export',
            'laporan.penjualan', 'laporan.pembelian', 'laporan.keuangan', 'laporan.performa', 'laporan.promo', 'laporan.inventory',
        ]);

        $kasirRole->syncPermissions([
            'warehouse.view', 'brand.view', 'tipe.view', 'kategori.view', 'grup.view',
            'supplier.view', 'tipe-customer.view', 'kategori-customer.view', 'customer.view',
            'metode-bayar.view', 'produk.view', 'stok.view', 'terminal.view',
            'pos.access', 'pos.retur', 'pos.void', 'pos.discount', 'laporan.view',
            'laporan.penjualan', 'laporan.pembelian', 'laporan.keuangan', 'laporan.performa', 'laporan.promo', 'laporan.inventory',
        ]);

        $gudangRole->syncPermissions([
            'warehouse.view', 'brand.view', 'tipe.view', 'kategori.view', 'grup.view',
            'supplier.view', 'tipe-customer.view', 'kategori-customer.view', 'customer.view',
            'metode-bayar.view', 'produk.view', 'produk.print-barcode', 'stok.view',
            'adjustment.view', 'adjustment.create', 'adjustment.update', 'adjustment.delete',
            'transfer.view', 'transfer.create', 'transfer.update', 'transfer.delete',
            'repack.view', 'repack.create', 'repack.update', 'repack.delete',
            'opname.view', 'opname.create', 'opname.update', 'opname.delete',
            'po.view', 'po.create', 'po.edit', 'po.delete',
            'serial-intake.view', 'serial-intake.create', 'serial-intake.update', 'serial-intake.delete',
            'serial-change.view', 'serial-change.create', 'serial-change.update', 'serial-change.delete',
            'serial-hpp.view', 'serial-hpp.create', 'serial-hpp.update', 'serial-hpp.delete',
            'hutang.view',
            'retur-beli.view', 'retur-beli.create', 'retur-beli.update', 'retur-beli.delete',
            'deposit-supplier.view', 'laporan.view',
            'laporan.penjualan', 'laporan.pembelian', 'laporan.keuangan', 'laporan.performa', 'laporan.promo', 'laporan.inventory',
        ]);
    }
}
