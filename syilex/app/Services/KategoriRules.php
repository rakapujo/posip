<?php

namespace App\Services;

use App\Models\MasterKategori;
use App\Models\MasterProduk;

class KategoriRules
{
    public static function deactivationBlockMessage(MasterKategori $kategori): ?string
    {
        if ($kategori->grups()->exists()) {
            return 'Tidak dapat menonaktifkan Kategori Produk karena masih memiliki Grup';
        }
        if (MasterProduk::where('kategori_id', $kategori->id)->exists()) {
            return 'Tidak dapat menonaktifkan Kategori Produk karena masih digunakan oleh produk';
        }

        return null;
    }
}
