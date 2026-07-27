import { test as base, expect } from '@playwright/test';
import { getAuthData, injectAuth } from '../helpers/auth.js';

/**
 * Extended test fixture with authenticated page.
 *
 * Usage:
 *   import { test, expect } from './fixtures/auth';
 *   test('something', async ({ authedPage }) => { ... });
 *
 * Reuses shared login cache — tidak login ulang per test.
 */
export const test = base.extend({
    authedPage: async ({ page, request }, use) => {
        const auth = await getAuthData(request);
        await injectAuth(page, auth);
        await use(page);
    }
});

export { expect } from '@playwright/test';
export { getAuthData, injectAuth, authHeaders, laravelApiBase, clearAuthCache } from '../helpers/auth.js';
