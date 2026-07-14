import { ref, computed, onMounted } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useNotification } from './useNotification';

/**
 * Composable for Master CRUD operations
 * Menggabungkan Pattern #1 (State Management), #2 (Pagination/Sort/Filter), #3 (loadData)
 *
 * @param {Object} api - API module dengan methods: getAll, get, create, update, toggleStatus, delete
 * @param {Object} options - Configuration options
 * @param {string} options.entityName - Nama entity untuk pesan (misal: 'brand', 'tipe')
 * @param {string} options.dataKey - Key untuk data di response (misal: 'brands', 'tipes')
 * @param {string} options.displayField - Field name untuk display di confirmation dialog (misal: 'nama_brand')
 * @param {Object} options.emptyForm - Default empty form object
 * @param {Array} options.filters - Additional filters config [{key: 'warehouse_id', default: null}]
 * @param {Function} options.transformFormData - Transform form data before save (optional)
 * @param {boolean} options.autoLoad - Auto load on mount (default: true)
 */
export function useMasterCrud(api, options = {}) {
    const { entityName = 'data', dataKey = 'items', displayField = null, emptyForm = {}, filters = [], transformFormData = null, autoLoad = true } = options;

    const notify = useNotification();
    const confirm = useConfirm();

    // ==================== STATE ====================

    // DataTable ref
    const dt = ref();

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
        sortField: 'created_at',
        sortOrder: -1
    });

    // Status filter (default untuk semua master)
    const selectedStatus = ref(null);
    const statusOptions = ref([
        { label: 'Semua Status', value: null },
        { label: 'Aktif', value: 'active' },
        { label: 'Nonaktif', value: 'inactive' }
    ]);

    // Additional filters state (dynamic)
    const additionalFilters = ref({});
    filters.forEach((f) => {
        additionalFilters.value[f.key] = f.default ?? null;
    });

    // Dialog states
    const itemDialog = ref(false);
    const detailDialog = ref(false);

    // Form states
    const submitted = ref(false);
    const saving = ref(false);
    const loadingDetail = ref(false);

    // Current item being edited/viewed
    const item = ref({});
    const detailData = ref({});

    // Computed
    const isEdit = computed(() => !!item.value.ulid);

    // Check if any filter is active
    const hasActiveFilters = computed(() => {
        if (selectedStatus.value !== null) return true;
        return Object.values(additionalFilters.value).some((v) => v !== null && v !== '');
    });

    // ==================== DATA LOADING ====================

    /**
     * Load data from API
     */
    async function loadData() {
        loading.value = true;
        try {
            const params = {
                page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
                per_page: lazyParams.value.rows,
                sort_field: lazyParams.value.sortField || 'created_at',
                sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
            };

            // Search
            if (searchQuery.value?.trim()) {
                params.search = searchQuery.value.trim();
            }

            // Status filter
            if (selectedStatus.value) {
                params.status = selectedStatus.value;
            }

            // Additional filters
            Object.entries(additionalFilters.value).forEach(([key, value]) => {
                if (value !== null && value !== '') {
                    params[key] = value;
                }
            });

            const response = await api.getAll(params);
            if (response.data.success) {
                items.value = response.data.data[dataKey] || response.data.data.items || [];
                totalRecords.value = response.data.data.pagination?.total || 0;
            }
        } catch (error) {
            notify.loadListError(entityName);
        } finally {
            loading.value = false;
        }
    }

    // ==================== PAGINATION & SORT ====================

    function onPage(event) {
        lazyParams.value.first = event.first;
        lazyParams.value.rows = event.rows;
        loadData();
    }

    function onSort(event) {
        lazyParams.value.sortField = event.sortField;
        lazyParams.value.sortOrder = event.sortOrder;
        loadData();
    }

    function onFilter() {
        lazyParams.value.first = 0;
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

    function resetFilters() {
        selectedStatus.value = null;
        Object.keys(additionalFilters.value).forEach((key) => {
            additionalFilters.value[key] = null;
        });
        lazyParams.value.first = 0;
        loadData();
    }

    function setFilter(key, value) {
        if (key === 'status') {
            selectedStatus.value = value;
        } else {
            additionalFilters.value[key] = value;
        }
        lazyParams.value.first = 0;
        loadData();
    }

    // ==================== DIALOG MANAGEMENT ====================

    function openNew() {
        item.value = { ...emptyForm };
        submitted.value = false;
        itemDialog.value = true;
    }

    function hideDialog() {
        itemDialog.value = false;
        submitted.value = false;
    }

    function editItem(data) {
        item.value = { ...data };
        submitted.value = false;
        itemDialog.value = true;
    }

    // ==================== VIEW DETAIL ====================

    async function viewDetail(data) {
        detailDialog.value = true;
        loadingDetail.value = true;
        detailData.value = {};

        try {
            const response = await api.get(data.ulid);
            if (response.data.success) {
                // Handle berbagai format response
                detailData.value = response.data.data[entityName] || response.data.data.item || response.data.data;
            }
        } catch (error) {
            notify.loadDetailError(entityName);
            detailDialog.value = false;
        } finally {
            loadingDetail.value = false;
        }
    }

    // ==================== SAVE (CREATE/UPDATE) ====================

    async function saveItem(formData = null) {
        submitted.value = true;
        saving.value = true;

        try {
            // Use provided formData or item.value
            let data = formData || { ...item.value };

            // Transform if function provided
            if (transformFormData) {
                data = transformFormData(data, isEdit.value);
            }

            let response;
            if (isEdit.value) {
                response = await api.update(item.value.ulid, data);
            } else {
                response = await api.create(data);
            }

            if (response.data.success) {
                notify.success(response.data.message);
                itemDialog.value = false;
                item.value = {};
                await loadData();
                return { success: true, data: response.data };
            }
        } catch (error) {
            notify.saveError(error);
            return { success: false, error };
        } finally {
            saving.value = false;
        }
    }

    // ==================== TOGGLE STATUS ====================

    /**
     * Get display name for confirmation dialogs
     */
    function getDisplayName(data) {
        if (displayField && data[displayField]) {
            return data[displayField];
        }
        // Try common field names
        return (
            data.nama_brand ||
            data.nama_tipe ||
            data.nama_kategori ||
            data.nama_grup ||
            data.nama_warehouse ||
            data.nama_supplier ||
            data.nama_customer ||
            data.nama_tipe_customer ||
            data.nama_kategori_customer ||
            data.nama_metode ||
            data.nama_produk ||
            data.name ||
            data.ulid
        );
    }

    /**
     * Show toggle status confirmation using useConfirm
     */
    function confirmToggleStatus(data) {
        const displayName = getDisplayName(data);
        const isActive = data.status === 'active';
        const action = isActive ? 'menonaktifkan' : 'mengaktifkan';

        confirm.require({
            message: `Apakah Anda yakin ingin ${action} ${entityName} "${displayName}"?`,
            header: isActive ? 'Konfirmasi Nonaktifkan' : 'Konfirmasi Aktifkan',
            icon: 'pi pi-exclamation-triangle',
            rejectProps: {
                label: 'Batal',
                severity: 'secondary',
                outlined: true
            },
            acceptProps: {
                label: isActive ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan',
                severity: isActive ? 'warn' : 'success'
            },
            accept: () => toggleStatus(data)
        });
    }

    /**
     * Execute toggle status
     */
    async function toggleStatus(data) {
        try {
            const response = await api.toggleStatus(data.ulid);
            if (response.data.success) {
                notify.success(response.data.message);
                await loadData();
            }
        } catch (error) {
            notify.statusChangeError(entityName);
        }
    }

    // ==================== DELETE ====================

    /**
     * Show delete confirmation using useConfirm
     */
    function confirmDelete(data) {
        const displayName = getDisplayName(data);

        confirm.require({
            message: `Apakah Anda yakin ingin menghapus ${entityName} "${displayName}"? Data yang dihapus tidak dapat dikembalikan.`,
            header: 'Konfirmasi Hapus',
            icon: 'pi pi-exclamation-triangle',
            rejectProps: {
                label: 'Batal',
                severity: 'secondary',
                outlined: true
            },
            acceptProps: {
                label: 'Ya, Hapus',
                severity: 'danger'
            },
            accept: () => deleteItem(data)
        });
    }

    /**
     * Execute delete
     */
    async function deleteItem(data) {
        try {
            const response = await api.delete(data.ulid);
            if (response.data.success) {
                notify.success(response.data.message);
                await loadData();
            }
        } catch (error) {
            notify.deleteError(error);
        }
    }

    // ==================== STATUS HELPERS ====================

    function getStatusSeverity(status) {
        return status === 'active' ? 'success' : 'danger';
    }

    function getStatusLabel(status) {
        return status === 'active' ? 'Aktif' : 'Nonaktif';
    }

    function getToggleLabel(status) {
        return status === 'active' ? 'Nonaktifkan' : 'Aktifkan';
    }

    function getToggleSeverity(status) {
        return status === 'active' ? 'warn' : 'success';
    }

    // ==================== LIFECYCLE ====================

    if (autoLoad) {
        onMounted(async () => {
            await loadData();
        });
    }

    // ==================== RETURN ====================

    return {
        // State
        dt,
        items,
        loading,
        totalRecords,
        searchQuery,
        lazyParams,
        selectedStatus,
        statusOptions,
        additionalFilters,

        // Dialog states
        itemDialog,
        detailDialog,

        // Form states
        submitted,
        saving,
        loadingDetail,
        item,
        detailData,
        isEdit,
        hasActiveFilters,

        // Data loading
        loadData,

        // Pagination & Sort
        onPage,
        onSort,
        onFilter,

        // Search
        doSearch,
        clearSearch,

        // Filters
        resetFilters,
        setFilter,

        // Dialog management
        openNew,
        hideDialog,
        editItem,
        viewDetail,

        // CRUD operations
        saveItem,
        confirmToggleStatus,
        toggleStatus,
        confirmDelete,
        deleteItem,

        // Status helpers
        getStatusSeverity,
        getStatusLabel,
        getToggleLabel,
        getToggleSeverity
    };
}

export default useMasterCrud;
