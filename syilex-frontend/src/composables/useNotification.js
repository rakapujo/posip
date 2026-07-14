/**
 * useNotification Composable
 *
 * Standarisasi toast notification messages untuk konsistensi UI.
 *
 * @example
 * const { success, error, created, loadListError, selectFirst } = useNotification();
 *
 * // Quick methods
 * success('Operasi berhasil');
 * error('Terjadi kesalahan');
 *
 * // CRUD operations
 * created('Adjustment');           // "Adjustment berhasil dibuat"
 * updated('Transfer');             // "Transfer berhasil diperbarui"
 * deleted('Brand');                // "Brand berhasil dihapus"
 *
 * // Load errors
 * loadListError('adjustment');     // "Gagal memuat data adjustment"
 * loadDetailError('hutang');       // "Gagal memuat detail hutang"
 *
 * // Validation
 * selectFirst('warehouse');        // "Pilih warehouse terlebih dahulu"
 * formInvalid();                   // "Periksa kembali form Anda"
 */

import { useToast } from 'primevue/usetoast';

// Default life durations (ms)
const LIFE = {
    DEFAULT: 3000,
    LONG: 5000,
    EXTRA_LONG: 8000
};

// Summary labels (Indonesian)
const SUMMARY = {
    SUCCESS: 'Berhasil',
    ERROR: 'Error',
    WARN: 'Peringatan',
    INFO: 'Informasi',
    VALIDATION: 'Validasi'
};

export function useNotification() {
    const toast = useToast();

    // =====================
    // QUICK METHODS
    // =====================

    /**
     * Show success toast
     * @param {string} detail - Message detail
     * @param {number} life - Duration in ms (default: 3000)
     */
    function success(detail, life = LIFE.DEFAULT) {
        toast.add({
            severity: 'success',
            summary: SUMMARY.SUCCESS,
            detail,
            life
        });
    }

    /**
     * Show error toast
     * @param {string} detail - Message detail
     * @param {number} life - Duration in ms (default: 3000, use 5000 for long messages)
     */
    function error(detail, life = LIFE.DEFAULT) {
        toast.add({
            severity: 'error',
            summary: SUMMARY.ERROR,
            detail,
            life
        });
    }

    /**
     * Show warning toast
     * @param {string} detail - Message detail
     * @param {number} life - Duration in ms (default: 3000)
     */
    function warn(detail, life = LIFE.DEFAULT) {
        toast.add({
            severity: 'warn',
            summary: SUMMARY.WARN,
            detail,
            life
        });
    }

    /**
     * Show info toast
     * @param {string} detail - Message detail
     * @param {number} life - Duration in ms (default: 3000)
     */
    function info(detail, life = LIFE.DEFAULT) {
        toast.add({
            severity: 'info',
            summary: SUMMARY.INFO,
            detail,
            life
        });
    }

    /**
     * Show error toast extracted from axios error response.
     * Handles validation errors (422 with errors object), network errors, and fallback.
     *
     * @param {Error|Object} err - Axios error (or any thrown error)
     * @param {string} fallback - Fallback message if error has no readable detail
     * @param {number} life - Duration in ms
     *
     * @example
     * try { await api.save(data); }
     * catch (e) { notify.apiError(e, 'Gagal menyimpan data'); }
     */
    function apiError(err, fallback = 'Terjadi kesalahan', life = LIFE.LONG) {
        const msg = extractApiMessage(err, fallback);
        error(msg, life);
    }

    // =====================
    // LOAD OPERATIONS
    // =====================

    /**
     * Show load list error
     * @param {string} entity - Entity name (lowercase)
     */
    function loadListError(entity) {
        error(`Gagal memuat data ${entity}`);
    }

    /**
     * Show load detail error
     * @param {string} entity - Entity name (lowercase)
     */
    function loadDetailError(entity) {
        error(`Gagal memuat detail ${entity}`);
    }

    /**
     * Show load for edit error
     * @param {string} entity - Entity name (lowercase)
     */
    function loadForEditError(entity) {
        error(`Gagal memuat data ${entity} untuk edit`);
    }

    // =====================
    // SAVE OPERATIONS
    // =====================

    /**
     * Show created success
     * @param {string} entity - Entity name (capitalized)
     */
    function created(entity) {
        success(`${entity} berhasil dibuat`);
    }

    /**
     * Show updated success
     * @param {string} entity - Entity name (capitalized)
     */
    function updated(entity) {
        success(`${entity} berhasil diperbarui`);
    }

    /**
     * Show save success based on edit mode
     * @param {string} entity - Entity name (capitalized)
     * @param {boolean} isEdit - Whether it's edit mode
     */
    function saveSuccess(entity, isEdit) {
        if (isEdit) {
            updated(entity);
        } else {
            created(entity);
        }
    }

    /**
     * Show save error
     * @param {string|Error} messageOrError - Error message or Error object
     */
    function saveError(messageOrError) {
        const message = extractErrorMessage(messageOrError);
        error(message, LIFE.LONG);
    }

    // =====================
    // DELETE OPERATIONS
    // =====================

    /**
     * Show deleted success
     * @param {string} entity - Entity name (capitalized)
     */
    function deleted(entity) {
        success(`${entity} berhasil dihapus`);
    }

    /**
     * Show delete error
     * @param {string|Error} messageOrError - Error message or Error object
     */
    function deleteError(messageOrError) {
        const message = extractErrorMessage(messageOrError);
        error(message, LIFE.LONG);
    }

    // =====================
    // STATUS OPERATIONS
    // =====================

    /**
     * Show status changed success
     * @param {string} entity - Entity name (lowercase)
     */
    function statusChanged(entity) {
        success(`Status ${entity} berhasil diubah`);
    }

    /**
     * Show status change error
     * @param {string} entity - Entity name (lowercase)
     */
    function statusChangeError(entity) {
        error(`Gagal mengubah status ${entity}`);
    }

    /**
     * Show approved success
     * @param {string} entity - Entity name (capitalized)
     * @param {string} detail - Optional additional detail
     */
    function approved(entity, detail = null) {
        const message = detail ? `${entity} berhasil disetujui. ${detail}` : `${entity} berhasil disetujui`;
        success(message);
    }

    /**
     * Show approve error
     * @param {string|Error} messageOrError - Error message or Error object
     */
    function approveError(messageOrError) {
        const message = extractErrorMessage(messageOrError);
        error(message, LIFE.LONG);
    }

    // =====================
    // VALIDATION
    // =====================

    /**
     * Show form invalid error
     */
    function formInvalid() {
        toast.add({
            severity: 'error',
            summary: 'Validasi Gagal',
            detail: 'Periksa kembali form Anda',
            life: LIFE.DEFAULT
        });
    }

    /**
     * Show select first warning
     * @param {string} field - Field name (lowercase)
     */
    function selectFirst(field) {
        warn(`Pilih ${field} terlebih dahulu`);
    }

    /**
     * Show no data for action warning
     * @param {string} entity - Entity name (lowercase)
     * @param {string} action - Action name (lowercase, e.g., "refresh", "export")
     */
    function noDataFor(entity, action) {
        warn(`Tidak ada ${entity} untuk di-${action}`);
    }

    // =====================
    // DOCUMENT STATE
    // =====================

    /**
     * Show cannot edit approved error
     * @param {string} entity - Entity name (capitalized)
     */
    function cannotEditApproved(entity) {
        error(`${entity} yang sudah disetujui tidak dapat diedit`);
    }

    /**
     * Show cannot edit locked error
     * @param {string} entity - Entity name (capitalized)
     */
    function cannotEditLocked(entity) {
        error(`${entity} yang sudah dikunci/disetujui tidak dapat diedit`);
    }

    /**
     * Show generic cannot edit error
     * @param {string} entity - Entity name (capitalized)
     */
    function cannotEdit(entity) {
        error(`${entity} ini sudah tidak bisa diedit`);
    }

    // =====================
    // DUPLICATE/CONFLICT
    // =====================

    /**
     * Show duplicate warning
     * @param {string} entity - Entity name (capitalized)
     * @param {string} list - List name (lowercase)
     */
    function duplicate(entity, list) {
        warn(`${entity} sudah ada di daftar ${list}`);
    }

    /**
     * Show conflict warning
     * @param {string} entity - Entity name (capitalized)
     * @param {string} other - Other entity name (lowercase)
     */
    function conflict(entity, other) {
        warn(`${entity} tidak boleh sama dengan ${other}`);
    }

    // =====================
    // FILE OPERATIONS
    // =====================

    /**
     * Show file too large error
     * @param {string} maxSize - Maximum size (e.g., "2MB")
     */
    function fileTooLarge(maxSize) {
        error(`Ukuran file terlalu besar. Maksimal ${maxSize}`);
    }

    // =====================
    // EXPORT OPERATIONS
    // =====================

    /**
     * Show export success
     */
    function exportSuccess() {
        success('Data berhasil di-export');
    }

    /**
     * Show export error
     */
    function exportError() {
        error('Gagal export data');
    }

    // =====================
    // REFRESH/SYNC OPERATIONS
    // =====================

    /**
     * Show refreshed success
     * @param {string} entity - Entity name (capitalized)
     */
    function refreshed(entity) {
        success(`${entity} berhasil di-refresh`);
    }

    /**
     * Show refresh error
     * @param {string} entity - Entity name (lowercase)
     */
    function refreshError(entity) {
        error(`Gagal refresh ${entity}`);
    }

    /**
     * Show data reset info
     * @param {string} entity - Entity name (lowercase)
     */
    function dataReset(entity) {
        info(`Data ${entity} telah direset`);
    }

    // =====================
    // BATCH OPERATIONS
    // =====================

    /**
     * Show items loaded success
     * @param {number} count - Number of items
     * @param {string} source - Source name (e.g., "PO")
     */
    function itemsLoaded(count, source) {
        success(`${count} item dari ${source} berhasil dimuat`);
    }

    // =====================
    // HELPER FUNCTIONS
    // =====================

    /**
     * Extract error message from various error formats
     * @param {string|Error|Object} errorOrMessage - Error source
     * @returns {string} Error message
     */
    function extractErrorMessage(errorOrMessage) {
        if (typeof errorOrMessage === 'string') {
            return errorOrMessage;
        }

        if (errorOrMessage?.response?.data?.message) {
            return errorOrMessage.response.data.message;
        }

        if (errorOrMessage?.message) {
            return errorOrMessage.message;
        }

        return 'Terjadi kesalahan';
    }

    // =====================
    // RETURN
    // =====================

    return {
        // Quick methods
        success,
        error,
        warn,
        info,

        // Load operations
        loadListError,
        loadDetailError,
        loadForEditError,

        // Save operations
        created,
        updated,
        saveSuccess,
        saveError,

        // Delete operations
        deleted,
        deleteError,

        // Status operations
        statusChanged,
        statusChangeError,
        approved,
        approveError,

        // Validation
        formInvalid,
        selectFirst,
        noDataFor,

        // Document state
        cannotEditApproved,
        cannotEditLocked,
        cannotEdit,

        // Duplicate/Conflict
        duplicate,
        conflict,

        // File operations
        fileTooLarge,

        // Export operations
        exportSuccess,
        exportError,

        // Refresh/Sync operations
        refreshed,
        refreshError,
        dataReset,

        // Batch operations
        itemsLoaded,

        // API error handling
        apiError,
        extractApiMessage,

        // Constants (for advanced usage)
        LIFE,
        SUMMARY
    };
}

// Standalone exports for non-component contexts (api interceptors, utils)
export function extractApiMessage(err, fallback = 'Terjadi kesalahan') {
    if (!err) return fallback;
    if (err.code === 'ERR_NETWORK' || err.message === 'Network Error') {
        return 'Tidak dapat terhubung ke server. Periksa koneksi Anda.';
    }
    if (err.code === 'ECONNABORTED' || /timeout/i.test(err.message || '')) {
        return 'Permintaan terlalu lama. Coba lagi.';
    }
    const resp = err.response;
    if (resp) {
        const data = resp.data || {};
        const status = resp.status;

        // HTTP error status — cek DULUAN supaya pesan generic dari backend
        // ("Unauthorized", "Forbidden", "Not Found") tidak lolos mentah-mentah.
        // Hanya override kalau data.message adalah teks generic HTTP standard.
        const genericHttpMessages = ['unauthorized', 'forbidden', 'not found', 'too many requests', 'server error', 'internal server error'];
        const isGenericMessage = typeof data.message === 'string' && genericHttpMessages.includes(data.message.trim().toLowerCase());

        if (status === 401 && isGenericMessage) return 'Sesi Anda berakhir. Silakan login ulang.';
        if (status === 403 && isGenericMessage) return 'Anda tidak memiliki akses untuk aksi ini.';
        if (status === 404 && isGenericMessage) return 'Data tidak ditemukan.';
        if (status === 429) return 'Terlalu banyak permintaan. Tunggu sebentar.';
        if (status >= 500 && isGenericMessage) return 'Server sedang bermasalah. Coba lagi beberapa saat.';

        // Validation errors (422) — ambil pesan spesifik per-field
        if (data.errors && typeof data.errors === 'object') {
            const firstKey = Object.keys(data.errors)[0];
            if (firstKey) {
                const firstErr = data.errors[firstKey];
                if (Array.isArray(firstErr) && firstErr.length) return firstErr[0];
                if (typeof firstErr === 'string') return firstErr;
            }
        }

        // Custom backend message (bukan generic) — tampilkan apa adanya
        if (typeof data.message === 'string' && data.message.trim()) return data.message;

        // Fallback per-status kalau tidak ada message sama sekali
        if (status === 401) return 'Sesi Anda berakhir. Silakan login ulang.';
        if (status === 403) return 'Anda tidak memiliki akses untuk aksi ini.';
        if (status === 404) return 'Data tidak ditemukan.';
        if (status === 409) return 'Konflik data. Coba refresh dan ulangi.';
        if (status >= 500) return 'Server sedang bermasalah. Coba lagi beberapa saat.';
    }
    if (typeof err.message === 'string' && err.message.trim() && err.message !== 'Error') {
        return err.message;
    }
    return fallback;
}
