import { TestRunner } from './testRunner.mjs';
import { bytesToBase64 } from '../../src/composables/print/base64Bytes.js';
import { checkStatusCore, printRawCore } from '../../src/composables/print/printAdapterCore.js';
import { clearStoredPrinter, setStoredPrinter } from '../../src/composables/print/printStorage.js';
import { clearActiveConnection, setActiveConnection } from '../../src/composables/print/printTransportCore.js';

const store = {};
globalThis.localStorage = {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => {
        store[k] = v;
    },
    removeItem: (k) => {
        delete store[k];
    }
};

const runner = new TestRunner('printAdapterCore');

console.log('\n🧪 printAdapterCore Tests\n' + '='.repeat(50) + '\n');

const sampleB64 = bytesToBase64(new Uint8Array([0x1b, 0x40]));

async function runAsyncTests() {
    await runner.testAsync('printRawCore rejects empty base64', async () => {
        const r = await printRawCore('');
        runner.assertFalse(r.ok);
        runner.assertEqual(r.error, 'Data cetak kosong');
    });

    await runner.testAsync('printRawCore rejects invalid base64', async () => {
        const r = await printRawCore('!!!not-base64!!!');
        runner.assertFalse(r.ok);
        runner.assertContains(r.error, 'base64');
    });

    await runner.testAsync('printRawCore writes via active connection', async () => {
        clearActiveConnection();
        let written = null;
        setActiveConnection({
            kind: 'serial',
            label: 'Test',
            write: async (data) => {
                written = data;
            },
            disconnect: async () => {}
        });
        const r = await printRawCore(sampleB64);
        runner.assertTrue(r.ok);
        runner.assertEqual(written?.length, 2);
        clearActiveConnection();
    });

    await runner.testAsync('printRawCore needPicker when no connection and no legacy', async () => {
        clearActiveConnection();
        const r = await printRawCore(sampleB64, {
            reconnectFn: async () => null
        });
        runner.assertFalse(r.ok);
        runner.assertTrue(r.needPicker);
    });

    await runner.testAsync('printRawCore whitespace base64 → payload kosong', async () => {
        const r = await printRawCore('   ', { reconnectFn: async () => null });
        runner.assertFalse(r.ok);
        runner.assertEqual(r.error, 'Payload ESC/POS kosong');
    });

    await runner.testAsync('printRawCore legacy-only when no browser connection', async () => {
        clearActiveConnection();
        clearStoredPrinter();
        let legacyCalled = false;
        const r = await printRawCore(sampleB64, {
            reconnectFn: async () => null,
            legacyPrinterId: 'WIN:TEST',
            legacy: {
                checkStatus: async () => true,
                printRaw: async () => {
                    legacyCalled = true;
                    return { success: true };
                }
            }
        });
        runner.assertTrue(r.ok);
        runner.assertTrue(legacyCalled);
        runner.assertTrue(r.legacyUsed);
    });

    await runner.testAsync('printRawCore stored kind without connection → needPicker + reconnect message', async () => {
        clearActiveConnection();
        setStoredPrinter({ kind: 'serial', terminalUlid: 'TERM1' });
        const r = await printRawCore(sampleB64, { reconnectFn: async () => null });
        runner.assertFalse(r.ok);
        runner.assertTrue(r.needPicker);
        runner.assertContains(r.error, 'disambungkan');
        clearStoredPrinter();
    });

    await runner.testAsync('printRawCore falls back to legacy on write failure', async () => {
        clearActiveConnection();
        setActiveConnection({
            kind: 'serial',
            label: 'Fail',
            write: async () => {
                throw new Error('USB unplugged');
            },
            disconnect: async () => {}
        });
        let legacyCalled = false;
        const r = await printRawCore(sampleB64, {
            legacyPrinterId: 'WIN:XP-58',
            legacy: {
                checkStatus: async () => true,
                printRaw: async () => {
                    legacyCalled = true;
                    return { success: true };
                }
            }
        });
        runner.assertTrue(r.ok);
        runner.assertTrue(legacyCalled);
        runner.assertTrue(r.legacyUsed);
        clearActiveConnection();
    });

    await runner.testAsync('printRawCore legacy checkStatus false → needPicker with legacy hint', async () => {
        clearActiveConnection();
        clearStoredPrinter();
        const r = await printRawCore(sampleB64, {
            reconnectFn: async () => null,
            legacyPrinterId: 'WIN:X',
            legacy: {
                checkStatus: async () => false,
                printRaw: async () => ({ success: false })
            }
        });
        runner.assertFalse(r.ok);
        runner.assertTrue(r.needPicker);
        runner.assertContains(r.error, 'Pasangkan printer');
    });

    await runner.testAsync('checkStatusCore true when browser thermal supported', async () => {
        const ok = await checkStatusCore({ serial: {} }, null);
        runner.assertTrue(ok);
    });

    await runner.testAsync('checkStatusCore falls back to legacy checkStatus', async () => {
        let checked = false;
        const ok = await checkStatusCore({}, {
            checkStatus: async () => {
                checked = true;
                return true;
            }
        });
        runner.assertTrue(checked);
        runner.assertTrue(ok);
    });
}

await runAsyncTests();

const ok = runner.summary();
process.exit(ok ? 0 : 1);
