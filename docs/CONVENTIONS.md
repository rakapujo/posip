# Konvensi dokumentasi POSIP

> **Status:** canonical  
> **SSoT:** repo docs + kode  
> **Jika konflik:** kode menang.

## Untuk siapa

| Audiens | Mulai dari |
|---------|------------|
| AI agent | [`ai/README.md`](ai/README.md) |
| Dev baru | [`00-start/overview.md`](00-start/overview.md) → onboarding |
| Ops | [`ops/`](ops/) |
| QA | [`quality/`](quality/) |

## Header wajib (file aktif)

Setiap `.md` di luar `archive/` mulai dengan:

```markdown
> **Status:** canonical | draft | archived
> **SSoT kode:** `path/ke/file` · …
> **Jika konflik:** ikuti kode, lalu update dokumen ini.
```

## Aturan isi

1. **Kode = sumber utama** — klaim perilaku harus link ke file di `syilex/` atau `syilex-frontend/`
2. Bahasa: **Indonesia**; identifier kode tetap English
3. Kalimat pendek; bullet; imperatif (`WAJIB` / `JANGAN`)
4. Jangan angka usang (“60 file”) — tunjuk folder
5. Detail panjang di `domain/`; agent guide hanya ringkas + pointer
6. Screenshot hanya di `assets/screenshots/`
7. Spek usang / plan Cursor → `archive/`

## Setelah ubah perilaku

- Update docs terkait, **atau** set `Status: draft` dan tanya user

## Larangan

- Jangan taruh spek hidup hanya di `archive/plans/`
- Jangan duplikasi full copy business rules di 3 tempat
