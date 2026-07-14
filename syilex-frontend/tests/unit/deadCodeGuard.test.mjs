/**
 * Guard — Sakai leftovers / unused barrels deleted in audit must stay gone.
 */
import { existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TestRunner } from './testRunner.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const srcRoot = join(__dirname, '../../src');

const DEAD_PATHS = [
    'components/BlockViewer.vue',
    'components/common/EmptyState.vue',
    'types/index.js'
];

const runner = new TestRunner('deadCodeGuard');

console.log('\n🧪 deadCodeGuard Tests\n' + '='.repeat(50) + '\n');

for (const rel of DEAD_PATHS) {
    runner.test(`${rel} must not exist`, () => {
        runner.assertFalse(existsSync(join(srcRoot, rel)), `${rel} was deleted; do not restore without a consumer`);
    });
}

const ok = runner.summary();
process.exit(ok ? 0 : 1);
