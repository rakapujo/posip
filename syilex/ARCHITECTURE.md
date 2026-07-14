# Architecture — SIPOS

Flow diagram + high-level arsitektur sistem. Diagram pakai Mermaid (auto-render di GitHub/GitLab/VSCode Mermaid Preview).

---

## 1. System Overview

```mermaid
graph LR
    subgraph Client
        BRW[Browser SPA<br/>Vue 3 + Vite]
        THM[Browser ESC/POS<br/>Web Serial / USB / BT]
        LEG[Print Service Legacy<br/>Python :5123]
    end

    subgraph Server
        APP[Laravel 12 API<br/>PHP 8.2]
        MW[Middleware Pipeline<br/>Auth, Idempotency,<br/>Scheduler Triggers]
        DB[(MySQL 8)]
        FS[(storage/app/public)]
        LOG[(Laravel Log)]
    end

    subgraph Monitoring
        HC[GET /health]
        DV[data:verify CLI]
        CE[/client-errors]
    end

    BRW -->|Bearer Token| APP
    BRW -->|Uint8Array ESC/POS| THM
    BRW -.->|fallback optional| LEG
    APP --> MW
    MW --> DB
    MW --> FS
    MW --> LOG
    CE --> LOG
    HC --> DB
    HC --> FS
    DV --> DB
```

**Key points:**
- Backend API stateless (Sanctum token in header)
- Print thermal via **browser Web API** (Chrome/Edge); legacy Python service opsional di `:5123`
- Frontend bisa deploy terpisah (CDN) atau dari `public/` Laravel
- No external cloud dependency (offsite backup adalah opsional di Tier 7)

---

## 2. Checkout Flow (POS)

End-to-end dari klik "Bayar" sampai struk tercetak:

```mermaid
sequenceDiagram
    autonumber
    actor Kasir
    participant FE as PosKasirPage.vue
    participant BE as CheckoutSalesAction
    participant DB as MySQL
    participant THM as usePrintAdapter<br/>Web Serial/USB/BT
    participant LEG as Print Service Legacy

    Kasir->>FE: Klik BAYAR (F12)
    FE->>FE: Validate cart not empty
    FE->>FE: Generate Idempotency-Key
    FE->>BE: POST /pos/checkout<br/>{ items, payments, diskon_5_* }

    BE->>BE: Middleware auth:sanctum
    BE->>BE: Middleware idempotency (cek cache)

    alt Cache hit (retry)
        BE-->>FE: Replay 2xx response
    else Fresh request
        BE->>DB: BEGIN TRANSACTION
        BE->>DB: SELECT ... FOR UPDATE (inventory_stock)
        BE->>BE: Validate stock cukup
        BE->>BE: Fetch active promos<br/>Pick best per item
        BE->>BE: Rebuild diskon_1..4 dari DB promo<br/>(anti-fraud: override FE value)
        BE->>BE: Preserve diskon_5 dari FE
        BE->>BE: Calculate grand_total + pajak
        BE->>BE: Validate SUM(payments) >= grand_total
        BE->>DB: INSERT doc_sales + details + payments
        BE->>DB: UPDATE inventory_stock (qty -=)
        BE->>DB: INSERT stock_card (SALES)
        BE->>DB: COMMIT
        BE->>BE: Cache 2xx response 10 min
        BE-->>FE: 201 Created { sales, receipt_url }
    end

    FE->>FE: Show receipt dialog
    opt auto_print_receipt
        FE->>FE: buildReceipt ESC/POS (useReceiptEscPos)
        FE->>THM: printRaw base64 → Uint8Array
        THM->>THM: trySilentReconnect → write()
    end

    alt Browser transport gagal
        THM-.->LEG: optional HTTP POST /print/raw
        FE->>FE: Fallback: PDF print preview
    end
```

**Key invariants:**
- Semua write dalam 1 transaction
- Stock locked via `lockForUpdate` sebelum decrement
- `stock_card` entry per (product, warehouse) selalu match mutation
- Idempotency cache prevent double-charge di network retry

---

## 3. Return Flow (Retur Sales)

```mermaid
sequenceDiagram
    actor Kasir
    participant FE as PosKasirPage.vue
    participant BE as ProcessSalesReturnAction
    participant DB as MySQL

    Kasir->>FE: Pilih transaksi → Retur
    FE->>FE: Tampilkan form pilih item + qty
    FE->>BE: POST /pos/returns { sales_id, items }

    BE->>BE: Validate sales.status === 'completed'
    BE->>BE: Validate qty_retur <= qty_sold - qty_already_returned

    BE->>DB: BEGIN
    BE->>DB: SELECT doc_sales FOR UPDATE
    BE->>DB: INSERT doc_sales_returns + details
    BE->>DB: UPDATE inventory_stock (qty +=)
    BE->>DB: INSERT stock_card (SALES_RETURN)
    Note over BE: HPP TIDAK direkalkulasi<br/>(by design)
    BE->>BE: Refund tunai:<br/>INSERT pos_cash_transactions (kas_keluar)
    BE->>DB: UPDATE doc_sales.retur_status<br/>('partial' atau 'full')
    BE->>DB: COMMIT

    BE-->>FE: { return_doc, refund_nominal }
    FE->>FE: Print refund receipt
```

**Catatan:**
- Void ≠ Retur: Void = batal total sebelum completion, Retur = pengembalian barang setelah completed
- Return hanya bisa kalau sales status = completed (tidak bisa kalau voided)
- Void tidak bisa kalau sudah ada return (harus un-retur dulu, tapi tidak ada flow un-retur — harus manual DB)

---

## 4. Price Change Auto-Apply (Middleware-Triggered)

```mermaid
graph TD
    A[User request API] --> B{Middleware<br/>ApplyScheduledPriceChanges}
    B -->|Cooldown cached<br/>< 5 min| Z[Pass through]
    B -->|Cooldown expired| C{Scheduler enabled?}
    C -->|No| Z
    C -->|Yes| D{Acquire Cache::lock<br/>price_change_running}
    D -->|Lock taken<br/>by other process| Z
    D -->|Acquired| E[Query pending docs<br/>tanggal_berlaku <= now]
    E -->|Empty| F[Set cooldown cache]
    E -->|Ada batch| G[Loop each doc:<br/>ApplyPriceChangeAction]
    G -->|Success| H[UPDATE master_produk.harga_*]
    G -->|Error| I[Log + continue next doc]
    H --> J[INSERT price_change_trigger_log]
    F --> Z
    J --> Z
    I --> Z
    Z[Response ke user]
```

**Keunggulan:** tidak butuh cron. Scheduler jalan on-demand saat ada traffic user. Ramah shared hosting.

---

## 5. Layered Architecture (Backend)

```mermaid
graph TB
    subgraph "Route Layer"
        RT[routes/api.php]
    end

    subgraph "HTTP Layer"
        MW[Middleware<br/>Auth, Idempotency,<br/>Scheduler, SecurityHeaders]
        CT[Controller<br/>thin: validate + auth + delegate]
    end

    subgraph "Business Layer"
        AC[Actions<br/>transactional write]
        SV[Services<br/>pure business logic:<br/>PromoService, SalesCalculation,<br/>SettingService]
        EX[Custom Exceptions<br/>BusinessException, StockInsufficient,<br/>DocumentStateException]
    end

    subgraph "Data Layer"
        MD[Eloquent Models<br/>+ Traits: HasUlid, HasAuditLog,<br/>HasCreatedUpdatedBy]
        OB[Observers<br/>auto stock_card write]
    end

    subgraph "Infrastructure"
        DB[(MySQL)]
        CC[Cache]
        LG[Log]
        ST[Storage]
    end

    RT --> MW
    MW --> CT
    CT --> AC
    CT --> SV
    AC --> SV
    AC --> MD
    AC -.throw.-> EX
    MD --> OB
    MD --> DB
    CC -.lock.-> AC
    AC --> LG
    AC --> ST
    EX -.render 422.-> CT
```

**Prinsip:**
- **Thin controller:** <100 LOC per method. Cuma validate + authorize + `Action::execute()`
- **Action per operasi:** 1 action = 1 business verb (Checkout, Void, Return, Approve, Cancel, Lock, Unlock)
- **Service untuk shared logic:** kalau dipakai >1 action, pindah ke service
- **Exception-driven:** throw custom exception → auto render ke 422 dengan message jelas

---

## 6. Frontend Structure

```mermaid
graph TB
    subgraph "Entry"
        MN[main.js]
    end

    subgraph "Routing"
        RT[router/index.js<br/>+ guards]
    end

    subgraph "State"
        PS[Pinia Stores<br/>auth, preferences, settings]
    end

    subgraph "UI Layer"
        LO[Layout<br/>AppLayout, AppMenu]
        VW[Views<br/>pages per domain]
        CM[Components<br/>common + domain]
    end

    subgraph "Logic Reuse"
        CP[Composables<br/>useNotification, useFormatters,<br/>usePosCart, useTransactionList,<br/>useErrorLogger]
    end

    subgraph "API"
        AX[axios client<br/>+ interceptors]
        MD[api/modules/<br/>per resource wrapper]
    end

    MN --> RT
    MN --> PS
    RT --> LO
    LO --> VW
    VW --> CM
    VW --> CP
    VW --> MD
    CP --> MD
    MD --> AX
    AX -.401.-> PS
```

**Router guard:**
```js
router.beforeEach((to, from, next) => {
    if (to.meta.requiresAuth && !authStore.isLoggedIn) return next('/login');
    if (to.meta.permission && !authStore.can(to.meta.permission)) return next('/forbidden');
    next();
});
```

---

## 7. Stock Ledger Invariant

```mermaid
graph LR
    PO[Purchase Order<br/>Approve] -->|qty_in| SC[stock_card]
    PR[Purchase Return<br/>Lock] -->|qty_out| SC
    SL[Sales<br/>Checkout] -->|qty_out| SC
    SR[Sales Return<br/>Process] -->|qty_in| SC
    TR[Transfer<br/>Approve] -->|qty_out FROM<br/>qty_in TO| SC
    AD[Adjustment<br/>Approve] -->|qty_in OR qty_out| SC
    RP[Repack<br/>Approve] -->|qty_in/out| SC
    OP[Opname<br/>Approve] -->|via Adjustment| AD
    HC[HPP Correction] -->|HPP_CORRECTION entry| SC

    SC -->|SUM per<br/>product+warehouse| IV[inventory_stock.qty]

    DV[data:verify] -.check.-> SC
    DV -.check.-> IV
```

**Rule iron-clad:**
1. Setiap mutation `inventory_stock` ada entry `stock_card` dengan delta yang sama
2. Setiap entry `stock_card` punya `transaction_type` + `transaction_no` (referensi dokumen asal)
3. HPP recalc hanya di `PURCHASE_RECEIVE` dan `ADJUSTMENT_IN`
4. Invariant verifiable: `SUM(qty_in - qty_out) per (prod, wh) === inventory_stock.qty`

Verifikasi via:
```bash
php artisan data:verify
```

---

## 8. HPP Calculation Flow

```mermaid
graph TD
    A[Purchase Order Receive<br/>atau Adjustment IN] --> B{current avg_cost exists?}
    B -->|Tidak / avg_cost=0| C[New avg_cost = harga_beli_baru]
    B -->|Ya| D[totalQty = current_stock + incoming_qty]
    D --> E{totalQty > 0?}
    E -->|Tidak| F[avg_cost tetap current]
    E -->|Ya| G[new_avg_cost =<br/>(current_stock * current_avg + incoming_qty * incoming_cost)<br/>/ totalQty]
    G --> H[UPDATE master_produk.avg_cost]
    C --> H
    F --> I[Return]
    H --> I
```

**Division-by-zero guard** penting karena adjustment bisa bikin stock 0 dulu baru IN lagi.

---

## 9. Shift Lifecycle

```mermaid
stateDiagram-v2
    [*] --> Started: start-shift
    Started --> Locked: lock (optional)
    Locked --> Started: unlock
    Started --> Ended: end-shift<br/>(hitung saldo_fisik)
    Started --> ForceEnded: force-release<br/>(admin only)
    Ended --> [*]
    ForceEnded --> [*]

    note right of Started
        pos_cash_transactions
        • setor_awal
        • kas_masuk
        • kas_keluar
        • (refund retur otomatis)
    end note

    note right of Ended
        Computed:
        saldo_system = setor_awal
         + SUM(cash_sales - kembalian)
         + kas_masuk
         - kas_keluar_manual
         - refund_tunai
        
        Stored:
        • saldo_system (snapshot)
        • saldo_fisik (input kasir)
        • selisih = saldo_fisik - saldo_system
        • closing_notes
    end note
```

---

## 10. Domain Reference Quick Map

| Domain | Controller | Main Actions | Key Tables |
|--------|------------|--------------|------------|
| Sales | `PosController`, `SalesReportController` | `CheckoutSalesAction`, `VoidSalesAction`, `ProcessSalesReturnAction` | `doc_sales*`, `pos_cash_transactions`, `pos_terminal_shifts` |
| Purchase | `PurchaseOrderController` | `CreatePurchaseOrderAction`, `ApprovePurchaseOrderAction` | `doc_purchase_order*`, `supplier_hutang`, `history_harga_beli` |
| Purchase Return | `PurchaseReturnController` | `LockPurchaseReturnAction`, `CancelPurchaseReturnAction` | `doc_purchase_return*` |
| Adjustment | `AdjustmentController` | `ApproveAdjustmentAction`, `CancelAdjustmentAction` | `doc_adjustment*` |
| Transfer | `TransferController` | `ApproveTransferAction` | `doc_transfer*` |
| Repack | `RepackController` | `ApproveRepackAction` | `doc_repack*` |
| Opname | `StockOpnameController` | `ApproveStockOpnameAction` | `doc_stock_opname*`, `doc_adjustment` (auto-link) |
| Price Change | `PriceChangeController` | `ApplyPriceChangeAction`, `ApprovePriceChangeAction`, `CancelPriceChangeAction` | `doc_price_change*`, `price_change_trigger_log` |
| Promo | `PromoController` | via `PromoService` | `doc_promo*` |
| HPP Correction | `HppCorrectionController` | `ApproveHppCorrectionAction` | `doc_hpp_correction*` |
| Pembayaran Hutang | `PembayaranHutangController` | `CompletePembayaranHutangAction` | `doc_pembayaran_hutang*`, `supplier_hutang`, `supplier_deposit` |

---

## 11. Middleware Pipeline

```mermaid
graph LR
    R[Request] --> A[CORS]
    A --> B[auth:sanctum<br/>protected routes]
    B --> C[idempotency<br/>POS checkout]
    C --> D[ApplyScheduledPriceChanges<br/>API group]
    D --> E[CleanupActivityLog<br/>API group]
    E --> F[PreventApiCaching]
    F --> G[SecurityHeaders]
    G --> H[Controller Action]
    H --> I[Response]
```

Order penting — scheduler middleware setelah auth supaya ada `auth()->id()` untuk logging.

---

## 12. Error Handling Pipeline

```mermaid
graph TD
    A[Controller/Action] -->|throw| B{Exception type}
    B -->|BusinessException<br/>family| C[bootstrap/app.php<br/>withExceptions render]
    C --> D[422 Unprocessable<br/>structured JSON]
    B -->|ValidationException| E[422 with errors object]
    B -->|AuthenticationException| F[401 Unauthenticated]
    B -->|AuthorizationException| G[403 Forbidden]
    B -->|ModelNotFoundException| H[404 Not Found]
    B -->|Generic Exception| I[500 Server Error<br/>+ log ke laravel.log]

    D --> J[Frontend receives]
    E --> J
    F --> J
    G --> J
    H --> J
    I --> J

    J --> K[notify.apiError wrapper<br/>extract message<br/>translate status code<br/>show toast]
```

---

## 13. Further Reading

- [CLAUDE.md](CLAUDE.md) — AI agent guide
- [API_DOCS.md](API_DOCS.md) — endpoint reference
- [DEPLOY.md](DEPLOY.md) — production deploy
- [RESTORE_DRILL.md](RESTORE_DRILL.md) — disaster recovery
- [ONBOARDING.md](ONBOARDING.md) — new dev setup
- Actions: `app/Actions/` — baca source per flow yang mau dimengerti
