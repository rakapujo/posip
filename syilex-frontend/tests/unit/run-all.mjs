import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));

const suites = [
    'transactionTypeSeverity.test.js',
    'printStorage.test.mjs',
    'base64Bytes.test.mjs',
    'printTransport.test.mjs',
    'printAdapter.test.mjs',
    'shiftPenjualanEscpos.test.mjs',
    'printIsolation.test.mjs',
    'printPolicy.test.mjs',
    'deadCodeGuard.test.mjs'
];

let failed = 0;

console.log('\n🔬 POSIP Frontend Unit Tests\n' + '='.repeat(50));

for (const file of suites) {
    const path = join(__dirname, file);
    const result = spawnSync(process.execPath, [path], { stdio: 'inherit', cwd: join(__dirname, '../..') });
    if (result.status !== 0) failed++;
}

console.log('\n' + '='.repeat(50));
if (failed) {
    console.log(`FAILED: ${failed} suite(s)`);
    process.exit(1);
}
console.log('ALL SUITES PASSED');
process.exit(0);
