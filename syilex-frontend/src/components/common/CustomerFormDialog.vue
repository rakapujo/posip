<script setup>
/**
 * Dialog tambah/edit Customer (reusable, DRY) — dipakai Master Customer & POS Kasir.
 * Reuse customersApi.create/update + tipe/kategori. Emit `saved(customer)` dgn relasi
 * tipe_customer/kategori_customer (incl diskon) sudah ter-load → siap dipakai disc nota POS.
 *
 * v-model:visible — buka/tutup. prop `customer` = record utk EDIT (null = tambah baru).
 */
import { ref, computed, watch, onMounted } from 'vue';
import { customersApi, tipeCustomersApi, kategoriCustomersApi } from '@/api';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const props = defineProps({
    visible: { type: Boolean, default: false },
    customer: { type: Object, default: null } // null = create
});
const emit = defineEmits(['update:visible', 'saved']);

const { shouldUppercase } = useFormatters();
const notify = useNotification();

const emptyCustomer = {
    kode_customer: '',
    nama: '',
    telepon: '',
    email: '',
    alamat: '',
    nik: '',
    npwp: '',
    tipe_customer_ulid: null,
    kategori_customer_ulid: null,
    jenis: 'spesifik',
    status: 'active'
};

const form = ref({ ...emptyCustomer });
const submitted = ref(false);
const saving = ref(false);
const isEdit = computed(() => !!form.value.ulid);

const tipeCustomerOptions = ref([]);
const kategoriCustomerOptions = ref([]);

async function loadOptions() {
    try {
        const [t, k] = await Promise.all([tipeCustomersApi.getList(), kategoriCustomersApi.getList()]);
        if (t.data.success) tipeCustomerOptions.value = t.data.data.tipe_customers;
        if (k.data.success) kategoriCustomerOptions.value = k.data.data.kategori_customers;
    } catch (e) {
        notify.apiError(e, 'Gagal memuat tipe/kategori customer');
    }
}
onMounted(loadOptions);

// Saat dialog dibuka → set form dari prop (edit) atau kosong (create)
watch(
    () => props.visible,
    (v) => {
        if (!v) return;
        submitted.value = false;
        if (props.customer) {
            const c = props.customer;
            form.value = {
                ulid: c.ulid,
                kode_customer: c.kode_customer,
                nama: c.nama,
                telepon: c.telepon,
                email: c.email || '',
                alamat: c.alamat || '',
                nik: c.nik || '',
                npwp: c.npwp || '',
                tipe_customer_ulid: c.tipe_customer?.ulid || null,
                kategori_customer_ulid: c.kategori_customer?.ulid || null,
                jenis: c.jenis,
                status: c.status
            };
        } else {
            form.value = { ...emptyCustomer };
        }
    }
);

function close() {
    emit('update:visible', false);
}

async function save() {
    submitted.value = true;
    if (!form.value.kode_customer?.trim()) return;
    if (!form.value.nama?.trim()) return;
    if (!form.value.telepon?.trim()) return;
    if (!form.value.jenis) return;
    if (!form.value.status) return;

    saving.value = true;
    try {
        const data = {
            nama: form.value.nama.trim(),
            telepon: form.value.telepon.trim(),
            email: form.value.email?.trim() || null,
            alamat: form.value.alamat?.trim() || null,
            nik: form.value.nik?.trim() || null,
            npwp: form.value.npwp?.trim() || null,
            tipe_customer_ulid: form.value.tipe_customer_ulid || null,
            kategori_customer_ulid: form.value.kategori_customer_ulid || null,
            jenis: form.value.jenis,
            status: form.value.status
        };
        // kode_customer hanya saat create (immutable setelah dibuat)
        if (!isEdit.value) data.kode_customer = form.value.kode_customer.trim();

        const res = isEdit.value ? await customersApi.update(form.value.ulid, data) : await customersApi.create(data);

        if (res.data.success) {
            notify.success(res.data.message);
            emit('saved', res.data.data.customer);
            emit('update:visible', false);
        }
    } catch (e) {
        notify.saveError(e);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :visible="visible" @update:visible="emit('update:visible', $event)" :style="{ width: '750px' }" :header="isEdit ? 'Edit Customer' : 'Tambah Customer'" :modal="true" :closable="!saving">
        <div class="grid grid-cols-2 gap-4">
            <!-- Kode Customer -->
            <div>
                <label class="block font-medium mb-2">
                    Kode Customer <span class="text-red-500">*</span>
                    <span v-if="isEdit" class="text-surface-500 text-sm">(tidak dapat diubah)</span>
                </label>
                <InputText
                    v-model.trim="form.kode_customer"
                    :invalid="submitted && !form.kode_customer"
                    :disabled="isEdit"
                    :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }"
                    fluid
                    placeholder="Masukkan kode customer"
                    autocomplete="off"
                />
                <small v-if="submitted && !form.kode_customer" class="text-red-500">Kode wajib diisi</small>
                <small v-else-if="!isEdit" class="text-surface-500">Kode tidak dapat diubah setelah disimpan</small>
            </div>

            <!-- Nama -->
            <div>
                <label class="block font-medium mb-2">Nama <span class="text-red-500">*</span></label>
                <InputText v-model.trim="form.nama" :invalid="submitted && !form.nama" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nama customer" autocomplete="off" />
                <small v-if="submitted && !form.nama" class="text-red-500">Nama wajib diisi</small>
            </div>

            <!-- Telepon -->
            <div>
                <label class="block font-medium mb-2">Telepon <span class="text-red-500">*</span></label>
                <InputText v-model.trim="form.telepon" :invalid="submitted && !form.telepon" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Nomor telepon" autocomplete="off" />
                <small v-if="submitted && !form.telepon" class="text-red-500">Telepon wajib diisi</small>
            </div>

            <!-- Email (dikecualikan dari uppercase — CLAUDE.md §3.5) -->
            <div>
                <label class="block font-medium mb-2">Email</label>
                <InputText v-model.trim="form.email" fluid placeholder="Email (opsional)" autocomplete="off" />
            </div>

            <!-- NIK -->
            <div>
                <label class="block font-medium mb-2">NIK</label>
                <InputText v-model.trim="form.nik" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Nomor Induk Kependudukan" autocomplete="off" />
            </div>

            <!-- NPWP -->
            <div>
                <label class="block font-medium mb-2">NPWP</label>
                <InputText v-model.trim="form.npwp" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Nomor NPWP" autocomplete="off" />
            </div>

            <!-- Alamat -->
            <div class="col-span-2">
                <label class="block font-medium mb-2">Alamat</label>
                <Textarea v-model="form.alamat" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid rows="2" placeholder="Alamat lengkap (opsional)" autoResize />
            </div>

            <!-- Section: Klasifikasi -->
            <div class="col-span-2 border-t pt-4 mt-2">
                <h5 class="text-surface-600 font-medium mb-3">Klasifikasi</h5>
            </div>

            <!-- Tipe Customer -->
            <div>
                <label class="block font-medium mb-2">Tipe Customer</label>
                <Select v-model="form.tipe_customer_ulid" :options="tipeCustomerOptions" optionLabel="nama_tipe" optionValue="ulid" placeholder="Pilih tipe customer" fluid filter filterPlaceholder="Cari tipe..." showClear>
                    <template #option="slotProps">
                        <div class="flex items-center gap-2">
                            <span class="text-surface-500">{{ slotProps.option.kode_tipe }}</span>
                            <span>-</span>
                            <span>{{ slotProps.option.nama_tipe }}</span>
                        </div>
                    </template>
                </Select>
            </div>

            <!-- Kategori Customer -->
            <div>
                <label class="block font-medium mb-2">Kategori Customer</label>
                <Select v-model="form.kategori_customer_ulid" :options="kategoriCustomerOptions" optionLabel="nama_kategori" optionValue="ulid" placeholder="Pilih kategori customer" fluid filter filterPlaceholder="Cari kategori..." showClear>
                    <template #option="slotProps">
                        <div class="flex items-center gap-2">
                            <span class="text-surface-500">{{ slotProps.option.kode_kategori }}</span>
                            <span>-</span>
                            <span>{{ slotProps.option.nama_kategori }}</span>
                        </div>
                    </template>
                </Select>
            </div>

            <!-- Section: Status -->
            <div class="col-span-2 border-t pt-4 mt-2">
                <h5 class="text-surface-600 font-medium mb-3">Status</h5>
            </div>

            <!-- Jenis -->
            <div>
                <label class="block font-medium mb-2">Jenis Customer <span class="text-red-500">*</span></label>
                <Select
                    v-model="form.jenis"
                    :options="[
                        { label: 'Walk-in', value: 'walk_in' },
                        { label: 'Spesifik', value: 'spesifik' }
                    ]"
                    optionLabel="label"
                    optionValue="value"
                    :invalid="submitted && !form.jenis"
                    :disabled="isEdit && form.jenis === 'walk_in'"
                    fluid
                    filter
                />
                <small v-if="submitted && !form.jenis" class="text-red-500">Jenis wajib dipilih</small>
                <small v-if="isEdit && form.jenis === 'walk_in'" class="text-surface-500">Walk-in tidak dapat diubah</small>
            </div>

            <!-- Status -->
            <div>
                <label class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                <Select
                    v-model="form.status"
                    :options="[
                        { label: 'Aktif', value: 'active' },
                        { label: 'Nonaktif', value: 'inactive' }
                    ]"
                    optionLabel="label"
                    optionValue="value"
                    :invalid="submitted && !form.status"
                    fluid
                    filter
                />
                <small v-if="submitted && !form.status" class="text-red-500">Status wajib dipilih</small>
            </div>
        </div>

        <template #footer>
            <Button label="Batal" icon="pi pi-times" text @click="close" :disabled="saving" />
            <Button label="Simpan" icon="pi pi-check" @click="save" :loading="saving" />
        </template>
    </Dialog>
</template>
