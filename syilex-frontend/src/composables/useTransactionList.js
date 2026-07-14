import { ref, reactive, onMounted } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useRouter } from 'vue-router';
import { useFormatters } from './useFormatters';
import { useNotification } from './useNotification';

/**
 * Composable untuk standarisasi List Page transaksi (Adjustment, Transfer, Repack, dll)
 *
 * Features:
 * - Lazy loading dengan pagination
 * - Search
 * - Status filter (draft/approved/cancelled)
 * - Date range filter
 * - Additional filters (flexible)
 * - Detail dialog
 * - Delete & Approve confirmation
 * - Status helpers
 *
 * @param {Object} api - API module dengan methods: getAll, get, delete, approve
 * @param {Object} options - Konfigurasi
 * @param {string} options.entityName - Nama entity untuk toast message (adjustment, transfer, perubahan harga)
 * @param {string} options.dataKey - Key untuk response data list (items, adjustments, transfers)
 * @param {string} options.detailKey - Key untuk response data detail (default: entityName). Contoh: 'price_change', 'correction'
 * @param {string} options.routePrefix - Prefix route untuk navigation (inventory-adjustment)
 * @param {Array} options.filters - Array filter tambahan [{ key: 'warehouse_id', default: null }]
 * @param {Array} options.statusOptions - Custom status options (default: draft/approved)
 * @param {boolean} options.hasDateFilter - Enable date range filter (default: true)
 * @param {boolean} options.autoLoad - Auto load on mount (default: true)
 */
export function useTransactionList(api, options = {}) {
    const {
        entityName = 'item',
        dataKey = 'items',
        detailKey = null, // If null, will try entityName, then 'item', then full data
        routePrefix = '',
        filters = [],
        statusOptions: customStatusOptions = null,
        hasDateFilter = true,
        autoLoad = true
    } = options;

    const confirm = useConfirm();
    const router = useRouter();
    const { toDateString } = useFormatters();
    const notify = useNotification();

    // ==================== STATE ====================

    // Data list
    const items = ref([]);
    const loading = ref(false);
    const totalRecords = ref(0);

    // Search
    const searchQuery = ref('');

    // Pagination & Sort
    const lazyParams = ref({
        first: 0,
        rows: 10,
        sortField: 'tanggal',
        sortOrder: -1
    });

    // Status filter
    const selectedStatus = ref(null);
    const statusOptions = customStatusOptions || [
        { label: 'Draft', value: 'draft' },
        { label: 'Approved', value: 'approved' }
    ];

    // Date range filter — default to current month
    const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    const endDate = ref(new Date());

    // Additional filters dari options
    const additionalFilters = reactive(
        filters.reduce((acc, filter) => {
            acc[filter.key] = filter.default ?? null;
            return acc;
        }, {})
    );

    // Detail dialog
    const detailDialog = ref(false);
    const detailData = ref({});
    const loadingDetail = ref(false);

    // Approve state
    const processingApprove = ref(false);

    // ==================== DATA LOADING ====================

    async function loadData() {
        loading.value = true;
        try {
            const params = {
                page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
                per_page: lazyParams.value.rows,
                sort_field: lazyParams.value.sortField || 'tanggal',
                sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
            };

            // Search
            if (searchQuery.value?.trim()) {
                params.search = searchQuery.value.trim();
            }

            // Status
            if (selectedStatus.value) {
                params.status = selectedStatus.value;
            }

            // Date range
            if (hasDateFilter) {
                if (startDate.value) {
                    params.date_from = toDateString(startDate.value);
                }
                if (endDate.value) {
                    params.date_to = toDateString(endDate.value);
                }
            }

            // Additional filters
            Object.entries(additionalFilters).forEach(([key, value]) => {
                if (value !== null && value !== undefined && value !== '') {
                    params[key] = value;
                }
            });

            const response = await api.getAll(params);
            if (response.data.success) {
                items.value = response.data.data[dataKey] || response.data.data.items || [];
                totalRecords.value = response.data.data.pagination?.total || 0;
            }
        } catch (error) {
            console.error(`Failed to load ${entityName}:`, error);
            notify.loadListError(entityName);
        } finally {
            loading.value = false;
        }
    }

    // ==================== PAGINATION & SORT ====================

    function onPage(event) {
        lazyParams.value = { ...lazyParams.value, ...event };
        loadData();
    }

    function onSort(event) {
        lazyParams.value = { ...lazyParams.value, ...event };
        loadData();
    }

    // ==================== SEARCH ====================

    function doSearch() {
        lazyParams.value.first = 0;
        loadData();
    }

    function clearSearch() {
        searchQuery.value = '';
        lazyParams.value.first = 0;
        loadData();
    }

    // ==================== FILTERS ====================

    function onFilter() {
        lazyParams.value.first = 0;
        loadData();
    }

    function resetFilters() {
        searchQuery.value = '';
        selectedStatus.value = null;
        startDate.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
        endDate.value = new Date();

        // Reset additional filters
        filters.forEach((filter) => {
            additionalFilters[filter.key] = filter.default ?? null;
        });

        lazyParams.value.first = 0;
        loadData();
    }

    // ==================== NAVIGATION ====================

    function createNew() {
        if (routePrefix) {
            router.push({ name: `${routePrefix}-create` });
        }
    }

    function editItem(data) {
        if (routePrefix) {
            router.push({ name: `${routePrefix}-edit`, params: { ulid: data.ulid } });
        }
    }

    // ==================== DETAIL DIALOG ====================

    async function viewDetail(data) {
        detailDialog.value = true;
        loadingDetail.value = true;

        try {
            const response = await api.get(data.ulid);
            if (response.data.success) {
                // Support different response keys: detailKey > entityName > 'item' > full data
                const responseData = response.data.data;
                detailData.value = (detailKey && responseData[detailKey]) || responseData[entityName] || responseData.item || responseData;
            }
        } catch (error) {
            console.error(`Failed to load ${entityName} detail:`, error);
            notify.loadDetailError(entityName);
            detailDialog.value = false;
        } finally {
            loadingDetail.value = false;
        }
    }

    function closeDetail() {
        detailDialog.value = false;
        detailData.value = {};
    }

    // ==================== DELETE ====================

    function confirmDelete(data) {
        confirm.require({
            message: `Apakah Anda yakin ingin menghapus ${entityName} ${data.nomor_dokumen}?`,
            header: 'Konfirmasi Hapus',
            icon: 'pi pi-exclamation-triangle',
            rejectLabel: 'Batal',
            acceptLabel: 'Hapus',
            rejectClass: 'p-button-secondary p-button-outlined',
            acceptClass: 'p-button-danger',
            accept: () => deleteItem(data)
        });
    }

    async function deleteItem(data) {
        try {
            const response = await api.delete(data.ulid);
            if (response.data.success) {
                notify.deleted(entityName);
                loadData();

                // Close detail dialog if open and showing same item
                if (detailDialog.value && detailData.value.ulid === data.ulid) {
                    closeDetail();
                }
            }
        } catch (error) {
            console.error(`Failed to delete ${entityName}:`, error);
            notify.deleteError(error);
        }
    }

    // ==================== APPROVE ====================

    function confirmApprove(data) {
        confirm.require({
            message: `Apakah Anda yakin ingin menyetujui ${entityName} ${data.nomor_dokumen}? Perubahan stok akan diterapkan dan tidak dapat dibatalkan.`,
            header: 'Konfirmasi Approve',
            icon: 'pi pi-check-circle',
            rejectLabel: 'Batal',
            acceptLabel: 'Approve',
            rejectClass: 'p-button-secondary p-button-outlined',
            acceptClass: 'p-button-success',
            accept: () => approveItem(data)
        });
    }

    async function approveItem(data) {
        processingApprove.value = true;
        try {
            const response = await api.approve(data.ulid);
            if (response.data.success) {
                notify.approved(entityName);
                loadData();

                // Update detail dialog if open and showing same item
                if (detailDialog.value && detailData.value.ulid === data.ulid) {
                    const responseData = response.data.data;
                    detailData.value = (detailKey && responseData[detailKey]) || responseData[entityName] || responseData.item || responseData;
                }
            }
        } catch (error) {
            console.error(`Failed to approve ${entityName}:`, error);
            const errorMessage = error.response?.data?.errors?.stock ? error.response.data.errors.stock.join(', ') : error.response?.data?.message || `Gagal menyetujui ${entityName}`;
            notify.approveError(errorMessage);
        } finally {
            processingApprove.value = false;
        }
    }

    // ==================== STATUS HELPERS ====================

    function getStatusSeverity(status) {
        const severityMap = {
            draft: 'warn',
            approved: 'success',
            completed: 'success',
            cancelled: 'danger'
        };
        return severityMap[status] || 'secondary';
    }

    function getStatusLabel(status) {
        const labelMap = {
            draft: 'Draft',
            approved: 'Approved',
            completed: 'Completed',
            cancelled: 'Cancelled'
        };
        return labelMap[status] || status;
    }

    function canEdit(item) {
        return item?.status === 'draft';
    }

    function canDelete(item) {
        return item?.status === 'draft';
    }

    function canApprove(item) {
        return item?.status === 'draft';
    }

    // ==================== LIFECYCLE ====================

    if (autoLoad) {
        onMounted(() => {
            loadData();
        });
    }

    // ==================== RETURN ====================

    return {
        // State - Data
        items,
        loading,
        totalRecords,

        // State - Search
        searchQuery,

        // State - Pagination & Sort
        lazyParams,

        // State - Filters
        selectedStatus,
        statusOptions,
        startDate,
        endDate,
        additionalFilters,

        // State - Detail Dialog
        detailDialog,
        detailData,
        loadingDetail,

        // State - Approve
        processingApprove,

        // Methods - Data Loading
        loadData,

        // Methods - Pagination & Sort
        onPage,
        onSort,

        // Methods - Search
        doSearch,
        clearSearch,

        // Methods - Filters
        onFilter,
        resetFilters,

        // Methods - Navigation
        createNew,
        editItem,

        // Methods - Detail Dialog
        viewDetail,
        closeDetail,

        // Methods - Delete
        confirmDelete,
        deleteItem,

        // Methods - Approve
        confirmApprove,
        approveItem,

        // Helpers - Status
        getStatusSeverity,
        getStatusLabel,
        canEdit,
        canDelete,
        canApprove
    };
}
