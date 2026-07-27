/**
 * Helpers for product×unit document lines (Sales / PO / returns).
 * Keep thin — parents own API fetch and apply-line logic.
 */

/** @param {number|string} productId @param {string} unit @param {boolean} [isSerial] */
export function productUnitKey(productId, unit, isSerial = false) {
    if (isSerial) return `s:${productId}`;
    return `${productId}|${unit || ''}`;
}

/**
 * Flatten API products into picker rows.
 * @param {object[]} products
 * @param {{ expandUnits?: boolean, serialOnly?: boolean, includeSerial?: boolean, getUnitPrice?: Function }} opts
 */
export function flattenProductUnitRows(products, opts = {}) {
    const { expandUnits = true, serialOnly = false, includeSerial = true, getUnitPrice = null } = opts;

    const rows = [];
    for (const product of products || []) {
        const isSerial = !!product.is_serial;
        if (serialOnly && !isSerial) continue;
        if (!includeSerial && isSerial) continue;

        if (isSerial || !expandUnits) {
            const unitObj = isSerial ? { unit: 'UNIT', konversi: 1, harga_jual: Number(product.harga_1 || product.units?.[0]?.harga_jual || 0) } : uniqueUnits(product)[0] || { unit: product.unit_1 || 'PCS', konversi: 1, harga_jual: 0 };
            const price = getUnitPrice ? getUnitPrice(product, unitObj) : (unitObj.harga_jual ?? unitObj.harga ?? null);
            rows.push({
                key: `${product.id}|${unitObj.unit}`,
                product,
                unit: unitObj.unit,
                konversi: Number(unitObj.konversi) || 1,
                price: price == null ? null : Number(price),
                is_serial: isSerial,
                unitObj
            });
            continue;
        }

        for (const unitObj of uniqueUnits(product)) {
            const price = getUnitPrice ? getUnitPrice(product, unitObj) : (unitObj.harga_jual ?? unitObj.harga ?? null);
            rows.push({
                key: `${product.id}|${unitObj.unit}`,
                product,
                unit: unitObj.unit,
                konversi: Number(unitObj.konversi) || 1,
                price: price == null ? null : Number(price),
                is_serial: false,
                unitObj
            });
        }
    }
    return rows;
}

function uniqueUnits(product) {
    const raw = product.units || [];
    if (raw.length) {
        const seen = new Set();
        return raw.filter((u) => {
            if (!u?.unit || seen.has(u.unit)) return false;
            seen.add(u.unit);
            return true;
        });
    }
    const out = [];
    const seen = new Set();
    for (let i = 1; i <= 4; i++) {
        const unit = product[`unit_${i}`];
        if (!unit || seen.has(unit)) continue;
        seen.add(unit);
        out.push({
            unit,
            konversi: Number(product[`konversi_${i}`]) || 1,
            harga_jual: Number(product[`harga_${i}`]) || 0
        });
    }
    return out;
}

/**
 * @param {object[]} details
 * @param {{ unitField?: string, exceptIndex?: number, isSerial?: (d)=>boolean }} opts
 * @returns {Set<string>}
 */
export function buildTakenKeys(details, opts = {}) {
    const unitField = opts.unitField || 'unit_used';
    const exceptIndex = opts.exceptIndex;
    const isSerial = opts.isSerial || ((d) => !!(d?.is_serial || d?.product?.is_serial));
    const set = new Set();
    (details || []).forEach((d, i) => {
        if (exceptIndex != null && i === exceptIndex) return;
        if (!d?.product_id) return;
        if (isSerial(d)) {
            set.add(productUnitKey(d.product_id, 'UNIT', true));
        } else if (d[unitField]) {
            set.add(productUnitKey(d.product_id, d[unitField], false));
        }
    });
    return set;
}

/**
 * Mark duplicate product+unit on validate (retail). Serial: one line per product_id.
 * @returns {{ index: number, message: string }[]}
 */
export function findDuplicateProductUnitErrors(details, opts = {}) {
    const unitField = opts.unitField || 'unit_used';
    const isSerial = opts.isSerial || ((d) => !!(d?.is_serial || d?.product?.is_serial));
    const seen = new Set();
    const errors = [];
    (details || []).forEach((d, index) => {
        if (!d?.product_id) return;
        const serial = isSerial(d);
        const unit = serial ? 'UNIT' : d[unitField];
        if (!unit) return;
        const key = productUnitKey(d.product_id, unit, serial);
        if (seen.has(key)) {
            errors.push({
                index,
                message: serial ? 'Produk serial sudah ada di baris lain' : 'Produk dengan satuan yang sama sudah ada'
            });
        } else {
            seen.add(key);
        }
    });
    return errors;
}

/**
 * Soft: same product_id (≥2). Strong: same product+unit (≥2).
 * @returns {'dup-unit'|'dup-product'|''}
 */
export function productUnitRowHighlight(detail, details, opts = {}) {
    if (!detail?.product_id) return '';
    const unitField = opts.unitField || 'unit_used';
    const isSerial = opts.isSerial || ((d) => !!(d?.is_serial || d?.product?.is_serial));
    const serial = isSerial(detail);
    const unit = serial ? 'UNIT' : detail[unitField];
    let sameProduct = 0;
    let sameUnit = 0;
    for (const d of details || []) {
        if (!d?.product_id || d.product_id !== detail.product_id) continue;
        sameProduct++;
        const dSerial = isSerial(d);
        const dUnit = dSerial ? 'UNIT' : d[unitField];
        if (unit && dUnit === unit && dSerial === serial) sameUnit++;
    }
    if (sameUnit >= 2) return 'dup-unit';
    if (sameProduct >= 2) return 'dup-product';
    return '';
}
