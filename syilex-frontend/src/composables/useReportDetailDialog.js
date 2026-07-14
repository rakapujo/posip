import { ref } from 'vue';
import { useNotification } from '@/composables/useNotification';

/**
 * Paginated detail dialog untuk laporan (drill-down baris tabel).
 *
 * @param {Object} config
 * @param {(context: *, params: Object) => Promise<{data: Object}>} config.fetchDetail
 * @param {(responseData: Object) => { meta?: Object, items?: Array, summary?: Object, total?: number, payload?: Object }} config.parseResponse
 *   Return `payload` untuk menyimpan blob response utuh (promo/customer detail).
 * @param {string} [config.errorLabel='detail']
 * @param {string} [config.defaultSortField='tanggal']
 * @param {number} [config.defaultRows=10]
 * @param {(error: unknown) => void} [config.onError]
 */
export function useReportDetailDialog(config) {
    const { fetchDetail, parseResponse, errorLabel = 'detail', defaultSortField = 'tanggal', defaultRows = 10, paginated = true, resolveDetailKey = null, onError = null } = config;

    const notify = useNotification();

    const detailDialog = ref(false);
    const loadingDetail = ref(false);
    const detailMeta = ref({});
    const detailItems = ref([]);
    const detailSummary = ref({});
    const detailPayload = ref({});
    const detailTotalRecords = ref(0);
    const detailLazyParams = ref({
        first: 0,
        rows: defaultRows,
        sortField: defaultSortField,
        sortOrder: -1
    });
    const detailContext = ref(null);

    function resetDetailState() {
        detailMeta.value = {};
        detailItems.value = [];
        detailSummary.value = {};
        detailPayload.value = {};
        detailTotalRecords.value = 0;
        detailLazyParams.value = {
            first: 0,
            rows: defaultRows,
            sortField: defaultSortField,
            sortOrder: -1
        };
    }

    function buildPageParams(extraParams = {}) {
        if (!paginated) {
            return { ...extraParams };
        }

        return {
            ...extraParams,
            page: Math.floor(detailLazyParams.value.first / detailLazyParams.value.rows) + 1,
            per_page: detailLazyParams.value.rows,
            sort_field: detailLazyParams.value.sortField || defaultSortField,
            sort_order: detailLazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };
    }

    async function callFetch(context, extraParams) {
        const params = buildPageParams(extraParams);
        if (resolveDetailKey) {
            const key = resolveDetailKey(context, detailMeta.value);
            return fetchDetail(key, params, context);
        }
        return fetchDetail(context, params);
    }

    async function openDetail(context, extraParams = {}) {
        detailContext.value = context;
        detailDialog.value = true;
        loadingDetail.value = true;
        resetDetailState();

        try {
            const response = await callFetch(context, extraParams);
            if (response.data.success) {
                applyParsed(parseResponse(response.data.data));
            }
        } catch (e) {
            if (onError) {
                onError(e);
            } else {
                notify.loadDetailError(errorLabel);
            }
            detailDialog.value = false;
        } finally {
            loadingDetail.value = false;
        }
    }

    async function loadDetailPage(extraParams = {}) {
        if (!detailContext.value || !paginated) {
            return;
        }
        loadingDetail.value = true;
        try {
            const response = await callFetch(detailContext.value, extraParams);
            if (response.data.success) {
                applyParsed(parseResponse(response.data.data));
            }
        } catch (e) {
            if (onError) {
                onError(e);
            } else {
                notify.loadDetailError(errorLabel);
            }
        } finally {
            loadingDetail.value = false;
        }
    }

    function applyParsed(parsed) {
        if (parsed.payload !== undefined) {
            detailPayload.value = parsed.payload;
            detailMeta.value = {};
            detailItems.value = [];
            detailSummary.value = {};
            detailTotalRecords.value = 0;
            return;
        }

        detailPayload.value = {};
        detailMeta.value = parsed.meta ?? {};
        detailItems.value = parsed.items ?? [];
        detailSummary.value = parsed.summary ?? {};
        detailTotalRecords.value = parsed.total ?? parsed.items?.length ?? 0;
    }

    function onDetailPage(event, extraParams = {}) {
        detailLazyParams.value = { ...detailLazyParams.value, ...event };
        loadDetailPage(extraParams);
    }

    return {
        detailDialog,
        loadingDetail,
        detailMeta,
        detailItems,
        detailSummary,
        detailPayload,
        detailTotalRecords,
        detailLazyParams,
        openDetail,
        loadDetailPage,
        onDetailPage
    };
}
