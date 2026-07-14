import { ref } from 'vue';
import JsBarcode from 'jsbarcode';

const STORAGE_KEY = 'barcode-print-settings';

const DEFAULT_SETTINGS = {
    paper: { width: 210, height: 297, preset: 'A4', orientation: 'portrait' },
    label: { width: 48, height: 30 },
    grid: { gapH: 2, gapV: 0, margin: 5 },
    font: { codeSize: 8, priceSize: 8 }
};

const PAPER_PRESETS = {
    A4: { width: 210, height: 297 },
    A5: { width: 148, height: 210 },
    Custom: null
};

/**
 * Calculate columns and rows that fit within the page.
 * Formula: floor((pageSize - 2*margin + gap) / (labelSize + gap))
 */
function calcGrid(paperW, paperH, labelW, labelH, gapH, gapV, margin) {
    // Halaman = ukuran kertas APA ADANYA (Lebar × Tinggi), tanpa transpose orientasi.
    // Minimal muat 1 label (cegah kertas kekecilan → label terpotong).
    const pageW = Math.max(paperW, labelW + 2 * margin);
    const pageH = Math.max(paperH, labelH + 2 * margin);

    const availW = pageW - 2 * margin;
    const availH = pageH - 2 * margin;

    const cols = Math.max(1, Math.floor((availW + gapH) / (labelW + gapH)));
    const rows = Math.max(1, Math.floor((availH + gapV) / (labelH + gapV)));

    return { cols, rows, pageW, pageH };
}

/**
 * Composable for barcode label generation and PDF printing.
 * Uses JsBarcode (canvas) + jsPDF for label layout.
 */
export function useBarcodePrint() {
    const generating = ref(false);

    const loadSettings = () => {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const parsed = JSON.parse(saved);
                return {
                    paper: { ...DEFAULT_SETTINGS.paper, ...parsed.paper },
                    label: { ...DEFAULT_SETTINGS.label, ...parsed.label },
                    grid: { ...DEFAULT_SETTINGS.grid, ...parsed.grid },
                    font: { ...DEFAULT_SETTINGS.font, ...parsed.font }
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

    /**
     * Generate barcode as data URL using JsBarcode
     * @param {string} value - Barcode value
     * @returns {string|null} data URL or null on failure
     */
    const generateBarcodeDataURL = (value) => {
        const text = String(value || 'N/A');
        const canvas = document.createElement('canvas');

        try {
            JsBarcode(canvas, text, {
                format: 'CODE128',
                width: 2,
                height: 50,
                displayValue: true,
                fontSize: 14,
                margin: 2,
                textMargin: 2
            });
            return canvas.toDataURL('image/png');
        } catch (err) {
            console.warn('JsBarcode failed for value:', text, err);
            return null;
        }
    };

    /** Deep-clone to plain object (strip Vue reactivity) */
    const toPlain = (obj) => JSON.parse(JSON.stringify(obj));

    /**
     * Build the barcode PDF document
     */
    const buildBarcodePdf = async (items, settingsRaw) => {
        const { jsPDF } = await import('jspdf');

        const s = toPlain(settingsRaw);

        const paperW = Number(s.paper.width) || 210;
        const paperH = Number(s.paper.height) || 297;
        const labelW = Number(s.label.width) || 48;
        const labelH = Number(s.label.height) || 30;
        const gapH = Number(s.grid.gapH) ?? 2;
        const gapV = Number(s.grid.gapV) ?? 0;
        const margin = Number(s.grid.margin) || 0;
        const codeFontSize = Number(s.font.codeSize) || 8;
        const priceFontSize = Number(s.font.priceSize) || 8;

        const { cols, rows, pageW, pageH } = calcGrid(paperW, paperH, labelW, labelH, gapH, gapV, margin);
        const labelsPerPage = cols * rows;

        // Orientasi PDF mengikuti dimensi halaman (Lebar×Tinggi apa adanya) agar tak ditranspos jsPDF
        const pageOrientation = pageW > pageH ? 'landscape' : 'portrait';
        const doc = new jsPDF({ orientation: pageOrientation, unit: 'mm', format: [pageW, pageH] });

        // Expand items by qty
        const labels = [];
        for (const item of items) {
            const qty = Number(item.qty) || 1;
            for (let i = 0; i < qty; i++) {
                labels.push(item);
            }
        }

        if (labels.length === 0) return doc;

        // Pre-generate all unique barcodes
        const barcodeCache = {};
        for (const item of items) {
            const barcodeValue = item.barcode || item.kode_produk || 'N/A';
            if (!barcodeCache[barcodeValue]) {
                barcodeCache[barcodeValue] = generateBarcodeDataURL(barcodeValue);
            }
        }

        const fontObj = { codeSize: codeFontSize, priceSize: priceFontSize };

        for (let i = 0; i < labels.length; i++) {
            const posInPage = i % labelsPerPage;
            const col = posInPage % cols;
            const row = Math.floor(posInPage / cols);

            if (i > 0 && posInPage === 0) {
                doc.addPage([pageW, pageH], pageOrientation);
            }

            const x = margin + col * (labelW + gapH);
            const y = margin + row * (labelH + gapV);

            const item = labels[i];
            const barcodeValue = item.barcode || item.kode_produk || 'N/A';
            const barcodeImg = barcodeCache[barcodeValue];

            drawLabel(doc, x, y, labelW, labelH, item, barcodeImg, fontObj);
        }

        return doc;
    };

    /** Draw a single barcode label */
    const drawLabel = (doc, x, y, w, h, item, barcodeImg, font) => {
        doc.setDrawColor(200, 200, 200);
        doc.setLineWidth(0.1);
        doc.rect(x, y, w, h);

        const padding = 1.5;
        const centerX = x + w / 2;
        const innerW = w - padding * 2;
        const smallSize = Math.max(font.codeSize - 2, 5);

        // 1. Kode produk (top, smaller)
        let curY = y + padding + smallSize * 0.35;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(smallSize);
        doc.text(String(item.kode_produk || ''), centerX, curY, { align: 'center', maxWidth: innerW });

        // 2. Nama produk (below kode, normal weight, truncated)
        curY += smallSize * 0.35 + 1;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(smallSize);
        const nama = String(item.nama_produk || '');
        doc.text(nama, centerX, curY, { align: 'center', maxWidth: innerW });

        // 2.5. Keterangan (optional, italic, slightly smaller font, below nama)
        if (item.keterangan) {
            const ketSize = Math.max(smallSize - 1, 4);
            curY += ketSize * 0.35 + 0.5;
            doc.setFont('helvetica', 'italic');
            doc.setFontSize(ketSize);
            doc.text(String(item.keterangan), centerX, curY, { align: 'center', maxWidth: innerW });
        }

        // 3. Barcode image (middle)
        const topUsed = curY - y + 1;
        const bottomUsed = padding + font.priceSize * 0.35 + 1;
        const barcodeH = h - topUsed - bottomUsed;
        const barcodeW = innerW - 2;
        const barcodeTopY = y + topUsed;

        if (barcodeImg && barcodeH > 3) {
            const imgX = centerX - barcodeW / 2;
            doc.addImage(barcodeImg, 'PNG', imgX, barcodeTopY, barcodeW, barcodeH);
        }

        // 4. Satuan + Harga (bottom)
        const bottomY = y + h - padding - 0.5;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(font.priceSize);

        const satuanText = `${item.satuan || ''} (${item.konversi ?? 1})`;
        doc.text(satuanText, x + padding, bottomY);

        doc.setFont('helvetica', 'bold');
        doc.text(String(item.harga || ''), x + w - padding, bottomY, { align: 'right' });
    };

    const printBarcodePdf = async (items, settings) => {
        generating.value = true;
        try {
            const doc = await buildBarcodePdf(items, settings);
            const blob = doc.output('blob');
            const url = URL.createObjectURL(blob);
            const win = window.open(url, '_blank');
            if (win) {
                win.addEventListener('load', () => win.print());
            }
        } finally {
            generating.value = false;
        }
    };

    const downloadBarcodePdf = async (items, settings, filename = 'barcode-labels') => {
        generating.value = true;
        try {
            const doc = await buildBarcodePdf(items, settings);
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
        generateBarcodeDataURL,
        calcGrid,
        buildBarcodePdf,
        printBarcodePdf,
        downloadBarcodePdf,
        DEFAULT_SETTINGS,
        PAPER_PRESETS
    };
}
