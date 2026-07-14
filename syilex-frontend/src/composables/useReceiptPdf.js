import { useFormatters } from './useFormatters';
import { useSettingsStore } from '@/stores/settings';
import { useNotification } from '@/composables/useNotification';

/**
 * Composable for building receipt PDF documents (80mm thermal)
 *
 * Extracted from PosKasirPage.vue and StrukOnlinePage.vue for DRY.
 * Handles: store header, items, discounts, summary, payments, returns, footer.
 *
 * @param {Object} options
 * @param {Object} options.storeOverride - Override store info (for public page without auth)
 */
export function useReceiptPdf(options = {}) {
    const { formatCurrency, formatQty, formatPercent, formatDateTime } = useFormatters();
    const settingsStore = options.storeOverride ? null : useSettingsStore();
    const notify = options.storeOverride ? null : useNotification();

    /**
     * Get store info (from settings store or override)
     * storeOverride can be an object or a function returning an object (for lazy evaluation)
     */
    const getStoreInfo = () => {
        if (options.storeOverride) {
            return typeof options.storeOverride === 'function' ? options.storeOverride() : options.storeOverride;
        }
        return {
            name: settingsStore.store.name || 'POSIP',
            address: settingsStore.store.address || '',
            phone: settingsStore.store.phone || '',
            email: settingsStore.store.email || '',
            npwp: settingsStore.store.npwp || '',
            receiptFooter: settingsStore.store.receiptFooter || 'Terima Kasih!'
        };
    };

    /**
     * Format 5-level line discount for display.
     * Output: "5,12%+3,00%+Rp4.000+4%" or empty if no discount.
     */
    const formatDiscLine = (detail) => {
        const parts = [];
        for (let i = 1; i <= 5; i++) {
            const tipe = detail[`diskon_${i}_tipe`];
            const nilai = Number(detail[`diskon_${i}_nilai`] || 0);
            if (tipe === 'none' || nilai === 0) continue;
            if (tipe === 'percent') {
                parts.push(`${formatPercent(nilai)}`);
            } else {
                parts.push(`${formatCurrency(nilai)}`);
            }
        }
        return parts.join('+');
    };

    /**
     * Build a retur policy text line based on terminal policy and sale date.
     * Examples:
     *   izinkan_retur=false → "Barang yang sudah dibeli tidak dapat dikembalikan."
     *   durasi null         → "Simpan struk ini untuk penukaran/pengembalian barang."
     *   durasi N            → "Penukaran/pengembalian barang sebelum DD Mon YYYY."
     */
    const buildReturPolicyText = (returPolicy, tanggal) => {
        if (!returPolicy || !returPolicy.izinkan_retur) {
            return 'Barang yang sudah dibeli tidak dapat dikembalikan.';
        }
        const durasi = returPolicy.durasi_retur;
        if (durasi === null || durasi === undefined) {
            return 'Simpan struk ini untuk penukaran/pengembalian barang.';
        }
        const tgl = new Date(tanggal);
        tgl.setDate(tgl.getDate() + Number(durasi));
        const formatted = tgl.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
        return `Penukaran/pengembalian barang sebelum ${formatted}.`;
    };

    /**
     * Build receipt PDF content onto a jsPDF document.
     *
     * @param {Object} data - Sales data with details, payments, returns
     * @param {Object} doc - jsPDF instance
     * @param {Object} pdfOptions
     * @param {string} pdfOptions.receiptStatus - 'completed'|'voided'|'retur_partial'|'retur_full' (for watermark)
     * @param {Object} pdfOptions.returPolicy - { izinkan_retur, durasi_retur } from terminal or public payload
     * @returns {Object} jsPDF doc
     */
    const buildReceiptPdf = (data, doc, pdfOptions = {}) => {
        const { receiptStatus = null, returPolicy = null } = pdfOptions;
        const storeInfo = getStoreInfo();

        const pageWidth = 80;
        const margin = 5;
        const marginTop = 10;
        const marginBottom = 10;
        let y = marginTop;
        const lineHeight = 4;
        const smallLineHeight = 3.5;

        const pageHeight = () => doc.internal.pageSize.getHeight();
        const ensureSpace = (needed = lineHeight) => {
            if (y + needed > pageHeight() - marginBottom) {
                doc.addPage([pageWidth, pageHeight()]);
                y = marginTop;
            }
        };

        // Helper functions
        const maxWidth = pageWidth - margin * 2;
        const center = (text, fontSize = 8) => {
            doc.setFontSize(fontSize);
            const lines = String(text).split(/\r?\n/);
            for (const line of lines) {
                const wrapped = doc.splitTextToSize(line, maxWidth);
                for (const wl of wrapped) {
                    ensureSpace(lineHeight);
                    const tw = doc.getTextWidth(wl);
                    doc.text(wl, (pageWidth - tw) / 2, y);
                    y += lineHeight;
                }
            }
        };
        const leftRight = (left, right, fontSize = 8) => {
            ensureSpace(lineHeight);
            doc.setFontSize(fontSize);
            doc.text(left, margin, y);
            const rightWidth = doc.getTextWidth(right);
            doc.text(right, pageWidth - margin - rightWidth, y);
            y += lineHeight;
        };
        const dashed = () => {
            ensureSpace(4);
            y += 1;
            doc.setLineDashPattern([1, 1], 0);
            doc.line(margin, y, pageWidth - margin, y);
            y += 3;
        };
        const leftWrap = (text, fontSize = 6, lh = smallLineHeight) => {
            doc.setFontSize(fontSize);
            for (const wl of doc.splitTextToSize(String(text), maxWidth)) {
                ensureSpace(lh);
                doc.text(wl, margin, y);
                y += lh;
            }
        };

        // Build a serial unit display string: "KI-xxx · SN xxx · Grade · Bat 90% Good · Active"
        const formatSerialUnit = (u) => {
            const parts = [];
            if (u.kode_internal) parts.push(u.kode_internal);
            parts.push(`SN ${u.serial_number || '-'}`);
            if (u.grade) parts.push(u.grade);
            if (u.battery_health !== null && u.battery_health !== undefined && u.battery_health !== '') {
                parts.push(`Bat ${u.battery_health}%${u.battery_condition ? ' ' + u.battery_condition : ''}`);
            } else if (u.battery_condition) {
                parts.push(`Bat ${u.battery_condition}`);
            }
            if (u.account_status) parts.push(u.account_status);
            return parts.join(' · ');
        };

        // ─── Store Header ───
        doc.setFont('helvetica', 'bold');
        center(storeInfo.name, 10);
        doc.setFont('helvetica', 'normal');
        if (storeInfo.address) center(storeInfo.address, 7);
        if (storeInfo.phone) center(`Telp: ${storeInfo.phone}`, 7);
        if (storeInfo.email) center(`Email: ${storeInfo.email}`, 7);
        if (storeInfo.npwp) center(`NPWP: ${storeInfo.npwp}`, 7);
        dashed();

        // ─── Transaction Info ───
        doc.setFontSize(7);
        ensureSpace(smallLineHeight * 2);
        doc.text(`No: ${data.nomor_dokumen}`, margin, y);
        if (data.created_by?.name) {
            doc.text(`Kasir: ${data.created_by.name}`, margin + 35, y);
        }
        y += smallLineHeight;
        doc.text(`Tgl: ${formatDateTime(data.tanggal)}`, margin, y);
        doc.text(`Cust: ${data.customer?.nama || 'Walk-in'}`, margin + 35, y);
        y += smallLineHeight;
        dashed();

        // ─── Items ───
        doc.setFontSize(7);
        for (const item of data.details) {
            ensureSpace(smallLineHeight * 2);
            doc.text(item.product?.nama_produk || '', margin, y);
            y += smallLineHeight;
            const qtyLine = `${formatQty(item.qty)} ${item.unit} x ${formatCurrency(item.harga_satuan)}`;
            const itemTotal = formatCurrency(Number(item.qty) * Number(item.harga_satuan));
            leftRight(`  ${qtyLine}`, itemTotal, 7);
            if (Number(item.diskon_total) > 0) {
                leftRight(`  ${formatDiscLine(item)}`, `-${formatCurrency(item.diskon_total)}`, 7);
            }
            // Serial units — one (wrapped) line per unit, indented
            if (item.serial_units?.length) {
                for (const u of item.serial_units) {
                    leftWrap(`  ${formatSerialUnit(u)}`, 6);
                    if (u.catatan) leftWrap(`    Cat: ${u.catatan}`, 6);
                }
            }
        }
        dashed();

        // ─── Summary ───
        leftRight('Subtotal', formatCurrency(data.subtotal), 7);
        // Label fallback chain: _disc_label_N (live from cart.discountLabels) →
        // diskon_nota_N_label (persisted on doc_sales at checkout) → generic.
        const discLabel = (n) => data[`_disc_label_${n}`] || data[`diskon_nota_${n}_label`] || `Disc Nota ${n}`;
        if (Number(data.diskon_nota_1_hasil) > 0) leftRight(discLabel(1), `-${formatCurrency(data.diskon_nota_1_hasil)}`, 7);
        if (Number(data.diskon_nota_2_hasil) > 0) leftRight(discLabel(2), `-${formatCurrency(data.diskon_nota_2_hasil)}`, 7);
        if (Number(data.diskon_nota_3_hasil) > 0) leftRight(discLabel(3), `-${formatCurrency(data.diskon_nota_3_hasil)}`, 7);
        if (Number(data.total_diskon) > 0) leftRight('Total', formatCurrency(data.total_setelah_diskon), 7);
        if (Number(data.biaya_kirim_hasil) > 0) {
            const bkLabel = data.biaya_kirim_tipe === 'percent' ? `Biaya Kirim (${formatPercent(data.biaya_kirim_nilai)})` : `Biaya Kirim (${formatCurrency(data.biaya_kirim_nilai)})`;
            leftRight(bkLabel, formatCurrency(data.biaya_kirim_hasil), 7);
        }
        if (Number(data.biaya_lain_hasil) > 0) {
            const blLabel = data.biaya_lain_tipe === 'percent' ? `Biaya Lain (${formatPercent(data.biaya_lain_nilai)})` : `Biaya Lain (${formatCurrency(data.biaya_lain_nilai)})`;
            leftRight(blLabel, formatCurrency(data.biaya_lain_hasil), 7);
        }
        if (Number(data.pajak_nominal) > 0) {
            leftRight('DPP', formatCurrency(data.dpp), 7);
            leftRight(`${data.pajak_nama} ${data.pajak_persen}%`, formatCurrency(data.pajak_nominal), 7);
        }
        if (Number(data.pembulatan)) leftRight('Pembulatan', formatCurrency(data.pembulatan), 7);
        dashed();

        // ─── Grand Total ───
        doc.setFont('helvetica', 'bold');
        leftRight('GRAND TOTAL', formatCurrency(data.grand_total), 9);
        doc.setFont('helvetica', 'normal');
        dashed();

        // ─── Payments ───
        doc.setFontSize(7);
        doc.text('Bayar:', margin, y);
        y += smallLineHeight;
        for (const pay of data.payments) {
            leftRight(`  ${pay.metode_pembayaran?.nama_pembayaran}`, formatCurrency(pay.nominal), 7);
            if (Number(pay.biaya_tambahan) > 0) {
                leftRight(`    Biaya`, formatCurrency(pay.biaya_tambahan), 6);
            }
        }
        doc.setFont('helvetica', 'bold');
        leftRight('Total Bayar', formatCurrency(data.total_bayar), 8);
        if (Number(data.kembalian) > 0) leftRight('Kembali', formatCurrency(data.kembalian), 8);
        doc.setFont('helvetica', 'normal');
        dashed();

        // ─── Return History ───
        if (data.returns?.length > 0) {
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(8);
            doc.text('RIWAYAT RETUR', margin, y);
            y += lineHeight;
            doc.setFont('helvetica', 'normal');

            for (const ret of data.returns) {
                doc.setFontSize(7);
                const refundLabel = 'Tunai';
                leftRight(ret.nomor_dokumen, refundLabel, 7);
                doc.text(`  ${formatDateTime(ret.tanggal)}`, margin, y);
                y += smallLineHeight;
                for (const d of ret.details) {
                    leftRight(`  ${d.product?.nama_produk} x${formatQty(d.qty)}`, `@ ${formatCurrency(d.harga_satuan)}`, 7);
                }
                if (Number(ret.pembulatan)) {
                    leftRight('  Pembulatan', formatCurrency(ret.pembulatan), 7);
                }
                doc.setFont('helvetica', 'bold');
                leftRight('  Total Retur', formatCurrency(ret.grand_total), 7);
                doc.setFont('helvetica', 'normal');
                y += 1;
            }
            dashed();

            // Ringkasan Retur
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(8);
            doc.text('RINGKASAN', margin, y);
            y += lineHeight;
            doc.setFont('helvetica', 'normal');

            leftRight('Pembayaran Asli', formatCurrency(data.grand_total), 7);

            if (Number(data.biaya_kirim_hasil) > 0 || Number(data.biaya_lain_hasil) > 0) {
                doc.setFontSize(6);
                doc.text('Tidak Termasuk Retur:', margin, y);
                y += smallLineHeight;
                if (Number(data.biaya_kirim_hasil) > 0) {
                    leftRight('  Biaya Kirim', formatCurrency(data.biaya_kirim_hasil), 6);
                }
                if (Number(data.biaya_lain_hasil) > 0) {
                    leftRight('  Biaya Lain', formatCurrency(data.biaya_lain_hasil), 6);
                }
            }

            const totalRetur = data.returns.reduce((sum, r) => sum + Number(r.grand_total), 0);

            doc.setFontSize(7);
            leftRight('Total Semua Retur', formatCurrency(totalRetur), 7);
            doc.setFontSize(6);
            leftRight('  Refund Tunai', formatCurrency(totalRetur), 6);

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(8);
            leftRight('NILAI BERSIH', formatCurrency(Number(data.grand_total) - totalRetur), 8);
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6);
            ensureSpace(smallLineHeight);
            doc.text('(Pembayaran - Retur)', margin, y);
            y += smallLineHeight;
            dashed();
        }

        // ─── Status Watermark (for public page / specific status) ───
        if (receiptStatus === 'voided') {
            ensureSpace(lineHeight + 2);
            y += 2;
            doc.setTextColor(220, 38, 38);
            center('*** VOID ***', 10);
            doc.setTextColor(0, 0, 0);
        } else if (receiptStatus === 'retur_full') {
            y += 2;
            doc.setTextColor(220, 38, 38);
            center('*** RETUR FULL ***', 10);
            doc.setTextColor(0, 0, 0);
        } else if (receiptStatus === 'retur_partial') {
            y += 2;
            doc.setTextColor(234, 179, 8);
            center('*** RETUR PARTIAL ***', 10);
            doc.setTextColor(0, 0, 0);
        }

        // ─── Retur Policy ───
        if (returPolicy) {
            y += 1;
            center(buildReturPolicyText(returPolicy, data.tanggal), 6);
        }

        // ─── Footer ───
        y += 2;
        const footerText = getStoreInfo().receiptFooter || 'Terima Kasih!';
        // Multi-line support: split on \n or \r\n
        for (const line of String(footerText).split(/\r?\n/)) {
            if (line.trim()) center(line.trim(), 8);
        }
        if (data.notes) center(data.notes, 7);

        return y;
    };

    const finalizeReceiptPdf = (doc, contentBottomY) => {
        if (doc.getNumberOfPages() === 1) {
            doc.internal.pageSize.height = Math.max(contentBottomY + 10, 40);
        }
    };

    /**
     * Download receipt as PDF file
     *
     * @param {Object} data - Sales data
     * @param {Object} pdfOptions - Options passed to buildReceiptPdf
     */
    const downloadReceiptPdf = async (data, pdfOptions = {}) => {
        if (!data) return;
        const { jsPDF } = await import('jspdf');
        // Page height 500mm accommodates long receipts (many items + retur history + multi-line footer).
        // jsPDF auto-crops unused bottom space; oversized page never hurts.
        const doc = new jsPDF({ unit: 'mm', format: [80, 500] });
        const finalY = buildReceiptPdf(data, doc, pdfOptions);
        finalizeReceiptPdf(doc, finalY);
        doc.save(`${data.nomor_dokumen}.pdf`);
    };

    /**
     * Print receipt via browser print (opens auto-print window with PDF)
     *
     * @param {Object} data - Sales data
     * @param {Object} pdfOptions - Options passed to buildReceiptPdf
     */
    const printReceiptPdf = async (data, pdfOptions = {}) => {
        if (!data) return;
        const { jsPDF } = await import('jspdf');
        // Page height 500mm accommodates long receipts (many items + retur history + multi-line footer).
        // jsPDF auto-crops unused bottom space; oversized page never hurts.
        const doc = new jsPDF({ unit: 'mm', format: [80, 500] });
        const finalY = buildReceiptPdf(data, doc, pdfOptions);
        finalizeReceiptPdf(doc, finalY);
        const pdfBlob = doc.output('blob');
        const url = URL.createObjectURL(pdfBlob);
        const printWindow = window.open(url);
        if (!printWindow) {
            URL.revokeObjectURL(url);
            notify?.warn('Popup diblokir browser. Izinkan popup untuk print, atau gunakan Download PDF.');
            return;
        }
        printWindow.addEventListener(
            'load',
            () => {
                printWindow.print();
                setTimeout(() => URL.revokeObjectURL(url), 60_000);
            },
            { once: true }
        );
    };

    return {
        formatDiscLine,
        buildReceiptPdf,
        buildReturPolicyText,
        downloadReceiptPdf,
        printReceiptPdf
    };
}
