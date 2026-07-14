<?php

namespace App\Services;

use App\Models\MasterGrup;
use App\Models\MasterKategori;

class ProdukRules
{
    public static function inactiveKategoriBlockMessage(?int $kategoriId): ?string
    {
        if (! $kategoriId) {
            return null;
        }

        $kategori = MasterKategori::find($kategoriId);
        if ($kategori && ! $kategori->isActive()) {
            return 'Kategori Produk tidak aktif';
        }

        return null;
    }

    public static function inactiveGrupBlockMessage(?int $grupId): ?string
    {
        if (! $grupId) {
            return null;
        }

        $grup = MasterGrup::find($grupId);
        if ($grup && ! $grup->isActive()) {
            return 'Grup Produk tidak aktif';
        }

        return null;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public static function masterReferenceErrors(?int $kategoriId, ?int $grupId): ?array
    {
        $errors = [];

        if ($message = self::inactiveKategoriBlockMessage($kategoriId)) {
            $errors['kategori_id'] = [$message];
        }

        if ($message = self::inactiveGrupBlockMessage($grupId)) {
            $errors['grup_id'] = [$message];
        }

        return $errors !== [] ? $errors : null;
    }
}
