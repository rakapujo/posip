import { ref } from 'vue';
import { salesReportApi } from '@/api';
import { useNotification } from '@/composables/useNotification';

/**
 * B3.5 — drill-down dialog: daftar nota untuk kasir/customer/metode pada periode filter.
 * Ponytail: reuse `salesReportApi.getAll` (sudah filter user_id/customer_id/metode_bayar_id) — no new BE endpoint.
 */
export function useSalesDrillDown() {
    const notify = useNotification();

    const drillVisible = ref(false);
    const drillLoading = ref(false);
    const drillItems = ref([]);
    const drillTitle = ref('Daftar Nota');

    async function openDrillDown(filters, title) {
        drillTitle.value = title;
        drillVisible.value = true;
        drillLoading.value = true;
        drillItems.value = [];
        try {
            const r = await salesReportApi.getAll({
                per_page: 50,
                sort_field: 'tanggal',
                sort_order: 'desc',
                ...filters
            });
            if (r.data.success) drillItems.value = r.data.data.items;
        } catch (e) {
            notify.apiError(e, 'Gagal load nota');
        } finally {
            drillLoading.value = false;
        }
    }

    return { drillVisible, drillLoading, drillItems, drillTitle, openDrillDown };
}

const salesStatusLabelMap = { completed: 'Selesai', voided: 'Void', retur_partial: 'Retur Sebagian', retur_full: 'Retur Penuh' };
const salesStatusSeverityMap = { completed: 'success', voided: 'danger', retur_partial: 'warn', retur_full: 'danger' };

export const drillStatusLabel = (status) => salesStatusLabelMap[status] || status;
export const drillStatusSeverity = (status) => salesStatusSeverityMap[status] || 'secondary';
