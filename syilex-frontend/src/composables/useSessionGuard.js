import { ref, computed, onBeforeUnmount } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { authApi } from '@/api';

/**
 * Session Guard — unified session expiry + shift duration tracking.
 *
 * Used by:
 *   PosKasirPage (mode with shiftStartedAt) → dialog "Lanjutkan & Perpanjang" / "Tutup Shift"
 *   AppLayout (mode without shift) → banner "Sesi berakhir X menit [Perpanjang]"
 *
 * Checks every 60 seconds. Triggers on:
 *   - Token expiring within 10 minutes
 *   - Shift running longer than 12 hours (POS only)
 *
 * @param {Object} options
 * @param {import('vue').Ref<string|null>} [options.shiftStartedAt] - Reactive ref to shift start ISO timestamp
 */
export function useSessionGuard(options = {}) {
    const authStore = useAuthStore();

    const minutesUntilExpiry = ref(999);
    const shiftDurationHours = ref(0);
    const refreshing = ref(false);

    // Shift extension: when kasir clicks "Lanjutkan", dismiss for 12 hours
    let extendedUntil = 0;

    const sessionExpiring = computed(() => minutesUntilExpiry.value <= 10);
    const shiftOvertime = computed(() => {
        if (!options.shiftStartedAt) return false;
        return shiftDurationHours.value >= 12 && Date.now() > extendedUntil;
    });
    const showGuardDialog = ref(false);

    function tick() {
        // Token expiry check
        const expiresAt = authStore.tokenExpiresAt || localStorage.getItem('token_expires_at');
        if (expiresAt) {
            const remaining = (new Date(expiresAt).getTime() - Date.now()) / 60000;
            minutesUntilExpiry.value = Math.max(0, Math.round(remaining));
        }

        // Shift duration check (POS only)
        const startedAt = options.shiftStartedAt?.value;
        if (startedAt) {
            shiftDurationHours.value = Math.max(0, (Date.now() - new Date(startedAt).getTime()) / 3600000);
        }

        // Trigger dialog/banner
        showGuardDialog.value = sessionExpiring.value || shiftOvertime.value;
    }

    // Poll every 60 seconds
    const interval = setInterval(tick, 60000);
    tick(); // Initial check

    /**
     * Refresh token — creates new token with fresh 12-hour lifetime.
     * Also dismisses shift overtime warning for 12 hours.
     */
    async function refresh() {
        if (refreshing.value) return;
        refreshing.value = true;
        try {
            const res = await authApi.refresh();
            const data = res.data?.data;
            if (data?.token) {
                authStore.token = data.token;
                localStorage.setItem('token', data.token);
            }
            if (data?.token_expires_at) {
                authStore.tokenExpiresAt = data.token_expires_at;
                localStorage.setItem('token_expires_at', data.token_expires_at);
            }
            // Dismiss shift warning for 12 hours
            extendedUntil = Date.now() + 12 * 3600000;
            showGuardDialog.value = false;
            tick(); // Recalculate immediately
        } catch {
            // If refresh fails (e.g. 401), let normal interceptor handle redirect
        } finally {
            refreshing.value = false;
        }
    }

    /**
     * Dismiss shift warning without refreshing token.
     * Used when kasir wants to continue but token isn't near expiry.
     */
    function dismiss() {
        extendedUntil = Date.now() + 12 * 3600000;
        showGuardDialog.value = false;
    }

    function stop() {
        clearInterval(interval);
    }

    onBeforeUnmount(stop);

    return {
        minutesUntilExpiry,
        shiftDurationHours,
        sessionExpiring,
        shiftOvertime,
        showGuardDialog,
        refreshing,
        refresh,
        dismiss,
        stop
    };
}
