import client from '@/api/client';

const DEDUPE_WINDOW_MS = 10_000;
const MAX_QUEUE = 20;

const recent = new Map();
let queued = 0;

function shouldSkip(message) {
    const now = Date.now();
    const last = recent.get(message);
    if (last && now - last < DEDUPE_WINDOW_MS) return true;
    recent.set(message, now);
    if (recent.size > 100) {
        const oldest = recent.keys().next().value;
        recent.delete(oldest);
    }
    return false;
}

export async function logClientError({ source, message, stack, component }) {
    try {
        if (!message) return;
        if (queued >= MAX_QUEUE) return;
        if (shouldSkip(message)) return;

        const token = localStorage.getItem('token');
        if (!token) return;

        queued++;
        await client.post('/client-errors', {
            source: source || 'unknown',
            message: String(message).slice(0, 2000),
            stack: stack ? String(stack).slice(0, 5000) : null,
            component: component || null,
            url: window.location.pathname + window.location.search,
            user_agent: navigator.userAgent
        });
    } catch {
        // never throw from logger
    } finally {
        queued = Math.max(0, queued - 1);
    }
}

export function installGlobalErrorHandlers(app) {
    app.config.errorHandler = (err, instance, info) => {
        console.error('[Vue error]', err, info);
        logClientError({
            source: 'vue',
            message: err?.message || String(err),
            stack: err?.stack,
            component: instance?.$options?.name || instance?.$options?.__name || info
        });
    };

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        console.error('[Unhandled rejection]', reason);
        logClientError({
            source: 'promise',
            message: reason?.message || String(reason),
            stack: reason?.stack
        });
    });

    window.addEventListener('error', (event) => {
        if (event.error) {
            logClientError({
                source: 'window',
                message: event.message,
                stack: event.error?.stack
            });
        }
    });
}
