<?php

namespace App\Services;

use App\Models\MasterGrup;

class GrupRules
{
    public static function deactivationBlockMessage(MasterGrup $grup): ?string
    {
        if ($grup->products()->exists()) {
            return 'Tidak dapat menonaktifkan Grup Produk karena masih digunakan oleh produk';
        }

        return null;
    }
}
