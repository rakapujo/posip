# Modul Promosi — Desain Detail

## Konsep Inti

```
1 Doc Promo = Header (aturan global) + Detail rows (target + syarat + 4 line diskon)

Header → siapa, kapan (customer, terminal, periode, jam)
Detail → apa, berapa, diskon berapa (target produk, min qty, line 1-4)
```

- Setiap detail row punya **target sendiri** + **min qty** + **4 line diskon**
- 1 item di POS bisa match beberapa detail row → semua yang match diterapkan ke slot masing-masing
- Jika 1 item kena **2+ doc promo** → simulate semua doc → ambil yang **total diskon rupiah terbesar**
- **Tidak pernah campur** detail dari doc berbeda — 1 item = 1 doc pemenang
- Kasir tidak bisa edit diskon promo (locked), hanya diskon_5 (manual) yang bisa diedit
- Tiebreaker jika total diskon sama: doc terbaru (created_at desc)

---

## Database Schema

### Tabel: `doc_promo` (Header)

```
id                      BIGINT PK
ulid                    CHAR(26) UNIQUE
kode_promo              VARCHAR(20) UNIQUE — auto: {PREFIX}-{YYMM}-{SEQ:4}
nama_promo              VARCHAR(100)
deskripsi               TEXT NULLABLE

-- Batasan (siapa yang dapat promo)
customer_type_id        FK NULLABLE → master_tipe_customer (null = semua)
terminal_id             FK NULLABLE → master_pos_terminal (null = semua)

-- Periode (kapan promo aktif)
tanggal_mulai           DATE
tanggal_selesai         DATE NULLABLE (null = tanpa batas)
jam_mulai               TIME NULLABLE (null = sepanjang hari)
jam_selesai             TIME NULLABLE

-- Status (hanya 3 di DB — active & expired adalah computed)
status                  ENUM('draft', 'approved', 'inactive') DEFAULT 'draft'
approved_at             DATETIME NULLABLE
approved_by             FK NULLABLE → users

-- Audit
created_by              FK → users
updated_by              FK NULLABLE → users
timestamps

-- Index
INDEX(status, tanggal_mulai, tanggal_selesai)
```

### Tabel: `doc_promo_details` (Baris Aturan Diskon)

```
id                      BIGINT PK
promo_id                FK → doc_promo (CASCADE DELETE)

-- Target (apa yang kena)
target_type             ENUM('semua', 'produk', 'grup', 'kategori') DEFAULT 'semua'
target_id               BIGINT NULLABLE — FK ke master_produk/grup/kategori (null jika 'semua')

-- Syarat
min_qty                 INT DEFAULT 1

-- 4 Line Diskon (mengisi slot diskon_1 s/d diskon_4 di item)
diskon_1_tipe           ENUM('percent', 'nominal', 'none') DEFAULT 'none'
diskon_1_nilai          DECIMAL(15,2) DEFAULT 0
diskon_2_tipe           ENUM('percent', 'nominal', 'none') DEFAULT 'none'
diskon_2_nilai          DECIMAL(15,2) DEFAULT 0
diskon_3_tipe           ENUM('percent', 'nominal', 'none') DEFAULT 'none'
diskon_3_nilai          DECIMAL(15,2) DEFAULT 0
diskon_4_tipe           ENUM('percent', 'nominal', 'none') DEFAULT 'none'
diskon_4_nilai          DECIMAL(15,2) DEFAULT 0

keterangan              VARCHAR(100) NULLABLE
```

### Tabel Existing yang Dimodifikasi: `doc_sales_detail`

```
-- Tambah kolom untuk audit trail promo
promo_id                FK NULLABLE → doc_promo (null jika tanpa promo)
```

**Total: 2 tabel baru + 1 kolom baru.**

---

## Status: Lazy Evaluation (Tidak Perlu Scheduler)

### Mengapa Tidak Perlu Scheduler?

Price Change perlu scheduler karena dia **mengubah data master** (harga di master_produk).
Promo **tidak mengubah data master** — hanya diterapkan saat checkout.

```
Price Change: approved → HARUS update master_produk → perlu scheduler/trigger
Promo:        approved → CUKUP query saat checkout → lazy eval
```

### Status di Database vs Status Computed

```
┌─────────────────────────────────────────────────────────────┐
│ Status DI DATABASE (3 nilai):                               │
│   draft     = belum approved, bisa edit/hapus               │
│   approved  = sudah approved, menunggu/sedang berlaku       │
│   inactive  = dimatikan manual oleh admin                   │
│                                                             │
│ Status COMPUTED (dihitung dari tanggal):                     │
│   active    = approved + tanggal_mulai <= now                │
│               + (tanggal_selesai >= now ATAU null)           │
│               + (jam_mulai <= now <= jam_selesai ATAU null)  │
│   expired   = approved + tanggal_selesai < now              │
│   upcoming  = approved + tanggal_mulai > now                │
└─────────────────────────────────────────────────────────────┘
```

### Scope di Model

```php
// Promo yang berlaku SEKARANG (untuk POS & checkout)
public function scopeEffective($query)
{
    $now = now();
    return $query->where('status', 'approved')
        ->where('tanggal_mulai', '<=', $now->toDateString())
        ->where(fn($q) => $q->whereNull('tanggal_selesai')
                             ->orWhere('tanggal_selesai', '>=', $now->toDateString()))
        ->where(fn($q) => $q->whereNull('jam_mulai')
                             ->orWhere(fn($q2) => $q2->where('jam_mulai', '<=', $now->format('H:i:s'))
                                                      ->where('jam_selesai', '>=', $now->format('H:i:s'))));
}

// Untuk filter list di admin
public function scopeByDisplayStatus($query, $status)
{
    return match($status) {
        'draft' => $query->where('status', 'draft'),
        'active' => $query->effective(),
        'upcoming' => $query->where('status', 'approved')->where('tanggal_mulai', '>', now()),
        'expired' => $query->where('status', 'approved')->where('tanggal_selesai', '<', now()),
        'inactive' => $query->where('status', 'inactive'),
        default => $query,
    };
}
```

### Keuntungan Lazy Eval

- **Tidak perlu scheduler/cron** — tidak ada proses background
- **Selalu akurat** — status dihitung real-time dari tanggal
- **Restart-safe** — server mati lalu nyala, promo otomatis berlaku lagi
- **Tidak ada stale state** — tidak bisa terjadi "status DB = active tapi tanggal sudah lewat"
- **Match pattern existing** — DocPriceChange.scopePending() pakai pola yang sama

---

## Status Flow (State Machine)

```
                         [Approve]
  [Buat Baru]            (permission: promo.approve)
 ──────────→  DRAFT  ──────────────→  APPROVED
                ↑                        │
                │    [Batalkan]          │
                └────────────────────────┘
                                         │
                              ┌──────────┴──────────┐
                              │                     │
                         (computed)            [Nonaktifkan]
                              │              (promo.toggle)
                              ▼                     │
                  ┌───────────────────┐             ▼
                  │                   │        INACTIVE
                  │  tanggal_mulai    │             │
                  │  <= now?          │    [Aktifkan Kembali]
                  │                   │    (promo.toggle)
                  │  YES = ACTIVE     │             │
                  │  NO  = UPCOMING   │             │
                  │                   │     ┌───────┘
                  │  tanggal_selesai  │     │
                  │  < now?           │     └──→ APPROVED
                  │                   │          (kembali ke approved,
                  │  YES = EXPIRED    │           lazy eval tentukan
                  │                   │           active/upcoming/expired)
                  └───────────────────┘
```

**Transisi status:**

| Dari | Ke | Trigger | Permission | Ubah DB? |
|------|----|---------|------------|----------|
| (baru) | draft | Buat promo | `promo.create` | Ya |
| draft | approved | Klik [Approve] | `promo.approve` | Ya |
| approved | draft | Klik [Batalkan] | `promo.approve` | Ya |
| approved | active | **Computed** (tanggal_mulai <= now) | - | **Tidak** |
| approved | expired | **Computed** (tanggal_selesai < now) | - | **Tidak** |
| approved | upcoming | **Computed** (tanggal_mulai > now) | - | **Tidak** |
| active/expired/upcoming | inactive | Klik [Nonaktifkan] | `promo.toggle` | Ya (status → inactive) |
| inactive | approved | Klik [Aktifkan Kembali] | `promo.toggle` | Ya (status → approved) |

**Aturan edit/hapus:**
- Edit: hanya status `draft`
- Hapus: hanya status `draft`
- Approved/inactive: tidak bisa edit, harus batalkan dulu ke draft

---

## Cara Kerja (Visual)

### Struktur 1 Doc Promo

```
╔══════════════════════════════════════════════════════════════════════════╗
║  PROMO: Ramadhan Sale                                                  ║
║  Berlaku: 1-30 April 2026, 10:00-14:00                                ║
║  Customer: Semua  |  Terminal: Semua                                   ║
╠══════════════════════════════════════════════════════════════════════════╣
║                                                                        ║
║  Target           │ Min Qty │ Line 1 │ Line 2 │ Line 3 │ Line 4      ║
║  ─────────────────┼─────────┼────────┼────────┼────────┼──────────   ║
║  Semua Produk     │    1    │  5%    │   -    │   -    │   -         ║
║  Grup: Makanan    │    3    │   -    │  3%    │   -    │   -         ║
║  Produk: Indomie  │    5    │   -    │   -    │  2%    │   -         ║
║  Semua Produk     │   10    │   -    │   -    │   -    │  Rp 500     ║
║                                                                        ║
╚══════════════════════════════════════════════════════════════════════════╝
```

### Matching Per Item

```
Indomie Goreng (Grup: Makanan), qty=5, harga=10.000

Cek setiap baris detail:
  Baris 1: target=Semua  ✓  qty 5≥1  ✓  → diskon_1 = 5%
  Baris 2: target=Makanan ✓  qty 5≥3  ✓  → diskon_2 = 3%
  Baris 3: target=Indomie ✓  qty 5≥5  ✓  → diskon_3 = 2%
  Baris 4: target=Semua  ✓  qty 5<10  ✗  → diskon_4 = -

Hasil: diskon_1=5%, diskon_2=3%, diskon_3=2%, diskon_4=none
```

```
Aqua 600ml (Grup: Minuman), qty=5, harga=4.000

Cek setiap baris detail:
  Baris 1: target=Semua   ✓  qty 5≥1  ✓  → diskon_1 = 5%
  Baris 2: target=Makanan ✗                → diskon_2 = -
  Baris 3: target=Indomie ✗                → diskon_3 = -
  Baris 4: target=Semua   ✓  qty 5<10  ✗  → diskon_4 = -

Hasil: diskon_1=5%, diskon_2=none, diskon_3=none, diskon_4=none
```

### Bertingkat: Qty Berubah → Diskon Berubah

**Indomie, harga Rp 10.000:**

```
Qty=1:   Line1=5%                      → diskon 500 (5,0%)
Qty=3:   Line1=5% + Line2=3%           → diskon 2.355 (7,9%)
Qty=5:   Line1=5% + Line2=3% + Line3=2% → diskon 4.746 (9,5%)
Qty=10:  Semua line aktif               → diskon 9.993 (10,0%)
```

### 2 Detail Baris Isi Slot Sama → Ambil Terbesar

```
Detail 1: Semua, ≥1 → Line1=5%
Detail 2: Makanan, ≥1 → Line1=8%   ← SAMA-SAMA ISI LINE 1!

Indomie (Makanan): keduanya match → Line1=8% (yang lebih besar)
```

---

## Bentrok Antar Doc Promo

### Aturan: 1 Item = 1 Doc Pemenang

Tidak pernah campur detail dari doc berbeda. Simulate semua doc, ambil total terbesar.

```
Doc A "Ramadhan":                        Doc B "Flash Sale":
  Detail 1: Semua, ≥1 → Line1=5%          Detail 1: Semua, ≥1 → Line1=8%
  Detail 2: Makanan, ≥3 → Line2=3%
  Detail 3: Aqua, ≥1 → Line3=10%

Indomie (Makanan), qty=3:
  Doc A: 1.500 + 855 = Rp 2.355
  Doc B: 2.400 = Rp 2.400         ← Doc B menang

Aqua (Minuman), qty=1:
  Doc A: 200 + 190 = Rp 390       ← Doc A menang
  Doc B: 320 = Rp 320

Hasil: Indomie pakai Doc B, Aqua pakai Doc A — normal, beda item beda pemenang.
```

---

## Best Case & Worst Case

### Best Case: 1 Promo, Match Sempurna

```
1 doc promo aktif, semua item match, qty cukup
→ Semua item dapat diskon dari promo itu
→ Tidak ada konflik, tidak ada simulasi perbandingan
→ Paling cepat
```

### Worst Case: Banyak Promo Aktif Bersamaan

```
10 doc promo aktif, 20 item di keranjang
→ Per item: simulate 10 doc → ambil terbaik
→ Total: 20 × 10 = 200 simulasi
→ Setiap simulasi: loop detail baris × 4 line = ringan
→ Masih cepat (< 50ms)
```

### Worst Case: Qty Berubah → Promo Berubah

```
Kasir scan Indomie qty=1:
  Doc A: Line1=5% → 500         Doc B: Line1=8% → 800
  → Pakai Doc B

Kasir ubah qty=5:
  Doc A: Line1=5%+L2=3%+L3=2% → 4.746    Doc B: Line1=8% → 4.000
  → BERALIH ke Doc A!
  → UI update label: "Flash Sale" → "Ramadhan Sale"
```

### Worst Case: Promo Expired Saat Checkout

```
Promo aktif s/d 14 Apr, jam_selesai=14:00
Kasir scan item jam 13:50 → promo diterapkan di frontend
Kasir checkout jam 14:05 → backend call PromoService::getActivePromos()
→ Query: jam_selesai >= 14:05 → FALSE → promo tidak ter-include
→ diskon_1-4 di-clear otomatis (promo tidak match)
→ Grand total berubah dari preview
→ Frontend handle: tampilkan warning "Promo telah berakhir, total berubah"
```

**Ini BUKAN error — ini fitur keamanan.** Backend selalu rebuild dari DB.

### Worst Case: Global Setting OFF

```
Setting promo.enabled = false
→ PromoService::getActivePromos() return empty
→ diskon_1-4 selalu none
→ Hanya diskon_5 manual yang bisa dipakai
```

### Worst Case: Server Down, Promo Seharusnya Aktif

```
Server down 13:45-14:15
Promo tanggal_mulai = 14:00
Server restart 14:20 → user login
→ Query real-time: tanggal_mulai 14:00 <= now 14:20 ✓ → promo aktif
→ TIDAK ADA data loss, TIDAK ADA catchup needed
→ Berbeda dengan Price Change yang perlu login listener
```

### Worst Case: Frontend Cache Stale

```
Frontend load promo list jam 10:00, cache di browser
Admin aktifkan promo baru jam 10:15
Kasir scan item jam 10:20 → frontend masih pakai cache lama → promo baru tidak tampil
→ Solusi: polling setiap 5 menit + refresh saat customer berubah
→ Checkout TETAP AMAN karena backend rebuild dari DB real-time
```

### Edge Case: Walk-in Customer + Promo Customer-Spesifik

```
Promo "Ramadhan" dengan customer_type_id = 5 (VIP)
Customer walk-in (tidak punya tipe_customer_id)

Query filter:
  WHERE customer_type_id = 5 OR customer_type_id IS NULL
  → Walk-in: customer_type_id = NULL pada sisi customer (bukan promo)
  → Walk-in TIDAK match promo yang customer_type_id=5
  → Walk-in hanya dapat promo yang customer_type_id IS NULL (target semua customer)

Ini by design — walk-in tidak qualify untuk promo khusus member.
```

### Edge Case: Customer Berubah Mid-Transaction

```
Kasir pilih customer A (VIP) → promo VIP diterapkan → diskon_1-4 = promo VIP
Kasir ganti customer B (regular) → promo VIP tidak berlaku

Behavior:
  watch(() => cart.customer.value, () => loadActivePromos())
  → Fetch ulang promo aktif untuk customer B
  → Reapply ke semua items (diskon_1-4 ter-override)
  → diskon_5 (manual kasir) TETAP → tidak di-reset

UX:
  Tampilkan toast: "Promo diperbarui karena customer berubah"
  Grand total ter-update otomatis
```

### Edge Case: Empty Promo (0 Detail Rows)

```
Admin buat promo draft tanpa detail → boleh (masih draft)
Admin klik Approve → REJECT dengan error:
  "Promo harus memiliki minimal 1 detail baris dengan diskon"

Validasi di PromoController::approve():
  if ($promo->details()->count() === 0) throw ValidationException
  if (semua detail diskon_1-4 = 'none') throw ValidationException

Jika somehow tersimpan (manual DB) → getActivePromos() skip:
  Effective scope return promo, tapi simulatePromo() return total_diskon = 0
  → findBestPromo() skip promo dengan total_diskon = 0
  → Item tidak dapat apa-apa (aman)
```

---

## Dampak ke Modul Lain

### Modul Retur — By Design, Tidak Perlu Fix

```
Skenario:
  Beli 5 Indomie → dapat promo "≥5 pcs diskon 2%" di Line 3
  Retur 2 → sisa 3 pcs → seharusnya tidak qualify promo ≥5 lagi

Behavior:
  Refund dihitung dari harga prorated (termasuk diskon promo)
  Sistem TIDAK recalculate promo untuk sisa qty
  Customer "untung" dari diskon yang seharusnya hilang

Status: BY DESIGN — standard POS retail
  - Retur selalu berdasarkan harga bayar, bukan harga asli
  - Sama behaviour-nya dengan diskon manual
  - Recalculate promo post-retur terlalu kompleks & membingungkan customer
```

### Modul Hold/Resume — PERLU DI-HANDLE

```
Skenario:
  Jam 13:50: kasir hold transaksi → item punya promo 10%
  Jam 14:05: kasir resume → promo sudah expired
  
Masalah:
  localStorage masih simpan diskon_1-4 dari promo lama
  Frontend tampilkan diskon yang sudah tidak berlaku
  
Safety net:
  Checkout backend SELALU rebuild diskon_1-4 dari DB
  → Grand total final tetap benar
  → Tapi frontend preview beda dari final = UX buruk

Solusi (implementasi di Phase 2):
  Saat resumeHold():
  1. Load items dari localStorage
  2. Fetch active promos terbaru
  3. Reapply promo ke semua items
  4. Jika diskon berubah → tampilkan info "Promo telah diperbarui"
```

### Modul Lain — Semua Aman

| Modul | Status | Alasan |
|-------|--------|--------|
| Void | ✅ AMAN | Void restore stock, tidak sentuh diskon |
| Price Change | ✅ AMAN | Held cart pakai snapshot harga, promo calculate dari harga cart |
| Inventory | ✅ AMAN | Diskon tidak pengaruhi qty stock |
| Payment | ✅ AMAN | grand_total berkurang, payment validation tetap valid |
| Shift Report | ✅ AMAN | Omzet = sum grand_total (benar), breakdown diskon_line_1-5 sudah ada |
| Sales Reports | ✅ AMAN | SalesDiscLineExport handle semua 5 level diskon |
| Customer | ✅ AMAN | Filter by customer_type, walk-in dapat promo jika target=semua |
| Product | ✅ AMAN | Filter by produk/grup/kategori, produk inactive ditolak checkout |
| Terminal | ✅ AMAN | Filter by terminal, terminal immutable selama shift |
| HPP/Margin | ✅ AMAN | Promo kurangi jumlah → margin turun (matematika benar) |
| Tax | ✅ AMAN | DPP dari total_setelah_diskon → promo kurangi → tax turun (benar) |
| Rounding | ✅ AMAN | Rounding setelah tax, grand_total berubah → rounding berubah (benar) |

---

## Integrasi Teknis

### 1. PromoService.php (Service Baru — DRY)

```php
class PromoService
{
    /**
     * Get semua doc promo yang berlaku SEKARANG.
     * Dipakai oleh: POS API (frontend preview) + CheckoutAction (anti-fraud).
     * Filter: status=approved, periode OK, jam OK, customer OK, terminal OK.
     *
     * PENTING: Eager load 'details' untuk hindari N+1 saat matching per item.
     */
    public static function getActivePromos(?int $terminalId, ?int $customerTypeId): Collection
    {
        return DocPromo::effective()
            ->when($terminalId, fn($q) => $q->where(fn($x) => $x->whereNull('terminal_id')->orWhere('terminal_id', $terminalId)))
            ->when($customerTypeId, fn($q) => $q->where(fn($x) => $x->whereNull('customer_type_id')->orWhere('customer_type_id', $customerTypeId)))
            ->with(['details'])  // WAJIB: cegah N+1 saat loop per item
            ->orderByDesc('created_at')  // tiebreaker
            ->get();
    }

    /**
     * Cari doc promo terbaik untuk 1 item.
     * - Loop semua doc promo aktif
     * - Per doc: simulate total diskon rupiah
     * - Ambil doc dengan total diskon terbesar
     * - Tiebreaker: created_at desc (sudah diurutkan di getActivePromos)
     */
    public static function findBestPromo(
        int $productId, ?int $grupId, ?int $kategoriId,
        float $qty, float $harga, Collection $activePromos, string $discountMode
    ): ?array;

    /**
     * Simulate diskon dari 1 doc promo untuk 1 item.
     *
     * Algoritma:
     * 1. Loop semua detail baris, filter yang match (target + min_qty)
     * 2. Per slot (Line 1-4): jika 2+ detail match dan isi slot sama,
     *    hitung nilai rupiah masing-masing → ambil yang terbesar
     * 3. Calculate final dengan recursive/sum mode
     *
     * Return format:
     *   ['promo_id' => 1, 'nama_promo' => 'Ramadhan',
     *    'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 8,
     *    'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
     *    ...
     *    'total_diskon' => 2400.00]
     */
    public static function simulatePromo(
        DocPromo $promo, int $productId, ?int $grupId, ?int $kategoriId,
        float $qty, float $harga, string $discountMode
    ): array;
}
```

#### Algoritma "Max of Line Slot" (Ketika 2 Detail Isi Slot Sama)

```php
// Dalam simulatePromo(), setelah filter detail yang match:
$bestPerSlot = [
    1 => ['tipe' => 'none', 'nilai' => 0, 'rupiah' => 0],
    2 => ['tipe' => 'none', 'nilai' => 0, 'rupiah' => 0],
    3 => ['tipe' => 'none', 'nilai' => 0, 'rupiah' => 0],
    4 => ['tipe' => 'none', 'nilai' => 0, 'rupiah' => 0],
];

$bruto = $qty * $harga;
foreach ($matchingDetails as $detail) {
    for ($i = 1; $i <= 4; $i++) {
        $tipe = $detail["diskon_{$i}_tipe"];
        $nilai = $detail["diskon_{$i}_nilai"];
        if ($tipe === 'none' || $nilai == 0) continue;

        // Hitung nilai rupiah untuk comparison yang fair
        $rupiah = SalesCalculationService::calculateDiscountLevel($tipe, $nilai, $bruto);

        // Ambil yang rupiahnya terbesar untuk slot ini
        if ($rupiah > $bestPerSlot[$i]['rupiah']) {
            $bestPerSlot[$i] = ['tipe' => $tipe, 'nilai' => $nilai, 'rupiah' => $rupiah];
        }
    }
}

// $bestPerSlot sekarang berisi nilai terbaik per slot
// Lalu apply recursive/sum mode untuk hitung total_diskon final
```

### 2. CheckoutSalesAction — Anti-Fraud (Exact Insertion)

```
Existing flow:
  Line 25-32:  Terima data (items, payments)
  Line 38-50:  Lock product + inventory (FOR UPDATE)
  >>> INSERT: Rebuild diskon_1-4 dari promo DB <<<
  Line 69-94:  Loop items → calculate diskon_1-5 → hitung subtotal
  Line 96-109: Build nota discounts + calculate totals
  Line 110-167: Create DocSales + DocSalesDetail
```

```php
// INSERT setelah line 50 (setelah lock), sebelum line 69 (sebelum discount calc)

// ─── AUTO-APPLY PROMO (anti-fraud: SELALU rebuild dari DB) ───
if (SettingService::getPromoSettings()['enabled']) {
    $customerTypeId = $customer?->tipe_customer_id;
    $activePromos = PromoService::getActivePromos($terminalId, $customerTypeId);

    foreach ($items as &$item) {
        $product = $products[$item['product_id']];
        $best = PromoService::findBestPromo(
            $item['product_id'],
            $product->grup_id,
            $product->grup?->kategori_id,
            $item['qty'],
            $item['harga_satuan'],
            $activePromos,
            $discountMode
        );

        // Override diskon_1-4 (JANGAN trust frontend)
        for ($i = 1; $i <= 4; $i++) {
            $item["diskon_{$i}_tipe"] = $best["diskon_{$i}_tipe"] ?? 'none';
            $item["diskon_{$i}_nilai"] = $best["diskon_{$i}_nilai"] ?? 0;
        }
        $item['promo_id'] = $best['promo_id'] ?? null;
        // diskon_5 tetap dari frontend (manual kasir)
    }
    unset($item);
}
// ─── END PROMO ───

// Line 69: existing discount calculation loop continues...
```

**Anti-fraud pattern sama dengan customer discount di buildNotaDiscounts():**
- Customer discount: frontend kirim diskon_nota_1/2, backend OVERRIDE dari DB customer
- Promo discount: frontend kirim diskon_1-4, backend OVERRIDE dari DB promo

### 3. usePosCart.js — Auto-Apply Frontend

```javascript
// Cache active promos (refreshed periodically)
const activePromos = ref([]);

// Set promos dari API
const setActivePromos = (promos) => {
    activePromos.value = promos;
    // Reapply ke semua item di keranjang
    items.value.forEach(item => applyPromo(item));
};

// Saat addItem / updateQty → reapply promo
function applyPromo(item) {
    if (!activePromos.value.length) {
        clearPromo(item);
        return;
    }
    const best = findBestPromo(item, activePromos.value);
    if (best) {
        for (let i = 1; i <= 4; i++) {
            item[`diskon_${i}_tipe`] = best[`diskon_${i}_tipe`];
            item[`diskon_${i}_nilai`] = best[`diskon_${i}_nilai`];
        }
        item._promo_id = best.promo_id;
        item._promo_nama = best.nama_promo;
    } else {
        clearPromo(item);
    }
    recalcLine(item);
}
```

### 4. PosKasirPage.vue — Promo Refresh

```javascript
// Load saat mount (shift start)
const activePromos = ref([]);

async function loadActivePromos() {
    try {
        const res = await posApi.getActivePromos();
        activePromos.value = res.data.data?.promos || [];
        cart.setActivePromos(activePromos.value);
    } catch { /* silent */ }
}

onMounted(() => {
    loadActivePromos();
});

// Refresh setiap 5 menit
const promoInterval = setInterval(loadActivePromos, 5 * 60 * 1000);
onBeforeUnmount(() => clearInterval(promoInterval));

// Refresh saat customer berubah (promo bisa per customer_type)
watch(() => cart.customer.value, () => loadActivePromos());
```

### 5. SettingService — Setting

```php
// Existing (sudah ada, tidak perlu ditambah):
'promo.enabled' => true
'promo.allow_manual_discount' => true
'promo.max_manual_discount_percent' => 100
'promo.max_manual_discount_nominal' => null
'calculation.discount_mode' => 'recursive'

// Baru:
'promo.auto_apply' => true      // otomatis apply promo di POS
'promo.show_label' => true      // tampilkan nama promo di item POS
'prefix.promo' => 'PM'          // prefix nomor dokumen
```

**PENTING: Tambahkan 'promo' di `getPrefix()` defaults**

```php
// File: app/Services/SettingService.php, method getPrefix()
// Fallback saat ini: strtoupper('promo') = 'PROMO' (7 karakter, terlalu panjang)
// WAJIB tambahkan eksplisit:

$defaults = [
    'purchase_order' => 'PO',
    'sales' => 'INV',
    'sales_return' => 'SR',
    'price_change' => 'PCH',
    'hpp_correction' => 'HPPC',
    'promo' => 'PM',  // ← TAMBAH BARIS INI
    // ...
];
```

Tanpa ini, `generateDocumentNumber('promo', 'doc_promo')` akan generate `PROMO-2604-0001` bukan `PM-2604-0001`.

### 6. Permission

**Peta diskon lengkap SIPOS — 3 mekanisme, 3 permission, slot berbeda:**

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        DISKON DI SIPOS                                  │
├──────────────────────┬──────────────────┬───────────────────────────────┤
│ Mekanisme            │ Permission       │ Slot yang diisi               │
├──────────────────────┼──────────────────┼───────────────────────────────┤
│ Diskon Customer      │ customer-        │ diskon_nota_1 (tipe customer) │
│ (tipe & kategori)    │ discount.manage  │ diskon_nota_2 (kategori cust) │
│ Dikelola: Admin      │                  │ Otomatis saat pilih customer  │
├──────────────────────┼──────────────────┼───────────────────────────────┤
│ Diskon Manual Kasir  │ pos.discount     │ diskon_nota_3 (nota manual)   │
│ Diinput: Kasir       │                  │ diskon_5 (line item manual)   │
│ Dibatasi: promo.*    │                  │ Divalidasi global settings    │
│ settings             │                  │                               │
├──────────────────────┼──────────────────┼───────────────────────────────┤
│ Promo Otomatis (BARU)│ promo.*          │ diskon_1 (line item auto)     │
│ Dikelola: Admin      │                  │ diskon_2 (line item auto)     │
│ Diterapkan: Otomatis │                  │ diskon_3 (line item auto)     │
│ di POS               │                  │ diskon_4 (line item auto)     │
└──────────────────────┴──────────────────┴───────────────────────────────┘
```

**Permission baru:**

```
promo.view      — lihat daftar promo
promo.create    — buat promo baru (draft)
promo.update    — edit promo (hanya status draft)
promo.delete    — hapus promo (hanya status draft)
promo.approve   — approve / batalkan (draft ↔ approved)
promo.toggle    — nonaktifkan / aktifkan kembali
```

| Role | Permission |
|------|-----------|
| super-admin | Semua `promo.*` |
| admin | Semua `promo.*` |
| kasir | Tidak ada (promo otomatis di POS tanpa permission) |
| gudang | Tidak ada |

---

## Race Condition & Safety

### Checkout: Aman

```
2 kasir checkout bersamaan, produk sama, promo sama:
→ Setiap checkout punya DB transaction sendiri
→ Lock: product + inventory rows (FOR UPDATE)
→ PromoService query independen per transaction
→ Promo tidak punya shared counter → tidak ada race
→ AMAN
```

### Promo Expire Mid-Transaction: Aman

```
Frontend: promo diterapkan jam 13:50
Backend checkout jam 14:05: PromoService.getActivePromos()
→ Query real-time: jam_selesai >= 14:05? NO → promo tidak ter-include
→ diskon_1-4 otomatis di-clear
→ Frontend TIDAK error, tapi grand total berubah
→ Frontend handle: tampilkan info "total berubah"
```

### Server Restart: Aman

```
Server down → restart → promo di DB tidak berubah
→ Query real-time menentukan status
→ Tidak ada data loss, tidak ada catchup needed
→ AMAN
```

### Frontend Cache Stale: Mitigasi

```
Frontend cache promo saat shift start
Admin tambah promo baru mid-shift → frontend belum tahu
→ Mitigasi: polling setiap 5 menit
→ Mitigasi: refresh saat customer berubah
→ Safety net: backend SELALU rebuild dari DB saat checkout
→ Worst case: frontend tampilkan harga tanpa promo, checkout dapat promo (pleasant surprise)
```

### Concurrent Admin Edit: By Design

```
Admin A & B edit promo draft bersamaan → last-write-wins
→ Match existing pattern (price change, master data)
→ Rendah risiko (admin jarang edit bersamaan)
```

---

## Layout Kasar

### A. List Promo

```
┌──────────────────────────────────────────────────────────────┐
│ PROMOSI                                           [+ Tambah] │
├──────────────────────────────────────────────────────────────┤
│ [Cari...]  [Status: v Semua]  [Periode: __ s/d __]          │
├──────┬──────────┬──────────┬───────────┬────────┬───────────┤
│ Kode │ Nama     │ Detail   │ Periode   │ Status │ Aksi      │
├──────┼──────────┼──────────┼───────────┼────────┼───────────┤
│ PM-  │ Ramadhan │ 4 baris  │ 1-30 Apr  │ Active │ [👁][✏][🗑]│
│ 2604 │ Sale     │          │ 10:00-14  │        │           │
├──────┼──────────┼──────────┼───────────┼────────┼───────────┤
│ PM-  │ Flash    │ 1 baris  │ Tanpa     │ Draft  │ [👁][✏][🗑]│
│ 2604 │ Sale     │          │ Batas     │        │[Approve]  │
└──────┴──────────┴──────────┴───────────┴────────┴───────────┘

Filter status: Semua | Draft | Active | Upcoming | Expired | Inactive
(Active/Upcoming/Expired = computed dari tanggal, diquery via scopeByDisplayStatus)
```

### B. Form Promo

```
┌──────────────────────────────────────────────────────────────────────────┐
│ ← Kembali                    BUAT PROMO BARU                            │
├──────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│ ┌─ HEADER ────────────────────────────────────────────────────────────┐  │
│ │  Kode         [PM-2604-0001] (auto)                                │  │
│ │  Nama         [Ramadhan Sale_______________________]               │  │
│ │  Deskripsi    [Diskon spesial bulan Ramadhan_______]               │  │
│ │  Periode      [01/04/2026] s/d [30/04/2026]  [ ] Tanpa Batas      │  │
│ │  Jam          [10:00] s/d [14:00]            [ ] Sepanjang Hari    │  │
│ │  Customer     [v Semua Tipe Customer]                              │  │
│ │  Terminal     [v Semua Terminal]                                    │  │
│ └─────────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│ ┌─ DETAIL DISKON ─────────────────────────────────────────────────────┐  │
│ │                                                                     │  │
│ │ Target          │Min│ Line 1     │ Line 2     │ Line 3  │ Line 4   │  │
│ │ ────────────────┼───┼────────────┼────────────┼─────────┼──────────│  │
│ │[v Semua       ] │[1]│[v %][5____]│[v - ]      │[v - ]   │[v - ]    │  │
│ │[v Grup: Makan ] │[3]│[v - ]      │[v %][3____]│[v - ]   │[v - ]    │  │
│ │[v Prod: Indomi] │[5]│[v - ]      │[v - ]      │[v%][2__]│[v - ]    │  │
│ │[v Semua       ] │[10│[v - ]      │[v - ]      │[v - ]   │[vRp][500]│  │
│ │                                                          [+ Tambah] │  │
│ └─────────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│ ┌─ PREVIEW ───────────────────────────────────────────────────────────┐  │
│ │ Indomie Goreng (Makanan) — Rp 10.000/pcs                          │  │
│ │ ┌─────┬─────────┬─────────┬─────────┬─────────┬────────┬─────┐    │  │
│ │ │ Qty │ Line 1  │ Line 2  │ Line 3  │ Line 4  │ Total  │ %   │    │  │
│ │ ├─────┼─────────┼─────────┼─────────┼─────────┼────────┼─────┤    │  │
│ │ │  1  │ -500    │ -       │ -       │ -       │ -500   │ 5,0 │    │  │
│ │ │  3  │ -1.500  │ -855    │ -       │ -       │ -2.355 │ 7,9 │    │  │
│ │ │  5  │ -2.500  │ -1.425  │ -821    │ -       │ -4.746 │ 9,5 │    │  │
│ │ │ 10  │ -5.000  │ -2.850  │ -1.643  │ -500    │ -9.993 │10,0 │    │  │
│ │ └─────┴─────────┴─────────┴─────────┴─────────┴────────┴─────┘    │  │
│ └─────────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  Status: DRAFT     →  [Simpan Draft]  [Approve]                          │
│  Status: APPROVED  →  [Batalkan] (form disabled)                         │
│  Status: ACTIVE    →  [Nonaktifkan] (form disabled)                      │
│  Status: INACTIVE  →  [Aktifkan Kembali] (form disabled)                 │
└──────────────────────────────────────────────────────────────────────────┘
```

### C. POS Terminal — Item dengan Promo

```
┌─ KERANJANG ────────────────────────────────────────────────────────┐
│                                                                    │
│  Indomie Goreng                                      Rp 17.500    │
│  5 PCS x Rp 3.500                                                 │
│  ┌ Promo Ramadhan Sale ────────────────────────────────────┐      │
│  │ Disc 5%              -875                               │      │
│  │ Makanan ≥3 extra 3%  -499                               │      │
│  │ Indomie ≥5 extra 2%  -322                               │      │
│  └─────────────────────────────────────── -1.696 (9,7%)    ┘      │
│                                                  = Rp 15.804      │
│                                                                    │
│  Aqua 600ml                                        Rp 4.000      │
│  1 PCS x Rp 4.000                                                 │
│  ┌ Promo Ramadhan Sale ────────────────────────────────────┐      │
│  │ Disc 5%              -200                               │      │
│  └─────────────────────────────────────── -200 (5,0%)      ┘      │
│                                                  = Rp 3.800       │
│                                                                    │
│  Teh Botol (Minuman)                               Rp 5.000      │
│  1 PCS x Rp 5.000                                                 │
│  (tidak ada promo)                                                 │
│                                                                    │
├────────────────────────────────────────────────────────────────────┤
│  Subtotal                                        Rp 24.604        │
└────────────────────────────────────────────────────────────────────┘
```

---

## Urutan Implementasi

### Phase 1: Backend
1. Migration (`doc_promo`, `doc_promo_details`, tambah `promo_id` di `doc_sales_detail`)
2. Model (`DocPromo`, `DocPromoDetail`) + scopes + relationships
3. `PromoService` (getActivePromos, findBestPromo, simulatePromo)
4. `PromoController` (CRUD + approve/cancel/toggle)
5. Update `CheckoutSalesAction` (anti-fraud: rebuild diskon_1-4 dari promo)
6. Update `SettingService` (prefix.promo, promo.auto_apply, promo.show_label)
7. Update `UserSeeder` (promo.* permissions)
8. API routes + POS endpoint `getActivePromos`

### Phase 2: Frontend
1. API module (`promosApi`)
2. `PromoPage.vue` (list dengan filter status computed)
3. `PromoFormPage.vue` (create/edit + preview calculator)
4. Update `usePosCart.js` (activePromos, applyPromo, lock diskon_1-4)
5. Update `PosKasirPage.vue` (load promos, polling 5 menit, label promo di item)
6. Router + menu + permission

### Phase 3: Polish
1. Dashboard badge (jumlah promo aktif)
2. Laporan penggunaan promo (sudah ter-capture di laporan diskon line existing + promo_id di doc_sales_detail)
