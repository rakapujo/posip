<?php

namespace App\Actions\Sales;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\DocSales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateManualSalesAction
{
    use RequiresAuthenticatedUser;

    public function execute(DocSales $sales, array $data): DocSales
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($sales, $data) {
            $sales = DocSales::manual()->whereKey($sales->id)->lockForUpdate()->firstOrFail();
            if (! $sales->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Hanya penjualan draft yang dapat diubah.']]);
            }

            return (new CreateManualSalesAction)->save($sales, $data);
        });
    }
}
