import client from '../client';

/**
 * Serial Units API module (Register Unit Serial — read-only, modul serial A+).
 * Telusuri tiap unit fisik per nomor seri: status, modal, harga jual, asal dokumen.
 */
export const serialUnitsApi = {
    /**
     * List unit serial (paginated) + ringkasan status.
     * @param {Object} params - search(SN), product_id(ulid/id), warehouse_id, intake_id(ulid), status, sort_field, sort_order, per_page, page
     * @returns {Promise}
     */
    getAll: (params = {}) => client.get('/serial-units', { params }),

    /**
     * Export Excel register unit serial (hormati filter aktif). Mengembalikan blob.
     * @param {Object} params - search, product_id, warehouse_id, status
     * @returns {Promise}
     */
    export: (params = {}) => client.get('/serial-units/export', { params, responseType: 'blob' }),

    /**
     * Unit serial TERSEDIA untuk dipilih di dokumen (Transfer/Adjustment-keluar/Retur).
     * @param {Object} params - product_id(ulid/id, wajib), warehouse_id(ulid/id, opsional)
     * @returns {Promise}
     */
    available: (params = {}) => client.get('/serial-units/available', { params }),

    /**
     * Scan pintar 1 unit (POS / form retur): cocokkan kode_internal (unik) → fallback nomor seri.
     * Respons: { unit, sellable, reason, matched_by } ATAU { ambiguous:true, candidates[] } bila SN ganda.
     * @param {Object} params - code(wajib; kode_internal/SN; `serial_number` diterima sbg alias), warehouse_id(id, wajib)
     * @returns {Promise}
     */
    lookup: (params = {}) => client.get('/serial-units/lookup', { params }),

    /**
     * Saran kode_internal berikutnya (KI-#######) untuk tombol Generate di form intake.
     * Respons: { prefix, pad, highest, next }. Hanya saran — keunikan final dicek saat simpan.
     * @returns {Promise}
     */
    peekKode: () => client.get('/serial-units/peek-kode')
};

export default serialUnitsApi;
