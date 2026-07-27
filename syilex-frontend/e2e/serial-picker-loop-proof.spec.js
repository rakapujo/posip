/**
 * Bukti root-cause loop SerialUnitPicker (tanpa DB / login).
 * Pola A = deep watch (lama) → emit meledak saat object baris dimutasi.
 * Pola B = @update:selection + idsKey (baru) → emit tetap 1.
 */
import { test, expect } from '@playwright/test';
import { ref, watch, nextTick, reactive } from 'vue';

function idsKey(ids) {
    return [...(ids || [])].map(String).sort().join('|');
}

test('PROVEN: deep-watch selected emits on every nested row mutate', async () => {
    let emitCount = 0;
    const modelValue = ref([]);
    const selected = ref([]);
    const units = reactive([{ ulid: 'a', harga_jual: 1 }]);

    watch(
        () => modelValue.value,
        () => {
            const cur = new Set(selected.value.map((u) => u.ulid));
            const incoming = new Set(modelValue.value || []);
            if (cur.size !== incoming.size || [...incoming].some((x) => !cur.has(x))) {
                const set = new Set(modelValue.value || []);
                selected.value = units.filter((u) => set.has(u.ulid));
            }
        }
    );

    // pola lama di SerialUnitPicker
    watch(
        selected,
        (val) => {
            emitCount++;
            modelValue.value = val.map((u) => u.ulid);
        },
        { deep: true }
    );

    selected.value = [units[0]];
    await nextTick();
    expect(emitCount).toBe(1);

    for (let i = 0; i < 8; i++) {
        units[0][`__prime_${i}`] = i; // simulasi mutasi nested PrimeVue
        await nextTick();
    }

    expect(emitCount).toBeGreaterThan(5);
});

test('PROVEN: selection handler + idsKey does not emit on nested mutate', async () => {
    let emitCount = 0;
    const modelValue = ref([]);
    const selected = ref([]);
    const units = reactive([{ ulid: 'a', harga_jual: 1 }]);

    function emitIfChanged(rows) {
        const ids = (rows || []).map((u) => u.ulid);
        if (idsKey(ids) === idsKey(modelValue.value)) return;
        emitCount++;
        modelValue.value = ids;
    }

    function onSelectionUpdate(rows) {
        selected.value = rows || [];
        emitIfChanged(selected.value);
    }

    watch(
        () => modelValue.value,
        (ids) => {
            if (idsKey(selected.value.map((u) => u.ulid)) === idsKey(ids)) return;
            const set = new Set([...(ids || [])].map(String));
            selected.value = units.filter((u) => set.has(String(u.ulid)));
        }
    );

    onSelectionUpdate([units[0]]);
    await nextTick();
    expect(emitCount).toBe(1);

    for (let i = 0; i < 8; i++) {
        units[0][`__prime_${i}`] = i;
        await nextTick();
    }

    expect(emitCount).toBe(1);
});

test('PROVEN: always-sync without idsKey infinite-emits with parent', async () => {
    let emitCount = 0;
    const modelValue = ref([]);
    const selected = ref([]);
    const units = [{ ulid: 'a' }];

    watch(
        () => modelValue.value,
        () => {
            const set = new Set(modelValue.value || []);
            selected.value = units.filter((u) => set.has(u.ulid));
        }
    );

    watch(
        selected,
        (val) => {
            emitCount++;
            if (emitCount > 25) return;
            modelValue.value = val.map((u) => u.ulid);
        },
        { deep: true }
    );

    selected.value = [units[0]];
    for (let i = 0; i < 15; i++) await nextTick();

    expect(emitCount).toBeGreaterThan(10);
});
