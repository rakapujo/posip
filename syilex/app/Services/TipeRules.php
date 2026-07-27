<?php

namespace App\Services;

use App\Models\MasterProduk;
use App\Models\MasterTipe;

class TipeRules
{
    public static function deactivationBlockMessage(MasterTipe $tipe): ?string
    {
        if ($tipe->kategoris()->exists()) {
            return 'Tidak dapat menonaktifkan Tipe Produk karena masih memiliki Kategori';
        }
        if (MasterProduk::where('tipe_id', $tipe->id)->exists()) {
            return 'Tidak dapat menonaktifkan Tipe Produk karena masih digunakan oleh produk';
        }

        return null;
    }
}
