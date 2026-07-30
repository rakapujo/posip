import { ref } from 'vue';
import { useFormatters } from './useFormatters';
import { useSettingsStore } from '@/stores/settings';
import { useAuthStore } from '@/stores/auth';

/**
 * Faktur penjualan PDF — A5 landscape (continuous half-form).
 * Header toko + customer + meta diulang di setiap halaman (seperti contoh salesInvoices).
 * Total + TTD hanya di halaman terakhir. Halaman non-akhir: "Bersambung ke halaman n".
 * Harga jual selalu ditampilkan (sales.view_harga dihapus).
 */
export function useSalesInvoicePdf() {
    const { formatCurrency, formatQty, formatDateTime } = useFormatters();
    const settingsStore = useSettingsStore();
    const authStore = useAuthStore();
    const exporting = ref(false);

    const PAGE_WIDTH = 210;
    const PAGE_HEIGHT = 148;
    const MARGIN = 8;
    const CONTENT_WIDTH = PAGE_WIDTH - MARGIN * 2;

    // Tinggi blok header penuh (toko + customer + meta) — harus sama di setiap halaman
    const HEADER_H = 48;
    const TABLE_HEAD_H = 6;
    const ROW_H = 5.5;
    const CONTINUE_H = 8;
    const TOTALS_H = 48;
    const TOTALS_GAP = 8; // jarak tabel → blok terbilang/subtotal
    const SERIAL_LINE_H = 3.2;

    const ONES = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    function terbilang(n) {
        n = Math.floor(Math.abs(Number(n) || 0));
        if (n < 12) return ONES[n] || 'nol';
        if (n < 20) return `${ONES[n - 10]} belas`;
        if (n < 100) return `${ONES[Math.floor(n / 10)]} puluh${n % 10 ? ` ${ONES[n % 10]}` : ''}`.trim();
        if (n < 200) return `seratus${n - 100 ? ` ${terbilang(n - 100)}` : ''}`;
        if (n < 1000) return `${ONES[Math.floor(n / 100)]} ratus${n % 100 ? ` ${terbilang(n % 100)}` : ''}`.trim();
        if (n < 2000) return `seribu${n - 1000 ? ` ${terbilang(n - 1000)}` : ''}`;
        if (n < 1_000_000) {
            const ribu = Math.floor(n / 1000);
            return `${terbilang(ribu)} ribu${n % 1000 ? ` ${terbilang(n % 1000)}` : ''}`.trim();
        }
        if (n < 1_000_000_000) {
            const juta = Math.floor(n / 1_000_000);
            return `${terbilang(juta)} juta${n % 1_000_000 ? ` ${terbilang(n % 1_000_000)}` : ''}`.trim();
        }
        const milyar = Math.floor(n / 1_000_000_000);
        return `${terbilang(milyar)} milyar${n % 1_000_000_000 ? ` ${terbilang(n % 1_000_000_000)}` : ''}`.trim();
    }

    function terbilangRupiah(amount) {
        const words = terbilang(amount);
        return `${words.charAt(0).toUpperCase()}${words.slice(1)} rupiah`;
    }

    function statusBadge(data) {
        if (data.status === 'draft') return 'DRAFT';
        if (data.status === 'voided' || data.status === 'cancelled') return 'VOID';
        if (data.cash_payment) return 'LUNAS';
        const p = data.piutang?.status;
        if (p === 'paid') return 'LUNAS';
        if (p === 'partial') return 'SEBAGIAN';
        if (data.status === 'completed') return 'TEMPO';
        return (data.status || '').toUpperCase() || '';
    }

    function formatSerialLine(u) {
        const parts = [];
        if (u.kode_internal) parts.push(u.kode_internal);
        parts.push(`SN ${u.serial_number || '-'}`);
        if (u.grade) parts.push(u.grade);
        if (u.catatan) parts.push(u.catatan);
        return parts.join(' · ');
    }

    function buildBodyRows(details) {
        return (details || []).map((row, i) => {
            const discParts = [];
            for (let s = 1; s <= 5; s++) {
                const tipe = row[`diskon_${s}_tipe`];
                const nilai = Number(row[`diskon_${s}_nilai`] || 0);
                if (!tipe || tipe === 'none' || !nilai) continue;
                discParts.push(tipe === 'percent' ? `${nilai}%` : formatCurrency(nilai));
            }

            const serialUnits = row.serial_units || [];
            let nama = row.product?.nama_produk || '';
            if (serialUnits.length) {
                nama += '\n' + serialUnits.map((u) => `  ${formatSerialLine(u)}`).join('\n');
            }

            return {
                no: String(i + 1),
                kode: row.product?.kode_produk || '',
                nama,
                unit: row.unit || '',
                qty: formatQty(row.qty),
                harga: formatCurrency(row.harga_satuan),
                disc: discParts.length ? discParts.join(' + ') : '-',
                jumlah: formatCurrency(row.jumlah),
                _h: ROW_H + serialUnits.length * SERIAL_LINE_H
            };
        });
    }

    /** Pack by estimated height — baris serial lebih tinggi. */
    function paginateRows(rows) {
        const totalsH = TOTALS_H + TOTALS_GAP;
        const availMid = PAGE_HEIGHT - MARGIN * 2 - HEADER_H - TABLE_HEAD_H - CONTINUE_H;
        const availLast = PAGE_HEIGHT - MARGIN * 2 - HEADER_H - TABLE_HEAD_H - totalsH;
        const heightOf = (list) => list.reduce((s, r) => s + (r._h || ROW_H), 0);

        if (!rows.length) return [[]];

        const pages = [];
        let i = 0;
        while (i < rows.length) {
            const rest = rows.slice(i);
            if (heightOf(rest) <= availLast) {
                pages.push(rest);
                break;
            }
            let used = 0;
            let n = 0;
            while (n < rest.length && used + (rest[n]._h || ROW_H) <= availMid) {
                used += rest[n]._h || ROW_H;
                n += 1;
            }
            pages.push(rest.slice(0, Math.max(1, n)));
            i += Math.max(1, n);
        }
        return pages;
    }

    /** Header penuh di setiap halaman — mirror contoh salesInvoices. */
    function drawHeader(doc, data, store, pageNo, pageCount) {
        let y = MARGIN + 2;

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);
        doc.setTextColor(0, 0, 0);
        doc.text(store.name, MARGIN, y);

        doc.setFontSize(11);
        doc.text('FAKTUR PENJUALAN', PAGE_WIDTH - MARGIN, y, { align: 'right' });
        y += 4;

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(6.5);
        const addrLines = doc.splitTextToSize(store.address || '', CONTENT_WIDTH * 0.62);
        if (addrLines.length) {
            doc.text(addrLines, MARGIN, y);
            y += addrLines.length * 3;
        }
        if (store.phone) {
            doc.text(`Telp: ${store.phone}`, MARGIN, y);
            y += 3;
        }

        // nomor + badge di kanan atas (sejajar alamat)
        const rightTop = MARGIN + 6;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(9);
        doc.text(data.nomor_dokumen || '-', PAGE_WIDTH - MARGIN, rightTop, { align: 'right' });
        const badge = statusBadge(data);
        if (badge) {
            doc.setFontSize(7);
            doc.text(badge, PAGE_WIDTH - MARGIN, rightTop + 4, { align: 'right' });
        }

        y = Math.max(y, rightTop + 8);
        doc.setDrawColor(0);
        doc.setLineWidth(0.3);
        doc.line(MARGIN, y, PAGE_WIDTH - MARGIN, y);
        y += 3.5;

        const customer = data.customer || {};
        const infoStart = y;
        doc.setFontSize(6.5);

        doc.setFont('helvetica', 'normal');
        doc.text('Kepada yth:', MARGIN, y);
        doc.setFont('helvetica', 'bold');
        const namaLines = doc.splitTextToSize(String(customer.nama || '-'), 88);
        doc.text(namaLines, MARGIN + 22, y);
        y += Math.max(3.2, namaLines.length * 3);

        if (customer.alamat) {
            doc.setFont('helvetica', 'normal');
            const alamatLines = doc.splitTextToSize(String(customer.alamat), 100);
            doc.text(alamatLines, MARGIN, y);
            y += alamatLines.length * 3;
        }
        if (customer.telepon) {
            doc.setFont('helvetica', 'normal');
            doc.text(`Telp: ${customer.telepon}`, MARGIN, y);
            y += 3.2;
        }

        let yR = infoStart;
        const right = [
            ['No Faktur', data.nomor_dokumen || '-'],
            ['Tanggal', formatDateTime(data.tanggal)],
            ['Jatuh Tempo', formatDateTime(data.tanggal_jatuh_tempo)],
            ['Tempo', `${data.tempo_hari ?? 0} hari`],
            ['Gudang', data.warehouse?.nama_warehouse || '-']
        ];
        for (const [label, value] of right) {
            doc.setFont('helvetica', 'normal');
            doc.text(label, MARGIN + 112, yR);
            doc.setFont('helvetica', 'bold');
            doc.text(`: ${value || '-'}`, MARGIN + 132, yR);
            yR += 3.2;
        }
        doc.setFont('helvetica', 'normal');
        doc.text(`Halaman : ${pageNo} / ${pageCount}`, MARGIN + 112, yR);

        y = Math.max(y, yR + 3.5);
        doc.setLineWidth(0.2);
        doc.line(MARGIN, y, PAGE_WIDTH - MARGIN, y);
        y += 2.5;

        return y;
    }

    function drawContinueFooter(doc, pageNo) {
        const y = PAGE_HEIGHT - MARGIN;
        doc.setFont('helvetica', 'italic');
        doc.setFontSize(7);
        doc.setTextColor(0, 0, 0);
        doc.text(`Bersambung ke halaman ${pageNo + 1}`, PAGE_WIDTH / 2, y, { align: 'center' });
    }

    function drawTotals(doc, data, startY) {
        // Gap jelas antara tabel terakhir dan blok terbilang / subtotal
        const blockY = startY + TOTALS_GAP;
        let y = blockY;
        const summary = [];
        summary.push(['Subtotal', formatCurrency(data.subtotal)]);
        for (let i = 1; i <= 3; i++) {
            const tipe = data[`diskon_nota_${i}_tipe`];
            const nilai = Number(data[`diskon_nota_${i}_nilai`] || 0);
            const hasil = Number(data[`diskon_nota_${i}_hasil`] || 0);
            if (!tipe || tipe === 'none' || !nilai) continue;
            const label = data[`diskon_nota_${i}_label`] || `Disc Nota ${i}`;
            summary.push([label, `-${formatCurrency(hasil)}`]);
        }
        if (Number(data.biaya_kirim_hasil) > 0) summary.push(['Biaya Kirim', formatCurrency(data.biaya_kirim_hasil)]);
        if (Number(data.biaya_lain_hasil) > 0) summary.push(['Biaya Lain', formatCurrency(data.biaya_lain_hasil)]);
        if (Number(data.pajak_nominal) > 0) {
            summary.push(['DPP', formatCurrency(data.dpp)]);
            summary.push([`${data.pajak_nama || 'Pajak'} (${data.pajak_persen || 0}%)`, formatCurrency(data.pajak_nominal)]);
        }
        if (data.pembulatan && Number(data.pembulatan) !== 0) {
            summary.push(['Pembulatan', formatCurrency(data.pembulatan)]);
        }
        summary.push(['Grand Total', formatCurrency(data.grand_total)]);

        const sumX = PAGE_WIDTH - MARGIN - 70;
        doc.setFontSize(7);
        for (const [label, value] of summary) {
            const bold = label === 'Grand Total';
            doc.setFont('helvetica', bold ? 'bold' : 'normal');
            doc.text(label, sumX, y);
            doc.text(value, PAGE_WIDTH - MARGIN, y, { align: 'right' });
            y += bold ? 4 : 3.2;
        }

        doc.setFont('helvetica', 'italic');
        doc.setFontSize(6.5);
        const terbilangLines = doc.splitTextToSize(`Terbilang: ${terbilangRupiah(data.grand_total)}`, CONTENT_WIDTH * 0.55);
        doc.text(terbilangLines, MARGIN, blockY);

        if (data.notes) {
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6.5);
            const noteY = Math.max(y, blockY + 14);
            doc.text(doc.splitTextToSize(`Catatan: ${data.notes}`, CONTENT_WIDTH * 0.55), MARGIN, noteY);
            y = Math.max(y, noteY + 4);
        }

        y = Math.max(y, blockY + 16);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(6.5);
        // Dua kolom tanda tangan: label + garis rata tengah di kolom masing-masing
        const sigW = 42;
        const leftCx = MARGIN + sigW / 2;
        const rightCx = PAGE_WIDTH - MARGIN - sigW / 2;
        doc.text('Penerima', leftCx, y, { align: 'center' });
        doc.text('Hormat kami', rightCx, y, { align: 'center' });
        y += 10;
        doc.text('(..................)', leftCx, y, { align: 'center' });
        doc.text('(..................)', rightCx, y, { align: 'center' });

        doc.setFontSize(5.5);
        doc.setTextColor(100, 100, 100);
        doc.text(`Dicetak: ${formatDateTime(new Date().toISOString())} oleh ${authStore.displayName || '-'}`, MARGIN, PAGE_HEIGHT - MARGIN);
        doc.setTextColor(0, 0, 0);
    }

    /**
     * @param {Object} data - full sales doc (with details, customer, warehouse)
     */
    async function exportSalesInvoicePdf(data) {
        exporting.value = true;
        try {
            const { jsPDF } = await import('jspdf');
            const { applyPlugin } = await import('jspdf-autotable');
            applyPlugin(jsPDF);

            const doc = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: 'a5'
            });

            const store = {
                name: settingsStore.store.name || 'POSIP',
                address: settingsStore.store.address || '',
                phone: settingsStore.store.phone || ''
            };

            const columns = [
                { header: '#', dataKey: 'no' },
                { header: 'Kode', dataKey: 'kode' },
                { header: 'Nama Produk', dataKey: 'nama' },
                { header: 'Satuan', dataKey: 'unit' },
                { header: 'Qty', dataKey: 'qty' },
                { header: 'Harga', dataKey: 'harga' },
                { header: 'Disc', dataKey: 'disc' },
                { header: 'Jumlah', dataKey: 'jumlah' }
            ];

            const allRows = buildBodyRows(data.details);
            const pages = paginateRows(allRows);
            const pageCount = pages.length;

            pages.forEach((chunk, idx) => {
                if (idx > 0) doc.addPage('a5', 'landscape');
                const pageNo = idx + 1;
                const isLast = pageNo === pageCount;

                const startY = drawHeader(doc, data, store, pageNo, pageCount);

                doc.autoTable({
                    startY,
                    head: [columns.map((c) => c.header)],
                    body: chunk.map((row) => columns.map((c) => row[c.dataKey] ?? '')),
                    margin: { left: MARGIN, right: MARGIN, bottom: isLast ? 8 : CONTINUE_H + 2 },
                    // Jangan biarkan autoTable bikin halaman yatim tanpa header kita
                    pageBreak: 'avoid',
                    rowPageBreak: 'avoid',
                    styles: {
                        fontSize: 6.5,
                        cellPadding: 0.8,
                        lineColor: [0, 0, 0],
                        lineWidth: 0.15,
                        textColor: [0, 0, 0],
                        overflow: 'linebreak',
                        minCellHeight: 4.5
                    },
                    headStyles: {
                        fillColor: [255, 255, 255],
                        textColor: [0, 0, 0],
                        fontStyle: 'bold',
                        lineWidth: 0.25
                    },
                    columnStyles: {
                        0: { cellWidth: 7, halign: 'center' },
                        1: { cellWidth: 26 },
                        2: { cellWidth: 'auto' },
                        3: { cellWidth: 18 },
                        4: { halign: 'right', cellWidth: 12 },
                        5: { halign: 'right', cellWidth: 22 },
                        6: { cellWidth: 28, overflow: 'linebreak' },
                        7: { halign: 'right', cellWidth: 24 }
                    },
                    theme: 'grid'
                });

                if (isLast) {
                    drawTotals(doc, data, doc.lastAutoTable.finalY);
                } else {
                    drawContinueFooter(doc, pageNo);
                }
            });

            doc.save(`${data.nomor_dokumen || 'faktur-penjualan'}.pdf`);
        } finally {
            exporting.value = false;
        }
    }

    return { exporting, exportSalesInvoicePdf, terbilangRupiah };
}
