<?php

namespace App\Actions\SerialChange;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\DocSerialChange;
use App\Models\SerialUnit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve Perubahan Data Serial (draft → approved) — terapkan nilai baru ke unit TERSEDIA.
 * Tidak menyentuh stok/HPP (field harga_modal sengaja dikecualikan dari modul ini).
 */
class ApproveSerialChangeAction
{
    use RequiresAuthenticatedUser;

    public function execute(DocSerialChange $change): DocSerialChange
    {
        $this->ensureAuthenticated();

        if (!$change->isDraft()) {
            throw ValidationException::withMessages(['status' => ['Hanya draft yang dapat disetujui.']]);
        }
        $change->load('details');
        if ($change->details->isEmpty()) {
            throw ValidationException::withMessages(['details' => ['Tidak ada unit untuk dikoreksi.']]);
        }

        return DB::transaction(function () use ($change) {
            $details = $change->details;
            $unitIds = $details->pluck('serial_unit_id')->all();

            $units = SerialUnit::whereIn('id', $unitIds)->lockForUpdate()->get()->keyBy('id');

            // SN tak lagi wajib unik (boleh kembar) — tidak ada re-cek tabrakan SN saat approve.
            // Identitas unik unit dijamin kode_internal (tak diubah modul ini).

            foreach ($details as $d) {
                $unit = $units->get($d->serial_unit_id);
                if (!$unit || $unit->status !== 'tersedia') {
                    continue; // unit sudah terjual / hilang → lewati
                }

                // Re-snapshot nilai lama tepat sebelum apply (audit akurat)
                $d->update([
                    'before' => [
                        'serial_number' => $unit->serial_number,
                        'harga_jual' => $unit->harga_jual,
                        'grade' => $unit->grade,
                        'battery_condition' => $unit->battery_condition,
                        'battery_health' => $unit->battery_health,
                        'account_status' => $unit->account_status,
                        'catatan' => $unit->catatan,
                    ],
                ]);

                $unit->update([
                    'serial_number' => $d->serial_number,
                    'harga_jual' => $d->harga_jual,
                    'grade' => $d->grade,
                    'battery_condition' => $d->battery_condition,
                    'battery_health' => $d->battery_health,
                    'account_status' => $d->account_status,
                    'catatan' => $d->catatan,
                ]);
            }

            $change->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            return $change->load(['product', 'details', 'approvedBy']);
        });
    }
}
