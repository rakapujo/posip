<?php

namespace Tests\Unit\Services;

use App\Services\ReportHelperService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ensures salesReceiptQtySelects wraps shared SQL with expected aliases
 * (used by SalesReportController + SalesPerNotaExport).
 */
class ReportHelperSalesQtySelectTest extends TestCase
{
    #[Test]
    public function sales_receipt_qty_selects_alias_bought_and_returned(): void
    {
        $selects = ReportHelperService::salesReceiptQtySelects('ds.id');
        $grammar = DB::connection()->getQueryGrammar();

        $this->assertCount(2, $selects);
        $this->assertSame(
            ReportHelperService::sqlSalesBoughtBase('ds.id') . ' as total_bought_base',
            $selects[0]->getValue($grammar)
        );
        $this->assertSame(
            ReportHelperService::sqlSalesReturnedBase('ds.id') . ' as total_returned_base',
            $selects[1]->getValue($grammar)
        );
    }
}
