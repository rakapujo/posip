# Business rules kritis

> **Status:** canonical  
> **SSoT kode:** `syilex/app/Actions/**`, `syilex/app/Models/MasterProduk.php`, `StockCard`, serial actions  
> **Detail serial:** [`../domain/serial.md`](../domain/serial.md)  
> **Jika konflik:** ikuti kode.

## A. Anti-fraud checkout POS

- FE hanya kirim `items[]` + `diskon_5_*` (manual kasir)
- BE rebuild `diskon_1..4` dari DB promo: [`CheckoutSalesAction.php`](../../syilex/app/Actions/Sales/CheckoutSalesAction.php)
- **JANGAN** trust nilai promo dari frontend

## B. HPP weighted average (non-serial)

- Recalc pada aliran masuk: approve **PO** / **Pembelian Serial**, **ADJUSTMENT_IN**, **Repack** (hasil)
- Stock card masuk biasanya `PURCHASE` / `ADJUSTMENT_IN` / repack — **bukan** label `PURCHASE_RECEIVE`
- **TIDAK** recalc weighted di `SALES`, `SALES_RETURN`, `PURCHASE_RETURN`, `TRANSFER`, `ADJUSTMENT_OUT` (non-serial)
- Formula: [`MasterProduk::recalculateAvgCost`](../../syilex/app/Models/MasterProduk.php)
- Guard: `if ($totalQty <= 0) return $currentAvgCost`

### Serial (Metode A)

- Setelah jual/void/retur serial: `avg_cost` = rata `cost_per_unit` unit `tersedia` (`RevertsSerialUnits::recomputeSerialAvgCost` / checkout)

## C. Stock invariants

- Setiap mutasi `inventory_stock` **WAJIB** ada `stock_card` padanan dalam transaksi yang sama
- Action sering skip observer + `StockCard::record()` manual
- Invariant: `SUM(qty_in - qty_out) === inventory_stock.qty` → `php artisan data:verify`

## D. Idempotency

- POS checkout: header `Idempotency-Key` (16–128 chars)
- Middleware: [`IdempotencyKey.php`](../../syilex/app/Http/Middleware/IdempotencyKey.php) — conflict = JSON 422 inline (bukan exception class)

## E. Concurrency

- Nomor dokumen: `lockForUpdate` di sequence
- Checkout: lock stock + produk
- Price change apply: `lockForUpdate` pada header `DocPriceChange` di dalam transaksi apply/cancel (cegah race cancel↔apply / cron)

## F. Document status (string, bukan PHP Enum)

| Dokumen | Alur tipikal |
|---------|----------------|
| Sales POS | `completed` / `voided` |
| Sales BO | `draft` → `completed` → optional `voided` |
| Purchase Order | `draft` / `approved` |
| Purchase Return | `draft` → `lock` → `approved` |
| Sales Return BO | `draft` → `lock` → `approved` (free mode: `returns.sales_allow_free`) |
| Sales Return POS | langsung `approved` |
| Price Change | `draft` → `scheduled` → `applied` (cancel scheduled → kembali `draft`) |

Stock card `transaction_type`: `PURCHASE`, `SALES`, `PURCHASE_RETURN`, `SALES_RETURN`, `ADJUSTMENT_IN/OUT`, `STOCK_OPNAME`, `TRANSFER_IN/OUT`, `REPACK_IN/OUT`, `HPP_RESET`, `HPP_CORRECTION`.

## G. Serial (ringkas)

- Produk `is_serial` tetap stok qty + HPP agregat; unit di `serial_units`
- Identitas unit = `kode_internal` (UNIQUE global); SN boleh kembar — pilih via **ulid**
- Pembelian Serial: draft → approve (baru stok + hutang)
- Menu: **Pembelian** → Pembelian Serial (`/app/inventory/serial-intake`)
- Flag: `modules.elektronik_enabled` + middleware `feature.elektronik`
- Detail: [`../domain/serial.md`](../domain/serial.md)

## Scheduler

- Price change: `Schedule::command('price-change:apply')` di [`routes/console.php`](../../syilex/routes/console.php) — **butuh** cron `schedule:run`
- Bukan middleware API
