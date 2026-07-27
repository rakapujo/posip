import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: 'html',

    use: {
        // Prefer Vite dev (relative /api proxy). Override for built SPA:
        // E2E_BASE_URL=http://POSIP.test/syilex/public npx playwright test
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:5173',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure'
    },

    webServer: process.env.E2E_BASE_URL
        ? undefined
        : {
              command: 'npm run dev -- --host 127.0.0.1 --port 5173',
              url: 'http://127.0.0.1:5173',
              reuseExistingServer: !process.env.CI,
              timeout: 120000
          },

    projects: [
        {
            name: 'chromium',
            use: { browserName: 'chromium' }
        }
    ]
});
