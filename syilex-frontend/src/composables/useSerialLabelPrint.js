import { ref } from 'vue';
import { useBarcodePrint } from './useBarcodePrint';

const STORAGE_KEY = 'serial-label-print-settings';

/**
 * Preset ukuran label unit serial (mm) + skala font dasar (pt).
 * Field & layout sama untuk semua preset; hanya dimensi + font yang berubah.
 */
export const SIZE_PRESETS = {
    Kecil: { label: { width: 40, height: 30 }, fontBase: 5 },
    Sedang: { label: { width: 50, height: 40 }, fontBase: 6 },
    Besar: { label: { width: 60, height: 45 }, fontBase: 7 }
};

const DEFAULT_SETTINGS = {
    paper: { width: 210, height: 297, preset: 'A4', orientation: 'portrait' },
    sizePreset: 'Sedang',
    label: { width: 50, height: 40 },
    grid: { gapH: 2, gapV: 2, margin: 5 },
    fontBase: 6,
    columns: 'auto' // 'auto' = isi sebanyak muat; angka = paksa N kolom (dibatasi yg muat)
};

const PAPER_PRESETS = {
    A4: { width: 210, height: 297 },
    A5: { width: 148, height: 210 },
    Custom: null
};

/** Tinggi baris (mm) perkiraan untuk font pt. */
const lh = (pt) => pt * 0.42;

/**
 * Composable cetak LABEL UNIT SERIAL (1 unit = 1 label, barcode = kode_internal yg UNIK).
 * SN ditampilkan sebagai teks (boleh kembar, jadi tak dipakai sbg barcode). Reuse mesin
 * barcode/grid (generateBarcodeDataURL + calcGrid) dari useBarcodePrint.
 *
 * labelItems = string SUDAH terformat (formatting currency/percent/date di pemanggil):
 *   { kode_produk, nama_produk, kode_internal, serial_number, spek, akun, harga, pbs }
 */
export function useSerialLabelPrint() {
    const generating = ref(false);
    const { generateBarcodeDataURL, calcGrid } = useBarcodePrint();

    const loadSettings = () => {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const p = JSON.parse(saved);
                return {
                    paper: { ...DEFAULT_SETTINGS.paper, ...p.paper },
                    sizePreset: p.sizePreset || DEFAULT_SETTINGS.sizePreset,
                    label: { ...DEFAULT_SETTINGS.label, ...p.label },
                    grid: { ...DEFAULT_SETTINGS.grid, ...p.grid },
                    fontBase: p.fontBase || DEFAULT_SETTINGS.fontBase,
                    columns: p.columns || DEFAULT_SETTINGS.columns
                };
            }
        } catch {
            // ignore
        }
        return JSON.parse(JSON.stringify(DEFAULT_SETTINGS));
    };

    const saveSettings = (settings) => {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
        } catch {
            // ignore
        }
    };

    const resetSettings = () => {
        localStorage.removeItem(STORAGE_KEY);
        return JSON.parse(JSON.stringify(DEFAULT_SETTINGS));
    };

    /** Terapkan preset ukuran ke settings (mutasi label + fontBase). Custom = biarkan. */
    const applySizePreset = (settings, name) => {
        settings.sizePreset = name;
        const preset = SIZE_PRESETS[name];
        if (preset) {
            settings.label.width = preset.label.width;
            settings.label.height = preset.label.height;
            settings.fontBase = preset.fontBase;
        }
        return settings;
    };

    /** Gambar satu label unit serial. */
    const drawSerialLabel = (doc, x, y, w, h, item, barcodeImg, fontBase, keterangan) => {
        doc.setDrawColor(200, 200, 200);
        doc.setLineWidth(0.1);
        doc.rect(x, y, w, h);

        const pad = 1.5;
        const cx = x + w / 2;
        const innerW = w - pad * 2;
        const big = fontBase; // kode, harga
        const mid = Math.max(fontBase - 1, 4); // nama, spek, akun
        const sm = Math.max(fontBase - 1.5, 3.5); // pbs

        let cy = y + pad + lh(big);

        // 1. Kode produk (bold, baris sendiri)
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(big);
        doc.text(String(item.kode_produk || ''), cx, cy, { align: 'center', maxWidth: innerW });

        // 2. Nama produk (wrap maks 2 baris, sisanya dipotong …)
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(mid);
        let namaLines = doc.splitTextToSize(String(item.nama_produk || ''), innerW);
        if (namaLines.length > 2) {
            namaLines = namaLines.slice(0, 2);
            namaLines[1] = namaLines[1].slice(0, Math.max(0, namaLines[1].length - 1)) + '…';
        }
        for (const ln of namaLines) {
            cy += lh(mid);
            doc.text(ln, cx, cy, { align: 'center', maxWidth: innerW });
        }

        // 3. Grade · Baterai
        cy += lh(mid) + 0.3;
        doc.text(String(item.spek || ''), cx, cy, { align: 'center', maxWidth: innerW });

        // 4. Akun
        cy += lh(mid);
        doc.text(String(item.akun || ''), cx, cy, { align: 'center', maxWidth: innerW });

        // 4b. Nomor Seri (teks; barcode di bawah = kode_internal yg unik)
        if (item.serial_number) {
            cy += lh(mid);
            doc.text('SN ' + String(item.serial_number), cx, cy, { align: 'center', maxWidth: innerW });
        }

        // 5. Keterangan (opsional, italic) — dicetak apa adanya tanpa prefix
        if (keterangan) {
            cy += lh(mid);
            doc.setFont('helvetica', 'italic');
            doc.text(String(keterangan), cx, cy, { align: 'center', maxWidth: innerW });
            doc.setFont('helvetica', 'normal');
        }

        const topUsed = cy - y + 0.8;

        // Blok bawah: harga (bold) + PBS·tgl
        const hargaH = lh(big);
        const pbsH = lh(sm);
        const bottomUsed = hargaH + pbsH + pad + 1;

        // 6. Barcode (= kode_internal yg UNIK; teks kode embedded via displayValue)
        const barcodeH = h - topUsed - bottomUsed;
        const barcodeW = innerW - 1;
        if (barcodeImg && barcodeH > 3) {
            doc.addImage(barcodeImg, 'PNG', cx - barcodeW / 2, y + topUsed, barcodeW, barcodeH);
        }

        // 7. Harga jual (bold) — di atas PBS
        const pbsY = y + h - pad;
        const hargaY = pbsY - pbsH - 0.3;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(big);
        doc.text(String(item.harga || ''), cx, hargaY, { align: 'center', maxWidth: innerW });

        // 8. No. PBS · tgl masuk
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(sm);
        doc.text(String(item.pbs || ''), cx, pbsY, { align: 'center', maxWidth: innerW });
    };

    /**
     * Resolve grid efektif. columns='auto' → isi sebanyak yang muat; angka → paksa N kolom
     * (dibatasi jumlah yang muat agar tak overflow). rows tetap otomatis dari tinggi label.
     */
    const resolveGrid = (s) => {
        const labelW = Number(s.label.width) || 50;
        const labelH = Number(s.label.height) || 40;
        const margin = Number(s.grid.margin) || 0;
        // Kertas WAJIB minimal muat 1 label (cegah kertas kosong/kekecilan → label terpotong)
        const paperW = Math.max(Number(s.paper.width) || 210, labelW + 2 * margin);
        const paperH = Math.max(Number(s.paper.height) || 297, labelH + 2 * margin);
        const g = calcGrid(paperW, paperH, labelW, labelH, Number(s.grid.gapH) ?? 2, Number(s.grid.gapV) ?? 2, margin);
        let cols = g.cols;
        if (s.columns && s.columns !== 'auto') {
            cols = Math.max(1, Math.min(Number(s.columns), g.cols));
        }
        return { ...g, cols, perPage: cols * g.rows };
    };

    /** Build PDF; labelItems = array string terformat (1 item = 1 label). */
    const buildSerialLabelPdf = async (labelItems, settingsRaw, opts = {}) => {
        const { jsPDF } = await import('jspdf');
        const s = JSON.parse(JSON.stringify(settingsRaw));
        const keterangan = opts.keterangan || '';

        const labelW = Number(s.label.width) || 50;
        const labelH = Number(s.label.height) || 40;
        const gapH = Number(s.grid.gapH) ?? 2;
        const gapV = Number(s.grid.gapV) ?? 2;
        const margin = Number(s.grid.margin) || 0;
        const fontBase = Number(s.fontBase) || 6;

        const { cols, pageW, pageH, perPage } = resolveGrid(s);

        // Orientasi PDF mengikuti dimensi halaman (Lebar×Tinggi apa adanya)
        const pageOrientation = pageW > pageH ? 'landscape' : 'portrait';
        const doc = new jsPDF({ orientation: pageOrientation, unit: 'mm', format: [pageW, pageH] });
        if (!labelItems.length) return doc;

        // Barcode = kode_internal (UNIK); fallback ke SN bila kode_internal kosong (data lama)
        const barcodeKey = (it) => it.kode_internal || it.serial_number || 'N/A';
        const cache = {};
        for (const it of labelItems) {
            const v = barcodeKey(it);
            if (!cache[v]) cache[v] = generateBarcodeDataURL(v);
        }

        for (let i = 0; i < labelItems.length; i++) {
            const p = i % perPage;
            const col = p % cols;
            const row = Math.floor(p / cols);
            if (i > 0 && p === 0) doc.addPage([pageW, pageH], pageOrientation);

            const x = margin + col * (labelW + gapH);
            const y = margin + row * (labelH + gapV);
            const it = labelItems[i];
            drawSerialLabel(doc, x, y, labelW, labelH, it, cache[barcodeKey(it)], fontBase, keterangan);
        }
        return doc;
    };

    const printSerialLabels = async (labelItems, settings, opts) => {
        generating.value = true;
        try {
            const doc = await buildSerialLabelPdf(labelItems, settings, opts);
            const url = URL.createObjectURL(doc.output('blob'));
            const win = window.open(url, '_blank');
            if (win) win.addEventListener('load', () => win.print());
        } finally {
            generating.value = false;
        }
    };

    const downloadSerialLabels = async (labelItems, settings, opts, filename = 'label-unit-serial') => {
        generating.value = true;
        try {
            const doc = await buildSerialLabelPdf(labelItems, settings, opts);
            doc.save(`${filename}.pdf`);
        } finally {
            generating.value = false;
        }
    };

    return {
        generating,
        loadSettings,
        saveSettings,
        resetSettings,
        applySizePreset,
        generateBarcodeDataURL,
        calcGrid,
        resolveGrid,
        buildSerialLabelPdf,
        printSerialLabels,
        downloadSerialLabels,
        SIZE_PRESETS,
        PAPER_PRESETS,
        DEFAULT_SETTINGS
    };
}
