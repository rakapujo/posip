import { ref, computed, onMounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';
import { downloadBlob } from '@/utils/downloadBlob';

/**
 * DRY untuk halaman laporan analitik sederhana: filter tanggal + load + export Excel.
 *
 * @param {Object} config
 * @param {(params: Object) => Promise} config.fetchList
 * @param {(params: Object) => Promise<{data: Blob}>} [config.exportFn]
 * @param {() => Object} config.buildParams
 * @param {(params: Object) => string} [config.exportFilename]
 * @param {string} [config.loadErrorLabel='data']
 * @param {() => void} [config.onMountedExtra]
 */
export function useReportAnalytic(config) {
    const { fetchList, exportFn = null, buildParams, exportFilename = (params) => `laporan_${params.date_from}.xlsx`, loadErrorLabel = 'data', onMountedExtra = null, onListLoaded = null } = config;

    const authStore = useAuthStore();
    const notify = useNotification();
    const { getPrimeDateFormatShort, toDateString } = useFormatters();

    const canExport = computed(() => authStore.can('laporan.export'));
    const exportingExcel = ref(false);
    const loading = ref(false);
    const items = ref([]);
    const summary = ref({});

    const startDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
    const endDate = ref(new Date());

    function paramsWithDates() {
        return buildParams({
            date_from: toDateString(startDate.value),
            date_to: toDateString(endDate.value)
        });
    }

    async function loadData() {
        loading.value = true;
        try {
            const response = await fetchList(paramsWithDates());
            if (response.data.success) {
                const payload = response.data.data;
                items.value = payload.items ?? payload;
                if (payload.summary) {
                    summary.value = payload.summary;
                }
                if (onListLoaded) {
                    onListLoaded(payload);
                }
            }
        } catch (e) {
            notify.apiError(e, `Gagal load ${loadErrorLabel}`);
        } finally {
            loading.value = false;
        }
    }

    async function exportExcel() {
        if (!exportFn || !canExport.value) {
            return;
        }
        exportingExcel.value = true;
        try {
            const params = paramsWithDates();
            const response = await exportFn(params);
            downloadBlob(response.data, exportFilename(params));
        } catch (e) {
            notify.apiError(e, 'Gagal export Excel');
        } finally {
            exportingExcel.value = false;
        }
    }

    onMounted(async () => {
        await loadData();
        if (onMountedExtra) {
            onMountedExtra();
        }
    });

    return {
        canExport,
        exportingExcel,
        loading,
        items,
        summary,
        startDate,
        endDate,
        getPrimeDateFormatShort,
        toDateString,
        loadData,
        exportExcel,
        paramsWithDates
    };
}
