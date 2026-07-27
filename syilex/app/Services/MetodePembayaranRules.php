<?php

namespace App\Services;

use App\Models\MasterMetodePembayaran;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MetodePembayaranRules
{
    /**
     * @return array<string, mixed>
     */
    public static function storeRules(Request $request): array
    {
        $rules = [
            'kode_pembayaran' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9-]+$/',
                'unique:master_metode_pembayaran,kode_pembayaran',
            ],
            'nama_pembayaran' => 'required|string|max:100',
            'metode' => 'required|in:tunai,non_tunai',
            'status' => 'required|in:active,inactive',
        ];

        return array_merge($rules, self::nonTunaiRules($request));
    }

    /**
     * @return array<string, mixed>
     */
    public static function updateRules(Request $request): array
    {
        $rules = [
            'nama_pembayaran' => 'required|string|max:100',
            'metode' => 'required|in:tunai,non_tunai',
            'status' => 'required|in:active,inactive',
        ];

        return array_merge($rules, self::nonTunaiRules($request));
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'kode_pembayaran.regex' => 'Kode hanya boleh berisi huruf, angka, dan tanda hubung (-)',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function normalize(array $validated): array
    {
        if (($validated['metode'] ?? '') === 'tunai') {
            $validated['jenis'] = null;
            $validated['nama_akun'] = null;
            $validated['nomor_akun'] = null;
            $validated['logo'] = null;
            $validated['qr_code'] = null;
            $validated['biaya_tambahan_tipe'] = 'none';
            $validated['biaya_tambahan_nilai'] = 0;
        } elseif (($validated['biaya_tambahan_tipe'] ?? 'none') === 'none') {
            $validated['biaya_tambahan_nilai'] = 0;
        }

        return $validated;
    }

    public static function deactivationBlockMessage(MasterMetodePembayaran $metodePembayaran): ?string
    {
        $defaultCount = $metodePembayaran->posTerminalsAsDefault()->count();
        $allowedCount = $metodePembayaran->posTerminals()->count();
        $totalCount = $defaultCount + $allowedCount;

        if ($totalCount === 0) {
            return null;
        }

        return "Tidak dapat menonaktifkan Metode Pembayaran karena masih digunakan oleh terminal POS ({$defaultCount} sebagai default, {$allowedCount} sebagai metode diizinkan)";
    }

    public static function deletionBlockMessage(MasterMetodePembayaran $metodePembayaran): ?string
    {
        $defaultCount = $metodePembayaran->posTerminalsAsDefault()->count();
        $allowedCount = $metodePembayaran->posTerminals()->count();
        $totalCount = $defaultCount + $allowedCount;

        if ($totalCount > 0) {
            return "Tidak dapat menghapus Metode Pembayaran karena masih digunakan oleh terminal POS ({$defaultCount} sebagai default, {$allowedCount} sebagai metode diizinkan)";
        }

        $paymentCount = $metodePembayaran->salesPayments()->count();
        if ($paymentCount > 0) {
            return "Tidak dapat menghapus Metode Pembayaran karena digunakan oleh {$paymentCount} pembayaran transaksi";
        }

        return null;
    }

    /**
     * Validate + normalize one import row for metode pembayaran.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: array<string, mixed>} [error|null, data]
     */
    public static function validateImportRow(array $data): array
    {
        $metode = $data['metode'] ?? 'tunai';
        if (! in_array($metode, ['tunai', 'non_tunai'], true)) {
            return ['Metode harus tunai atau non_tunai', $data];
        }

        if ($metode === 'non_tunai') {
            $allowedJenis = ['bank', 'qris', 'credit_card', 'debit_card', 'e_wallet', 'lainnya'];
            if (! in_array($data['jenis'] ?? null, $allowedJenis, true)) {
                return ['Jenis non-tunai wajib (bank/qris/credit_card/debit_card/e_wallet/lainnya)', $data];
            }
            $tipe = $data['biaya_tambahan_tipe'] ?? 'none';
            if (! in_array($tipe, ['none', 'percent', 'nominal'], true)) {
                return ['Tipe biaya tambahan tidak valid', $data];
            }
            $nilai = (float) ($data['biaya_tambahan_nilai'] ?? 0);
            if ($tipe !== 'none' && $nilai < 0) {
                return ['Nilai biaya tambahan tidak boleh negatif', $data];
            }
            if ($tipe === 'percent' && $nilai > 100) {
                return ['Biaya tambahan persen maksimal 100', $data];
            }
            $data['biaya_tambahan_tipe'] = $tipe;
            $data['biaya_tambahan_nilai'] = $tipe === 'none' ? 0 : $nilai;
        }

        return [null, self::normalize($data)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nonTunaiRules(Request $request): array
    {
        if ($request->metode !== 'non_tunai') {
            return [];
        }

        return [
            'jenis' => 'required|in:bank,qris,credit_card,debit_card,e_wallet,lainnya',
            'nama_akun' => 'nullable|string|max:100',
            'nomor_akun' => 'nullable|string|max:50',
            'logo' => 'nullable|string|max:255',
            'qr_code' => 'nullable|string|max:255',
            'biaya_tambahan_tipe' => 'required|in:none,percent,nominal',
            'biaya_tambahan_nilai' => [
                'required_unless:biaya_tambahan_tipe,none',
                'numeric',
                'min:0',
                Rule::when($request->biaya_tambahan_tipe === 'percent', 'max:100'),
            ],
        ];
    }
}
