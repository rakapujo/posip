# Restore Drill — SIPOS

Backup yang tidak pernah dites = tidak punya backup. Jalankan drill **bulanan** di staging/sandbox, bukan di production.

## Tujuan

1. Memastikan file backup (`.zip` atau `.sql.gz`) benar-benar bisa direstore
2. Mengukur RTO (Recovery Time Objective) — berapa lama dari zero sampai app jalan lagi
3. Melatih tim ops mengeksekusi prosedur di bawah tekanan (incident nyata)

## Prasyarat

- Server staging terpisah (jangan test di production!)
- Backup terbaru dari production (copy via scp atau rsync)
- Akses ke `mysql` client + php + composer

## Skenario Drill

### A. Full Disaster Recovery (DB + Files Hilang)

Simulasi: server production mati total, harus rebuild dari backup.

```bash
# 1. Siapkan server staging (fresh install SIPOS, lihat DEPLOY.md)
cd /path/ke/sipos-staging
composer install --no-dev --optimize-autoloader

# 2. Setup .env staging (DB kosong, credential berbeda dari prod)
cp .env.production.example .env
# Edit: DB_DATABASE=sipos_drill (DB kosong baru)
php artisan key:generate

# 3. Copy backup dari prod ke staging
scp prod:/backup/sipos/db_20260415.sql.gz ./restore-test/
scp prod:/backup/sipos/files_20260415.tar.gz ./restore-test/

# 4. Timer START
time_start=$(date +%s)

# 5. Restore database
gunzip -c restore-test/db_20260415.sql.gz | mysql -u$DB_USER -p$DB_PASS sipos_drill

# 6. Restore files
tar -xzf restore-test/files_20260415.tar.gz -C ./
# ini akan extract ke storage/app/public/

# 7. Symlink + cache
php artisan storage:link
php artisan config:cache

# 8. Smoke test
time_end=$(date +%s)
echo "RTO: $((time_end - time_start)) seconds"

# 9. Verifikasi data
php artisan data:verify
# Must return exit 0 (no mismatches)

# 10. Login manual, buka dashboard, cek:
#     - Daftar produk lengkap
#     - Gambar produk muncul (uploads restored)
#     - Laporan penjualan bulan sebelumnya match
#     - Stock per warehouse sesuai
```

**Kriteria lulus:**
- RTO < 30 menit (target)
- `data:verify` zero mismatches
- 3 sales dari hari terakhir di prod match di staging (nomor_dokumen, grand_total, items)
- 1 gambar produk yang pernah diupload bisa dilihat

### B. Restore via UI (.zip)

Simulasi: admin upload `.zip` backup dari menu UI.

```bash
# 1. Dari UI: Admin → Backup & Restore → Download Backup → simpan sebagai posip_backup_YYYY-MM-DD.zip
# 2. Di staging: Login sebagai admin
# 3. Admin → Backup & Restore → Upload File → pilih .zip → masukkan password → Restore
# 4. Verifikasi sesuai kriteria A
```

### C. Restore Sebagian (Single Table)

Simulasi: hanya 1 tabel corrupt (misal `doc_sales`), sisanya OK.

```bash
# 1. Extract hanya tabel target dari .sql.gz
gunzip -c backup.sql.gz | sed -n '/CREATE TABLE `doc_sales`/,/CREATE TABLE/p' > doc_sales_only.sql

# 2. Drop + restore tabel di staging
mysql -u$DB_USER -p$DB_PASS sipos_drill -e "DROP TABLE IF EXISTS doc_sales;"
mysql -u$DB_USER -p$DB_PASS sipos_drill < doc_sales_only.sql

# 3. Data:verify akan report mismatch kalau stock_card masih reference sales yang belum restored
php artisan data:verify
```

**Catatan:** restore parsial berisiko — foreign key constraint bisa break. Lebih aman: full restore ke staging, lalu export hanya row yang dibutuhkan, import ke prod.

## Drill Log (Isi tiap bulan)

| Tanggal | Scenario | RTO | data:verify | Issues | PIC |
|---------|----------|-----|-------------|--------|-----|
| 2026-04-15 | A (full) | 12 min | ✅ 0 mismatch | - | budi@ops |
| ... | ... | ... | ... | ... | ... |

## Incident Runbook (Production Down)

Kalau production beneran mati, bukan drill:

1. **Assess:** DB corrupt? File hilang? Server down?
2. **Communicate:** Kabari stakeholder (supervisor toko, owner) — ETA recovery
3. **Decide:** Restore dari backup kemarin (data loss ≤ 24 jam) vs. repair in-place
4. **Execute:** Ikuti Skenario A di atas, tapi di server production (bukan staging)
5. **Post-mortem:** Tulis timeline + root cause + preventive action dalam 48 jam

### Kontak Emergency

| Role | Kontak |
|------|--------|
| DevOps lead | isi dengan WA/email |
| DB admin | isi |
| Vendor hosting | isi |

## Common Pitfalls

- ❌ Test restore di production DB → bisa overwrite data live
- ❌ Lupa `storage:link` → gambar tidak muncul walau files restored
- ❌ Lupa clear cache (`optimize:clear`) → route/config lama masih dipakai
- ❌ APP_KEY berbeda dari prod → session encrypted lama tidak bisa dibaca (user harus login ulang — acceptable)
- ❌ Timezone mismatch → tanggal transaksi geser

## References

- [DEPLOY.md](DEPLOY.md) — fresh install + update deploy
- [BackupController.php](app/Http/Controllers/Api/V1/BackupController.php) — backup/restore implementation
- [VerifyDataInvariants.php](app/Console/Commands/VerifyDataInvariants.php) — post-restore integrity check
