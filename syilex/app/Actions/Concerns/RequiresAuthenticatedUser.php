<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Trait untuk Action class yang WAJIB punya authenticated user.
 *
 * Defense-in-depth: meskipun Controller sudah check permission,
 * trait ini memastikan Action tidak bisa dipanggil dari CLI/Job
 * tanpa user context. Mencegah ghost execution.
 *
 * Usage:
 * ```
 * class CheckoutSalesAction {
 *     use RequiresAuthenticatedUser;
 *
 *     public function execute(array $data): DocSales {
 *         $this->ensureAuthenticated();
 *         // ... sisa logic
 *     }
 * }
 * ```
 */
trait RequiresAuthenticatedUser
{
    /**
     * Throw exception kalau tidak ada user yang authenticated.
     * Dipanggil di awal execute().
     */
    protected function ensureAuthenticated(): void
    {
        if (!Auth::check()) {
            throw new RuntimeException(
                static::class . ' requires an authenticated user. ' .
                'Pastikan dipanggil dari Controller yang sudah auth, atau actingAs() di test.'
            );
        }
    }
}
