/**
 * Unit Test: Transaction Type Severity
 *
 * Jalankan dengan: node tests/unit/transactionTypeSeverity.test.js
 *
 * Test ini memverifikasi fungsi getTransactionSeverity yang digunakan
 * di StockCardPage.vue dan HppMovementPage.vue
 */

// Implementation dari Vue component (copy untuk testing)
function getTransactionSeverity(type) {
    const inTypes = ['PURCHASE', 'SALES_RETURN', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'REPACK_IN'];
    const outTypes = ['SALES', 'PURCHASE_RETURN', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'REPACK_OUT'];
    const systemTypes = ['HPP_RESET', 'STOCK_OPNAME'];

    if (inTypes.includes(type)) return 'success';
    if (outTypes.includes(type)) return 'danger';
    if (systemTypes.includes(type)) return 'warn';
    return 'info';
}

// Test Runner
class TestRunner {
    constructor() {
        this.passed = 0;
        this.failed = 0;
        this.results = [];
    }

    test(name, fn) {
        try {
            fn();
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
            throw new Error(`${message} Expected "${expected}", got "${actual}"`);
        }
    }

    assertContains(array, item, message = '') {
        if (!array.includes(item)) {
            throw new Error(`${message} Array does not contain "${item}"`);
        }
    }

    summary() {
        console.log('\n' + '='.repeat(50));
        console.log(`SUMMARY: ${this.passed} passed, ${this.failed} failed`);
        console.log('='.repeat(50));
        return this.failed === 0;
    }
}

// Run Tests
const runner = new TestRunner();

console.log('\n🧪 Transaction Type Severity Tests\n' + '='.repeat(50) + '\n');

// Test IN types
runner.test('PURCHASE should return success', () => {
    runner.assertEqual(getTransactionSeverity('PURCHASE'), 'success');
});

runner.test('SALES_RETURN should return success', () => {
    runner.assertEqual(getTransactionSeverity('SALES_RETURN'), 'success');
});

runner.test('ADJUSTMENT_IN should return success', () => {
    runner.assertEqual(getTransactionSeverity('ADJUSTMENT_IN'), 'success');
});

runner.test('TRANSFER_IN should return success', () => {
    runner.assertEqual(getTransactionSeverity('TRANSFER_IN'), 'success');
});

runner.test('REPACK_IN should return success', () => {
    runner.assertEqual(getTransactionSeverity('REPACK_IN'), 'success');
});

// Test OUT types
runner.test('SALES should return danger', () => {
    runner.assertEqual(getTransactionSeverity('SALES'), 'danger');
});

runner.test('PURCHASE_RETURN should return danger', () => {
    runner.assertEqual(getTransactionSeverity('PURCHASE_RETURN'), 'danger');
});

runner.test('ADJUSTMENT_OUT should return danger', () => {
    runner.assertEqual(getTransactionSeverity('ADJUSTMENT_OUT'), 'danger');
});

runner.test('TRANSFER_OUT should return danger', () => {
    runner.assertEqual(getTransactionSeverity('TRANSFER_OUT'), 'danger');
});

runner.test('REPACK_OUT should return danger', () => {
    runner.assertEqual(getTransactionSeverity('REPACK_OUT'), 'danger');
});

// Test SYSTEM types
runner.test('HPP_RESET should return warn', () => {
    runner.assertEqual(getTransactionSeverity('HPP_RESET'), 'warn');
});

runner.test('STOCK_OPNAME should return warn', () => {
    runner.assertEqual(getTransactionSeverity('STOCK_OPNAME'), 'warn');
});

// Test unknown type
runner.test('Unknown type should return info', () => {
    runner.assertEqual(getTransactionSeverity('UNKNOWN_TYPE'), 'info');
});

runner.test('Empty string should return info', () => {
    runner.assertEqual(getTransactionSeverity(''), 'info');
});

// Summary
const success = runner.summary();
process.exit(success ? 0 : 1);
