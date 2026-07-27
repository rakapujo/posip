/**
 * Thermal PDF (80mm): measure on tall page, then redraw on fitted height.
 * Never mutate pageSize after draw — that blanks Chrome (MediaBox vs text coords).
 *
 * @param {(doc: import('jspdf').jsPDF) => number} buildFn - draws content, returns bottom Y (mm)
 * @returns {Promise<import('jspdf').jsPDF>}
 */
export async function createFittedThermalPdf(buildFn) {
    const { jsPDF } = await import('jspdf');
    const probe = new jsPDF({ unit: 'mm', format: [80, 500] });
    const bottomY = buildFn(probe);
    if (probe.getNumberOfPages() > 1) return probe;

    const height = Math.min(500, Math.max(Math.ceil(Number(bottomY) + 10), 80));
    // Min 80mm: jsPDF swaps [80, h]→[h, 80] when h < 80 (portrait), which breaks thermal layout.
    const doc = new jsPDF({ unit: 'mm', format: [80, height] });
    buildFn(doc);
    return doc;
}
