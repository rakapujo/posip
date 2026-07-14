/**
 * Minimal test runner — konsisten dengan transactionTypeSeverity.test.js
 * Jalankan semua: node tests/unit/run-all.mjs
 */

export class TestRunner {
    constructor(suiteName = 'Tests') {
        this.suiteName = suiteName;
        this.passed = 0;
        this.failed = 0;
        this.results = [];
    }

    test(name, fn) {
        try {
            const result = fn();
            if (result instanceof Promise) {
                throw new Error('Async tests must use testAsync()');
            }
            this.passed++;
            this.results.push({ name, status: 'PASS' });
            console.log(`✅ PASS: ${name}`);
        } catch (error) {
            this.failed++;
            this.results.push({ name, status: 'FAIL', error: error.message });
            console.log(`❌ FAIL: ${name}`);
            console.log(`   Error: ${error.message}`);
        }
    }

    async testAsync(name, fn) {
        try {
            await fn();
            this.passed++;
            this.results.push({ name, status: 'PASS' });
            console.log(`✅ PASS: ${name}`);
        } catch (error) {
            this.failed++;
            this.results.push({ name, status: 'FAIL', error: error.message });
            console.log(`❌ FAIL: ${name}`);
            console.log(`   Error: ${error.message}`);
        }
    }

    assertEqual(actual, expected, message = '') {
        if (actual !== expected) {
            throw new Error(`${message} Expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
        }
    }

    assertTrue(value, message = '') {
        if (!value) throw new Error(message || 'Expected truthy value');
    }

    assertFalse(value, message = '') {
        if (value) throw new Error(message || 'Expected falsy value');
    }

    assertContains(haystack, needle, message = '') {
        if (!String(haystack).includes(needle)) {
            throw new Error(`${message} "${haystack}" does not contain "${needle}"`);
        }
    }

    assertDeepEqual(actual, expected, message = '') {
        const a = JSON.stringify(actual);
        const b = JSON.stringify(expected);
        if (a !== b) {
            throw new Error(`${message} Expected ${b}, got ${a}`);
        }
    }

    assertThrows(fn, message = '') {
        let threw = false;
        try {
            fn();
        } catch {
            threw = true;
        }
        if (!threw) throw new Error(message || 'Expected function to throw');
    }

    summary() {
        console.log('\n' + '='.repeat(50));
        console.log(`${this.suiteName}: ${this.passed} passed, ${this.failed} failed`);
        console.log('='.repeat(50));
        return this.failed === 0;
    }
}
