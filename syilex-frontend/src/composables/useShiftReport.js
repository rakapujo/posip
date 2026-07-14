import { ref } from 'vue';
import { useFormatters } from '@/composables/useFormatters';
import { useSettingsStore } from '@/stores/settings';
import { useNotification } from '@/composables/useNotification';
import { posApi } from '@/api';

/**
 * Composable for Shift Report functionality
 * Used in: ShiftPage, PosTerminalPage, PosKasirPage
 */
export function useShiftReport() {
    const { formatDateTime, formatCurrency } = useFormatters();
    const settingsStore = useSettingsStore();
    const notify = useNotification();

    // State
    const shiftReportDialog = ref(false);
    const shiftReportData = ref(null);
    const loadingShiftReport = ref(false);

    /**
     * Get status text for shift close type
     */
    const getShiftCloseStatusText = (shift) => {
        if (!shift?.ended_at) return 'Masih Aktif';
        if (shift.ended_by_force) {
            const forcedBy = shift.forced_by_user?.name || 'Admin';
            return `Ditutup Paksa oleh ${forcedBy}`;
        }
        return 'Ditutup Normal';
    };

    /**
     * Load shift report data from API
     * @param {string} shiftUlid - ULID of the shift
     */
    const loadShiftReport = async (shiftUlid) => {
        if (!shiftUlid) {
            console.warn('[useShiftReport] loadShiftReport called with empty shiftUlid');
            return;
        }
        loadingShiftReport.value = true;
        shiftReportDialog.value = true;
        shiftReportData.value = null;
        try {
            const res = await posApi.getShiftReport(shiftUlid);
            const data = res.data?.data;

            // Validate response has required shift data
            if (!data || !data.shift) {
                console.warn('[useShiftReport] API response missing shift data:', { shiftUlid, responseData: res.data });
                notify.error('Data shift tidak ditemukan');
                shiftReportDialog.value = false;
                return;
            }

            shiftReportData.value = data;
        } catch (error) {
            console.error('[useShiftReport] Failed to load shift report:', { shiftUlid, error });
            notify.error('Gagal memuat laporan shift');
            shiftReportDialog.value = false;
        } finally {
            loadingShiftReport.value = false;
        }
    };

    /**
     * Build shift report PDF document
     * @param {Object} data - Shift report data
     * @param {jsPDF} doc - jsPDF instance
     * @returns {jsPDF} - Modified jsPDF instance
     */
    const buildShiftReportPdf = (data, doc) => {
        const pageWidth = 80;
        const margin = 5;
        const marginTop = 8;
        const marginBottom = 10;
        let y = marginTop;
        const lineHeight = 4;

        const pageHeight = () => doc.internal.pageSize.getHeight();

        const ensureSpace = (needed = lineHeight) => {
            if (y + needed > pageHeight() - marginBottom) {
                doc.addPage([pageWidth, pageHeight()]);
                y = marginTop;
            }
        };

        // Helper functions
        const center = (text, fontSize = 8) => {
            ensureSpace(lineHeight);
            doc.setFontSize(fontSize);
            const textWidth = doc.getTextWidth(text);
            doc.text(text, (pageWidth - textWidth) / 2, y);
            y += lineHeight;
        };
        const leftRight = (left, right, fontSize = 8) => {
            ensureSpace(lineHeight);
            doc.setFontSize(fontSize);
            doc.text(left, margin, y);
            const rightWidth = doc.getTextWidth(String(right));
            doc.text(String(right), pageWidth - margin - rightWidth, y);
            y += lineHeight;
        };
        const dashed = () => {
            ensureSpace(4);
            y += 1;
            doc.setLineDashPattern([1, 1], 0);
            doc.line(margin, y, pageWidth - margin, y);
            y += 3;
        };
        const section = (title) => {
            ensureSpace(lineHeight);
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(8);
            doc.text(title, margin, y);
            y += lineHeight;
            doc.setFont('helvetica', 'normal');
        };

        // Store Header
        doc.setFont('helvetica', 'bold');
        center(settingsStore.store.name || 'POSIP', 10);
        doc.setFont('helvetica', 'normal');
        if (settingsStore.store.address) center(settingsStore.store.address, 7);
        if (settingsStore.store.phone) center(`Telp: ${settingsStore.store.phone}`, 7);
        dashed();

        // Report Title + ULID
        doc.setFont('helvetica', 'bold');
        center('LAPORAN SHIFT', 10);
        doc.setFont('courier', 'normal');
        if (data.shift?.ulid) center(data.shift.ulid, 5);
        doc.setFont('helvetica', 'normal');
        dashed();

        // Shift Info
        doc.setFontSize(7);
        leftRight('Terminal', data.shift?.terminal?.kode_terminal || '-', 7);
        leftRight('Kasir', data.shift?.user?.name || '-', 7);
        leftRight('Mulai', formatDateTime(data.shift?.started_at), 7);
        leftRight('Selesai', data.shift?.ended_at ? formatDateTime(data.shift.ended_at) : '-', 7);
        const statusText = getShiftCloseStatusText(data.shift);
        ensureSpace(lineHeight);
        doc.text('Status', margin, y);
        y += lineHeight;
        doc.setFontSize(6);
        for (const line of doc.splitTextToSize(statusText, pageWidth - margin * 2)) {
            ensureSpace(lineHeight);
            doc.text(line, margin, y);
            y += lineHeight;
        }
        doc.setFontSize(7);
        dashed();

        // Penjualan (full breakdown matching dialog + thermal)
        const p = data.penjualan || {};
        section(`PENJUALAN (${p.jumlah_transaksi || 0} trx)`);
        leftRight('Penjualan Kotor', formatCurrency(p.penjualan_kotor), 7);

        // Disc item breakdown (5 level)
        if (Number(p.diskon_item) > 0) {
            leftRight('Diskon Item', '-' + formatCurrency(p.diskon_item), 7);
            doc.setFontSize(6);
            for (let i = 1; i <= 5; i++) {
                const val = Number(p[`diskon_line_${i}`] || 0);
                if (val > 0) leftRight(`  Line ${i}${i === 5 ? ' (Manual)' : ''}`, '-' + formatCurrency(val), 6);
            }
            doc.setFontSize(7);
        } else {
            leftRight('Diskon Item', formatCurrency(0), 7);
        }

        // Disc nota breakdown (3 level)
        if (Number(p.diskon_nota) > 0) {
            leftRight('Diskon Nota', '-' + formatCurrency(p.diskon_nota), 7);
            doc.setFontSize(6);
            if (Number(p.diskon_nota_l1) > 0) leftRight('  Tipe Customer (L1)', '-' + formatCurrency(p.diskon_nota_l1), 6);
            if (Number(p.diskon_nota_l2) > 0) leftRight('  Kategori Customer (L2)', '-' + formatCurrency(p.diskon_nota_l2), 6);
            if (Number(p.diskon_nota_l3) > 0) leftRight('  Manual Kasir (L3)', '-' + formatCurrency(p.diskon_nota_l3), 6);
            doc.setFontSize(7);
        } else {
            leftRight('Diskon Nota', formatCurrency(0), 7);
        }

        leftRight('Penjualan Bersih', formatCurrency(p.penjualan_bersih), 7);
        leftRight('Biaya Kirim', formatCurrency(p.biaya_kirim), 7);
        leftRight('Biaya Lain', formatCurrency(p.biaya_lain), 7);
        if (p.pajak_nama) {
            leftRight(`Pajak (${p.pajak_nama} ${p.pajak_persen}%)`, formatCurrency(p.pajak_nominal), 7);
        } else {
            leftRight('Pajak', formatCurrency(p.pajak_nominal), 7);
        }
        leftRight('Pembulatan', formatCurrency(p.pembulatan), 7);
        const totalBiayaPembayaran = (data.payment_breakdown || []).reduce((s, pb) => s + Number(pb.biaya_tambahan || 0), 0);
        if (totalBiayaPembayaran > 0) {
            leftRight('Biaya Pembayaran', formatCurrency(totalBiayaPembayaran), 7);
        }

        // OMZET bold
        doc.setFont('helvetica', 'bold');
        leftRight('OMZET', formatCurrency(p.omzet), 9);
        doc.setFont('helvetica', 'normal');
        dashed();

        // Unit Serial Terjual (hanya kalau ada isinya)
        const serialUnits = data.serial_units_sold || [];
        if (serialUnits.length) {
            section(`UNIT SERIAL TERJUAL (${serialUnits.length} unit)`);
            for (const u of serialUnits) {
                // Baris 1: produk + harga
                leftRight(u.product || '-', formatCurrency(u.harga), 7);
                // Baris 2: kode internal (identitas unik) atau SN + nomor nota (kecil)
                doc.setFontSize(6);
                leftRight(`  ${u.kode_internal || 'SN ' + (u.serial_number || '-')}`, u.nomor_dokumen || '-', 6);
                // Baris 3: SN (bila ada kode_internal) / grade / baterai / status akun (gabung, skip yang kosong)
                const meta = [];
                if (u.kode_internal && u.serial_number) meta.push(`SN ${u.serial_number}`);
                if (u.grade) meta.push(`Grade ${u.grade}`);
                if (u.battery_health !== null && u.battery_health !== undefined) meta.push(`Bat ${u.battery_health}%`);
                if (u.account_status) meta.push(`Akun ${u.account_status}`);
                if (meta.length) {
                    ensureSpace(lineHeight);
                    doc.text(`  ${meta.join(' | ')}`, margin, y);
                    y += lineHeight;
                }
                doc.setFontSize(7);
            }
            dashed();
        }

        // Per Metode Bayar (with kembalian/surcharge)
        if (data.payment_breakdown?.length) {
            section('PER METODE BAYAR');
            for (const pb of data.payment_breakdown) {
                leftRight(`${pb.nama} (${pb.count}x)`, formatCurrency(pb.total), 7);
                if (pb.is_tunai && Number(data.total_kembalian) > 0) {
                    doc.setFontSize(6);
                    leftRight('  Kembalian', '-' + formatCurrency(data.total_kembalian), 6);
                    leftRight('  Nett Tunai', formatCurrency(pb.total - data.total_kembalian), 6);
                    doc.setFontSize(7);
                }
                if (Number(pb.biaya_tambahan) > 0) {
                    doc.setFontSize(6);
                    leftRight('  Biaya', formatCurrency(pb.biaya_tambahan), 6);
                    doc.setFontSize(7);
                }
            }
            dashed();
        }

        // Void
        section('VOID');
        leftRight('Jumlah Void', data.void?.jumlah || 0, 7);
        leftRight('Nominal Void', formatCurrency(data.void?.nominal), 7);
        dashed();

        // Retur
        section('RETUR');
        leftRight('Total Retur', `${data.retur?.jumlah || 0} trx`, 7);
        leftRight('Total Refund', formatCurrency(data.retur?.total_refund), 7);
        doc.setFontSize(6);
        leftRight('  - Sesi Ini', `${data.retur?.sesi_ini?.jumlah || 0} (${formatCurrency(data.retur?.sesi_ini?.nominal)})`, 6);
        leftRight('  - Sesi Sebelumnya', `${data.retur?.sesi_sebelumnya?.jumlah || 0} (${formatCurrency(data.retur?.sesi_sebelumnya?.nominal)})`, 6);
        dashed();

        // Kas (with detail items)
        section('KAS (Uang Fisik di Laci)');
        leftRight('Setor Awal', formatCurrency(data.kas?.setor_awal), 7);
        leftRight('Penjualan Tunai (net)', '+' + formatCurrency(data.kas?.penjualan_tunai), 7);

        // Kas Masuk detail
        const kmDetail = data.kas?.kas_masuk_detail || [];
        leftRight(`Kas Masuk${kmDetail.length ? ` (${kmDetail.length}x)` : ''}`, '+' + formatCurrency(data.kas?.kas_masuk), 7);
        if (kmDetail.length) {
            doc.setFontSize(6);
            for (const item of kmDetail) leftRight(`  ${item.keterangan || '-'}`, '+' + formatCurrency(item.nominal), 6);
            doc.setFontSize(7);
        }

        // Kas Keluar detail
        const kkDetail = data.kas?.kas_keluar_detail || [];
        leftRight(`Kas Keluar${kkDetail.length ? ` (${kkDetail.length}x)` : ''}`, '-' + formatCurrency(data.kas?.kas_keluar), 7);
        if (kkDetail.length) {
            doc.setFontSize(6);
            for (const item of kkDetail) leftRight(`  ${item.keterangan || '-'}`, '-' + formatCurrency(item.nominal), 6);
            doc.setFontSize(7);
        }

        leftRight('Refund Retur (Cash)', '-' + formatCurrency(data.kas?.refund_tunai), 7);
        doc.setFont('helvetica', 'bold');
        leftRight('Saldo Kas', formatCurrency(data.kas?.saldo), 7);
        doc.setFont('helvetica', 'normal');
        dashed();

        // Rekonsiliasi — tampil kalau shift sudah ended. Kalau saldo_fisik null berarti
        // kasir skip reconcile saat end shift — tampilkan "Belum di-input".
        const shift = data.shift || {};
        if (shift.ended_at) {
            section('REKONSILIASI KAS');
            leftRight('Saldo Sistem', formatCurrency(shift.saldo_system), 7);
            if (shift.saldo_fisik !== null && shift.saldo_fisik !== undefined) {
                leftRight('Uang Fisik di Laci', formatCurrency(shift.saldo_fisik), 7);
                const selisih = Number(shift.selisih || 0);
                const selisihLabel = selisih === 0 ? 'Cocok' : selisih > 0 ? 'Lebih' : 'Kurang';
                const selisihStr = (selisih > 0 ? '+' : '') + formatCurrency(selisih) + ' (' + selisihLabel + ')';
                doc.setFont('helvetica', 'bold');
                leftRight('Selisih', selisihStr, 8);
                doc.setFont('helvetica', 'normal');
            } else {
                leftRight('Uang Fisik di Laci', 'Belum di-input', 7);
            }
            if (shift.closing_notes) {
                doc.setFontSize(6);
                for (const line of doc.splitTextToSize('Catatan: ' + shift.closing_notes, pageWidth - margin * 2)) {
                    ensureSpace(lineHeight);
                    doc.text(line, margin, y);
                    y += lineHeight;
                }
                doc.setFontSize(7);
            }
            dashed();
        }

        // Ringkasan Akhir
        section('RINGKASAN AKHIR');
        doc.setFont('helvetica', 'bold');
        leftRight('Total Tunai', formatCurrency(data.ringkasan?.total_tunai), 8);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(5);
        ensureSpace(3);
        doc.text('(Setor+Tunai+Masuk-Keluar-Refund)', margin, y);
        y += 3;
        doc.setFont('helvetica', 'bold');
        leftRight('Total Non-Tunai', formatCurrency(data.ringkasan?.total_non_tunai), 8);
        dashed();
        doc.setFontSize(10);
        leftRight('TOTAL SEMUA', formatCurrency(data.ringkasan?.total_semua), 10);
        doc.setFont('helvetica', 'normal');

        return y;
    };

    /**
     * Create jsPDF instance sized for thermal shift reports (80mm roll).
     * 500mm height matches receipt PDF — avoids clipping KAS / Ringkasan sections.
     */
    const createShiftReportPdfDoc = async () => {
        const { jsPDF } = await import('jspdf');
        return new jsPDF({ unit: 'mm', format: [80, 500] });
    };

    /**
     * Trim single-page thermal PDF to actual content height.
     */
    const finalizeShiftReportPdf = (doc, contentBottomY) => {
        if (doc.getNumberOfPages() === 1) {
            doc.internal.pageSize.height = Math.max(contentBottomY + 10, 40);
        }
    };

    /**
     * Print shift report
     * @param {Object} data - Shift report data (optional, uses shiftReportData if not provided)
     */
    const printShiftReport = async (data = null) => {
        // Ignore event objects (when called from @click without arguments)
        const reportData = data && typeof data === 'object' && data.shift ? data : shiftReportData.value;
        if (!reportData) {
            console.warn('[useShiftReport] printShiftReport called with no data');
            return;
        }

        // Validate shift data exists
        if (!reportData.shift) {
            console.warn('[useShiftReport] reportData missing shift:', reportData);
            notify.error('Data shift tidak tersedia untuk print');
            return;
        }

        const doc = await createShiftReportPdfDoc();
        const finalY = buildShiftReportPdf(reportData, doc);
        finalizeShiftReportPdf(doc, finalY);

        const pdfBlob = doc.output('blob');
        const url = URL.createObjectURL(pdfBlob);
        const printWindow = window.open(url);
        if (!printWindow) {
            URL.revokeObjectURL(url);
            notify.warn('Popup diblokir browser. Izinkan popup untuk print, atau gunakan Download PDF.');
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

    /**
     * Download shift report as PDF
     * @param {Object} data - Shift report data (optional, uses shiftReportData if not provided)
     */
    const downloadShiftReportPdf = async (data = null) => {
        // Ignore event objects (when called from @click without arguments)
        const reportData = data && typeof data === 'object' && data.shift ? data : shiftReportData.value;
        if (!reportData) {
            console.warn('[useShiftReport] downloadShiftReportPdf called with no data');
            return;
        }

        // Validate shift data exists
        if (!reportData.shift) {
            console.warn('[useShiftReport] reportData missing shift:', reportData);
            notify.error('Data shift tidak tersedia untuk PDF');
            return;
        }

        const doc = await createShiftReportPdfDoc();
        const finalY = buildShiftReportPdf(reportData, doc);
        finalizeShiftReportPdf(doc, finalY);

        // Download with safe filename
        const terminalCode = reportData.shift?.terminal?.kode_terminal || 'UNKNOWN';
        const startedAt = reportData.shift?.started_at ? formatDateTime(reportData.shift.started_at).replace(/[/:]/g, '-') : 'NoDate';
        const filename = `Laporan_Shift_${terminalCode}_${startedAt}.pdf`;
        doc.save(filename);
    };

    /**
     * Close the shift report dialog
     */
    const closeShiftReport = () => {
        shiftReportDialog.value = false;
        shiftReportData.value = null;
    };

    return {
        // State
        shiftReportDialog,
        shiftReportData,
        loadingShiftReport,

        // Methods
        getShiftCloseStatusText,
        loadShiftReport,
        printShiftReport,
        downloadShiftReportPdf,
        closeShiftReport
    };
}
