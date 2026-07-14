import client from '../client';

/**
 * Unified Reports API — Sprint 1-3 laporan + sales/purchase admin reports.
 */
export const salesReport = {
    getAll: (params = {}) => client.get('/sales-report', { params }),
    get: (ulid) => client.get(`/sales-report/${ulid}`),
    getDropdowns: () => client.get('/sales-report/dropdowns'),
    exportExcel: (params = {}) => client.get('/sales-report/export', { params, responseType: 'blob' })
};

export const salesProductReport = {
    getAll: (params = {}) => client.get('/sales-product-report', { params }),
    get: (productUlid, params = {}) => client.get(`/sales-product-report/${productUlid}`, { params }),
    getDropdowns: () => client.get('/sales-product-report/dropdowns'),
    exportExcel: (params = {}) => client.get('/sales-product-report/export', { params, responseType: 'blob' })
};

export const salesFinancialReport = {
    getPembulatan: (params = {}) => client.get('/sales-financial-report/pembulatan', { params }),
    getDiscLine: (params = {}) => client.get('/sales-financial-report/disc-line', { params }),
    getDiscLineDetail: (salesUlid) => client.get(`/sales-financial-report/disc-line/${salesUlid}`),
    getDiscNota: (params = {}) => client.get('/sales-financial-report/disc-nota', { params }),
    getBiaya: (params = {}) => client.get('/sales-financial-report/biaya', { params }),
    getDropdowns: () => client.get('/sales-financial-report/dropdowns'),
    exportPembulatan: (params = {}) => client.get('/sales-financial-report/pembulatan/export', { params, responseType: 'blob' }),
    exportDiscLine: (params = {}) => client.get('/sales-financial-report/disc-line/export', { params, responseType: 'blob' }),
    exportDiscNota: (params = {}) => client.get('/sales-financial-report/disc-nota/export', { params, responseType: 'blob' }),
    exportBiaya: (params = {}) => client.get('/sales-financial-report/biaya/export', { params, responseType: 'blob' })
};

export const purchaseReport = {
    getPerDokumen: (params = {}) => client.get('/purchase-report/per-dokumen', { params }),
    getPerDokumenDetail: (ulid) => client.get(`/purchase-report/per-dokumen/${ulid}`),
    getPerBarang: (params = {}) => client.get('/purchase-report/per-barang', { params }),
    getPerBarangDetail: (productUlid, params = {}) => client.get(`/purchase-report/per-barang/${productUlid}`, { params }),
    getPerSupplier: (params = {}) => client.get('/purchase-report/per-supplier', { params }),
    getPerSupplierDetail: (supplierId, params = {}) => client.get(`/purchase-report/per-supplier/${supplierId}`, { params }),
    getDiskon: (params = {}) => client.get('/purchase-report/diskon', { params }),
    getHargaTerakhir: (params = {}) => client.get('/purchase-report/harga-terakhir', { params }),
    getDropdowns: () => client.get('/purchase-report/dropdowns'),
    exportDiskon: (params = {}) => client.get('/purchase-report/diskon/export', { params, responseType: 'blob' }),
    exportPerDokumen: (params = {}) => client.get('/purchase-report/per-dokumen/export', { params, responseType: 'blob' }),
    exportPerSupplier: (params = {}) => client.get('/purchase-report/per-supplier/export', { params, responseType: 'blob' }),
    exportPerBarang: (params = {}) => client.get('/purchase-report/per-barang/export', { params, responseType: 'blob' }),
    exportHargaTerakhir: (params = {}) => client.get('/purchase-report/harga-terakhir/export', { params, responseType: 'blob' })
};

export const reportsApi = {
    salesReport,
    salesProductReport,
    salesFinancialReport,
    purchaseReport,

    // ─── Sprint 1: Keuangan ───────────────────────────────────────

    grossProfit: {
        summary: (params = {}) => client.get('/reports/gross-profit/summary', { params }),
        daily: (params = {}) => client.get('/reports/gross-profit/daily', { params }),
        byKategori: (params = {}) => client.get('/reports/gross-profit/by-kategori', { params }),
        topProducts: (params = {}) => client.get('/reports/gross-profit/top-products', { params }),
        exportDaily: (params = {}) => client.get('/reports/gross-profit/daily/export', { params, responseType: 'blob' }),
        exportByKategori: (params = {}) => client.get('/reports/gross-profit/by-kategori/export', { params, responseType: 'blob' }),
        exportTopProducts: (params = {}) => client.get('/reports/gross-profit/top-products/export', { params, responseType: 'blob' })
    },

    marginPerBarang: {
        summary: (params = {}) => client.get('/reports/margin-per-barang/summary', { params }),
        list: (params = {}) => client.get('/reports/margin-per-barang', { params }),
        exportExcel: (params = {}) => client.get('/reports/margin-per-barang/export', { params, responseType: 'blob' })
    },

    cashFlow: {
        summary: (params = {}) => client.get('/reports/cash-flow/summary', { params }),
        daily: (params = {}) => client.get('/reports/cash-flow/daily', { params }),
        exportDaily: (params = {}) => client.get('/reports/cash-flow/daily/export', { params, responseType: 'blob' })
    },

    kasirPerformance: {
        list: (params = {}) => client.get('/reports/kasir-performance', { params }),
        exportExcel: (params = {}) => client.get('/reports/kasir-performance/export', { params, responseType: 'blob' })
    },

    // ─── Sprint 2: Promo Suite ────────────────────────────────────

    promoUsage: {
        summary: (params = {}) => client.get('/reports/promo-usage/summary', { params }),
        list: (params = {}) => client.get('/reports/promo-usage', { params }),
        detail: (ulid, params = {}) => client.get(`/reports/promo-usage/${ulid}`, { params }),
        exportExcel: (params = {}) => client.get('/reports/promo-usage/export', { params, responseType: 'blob' })
    },

    productPromo: {
        byProduct: (params = {}) => client.get('/reports/product-promo/by-product', { params }),
        byPromo: (params = {}) => client.get('/reports/product-promo/by-promo', { params }),
        exportByProduct: (params = {}) => client.get('/reports/product-promo/by-product/export', { params, responseType: 'blob' }),
        exportByPromo: (params = {}) => client.get('/reports/product-promo/by-promo/export', { params, responseType: 'blob' })
    },

    customerPromo: {
        summary: (params = {}) => client.get('/reports/customer-promo/summary', { params }),
        byTipe: (params = {}) => client.get('/reports/customer-promo/by-tipe', { params }),
        byKategori: (params = {}) => client.get('/reports/customer-promo/by-kategori', { params }),
        byCustomer: (params = {}) => client.get('/reports/customer-promo/by-customer', { params }),
        showCustomer: (ulid, params = {}) => client.get(`/reports/customer-promo/customer/${ulid}`, { params }),
        exportSummary: (params = {}) => client.get('/reports/customer-promo/summary/export', { params, responseType: 'blob' }),
        exportByTipe: (params = {}) => client.get('/reports/customer-promo/by-tipe/export', { params, responseType: 'blob' }),
        exportByKategori: (params = {}) => client.get('/reports/customer-promo/by-kategori/export', { params, responseType: 'blob' }),
        exportByCustomer: (params = {}) => client.get('/reports/customer-promo/by-customer/export', { params, responseType: 'blob' })
    },

    // ─── Sprint 3: Operational ────────────────────────────────────

    paymentMethod: {
        breakdown: (params = {}) => client.get('/reports/payment-method/breakdown', { params }),
        exportExcel: (params = {}) => client.get('/reports/payment-method/breakdown/export', { params, responseType: 'blob' })
    },

    topCustomer: {
        list: (params = {}) => client.get('/reports/customer/top', { params }),
        exportExcel: (params = {}) => client.get('/reports/customer/top/export', { params, responseType: 'blob' })
    },

    returPattern: {
        list: (params = {}) => client.get('/reports/retur/pattern', { params }),
        exportExcel: (params = {}) => client.get('/reports/retur/pattern/export', { params, responseType: 'blob' })
    },

    deadStock: {
        list: (params = {}) => client.get('/reports/inventory/dead-stock', { params }),
        exportExcel: (params = {}) => client.get('/reports/inventory/dead-stock/export', { params, responseType: 'blob' })
    }
};

export default reportsApi;
