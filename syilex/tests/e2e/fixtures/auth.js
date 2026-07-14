import { test as base, expect } from '@playwright/test';

/**
 * Extended test fixture with authenticated page.
 *
 * Usage:
 *   import { test, expect } from './fixtures/auth';
 *   test('something', async ({ authedPage }) => { ... });
 *
 * `authedPage` is a Playwright Page that already has:
 *   - localStorage token, user, permissions set
 *   - Ready to navigate to any authenticated route
 */
export const test = base.extend({
    authedPage: async ({ page, baseURL }, use) => {
        // Login via API to get token
        const apiURL = baseURL + '/api/v1';
        const response = await page.request.post(`${apiURL}/auth/login`, {
            data: {
                email: 'admin@posip.com',
                password: 'password',
            },
        });

        expect(response.ok()).toBeTruthy();
        const body = await response.json();
        const { token, user, permissions } = body.data || body;

        // Inject auth state into browser localStorage BEFORE navigating
        await page.goto(baseURL);
        await page.evaluate(
            ({ token, user, permissions }) => {
                localStorage.setItem('token', token);
                localStorage.setItem('user', JSON.stringify(user));
                localStorage.setItem('permissions', JSON.stringify(permissions || []));
            },
            { token, user, permissions }
        );

        await use(page);
    },
});

export { expect } from '@playwright/test';
