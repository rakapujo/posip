import { TestRunner } from './testRunner.mjs';
import {
    clearActiveConnection,
    connectBluetooth,
    connectSerial,
    connectUsb,
    isThermalSupported,
    setActiveConnection,
    supportMatrix,
    trySilentReconnect
} from '../../src/composables/print/printTransportCore.js';

const runner = new TestRunner('printTransportCore');

console.log('\n🧪 printTransportCore Tests\n' + '='.repeat(50) + '\n');

runner.test('supportMatrix detects APIs', () => {
    const nav = { bluetooth: {}, serial: null, usb: {} };
    runner.assertDeepEqual(supportMatrix(nav), { bluetooth: true, serial: false, usb: true });
});

runner.test('isThermalSupported false when all missing', () => {
    runner.assertFalse(isThermalSupported({}));
});

runner.test('isThermalSupported true with serial only', () => {
    runner.assertTrue(isThermalSupported({ serial: {} }));
});

async function runAsyncTests() {
    await runner.testAsync('connectSerial throws when unsupported', async () => {
        let err = null;
        try {
            await connectSerial({});
        } catch (e) {
            err = e;
        }
        runner.assertTrue(err?.message?.includes('Web Serial'));
    });

    await runner.testAsync('trySilentReconnect uses first authorized serial port', async () => {
        clearActiveConnection();
        const writes = [];
        const port = {
            open: async () => {},
            close: async () => {},
            writable: {
                getWriter: () => ({
                    write: async (data) => {
                        writes.push(data.length);
                    },
                    releaseLock: () => {}
                })
            }
        };
        const nav = {
            serial: {
                getPorts: async () => [port]
            }
        };
        const conn = await trySilentReconnect('serial', nav);
        runner.assertTrue(!!conn);
        runner.assertEqual(conn.kind, 'serial');
        await conn.write(new Uint8Array([1, 2, 3]));
        runner.assertDeepEqual(writes, [3]);
        clearActiveConnection();
    });

    await runner.testAsync('trySilentReconnect returns null when no ports', async () => {
        clearActiveConnection();
        const nav = { serial: { getPorts: async () => [] } };
        const conn = await trySilentReconnect('serial', nav);
        runner.assertEqual(conn, null);
    });

    await runner.testAsync('getActiveConnection short-circuit when already connected', async () => {
        clearActiveConnection();
        const fake = {
            kind: 'usb',
            label: 'Fake',
            write: async () => {},
            disconnect: async () => {}
        };
        setActiveConnection(fake);
        const nav = { serial: { getPorts: async () => [{ open: async () => {}, writable: { getWriter: () => ({ write: async () => {}, releaseLock: () => {} }) }, close: async () => {} }] } };
        const conn = await trySilentReconnect('serial', nav);
        runner.assertEqual(conn, fake);
        clearActiveConnection();
    });

    await runner.testAsync('trySilentReconnect null kind returns null', async () => {
        clearActiveConnection();
        const conn = await trySilentReconnect(null, { serial: { getPorts: async () => [{}] } });
        runner.assertEqual(conn, null);
    });

    await runner.testAsync('connectBluetooth throws when unsupported', async () => {
        let err = null;
        try {
            await connectBluetooth({});
        } catch (e) {
            err = e;
        }
        runner.assertTrue(err?.message?.includes('Web Bluetooth'));
    });

    await runner.testAsync('connectUsb throws when unsupported', async () => {
        let err = null;
        try {
            await connectUsb({});
        } catch (e) {
            err = e;
        }
        runner.assertTrue(err?.message?.includes('WebUSB'));
    });

    await runner.testAsync('serial reconnect tolerates port already open', async () => {
        clearActiveConnection();
        let openCalls = 0;
        const port = {
            open: async () => {
                openCalls++;
                if (openCalls > 1) throw new Error('already open');
            },
            close: async () => {},
            writable: {
                getWriter: () => ({
                    write: async () => {},
                    releaseLock: () => {}
                })
            }
        };
        const nav = { serial: { getPorts: async () => [port] } };
        const conn = await trySilentReconnect('serial', nav);
        runner.assertTrue(!!conn);
        clearActiveConnection();
    });
}

await runAsyncTests();

const ok = runner.summary();
process.exit(ok ? 0 : 1);
