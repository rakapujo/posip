import { TestRunner } from './testRunner.mjs';
import {
    STORAGE_KEY,
    clearStoredPrinter,
    getStoredPrinter,
    isStoredForTerminal,
    parseStoredPrinter,
    setStoredPrinter
} from '../../src/composables/print/printStorage.js';

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

const runner = new TestRunner('printStorage');

console.log('\n🧪 printStorage Tests\n' + '='.repeat(50) + '\n');

runner.test('parseStoredPrinter rejects invalid kind', () => {
    runner.assertEqual(parseStoredPrinter({ kind: 'wifi' }), null);
    runner.assertEqual(parseStoredPrinter(null), null);
    runner.assertEqual(parseStoredPrinter('x'), null);
});

runner.test('parseStoredPrinter accepts valid payload', () => {
    runner.assertDeepEqual(parseStoredPrinter({ kind: 'serial', label: ' XP-58 ' }), {
        kind: 'serial',
        label: 'XP-58'
    });
});

runner.test('setStoredPrinter + getStoredPrinter roundtrip', () => {
    clearStoredPrinter();
    setStoredPrinter({ kind: 'usb', terminalUlid: '01ABC', label: 'TM-T82' });
    runner.assertDeepEqual(getStoredPrinter(), { kind: 'usb', terminalUlid: '01ABC', label: 'TM-T82' });
    runner.assertEqual(localStorage.getItem(STORAGE_KEY).includes('"kind":"usb"'), true);
});

runner.test('setStoredPrinter throws on invalid kind', () => {
    runner.assertThrows(() => setStoredPrinter({ kind: 'net' }), 'invalid kind');
});

runner.test('clearStoredPrinter removes key', () => {
    setStoredPrinter({ kind: 'bluetooth' });
    clearStoredPrinter();
    runner.assertEqual(getStoredPrinter(), null);
});

runner.test('isStoredForTerminal matches terminal ulid', () => {
    setStoredPrinter({ kind: 'serial', terminalUlid: 'T1' });
    runner.assertTrue(isStoredForTerminal('T1'));
    runner.assertFalse(isStoredForTerminal('T2'));
});

runner.test('isStoredForTerminal true when stored has no terminalUlid', () => {
    setStoredPrinter({ kind: 'serial' });
    runner.assertTrue(isStoredForTerminal('ANY'));
});

runner.test('getStoredPrinter returns null on corrupt JSON', () => {
    localStorage.setItem(STORAGE_KEY, '{bad json');
    runner.assertEqual(getStoredPrinter(), null);
});

runner.test('isStoredForTerminal false when nothing stored', () => {
    clearStoredPrinter();
    runner.assertFalse(isStoredForTerminal('T1'));
});

runner.test('parseStoredPrinter ignores blank terminalUlid and label', () => {
    runner.assertDeepEqual(parseStoredPrinter({ kind: 'usb', terminalUlid: '  ', label: '' }), { kind: 'usb' });
});

runner.test('parseStoredPrinter trims terminalUlid', () => {
    runner.assertDeepEqual(parseStoredPrinter({ kind: 'serial', terminalUlid: '  ULID1  ' }), {
        kind: 'serial',
        terminalUlid: 'ULID1'
    });
});

runner.test('STORAGE_KEY matches posip-thermal-printer', () => {
    runner.assertEqual(STORAGE_KEY, 'posip-thermal-printer');
});

runner.test('setStoredPrinter omits empty optional fields from JSON', () => {
    clearStoredPrinter();
    setStoredPrinter({ kind: 'bluetooth', label: '' });
    const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY));
    runner.assertEqual(parsed.kind, 'bluetooth');
    runner.assertFalse('label' in parsed);
    runner.assertFalse('terminalUlid' in parsed);
});

const ok = runner.summary();
process.exit(ok ? 0 : 1);
