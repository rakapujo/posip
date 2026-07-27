/**
 * Guard — Master UI unify: RowActionButtons, text aksi, Produk ImageUpload, Warehouse filter badge.
 */
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TestRunner } from './testRunner.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const srcRoot = join(__dirname, '../../src');
const viewsRoot = join(srcRoot, 'views');

function read(rel) {
    return readFileSync(join(srcRoot, rel), 'utf8');
}

function walkPages(dir, acc = []) {
    for (const e of readdirSync(dir, { withFileTypes: true })) {
        const p = join(dir, e.name);
        if (e.isDirectory()) walkPages(p, acc);
        else if (e.name.endsWith('Page.vue')) acc.push(p);
    }
    return acc;
}

/** Extract each <Column ...>...</Column> block (non-greedy, one level). */
function extractColumns(vue) {
    const cols = [];
    const re = /<Column\b([^>]*)>([\s\S]*?)<\/Column>/g;
    let m;
    while ((m = re.exec(vue)) !== null) {
        cols.push({ attrs: m[1], inner: m[2] });
    }
    return cols;
}

function isAksiColumn(attrs, inner) {
    if (/header\s*=\s*["']Aksi["']/.test(attrs)) return true;
    if (/alignFrozen\s*=\s*["']right["']/.test(attrs) && /frozen/.test(attrs) && /#body/.test(inner)) {
        return /pi-(eye|pencil|trash|power-off|check|ban|file-pdf|undo|play|history)/.test(inner);
    }
    return false;
}

function iconActionButtons(html) {
    return [...html.matchAll(/<Button\b([^>]*icon\s*=\s*["']pi pi-(?:eye|pencil|trash|power-off|check|ban|file-pdf|undo|play|history|times-circle|check-circle)[^"']*["'][^>]*)\/?>/g)].map((m) => m[1]);
}

const SKIP_AKSI_PAGES = new Set([
    // POS card footer / non-standard list — covered separately
]);

const runner = new TestRunner('masterUiGuard');

console.log('\n🧪 masterUiGuard Tests\n' + '='.repeat(50) + '\n');

// ─── RowActionButtons on list Aksi columns ───
const offendersNoWrap = [];
const offendersOutlined = [];
const offendersMissingText = [];

for (const file of walkPages(viewsRoot)) {
    const rel = file.slice(srcRoot.length + 1).replace(/\\/g, '/');
    if (SKIP_AKSI_PAGES.has(rel)) continue;
    const vue = readFileSync(file, 'utf8');
    for (const col of extractColumns(vue)) {
        if (!isAksiColumn(col.attrs, col.inner)) continue;
        const bodyMatch = col.inner.match(/<template\s+#body[^>]*>([\s\S]*?)<\/template>/);
        if (!bodyMatch) continue;
        const body = bodyMatch[1];
        if (!/<Button\b/.test(body)) continue;

        if (!/<RowActionButtons\b/.test(body)) {
            offendersNoWrap.push(rel);
            continue;
        }

        const wrapMatch = body.match(/<RowActionButtons\b[^>]*>([\s\S]*?)<\/RowActionButtons>/);
        if (!wrapMatch) continue;
        const wrapInner = wrapMatch[1];
        for (const attrs of iconActionButtons(wrapInner)) {
            if (/\boutlined\b/.test(attrs)) offendersOutlined.push(`${rel}: ${attrs.slice(0, 80)}`);
            if (!/\btext\b/.test(attrs)) offendersMissingText.push(`${rel}: ${attrs.slice(0, 80)}`);
        }
    }
}

runner.test('every list Aksi column wraps buttons in RowActionButtons', () => {
    runner.assertDeepEqual([...new Set(offendersNoWrap)].sort(), []);
});

runner.test('RowActionButtons icon actions use text (not outlined)', () => {
    runner.assertDeepEqual(offendersOutlined, []);
});

runner.test('RowActionButtons icon actions include text prop', () => {
    runner.assertDeepEqual(offendersMissingText, []);
});

// ─── Produk ImageUpload eager ───
runner.test('ProdukPage uses ImageUpload folder products', () => {
    const vue = read('views/master/ProdukPage.vue');
    runner.assertContains(vue, "import ImageUpload from '@/components/common/ImageUpload.vue'");
    runner.assertContains(vue, 'folder="products"');
    runner.assertFalse(vue.includes('type="file"'), 'ProdukPage must not use raw file input');
    runner.assertFalse(/FormData/.test(vue), 'ProdukPage must not build FormData');
});

runner.test('produks API uses JSON create/update (not multipart)', () => {
    const api = read('api/modules/produks.js');
    runner.assertContains(api, "client.post('/produks', data)");
    runner.assertContains(api, 'client.put(`/produks/${ulid}`, data)');
    runner.assertFalse(/FormData/.test(api), 'produks API must not use FormData');
});

// ─── Warehouse filter badge ───
runner.test('WarehousePage activeFilterCount includes is_saleable', () => {
    const vue = read('views/master/WarehousePage.vue');
    runner.assertContains(vue, 'additionalFilters.value.is_saleable');
    const fn = vue.match(/const activeFilterCount = computed\(\(\) => \{[\s\S]*?\}\);/);
    runner.assertTrue(!!fn, 'activeFilterCount computed must exist');
    runner.assertContains(fn[0], 'is_saleable');
});

// ─── PosTerminal card footer ───
runner.test('PosTerminalPage card footer uses RowActionButtons', () => {
    const vue = read('views/master/PosTerminalPage.vue');
    runner.assertContains(vue, 'RowActionButtons');
    runner.assertContains(vue, "import RowActionButtons from '@/components/common/RowActionButtons.vue'");
});

// ─── RowActionButtons component ───
runner.test('RowActionButtons component exists with flex gap-1', () => {
    const vue = read('components/common/RowActionButtons.vue');
    runner.assertContains(vue, 'flex gap-1');
    runner.assertTrue(existsSync(join(srcRoot, 'components/common/RowActionButtons.vue')));
});

const ok = runner.summary();
process.exit(ok ? 0 : 1);
