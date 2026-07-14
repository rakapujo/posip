import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

const OUT_DIR = path.resolve(process.cwd(), '../docs/assets/screenshots');

const DB = {
    host: process.env.E2E_DB_HOST || '127.0.0.1',
    port: process.env.E2E_DB_PORT || '3306',
    database: process.env.E2E_DB_DATABASE || 'posip_db',
    username: process.env.E2E_DB_USERNAME || 'root',
    password: process.env.E2E_DB_PASSWORD || '',
};

const STORE = {
    name: 'Toko Demo POSIP',
    address: 'Jl. Raya Contoh No. 1, Jakarta',
    phone: '021-5550100',
    email: 'toko@posip.local',
    npwp: '',
};

const ADMIN = {
    name: 'Super Admin',
    email: 'admin@posip.com',
    password: 'password',
};

async function snap(page, name) {
    fs.mkdirSync(OUT_DIR, { recursive: true });
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(600);
    await page.screenshot({
        path: path.join(OUT_DIR, `${name}.png`),
        fullPage: true,
    });
}

test.describe('Installer wizard documentation', () => {
    test('walk through all install steps and capture screenshots', async ({ page, baseURL }) => {
        test.setTimeout(300000);

        await page.goto(`${baseURL}/install`);
        await expect(page.getByRole('heading', { name: 'Cek Server' })).toBeVisible({ timeout: 15000 });
        await snap(page, 'install-01-cek-server');

        await page.getByRole('button', { name: /Lanjut/i }).click();
        await expect(page.getByRole('heading', { name: 'Konfigurasi Database' })).toBeVisible();
        await snap(page, 'install-02-database');

        await page.fill('input[name="host"]', DB.host);
        await page.fill('input[name="port"]', DB.port);
        await page.fill('input[name="database"]', DB.database);
        await page.fill('input[name="username"]', DB.username);
        await page.fill('input[name="password"]', DB.password);
        await page.getByRole('button', { name: /Test Koneksi/i }).click();
        await expect(page.getByRole('heading', { name: 'Informasi Toko' })).toBeVisible({ timeout: 30000 });
        await snap(page, 'install-03-informasi-toko');

        await page.fill('input[name="name"]', STORE.name);
        await page.fill('textarea[name="address"]', STORE.address);
        await page.fill('input[name="phone"]', STORE.phone);
        await page.fill('input[name="email"]', STORE.email);
        await page.getByRole('button', { name: /^Lanjut/i }).click();
        await expect(page.getByRole('heading', { name: 'Regional & Mata Uang' })).toBeVisible();
        await snap(page, 'install-04-regional-mata-uang');

        await page.getByRole('button', { name: /^Lanjut/i }).click();
        await expect(page.getByRole('heading', { name: 'Pajak & Perhitungan' })).toBeVisible();
        await snap(page, 'install-05-pajak-perhitungan');

        await page.getByRole('button', { name: /^Lanjut/i }).click();
        await expect(page.getByRole('heading', { name: 'Promo & Diskon' })).toBeVisible();
        await snap(page, 'install-06-promo-diskon');

        await page.getByRole('button', { name: /^Lanjut/i }).click();
        await expect(page.getByRole('heading', { name: 'Akun Admin' })).toBeVisible();
        await snap(page, 'install-07-akun-admin');

        await page.fill('input[name="name"]', ADMIN.name);
        await page.fill('input[name="email"]', ADMIN.email);
        await page.fill('input[name="password"]', ADMIN.password);
        await page.fill('input[name="password_confirmation"]', ADMIN.password);
        await page.getByRole('button', { name: /^Lanjut/i }).click();
        await expect(page.getByRole('heading', { name: 'Data Awal' })).toBeVisible();
        await snap(page, 'install-08-data-awal');

        await page.locator('#seed_demo').check();
        await page.locator('#createTerminal').check();
        await page.fill('input[name="terminal_kode"]', 'KASIR_1');
        await page.fill('input[name="terminal_nama"]', 'Kasir Utama');
        await page.getByRole('button', { name: /Mulai Instalasi/i }).click();

        await expect(page.getByRole('heading', { name: 'Menginstall POSIP...' })).toBeVisible();
        await page.waitForTimeout(1500);
        await snap(page, 'install-09-proses-instalasi');

        const doneLink = page.getByRole('link', { name: /Selesai/i });
        await expect(doneLink).toBeVisible({ timeout: 180000 });
        await snap(page, 'install-09-proses-selesai');

        await doneLink.click();
        await expect(page.getByRole('heading', { name: 'Instalasi Berhasil!' })).toBeVisible({ timeout: 30000 });
        await snap(page, 'install-10-selesai');
    });
});
