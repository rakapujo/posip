import { ref, onMounted } from 'vue';
import { useFormatters } from './useFormatters';
import { useNotification } from './useNotification';
import { downloadBlob } from '@/utils/downloadBlob';

/**
 * DRY lazy-table + date-range + Excel export for laporan pages.
 *
 * @param {Object} config
 * @param {(params: Object) => Promise} config.fetchList
 * @param {(params: Object) => Promise} [config.fetchSummary]
 * @param {(params: Object) => Promise<{data: Blob}>} [config.exportFn]
 * @param {string} [config.exportFilenamePrefix]
 * @param {(params: Object) => Promise} [config.fetchDropdowns]
 * @param {() => Object} [config.getExtraFilters]
 * @param {() => void} [config.onResetFilters]
 * @param {string} [config.listErrorLabel]
 * @param {string} [config.defaultSortField='tanggal']
 * @param {number} [config.defaultSortOrder=-1]
 * @param {(data: Object) => void} [config.onListLoaded]
 * @param {() => string} [config.exportFilenameFn]
 */
export function useReportList(config) {
    const {
        fetchList,
        fetchSummary = null,
        exportFn = null,
        exportFilenamePrefix = 'laporan',
        exportFilenameFn = null,
        fetchDropdowns = null,
        getExtraFilters = null,
        onResetFilters = null,
        listErrorLabel = 'laporan',
        defaultSortField = 'tanggal',
        defaultSortOrder = -1,
        onListLoaded = null
    } = config;

    const notify = useNotification();
    const { toDateString } = useFormatters();

    const items = ref([]);
    const loading = ref(false);
    const totalRecords = ref(0);
    const summary = ref({});
    const searchQuery = ref('');
    const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    const endDate = ref(new Date());
    const lazyParams = ref({
        first: 0,
        rows: 10,
        sortField: defaultSortField,
        sortOrder: defaultSortOrder
    });
    const dropdowns = ref({});
    const exportingExcel = ref(false);

    function buildFilterParams() {
        const params = {
            date_from: toDateString(startDate.value),
            date_to: toDateString(endDate.value)
        };
        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }
        if (getExtraFilters) {
            Object.entries(getExtraFilters()).forEach(([key, value]) => {
                if (value !== null && value !== undefined && value !== '') {
                    params[key] = value;
                }
            });
        }
        return params;
    }

    function buildParams() {
        return {
            ...buildFilterParams(),
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || defaultSortField,
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };
    }

    async function loadData() {
        loading.value = true;
        try {
            const requests = [fetchList(buildParams())];
            if (fetchSummary) {
                requests.push(fetchSummary(buildFilterParams()));
            }
            const [listRes, summaryRes] = await Promise.all(requests);
            const data = listRes.data?.data ?? listRes.data;
            items.value = data.items ?? data.data ?? [];
            totalRecords.value = data.pagination?.total ?? items.value.length;
            if (data.summary) {
                summary.value = data.summary;
            } else if (summaryRes) {
                summary.value = summaryRes.data.data ?? {};
            }
            if (onListLoaded) {
                onListLoaded(data);
            }
        } catch {
            notify.loadListError(listErrorLabel);
        } finally {
            loading.value = false;
        }
    }

    async function loadDropdowns() {
        if (!fetchDropdowns) {
            return;
        }
        try {
            const res = await fetchDropdowns();
            dropdowns.value = res.data.data ?? {};
        } catch {
            // silent — filters optional
        }
    }

    async function exportExcel() {
        if (!exportFn) {
            return;
        }
        exportingExcel.value = true;
        try {
            const response = await exportFn(buildFilterParams());
            const filename = exportFilenameFn ? exportFilenameFn() : `${exportFilenamePrefix}_${toDateString(new Date())}.xlsx`;
            downloadBlob(response.data, filename.endsWith('.xlsx') ? filename : `${filename}.xlsx`);
            notify.exportSuccess();
        } catch {
            notify.exportError();
        } finally {
            exportingExcel.value = false;
        }
    }

    function onPage(event) {
        lazyParams.value = { ...lazyParams.value, ...event };
        loadData();
    }

    function onSort(event) {
        lazyParams.value = { ...lazyParams.value, ...event };
        loadData();
    }

    function applyFilters() {
        lazyParams.value.first = 0;
        loadData();
    }

    function doSearch() {
        applyFilters();
    }

    function clearSearch() {
        searchQuery.value = '';
        applyFilters();
    }

    function onFilterChange() {
        applyFilters();
    }

    function resetFilters() {
        searchQuery.value = '';
        startDate.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
        endDate.value = new Date();
        if (onResetFilters) {
            onResetFilters();
        }
        lazyParams.value.first = 0;
        loadData();
    }

    onMounted(async () => {
        await Promise.all([loadData(), loadDropdowns()]);
    });

    return {
        items,
        loading,
        totalRecords,
        summary,
        searchQuery,
        startDate,
        endDate,
        lazyParams,
        dropdowns,
        exportingExcel,
        loadData,
        loadDropdowns,
        exportExcel,
        onPage,
        onSort,
        applyFilters,
        doSearch,
        clearSearch,
        onFilterChange,
        resetFilters,
        buildFilterParams,
        buildParams
    };
}
