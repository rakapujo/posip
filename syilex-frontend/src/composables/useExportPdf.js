import { ref } from 'vue';
import { useFormatters } from './useFormatters';
import { useSettingsStore } from '@/stores/settings';
import { useAuthStore } from '@/stores/auth';

/**
 * Composable for exporting PDF documents (A4 format).
 *
 * Two modes:
 * - exportListPdf: Export table/list data (master pages)
 * - exportDocumentPdf: Export single document (transactions)
 *
 * Uses jsPDF + jspdf-autotable (dynamic import).
 *
 * Returns `exporting` ref for loading state.
 */
export function useExportPdf() {
    const { formatDateTime } = useFormatters();
    const settingsStore = useSettingsStore();
    const authStore = useAuthStore();
    const exporting = ref(false);

    // Constants
    const PAGE_WIDTH = 210;
    const MARGIN = 15;
    const CONTENT_WIDTH = PAGE_WIDTH - MARGIN * 2;
    const PRIMARY_COLOR = [59, 130, 246];

    /**
     * Get store info from settings
     */
    const getStoreInfo = () => ({
        name: settingsStore.store.name || 'POSIP',
        address: settingsStore.store.address || '',
        phone: settingsStore.store.phone || ''
    });

    /**
     * Resolve nested field value using dot notation
     * e.g. 'brand.nama_brand' → row.brand.nama_brand
     */
    const resolveField = (row, field) => {
        if (!field || !row) return '';
        return field.split('.').reduce((obj, key) => obj?.[key], row) ?? '';
    };

    /**
     * Build PDF header: store name, address, phone, separator, title, date
     * @returns {number} y position after header
     */
    const buildHeader = (doc, title) => {
        const store = getStoreInfo();
        let y = 15;

        // Store name
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.text(store.name, PAGE_WIDTH / 2, y, { align: 'center' });
        y += 5;

        // Address & phone
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8);
        const subParts = [];
        if (store.address) subParts.push(store.address);
        if (store.phone) subParts.push(`Telp: ${store.phone}`);
        if (subParts.length > 0) {
            doc.text(subParts.join(' | '), PAGE_WIDTH / 2, y, { align: 'center' });
            y += 4;
        }

        // Separator line
        y += 2;
        doc.setDrawColor(...PRIMARY_COLOR);
        doc.setLineWidth(0.5);
        doc.line(MARGIN, y, PAGE_WIDTH - MARGIN, y);
        y += 6;

        // Title
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.text(title, PAGE_WIDTH / 2, y, { align: 'center' });
        y += 5;

        // Print date & user
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7);
        doc.setTextColor(120, 120, 120);
        doc.text(`Dicetak: ${formatDateTime(new Date().toISOString())} oleh ${authStore.displayName}`, PAGE_WIDTH / 2, y, { align: 'center' });
        doc.setTextColor(0, 0, 0);
        y += 6;

        return y;
    };

    /**
     * Draw info section: 2-column key-value grid
     * @param {Object} doc - jsPDF instance
     * @param {number} y - current Y position
     * @param {Array<{label: string, value: string}>} info - key-value pairs
     * @returns {number} new Y position
     */
    const drawInfoSection = (doc, y, info) => {
        if (!info || info.length === 0) return y;

        doc.setFontSize(8);
        const colWidth = CONTENT_WIDTH / 2;
        const labelWidth = 40;

        for (let i = 0; i < info.length; i += 2) {
            // Left column
            const left = info[i];
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(100, 100, 100);
            doc.text(left.label, MARGIN, y);
            doc.setTextColor(0, 0, 0);
            doc.setFont('helvetica', 'bold');
            doc.text(`: ${left.value || '-'}`, MARGIN + labelWidth, y);

            // Right column
            if (i + 1 < info.length) {
                const right = info[i + 1];
                doc.setFont('helvetica', 'normal');
                doc.setTextColor(100, 100, 100);
                doc.text(right.label, MARGIN + colWidth, y);
                doc.setTextColor(0, 0, 0);
                doc.setFont('helvetica', 'bold');
                doc.text(`: ${right.value || '-'}`, MARGIN + colWidth + labelWidth, y);
            }

            y += 4.5;
        }

        doc.setFont('helvetica', 'normal');
        return y + 2;
    };

    /**
     * Draw summary section: right-aligned financial summary
     * @param {Object} doc - jsPDF instance
     * @param {number} y - current Y position
     * @param {Array<{label: string, value: string, bold?: boolean, separator?: boolean}>} summary
     * @returns {number} new Y position
     */
    const drawSummarySection = (doc, y, summary) => {
        if (!summary || summary.length === 0) return y;

        const summaryWidth = 80;
        const startX = PAGE_WIDTH - MARGIN - summaryWidth;

        doc.setFontSize(8);

        for (const row of summary) {
            if (row.separator) {
                doc.setDrawColor(200, 200, 200);
                doc.setLineWidth(0.3);
                doc.line(startX, y, PAGE_WIDTH - MARGIN, y);
                y += 3;
                continue;
            }

            doc.setFont('helvetica', row.bold ? 'bold' : 'normal');
            const fontSize = row.bold ? 9 : 8;
            doc.setFontSize(fontSize);

            doc.text(row.label, startX, y);
            doc.text(row.value || '-', PAGE_WIDTH - MARGIN, y, { align: 'right' });
            y += row.bold ? 5 : 4;
        }

        doc.setFont('helvetica', 'normal');
        return y;
    };

    /**
     * Draw audit section: small text audit trail
     * @param {Object} doc - jsPDF instance
     * @param {number} y - current Y position
     * @param {Array<{label: string, value: string, date?: string}>} audit
     * @returns {number} new Y position
     */
    const drawAuditSection = (doc, y, audit) => {
        if (!audit || audit.length === 0) return y;

        y += 2;
        doc.setDrawColor(230, 230, 230);
        doc.setLineWidth(0.2);
        doc.line(MARGIN, y, PAGE_WIDTH - MARGIN, y);
        y += 4;

        doc.setFontSize(7);
        doc.setTextColor(140, 140, 140);

        for (const item of audit) {
            const text = item.date ? `${item.label}: ${item.value} (${item.date})` : `${item.label}: ${item.value}`;
            doc.text(text, MARGIN, y);
            y += 3.5;
        }

        doc.setTextColor(0, 0, 0);
        return y;
    };

    /**
     * Add page numbering to all pages: "Halaman X dari Y"
     */
    const addPageNumbers = (doc) => {
        const totalPages = doc.internal.getNumberOfPages();
        for (let i = 1; i <= totalPages; i++) {
            doc.setPage(i);
            doc.setFontSize(7);
            doc.setTextColor(150, 150, 150);
            doc.text(`Halaman ${i} dari ${totalPages}`, PAGE_WIDTH / 2, doc.internal.pageSize.getHeight() - 8, { align: 'center' });
        }
        doc.setTextColor(0, 0, 0);
    };

    /**
     * Export list/table data as PDF (A4)
     *
     * @param {Object} options
     * @param {string} options.title - Document title
     * @param {string} options.filename - Filename without .pdf
     * @param {Array<{header: string, field: string, width?: number, align?: string, accessor?: Function}>} options.columns
     * @param {Array} options.data - Array of row objects
     * @param {string} [options.filters] - Active filter description
     * @param {string} [options.totalLabel] - Total row label
     */
    const exportListPdf = async (options) => {
        const { title, filename, columns, data, filters, totalLabel } = options;

        exporting.value = true;
        try {
            const { jsPDF } = await import('jspdf');
            const { applyPlugin } = await import('jspdf-autotable');
            applyPlugin(jsPDF);

            const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
            const landscapeWidth = 297;
            const landscapeMargin = 15;

            // Build header (landscape)
            let y = buildHeaderLandscape(doc, title, landscapeWidth);

            // Filter description
            if (filters) {
                doc.setFontSize(7);
                doc.setTextColor(100, 100, 100);
                doc.text(`Filter: ${filters}`, landscapeMargin, y);
                doc.setTextColor(0, 0, 0);
                y += 5;
            }

            // Build autoTable columns & body
            const head = [columns.map((c) => c.header)];
            const body = data.map((row, idx) =>
                columns.map((col) => {
                    if (col.field === '#') return String(idx + 1);
                    if (col.accessor) return String(col.accessor(row, idx));
                    const val = resolveField(row, col.field);
                    return val != null ? String(val) : '';
                })
            );

            // Column styles
            const columnStyles = {};
            columns.forEach((col, i) => {
                const style = {};
                if (col.align === 'right') style.halign = 'right';
                if (col.align === 'center') style.halign = 'center';
                if (col.width) style.cellWidth = col.width;
                if (col.cellStyle) Object.assign(style, col.cellStyle);
                if (Object.keys(style).length > 0) columnStyles[i] = style;
            });

            doc.autoTable({
                startY: y,
                head,
                body,
                margin: { left: landscapeMargin, right: landscapeMargin },
                theme: 'grid',
                headStyles: {
                    fillColor: PRIMARY_COLOR,
                    textColor: [255, 255, 255],
                    fontSize: 8,
                    fontStyle: 'bold',
                    halign: 'center'
                },
                bodyStyles: {
                    fontSize: 7.5,
                    cellPadding: 2
                },
                alternateRowStyles: {
                    fillColor: [248, 250, 252]
                },
                columnStyles
            });

            // Total label
            if (totalLabel) {
                const finalY = doc.lastAutoTable.finalY + 5;
                doc.setFontSize(8);
                doc.setFont('helvetica', 'bold');
                doc.text(totalLabel, landscapeMargin, finalY);
                doc.setFont('helvetica', 'normal');
            }

            // Page numbers
            addPageNumbers(doc);

            doc.save(`${filename}.pdf`);
        } finally {
            exporting.value = false;
        }
    };

    /**
     * Build header for landscape orientation
     */
    const buildHeaderLandscape = (doc, title, pageW) => {
        const store = getStoreInfo();
        let y = 15;

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(14);
        doc.text(store.name, pageW / 2, y, { align: 'center' });
        y += 5;

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8);
        const subParts = [];
        if (store.address) subParts.push(store.address);
        if (store.phone) subParts.push(`Telp: ${store.phone}`);
        if (subParts.length > 0) {
            doc.text(subParts.join(' | '), pageW / 2, y, { align: 'center' });
            y += 4;
        }

        y += 2;
        doc.setDrawColor(...PRIMARY_COLOR);
        doc.setLineWidth(0.5);
        doc.line(15, y, pageW - 15, y);
        y += 6;

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(12);
        doc.text(title, pageW / 2, y, { align: 'center' });
        y += 5;

        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7);
        doc.setTextColor(120, 120, 120);
        doc.text(`Dicetak: ${formatDateTime(new Date().toISOString())} oleh ${authStore.displayName}`, pageW / 2, y, { align: 'center' });
        doc.setTextColor(0, 0, 0);
        y += 6;

        return y;
    };

    /**
     * Export single document as PDF (A4 portrait)
     *
     * @param {Object} options
     * @param {string} options.title - Document title (e.g. 'Purchase Order')
     * @param {string} options.filename - Filename without .pdf
     * @param {Array<{label: string, value: string}>} options.info - Document info key-value pairs
     * @param {Object} [options.table] - Single table data (use `table` or `tables`, not both)
     * @param {Array<{header: string, field: string, width?: number, align?: string, accessor?: Function}>} [options.table.columns]
     * @param {Array} [options.table.data] - Array of row objects
     * @param {Array<{title?: string, columns: Array, data: Array}>} [options.tables] - Multiple tables with optional sub-titles
     * @param {Array<{label: string, value: string, bold?: boolean, separator?: boolean}>} [options.summary] - Financial summary
     * @param {Array<{label: string, value: string, date?: string}>} [options.audit] - Audit trail
     * @param {string} [options.notes] - Notes text
     */
    const exportDocumentPdf = async (options) => {
        const { title, filename, info, table, tables, summary, audit, notes } = options;

        exporting.value = true;
        try {
            const { jsPDF } = await import('jspdf');
            const { applyPlugin: applyAutoTable } = await import('jspdf-autotable');
            applyAutoTable(jsPDF);

            const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

            // Header
            let y = buildHeader(doc, title);

            // Info section
            y = drawInfoSection(doc, y, info);

            // Tables - supports both single `table` and multiple `tables`
            const tablesToRender = tables ? tables : table ? [table] : [];
            for (const tbl of tablesToRender) {
                if (!tbl.columns || !tbl.data) continue;

                // Sub-title for multi-table mode
                if (tbl.title) {
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(9);
                    doc.text(tbl.title, MARGIN, y);
                    y += 5;
                    doc.setFont('helvetica', 'normal');
                }

                const head = [tbl.columns.map((c) => c.header)];
                const body = tbl.data.map((row, idx) =>
                    tbl.columns.map((col) => {
                        if (col.field === '#') return String(idx + 1);
                        if (col.accessor) return String(col.accessor(row, idx));
                        const val = resolveField(row, col.field);
                        return val != null ? String(val) : '';
                    })
                );

                const columnStyles = {};
                tbl.columns.forEach((col, i) => {
                    const style = {};
                    if (col.align === 'right') style.halign = 'right';
                    if (col.align === 'center') style.halign = 'center';
                    if (col.width) style.cellWidth = col.width;
                    if (Object.keys(style).length > 0) columnStyles[i] = style;
                });

                doc.autoTable({
                    startY: y,
                    head,
                    body,
                    margin: { left: MARGIN, right: MARGIN },
                    theme: 'grid',
                    headStyles: {
                        fillColor: PRIMARY_COLOR,
                        textColor: [255, 255, 255],
                        fontSize: 8,
                        fontStyle: 'bold',
                        halign: 'center'
                    },
                    bodyStyles: {
                        fontSize: 7.5,
                        cellPadding: 2
                    },
                    alternateRowStyles: {
                        fillColor: [248, 250, 252]
                    },
                    columnStyles
                });

                y = doc.lastAutoTable.finalY + 6;
            }

            // Summary section
            y = drawSummarySection(doc, y, summary);

            // Notes
            if (notes) {
                y += 4;
                doc.setFontSize(8);
                doc.setFont('helvetica', 'bold');
                doc.text('Catatan:', MARGIN, y);
                y += 4;
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(7.5);
                const lines = doc.splitTextToSize(notes, CONTENT_WIDTH);
                doc.text(lines, MARGIN, y);
                y += lines.length * 3.5;
            }

            // Audit trail
            y = drawAuditSection(doc, y, audit);

            // Page numbers
            addPageNumbers(doc);

            doc.save(`${filename}.pdf`);
        } finally {
            exporting.value = false;
        }
    };

    return {
        exporting,
        exportListPdf,
        exportDocumentPdf
    };
}
