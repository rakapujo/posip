import fs from 'fs';
import path from 'path';
import { expect } from '@playwright/test';

/**
 * Shared E2E auth — login sekali, reuse token untuk semua spec.
 *
 * Cache: memory (satu worker) + file `.auth/e2e-admin.json` (lintas file / re-run cepat).
 * Tidak menyentuh middleware throttle production — hanya mengurangi hit ke /auth/login.
 */

const CACHE_DIR = path.resolve(process.cwd(), '.auth');
const CACHE_FILE = path.join(CACHE_DIR, 'e2e-admin.json');

const DEFAULT_EMAIL = process.env.E2E_ADMIN_EMAIL || 'admin@posip.com';
const DEFAULT_PASSWORD = process.env.E2E_ADMIN_PASSWORD || 'password';

/** @type {{ token: string, user: object, permissions?: string[], email?: string, cachedAt?: number } | null} */
let memoryCache = null;

export function laravelApiBase() {
    return (process.env.VITE_API_PROXY || 'http://POSIP.test/syilex/public').replace(/\/$/, '') + '/api/v1';
}

export function e2eCredentials() {
    return { email: DEFAULT_EMAIL, password: DEFAULT_PASSWORD };
}

function readFileCache() {
    try {
        if (!fs.existsSync(CACHE_FILE)) return null;
        const raw = JSON.parse(fs.readFileSync(CACHE_FILE, 'utf8'));
        if (!raw?.token || !raw?.user) return null;
        if (raw.email && raw.email !== DEFAULT_EMAIL) return null;
        // Token max usia 45 menit (Sanctum/session lokal biasanya lebih lama; aman di-refresh)
        if (raw.cachedAt && Date.now() - raw.cachedAt > 45 * 60 * 1000) return null;
        return raw;
    } catch {
        return null;
    }
}

function writeFileCache(data) {
    try {
        fs.mkdirSync(CACHE_DIR, { recursive: true });
        fs.writeFileSync(
            CACHE_FILE,
            JSON.stringify({ ...data, email: DEFAULT_EMAIL, cachedAt: Date.now() }, null, 2),
            'utf8'
        );
    } catch {
        // Non-fatal — memory cache tetap dipakai
    }
}

export function clearAuthCache() {
    memoryCache = null;
    try {
        if (fs.existsSync(CACHE_FILE)) fs.unlinkSync(CACHE_FILE);
    } catch {
        /* ignore */
    }
}

export function authHeaders(authData = memoryCache) {
    if (!authData?.token) {
        throw new Error('authHeaders: no token — call getAuthData(request) first');
    }
    return {
        Authorization: `Bearer ${authData.token}`,
        Accept: 'application/json',
        'Content-Type': 'application/json'
    };
}

async function tokenStillValid(request, token) {
    const res = await request.get(`${laravelApiBase()}/auth/me`, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    });
    return res.ok();
}

/**
 * Ambil auth (cache → validate → login baru jika perlu).
 * @returns {Promise<{ token: string, user: object, permissions?: string[] }>}
 */
export async function getAuthData(request, { force = false } = {}) {
    if (!force) {
        const cached = memoryCache || readFileCache();
        if (cached?.token) {
            if (await tokenStillValid(request, cached.token)) {
                memoryCache = cached;
                return cached;
            }
            clearAuthCache();
        }
    }

    const { email, password } = e2eCredentials();
    const loginRes = await request.post(`${laravelApiBase()}/auth/login`, {
        headers: { Accept: 'application/json' },
        data: { email, password }
    });
    expect(loginRes.ok(), `E2E login ${loginRes.status()} — throttle? php artisan auth:clear-login-throttle --all`).toBeTruthy();

    const body = await loginRes.json();
    const data = body.data;
    expect(data?.token, 'login response missing token').toBeTruthy();

    // Normalisasi: permissions sering di dalam user
    if (!data.permissions && data.user?.permissions) {
        data.permissions = data.user.permissions;
    }

    memoryCache = data;
    writeFileCache(data);
    return data;
}

/**
 * Inject token/user/permissions sebelum SPA boot (Pinia baca localStorage saat init).
 */
export async function injectAuth(page, authData = memoryCache) {
    expect(authData?.token, 'injectAuth: call getAuthData first').toBeTruthy();
    const payload = {
        token: authData.token,
        user: authData.user,
        permissions: authData.permissions || authData.user?.permissions || []
    };
    await page.addInitScript(({ token, user, permissions }) => {
        localStorage.setItem('token', token);
        localStorage.setItem('user', JSON.stringify(user));
        localStorage.setItem('permissions', JSON.stringify(permissions || []));
    }, payload);
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Inject auth lalu navigate ke path (absolut dari baseURL atau path relatif).
 */
export async function injectAuthAndGoto(page, path = '/', authData = memoryCache) {
    await injectAuth(page, authData);
    if (path && path !== '/') {
        const target = path.startsWith('http') ? path : path;
        await page.goto(target);
        await page.waitForLoadState('networkidle');
    }
}

/**
 * Kompat docs-helpers: login + inject ke page yang sudah di baseURL.
 * Prefer getAuthData + injectAuth untuk suite baru.
 */
export async function loginViaApiOnPage(page, request = page.request) {
    const auth = await getAuthData(request);
    await injectAuth(page, auth);
    return auth;
}
