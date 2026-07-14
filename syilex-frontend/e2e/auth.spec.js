import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
    test('login page renders with email and password fields', async ({ page, baseURL }) => {
        await page.goto(baseURL);
        await page.waitForLoadState('networkidle');

        await expect(page.locator('#email')).toBeVisible({ timeout: 10000 });
        await expect(page.locator('#password input')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test('login with valid credentials stores token and navigates away', async ({ page, baseURL }) => {
        await page.goto(baseURL);
        await page.waitForLoadState('networkidle');

        await page.locator('#email').fill('admin@posip.com');
        await page.locator('#password input').fill('password');
        await page.locator('button[type="submit"]').click();

        // Wait for URL to contain /app (router pushes to /app after login)
        await page.waitForURL((url) => url.pathname.startsWith('/app'), { timeout: 15000 });

        // Token should be stored
        const token = await page.evaluate(() => localStorage.getItem('token'));
        expect(token).toBeTruthy();

        // Login form should no longer be visible
        await expect(page.locator('#email')).not.toBeVisible();
    });

    test('login with wrong password stays on login page', async ({ page, baseURL }) => {
        await page.goto(baseURL);
        await page.waitForLoadState('networkidle');

        await page.locator('#email').fill('admin@posip.com');
        await page.locator('#password input').fill('wrongpassword');
        await page.locator('button[type="submit"]').click();

        await page.waitForTimeout(3000);
        const path = new URL(page.url()).pathname;
        expect(path).toBe('/');
    });

    test('accessing protected route without auth shows login', async ({ page, baseURL }) => {
        // Clear auth and navigate to root
        await page.goto(baseURL);
        await page.evaluate(() => {
            localStorage.clear();
        });
        await page.reload();
        await page.waitForLoadState('networkidle');

        // Login form should be visible (guard redirects to '/')
        await expect(page.locator('#email')).toBeVisible({ timeout: 10000 });
    });
});
