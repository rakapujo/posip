# Backend conventions (AI)

> **Status:** canonical  
> **SSoT kode:** `syilex/app/Actions/`, `Http/Controllers/Api/V1/`, `Exceptions/`, `database/seeders/RolePermissionSeeder.php`  
> **Jika konflik:** ikuti kode.

## Layering

```
Route → Controller (thin) → Action (transactional write) → Model
                         ↘ Service (HPP, Promo, Settings, …)
```

- Controller: validate, authorize, delegate
- Actions: `app/Actions/{Domain}/{Verb}Action.php`

## API response

Pakai `BaseApiController`: `success`, `created`, `error`, `forbidden`, `notFound`.

```json
{ "success": true, "data": { ... }, "message": "..." }
```

## Exceptions

Auto 422 via `bootstrap/app.php`:

- `BusinessException`
- `StockInsufficientException`
- `DocumentStateException`

Idempotency conflict = middleware JSON inline — bukan exception class.

**JANGAN** catch `\Exception` generik di controller (kecuali infra).

## Permission

```php
if (!auth()->user()->can('promo.create')) return $this->forbidden();
```

Seed: [`RolePermissionSeeder.php`](../../syilex/database/seeders/RolePermissionSeeder.php)

### Laporan

Gate per kategori: `laporan.penjualan|pembelian|keuangan|performa|promo|inventory`.  
Export: `laporan.export`.  
`laporan.view` = **Dashboard saja**.

### Nilai sensitif (orthogonal)

- `stok.view_hpp` — HPP / cost unit
- `po.view_harga` — harga beli PO + laporan pembelian
- `serial-intake.view_harga` — harga dokumen PBS
- `hutang.view_nominal` — nominal hutang

View yang strip harga **WAJIB** strip export juga.

### Feature flag

- `modules.elektronik_enabled` → serial; middleware `feature.elektronik`
- Retur free: `returns.sales_allow_free` / `returns.purchase_allow_free`

## ULID

Public API expose `ulid`; model `$hidden = ['id']`.

## Gotchas

1. SoftDeletes master — jangan `withTrashed()` sembarangan  
2. `FOREIGN_KEY_CHECKS` hanya MySQL — guard driver  
3. Backup = ZIP (`database.sql` + uploads)  
4. Prefix dokumen 3-char di `SettingService::getPrefix` (`POR`, `SOM`, `PBS`, …)  
5. Timezone via settings + `LocalDateTime` cast  
6. `$fillable` explicit  
7. Observer skip pola di Action — jangan diubah ringan  
8. Filter DATETIME: akhir hari `23:59:59` atau `whereDate()` / trait [`HasDateRangeScope`](../../syilex/app/Traits/HasDateRangeScope.php)

## Saat mengubah kode

- Multi-table → `DB::transaction()` + `lockForUpdate` bila perlu
- Touch stock → pair `stock_card`
- Action baru → test + permission
- Migration: `down()`; guard MySQL-only di test
- Invariant baru → `VerifyDataInvariants`

## Health

- `GET /api/v1/health`
- `php artisan data:verify`

## JANGAN

- Trust promo FE  
- Mutasi stock tanpa stock_card  
- `queue:sync` di prod  
- Endpoint tanpa `->can()` + seeder  
- Filter date-only polos pada kolom DATETIME  
