<script setup>
import { usersApi } from '@/api';
import { onMounted, ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import ImageUpload from '@/components/common/ImageUpload.vue';
import DataTableHeader from '@/components/common/DataTableHeader.vue';
import DetailDialog from '@/components/common/DetailDialog.vue';
import DetailItem from '@/components/common/DetailItem.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useNotification } from '@/composables/useNotification';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const notify = useNotification();
const { formatDateTime, shouldUppercase } = useFormatters();

// Permissions
const canCreate = computed(() => authStore.can('user.create'));
const canUpdate = computed(() => authStore.can('user.update'));
const canDelete = computed(() => authStore.can('user.delete'));

// Data
const dt = ref();
const users = ref([]);
const roles = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

// Search
const searchQuery = ref('');

// Pagination & Sort
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'created_at',
    sortOrder: -1
});

// Filters
const selectedStatus = ref(null);
const selectedRole = ref(null);
const statusOptions = ref([
    { label: 'Semua Status', value: null },
    { label: 'Aktif', value: 'active' },
    { label: 'Nonaktif', value: 'inactive' }
]);

// Dialog states
const userDialog = ref(false);
const deleteUserDialog = ref(false);
const toggleStatusDialog = ref(false);
const detailDialog = ref(false);
const unassignTerminalsDialog = ref(false);
const pendingTerminals = ref([]);
const pendingSavePayload = ref(null);
const submitted = ref(false);
const saving = ref(false);
const loadingDetail = ref(false);
const detailData = ref({});

// Current user being edited
const user = ref({});
const isEdit = computed(() => !!user.value.ulid);
// PIN yang sudah di-strip dari placeholder chars '______' yang kadang emit-nya
// oleh InputMask. Dipakai untuk validasi + invalid binding di template.
const pinDigits = computed(() => (user.value.pin || '').replace(/\D/g, ''));
// Dirty flag: PIN hanya divalidasi kalau user benar-benar mengetik.
// InputMask kadang emit placeholder '______' otomatis saat mount,
// yang bikin validasi salah trigger meski user tidak sentuh fieldnya.
const pinDirty = ref(false);

// Initial user data structure
const emptyUser = {
    name: '',
    email: '',
    password: '',
    pin: '',
    phone: '',
    role: '',
    status: 'active',
    avatar: ''
};

onMounted(async () => {
    await loadRoles();
    await loadUsers();

    // Check if there's an edit query parameter (from profile menu)
    if (route.query.edit) {
        const editUlid = route.query.edit;
        // Clear the query parameter
        router.replace({ query: {} });
        // Open edit dialog for the specified user
        await openEditByUlid(editUlid);
    }
});

// Watch for route changes (in case user navigates here with edit param)
watch(
    () => route.query.edit,
    async (newEditUlid) => {
        if (newEditUlid) {
            router.replace({ query: {} });
            await openEditByUlid(newEditUlid);
        }
    }
);

async function openEditByUlid(ulid) {
    try {
        const response = await usersApi.get(ulid);
        if (response.data.success) {
            editUser(response.data.data.user);
        }
    } catch (error) {
        notify.error('User tidak ditemukan');
    }
}

async function loadRoles() {
    try {
        const response = await usersApi.getRoles();
        if (response.data.success) {
            roles.value = response.data.data.roles.map((r) => ({
                label: r.name.charAt(0).toUpperCase() + r.name.slice(1).replace(/-/g, ' '),
                value: r.name
            }));
        }
    } catch (error) {
        console.error('Failed to load roles:', error);
        notify.apiError(error, 'Gagal load roles');
    }
}

async function loadUsers() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'created_at',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };

        // Search
        if (searchQuery.value?.trim()) {
            params.search = searchQuery.value.trim();
        }

        // Filter by status
        if (selectedStatus.value) {
            params.status = selectedStatus.value;
        }

        // Filter by role
        if (selectedRole.value) {
            params.role = selectedRole.value;
        }

        const response = await usersApi.getAll(params);
        if (response.data.success) {
            users.value = response.data.data.users;
            totalRecords.value = response.data.data.pagination.total;
        }
    } catch (error) {
        notify.loadListError('user');
    } finally {
        loading.value = false;
    }
}

function onPage(event) {
    lazyParams.value.first = event.first;
    lazyParams.value.rows = event.rows;
    loadUsers();
}

function onSort(event) {
    lazyParams.value.sortField = event.sortField;
    lazyParams.value.sortOrder = event.sortOrder;
    loadUsers();
}

function onFilter() {
    lazyParams.value.first = 0;
    loadUsers();
}

function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadUsers();
}

function doSearch() {
    lazyParams.value.first = 0;
    loadUsers();
}

function openNew() {
    user.value = { ...emptyUser };
    submitted.value = false;
    pinDirty.value = true; // create mode: PIN wajib, selalu validasi
    userDialog.value = true;
}

function hideDialog() {
    userDialog.value = false;
    submitted.value = false;
}

function editUser(userData) {
    user.value = {
        ulid: userData.ulid,
        name: userData.name,
        email: userData.email,
        password: '', // Don't show password
        pin: '', // Don't show PIN
        phone: userData.phone || '',
        role: userData.roles?.[0]?.name || '',
        status: userData.status,
        avatar: userData.avatar_url || ''
    };
    submitted.value = false;
    pinDirty.value = false;
    userDialog.value = true;
}

async function viewDetail(userData) {
    detailDialog.value = true;
    loadingDetail.value = true;

    try {
        const response = await usersApi.get(userData.ulid);
        if (response.data.success) {
            detailData.value = response.data.data.user;
        }
    } catch (error) {
        notify.loadDetailError('user');
        detailDialog.value = false;
    } finally {
        loadingDetail.value = false;
    }
}

async function saveUser() {
    submitted.value = true;

    // Validate required fields
    if (!user.value.name?.trim()) return;
    if (!user.value.email?.trim()) return;
    if (!isEdit.value && !user.value.password?.trim()) return;
    // PIN: hanya validasi kalau user sentuh field (pinDirty=true).
    // InputMask kadang emit placeholder '______' saat mount yang bikin validasi
    // salah trigger meski user tidak sentuh fieldnya.
    const cleanPin = (user.value.pin || '').replace(/\D/g, '');
    if (!isEdit.value && !cleanPin) return;
    if (pinDirty.value && cleanPin && cleanPin.length !== 6) return;
    if (!user.value.phone?.trim()) return;
    if (!user.value.role) return;
    if (!user.value.status) return;

    saving.value = true;

    const data = {
        name: user.value.name.trim(),
        email: user.value.email.trim(),
        phone: user.value.phone.trim(),
        role: user.value.role,
        status: user.value.status,
        avatar: user.value.avatar || null
    };

    // Only include password if provided
    if (user.value.password?.trim()) {
        data.password = user.value.password;
    }

    // Only include PIN if provided (cleanPin from validation above)
    if (cleanPin) {
        data.pin = cleanPin;
    }

    try {
        let response;
        if (isEdit.value) {
            response = await usersApi.update(user.value.ulid, data);
        } else {
            response = await usersApi.create(data);
        }

        if (response.data.success) {
            notify.success(response.data.message);
            userDialog.value = false;

            // If editing own profile, refresh auth store to update header avatar
            if (isEdit.value && user.value.ulid === authStore.user?.ulid) {
                await authStore.fetchUser();
            }

            user.value = {};
            await loadUsers();
        }
    } catch (error) {
        // Backend minta konfirmasi: role baru kehilangan pos.access + user masih
        // ter-assign di terminal. Show dialog → kalau admin setuju, resubmit dengan
        // flag unassign_terminals=true.
        const body = error?.response?.data;
        if (error?.response?.status === 409 && body?.code === 'REQUIRES_UNASSIGN_CONFIRMATION') {
            pendingTerminals.value = body.data?.terminals || [];
            pendingSavePayload.value = data;
            unassignTerminalsDialog.value = true;
        } else {
            notify.saveError(error);
        }
    } finally {
        saving.value = false;
    }
}

async function confirmUnassignAndSave() {
    if (!pendingSavePayload.value) return;
    saving.value = true;
    try {
        const payload = { ...pendingSavePayload.value, unassign_terminals: true };
        const response = await usersApi.update(user.value.ulid, payload);
        if (response.data.success) {
            notify.success(response.data.message);
            unassignTerminalsDialog.value = false;
            userDialog.value = false;
            pendingTerminals.value = [];
            pendingSavePayload.value = null;
            if (user.value.ulid === authStore.user?.ulid) {
                await authStore.fetchUser();
            }
            user.value = {};
            await loadUsers();
        }
    } catch (error) {
        notify.saveError(error);
    } finally {
        saving.value = false;
    }
}

function confirmDeleteUser(userData) {
    user.value = userData;
    deleteUserDialog.value = true;
}

function confirmToggleStatus(userData) {
    user.value = userData;
    toggleStatusDialog.value = true;
}

async function toggleStatus() {
    try {
        const response = await usersApi.toggleStatus(user.value.ulid);
        if (response.data.success) {
            notify.success(response.data.message);
            toggleStatusDialog.value = false;
            user.value = {};
            await loadUsers();
        }
    } catch (error) {
        notify.statusChangeError('user', error);
        toggleStatusDialog.value = false;
    }
}

async function deleteUser() {
    try {
        const response = await usersApi.delete(user.value.ulid);
        if (response.data.success) {
            notify.deleted('user');
            deleteUserDialog.value = false;
            user.value = {};
            await loadUsers();
        }
    } catch (error) {
        notify.deleteError(error);
        deleteUserDialog.value = false;
    }
}

function getStatusSeverity(status) {
    return status === 'active' ? 'success' : 'danger';
}

function getStatusLabel(status) {
    return status === 'active' ? 'Aktif' : 'Nonaktif';
}

function getRoleBadge(userData) {
    const roleName = userData.roles?.[0]?.name;
    if (!roleName) return { label: '-', severity: 'secondary' };

    const roleLabels = {
        'super-admin': { label: 'Super Admin', severity: 'danger' },
        admin: { label: 'Admin', severity: 'warn' },
        kasir: { label: 'Kasir', severity: 'info' },
        gudang: { label: 'Gudang', severity: 'success' }
    };

    return roleLabels[roleName] || { label: roleName, severity: 'secondary' };
}

// Check if user is self
function isSelf(userData) {
    return authStore.user?.ulid === userData.ulid;
}

// Check if user can be deleted
function canDeleteUser(userData) {
    // Can't delete self
    return !isSelf(userData);
}

// Check if user status can be toggled
function canToggleStatus(userData) {
    // Can't toggle self
    return !isSelf(userData);
}

// Reset all filters
function resetFilters() {
    selectedStatus.value = null;
    selectedRole.value = null;
    lazyParams.value.first = 0;
    loadUsers();
}
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah User" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>

                <template #end>
                    <div class="flex gap-2">
                        <Select v-model="selectedStatus" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Filter Status" class="w-40" filter filterPlaceholder="Cari..." @change="onFilter" />
                        <Select v-model="selectedRole" :options="[{ label: 'Semua Role', value: null }, ...roles]" optionLabel="label" optionValue="value" placeholder="Filter Role" class="w-40" filter filterPlaceholder="Cari..." @change="onFilter" />
                        <Button label="Reset" icon="pi pi-filter-slash" severity="secondary" outlined @click="resetFilters" />
                    </div>
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="users"
                :lazy="true"
                :paginator="true"
                :rows="lazyParams.rows"
                :totalRecords="totalRecords"
                :loading="loading"
                :rowsPerPageOptions="[10, 25, 50]"
                :first="lazyParams.first"
                dataKey="ulid"
                @page="onPage"
                @sort="onSort"
                stripedRows
                showGridlines
                scrollable
            >
                <template #header>
                    <DataTableHeader v-model="searchQuery" title="Manajemen User" placeholder="Cari nama, email, telepon..." @search="doSearch" @clear="clearSearch" />
                </template>

                <template #empty>
                    <div class="text-center py-4">
                        <i class="pi pi-users text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data user</p>
                    </div>
                </template>

                <Column header="Avatar" style="width: 80px">
                    <template #body="slotProps">
                        <img v-if="slotProps.data.avatar_url" :src="slotProps.data.avatar_url" :alt="slotProps.data.name" class="rounded-full w-10 h-10 object-cover" />
                        <div v-else class="rounded-full w-10 h-10 bg-surface-200 flex items-center justify-center">
                            <i class="pi pi-user text-surface-500"></i>
                        </div>
                    </template>
                </Column>
                <Column field="name" header="Nama" sortable style="min-width: 150px"></Column>
                <Column field="email" header="Email" sortable style="min-width: 200px"></Column>
                <Column field="phone" header="Telepon" style="min-width: 130px">
                    <template #body="slotProps">
                        {{ slotProps.data.phone || '-' }}
                    </template>
                </Column>
                <Column header="Role" style="min-width: 120px">
                    <template #body="slotProps">
                        <Tag :value="getRoleBadge(slotProps.data).label" :severity="getRoleBadge(slotProps.data).severity" />
                    </template>
                </Column>
                <Column field="status" header="Status" sortable style="min-width: 100px">
                    <template #body="slotProps">
                        <Tag :value="getStatusLabel(slotProps.data.status)" :severity="getStatusSeverity(slotProps.data.status)" />
                    </template>
                </Column>
                <Column header="Login Terakhir" style="min-width: 160px">
                    <template #body="slotProps">
                        {{ formatDateTime(slotProps.data.last_login_at) }}
                    </template>
                </Column>
                <Column :exportable="false" style="min-width: 220px" alignFrozen="right" frozen>
                    <template #body="slotProps">
                        <Button icon="pi pi-eye" outlined rounded class="mr-2" severity="info" @click="viewDetail(slotProps.data)" v-tooltip.top="'Lihat Detail'" />
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editUser(slotProps.data)" v-tooltip.top="'Edit'" />
                        <Button
                            v-if="canUpdate"
                            :icon="slotProps.data.status === 'active' ? 'pi pi-ban' : 'pi pi-check-circle'"
                            outlined
                            rounded
                            class="mr-2"
                            :severity="slotProps.data.status === 'active' ? 'warn' : 'success'"
                            :disabled="!canToggleStatus(slotProps.data)"
                            @click="confirmToggleStatus(slotProps.data)"
                            v-tooltip.top="slotProps.data.status === 'active' ? 'Nonaktifkan' : 'Aktifkan'"
                        />
                        <Button v-if="canDelete" icon="pi pi-trash" outlined rounded severity="danger" :disabled="!canDeleteUser(slotProps.data)" @click="confirmDeleteUser(slotProps.data)" v-tooltip.top="'Hapus'" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- User Dialog -->
        <Dialog v-model:visible="userDialog" :style="{ width: '500px' }" :header="isEdit ? 'Edit User' : 'Tambah User'" :modal="true" :closable="!saving">
            <div class="flex flex-col gap-4">
                <!-- Avatar -->
                <div class="flex justify-center">
                    <ImageUpload v-model="user.avatar" folder="avatars" label="Avatar" :previewWidth="'100px'" :previewHeight="'100px'" :maxSize="1024" />
                </div>

                <!-- Name -->
                <div>
                    <label class="block font-medium mb-2">Nama <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="user.name" :invalid="submitted && !user.name" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nama" autocomplete="off" name="new-user-name" />
                    <small v-if="submitted && !user.name" class="text-red-500">Nama wajib diisi</small>
                </div>

                <!-- Email -->
                <div>
                    <label class="block font-medium mb-2">Email <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="user.email" type="email" :invalid="submitted && !user.email" fluid placeholder="Masukkan email" autocomplete="off" name="new-user-email" />
                    <small v-if="submitted && !user.email" class="text-red-500">Email wajib diisi</small>
                </div>

                <!-- Password -->
                <div>
                    <label class="block font-medium mb-2">
                        Password
                        <span v-if="!isEdit" class="text-red-500">*</span>
                        <span v-else class="text-surface-500 text-sm">(kosongkan jika tidak diubah)</span>
                    </label>
                    <Password
                        v-model="user.password"
                        :invalid="submitted && !isEdit && !user.password"
                        fluid
                        toggleMask
                        :feedback="false"
                        placeholder="Masukkan password"
                        :pt="{
                            pcInputText: {
                                root: {
                                    autocomplete: 'new-password',
                                    name: 'new-user-pwd'
                                }
                            }
                        }"
                    />
                    <small v-if="submitted && !isEdit && !user.password" class="text-red-500">Password wajib diisi</small>
                    <small v-else class="text-surface-500">Minimal 8 karakter dengan angka</small>
                </div>

                <!-- PIN -->
                <div>
                    <label class="block font-medium mb-2">
                        PIN Terminal
                        <span v-if="!isEdit" class="text-red-500">*</span>
                        <span v-else class="text-surface-500 text-sm">(kosongkan jika tidak diubah)</span>
                    </label>
                    <InputMask v-model="user.pin" mask="999999" :unmask="true" :invalid="submitted && ((!isEdit && !pinDigits) || (pinDirty && pinDigits && pinDigits.length !== 6))" fluid placeholder="______" @input="pinDirty = true" />
                    <small v-if="submitted && !isEdit && !pinDigits" class="text-red-500">PIN wajib diisi</small>
                    <small v-else-if="submitted && pinDirty && pinDigits && pinDigits.length !== 6" class="text-red-500">PIN harus 6 digit angka</small>
                    <small v-else class="text-surface-500">6 digit PIN untuk unlock terminal kasir</small>
                </div>

                <!-- Phone -->
                <div>
                    <label class="block font-medium mb-2">Telepon <span class="text-red-500">*</span></label>
                    <InputText v-model.trim="user.phone" :invalid="submitted && !user.phone" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" fluid placeholder="Masukkan nomor telepon" />
                    <small v-if="submitted && !user.phone" class="text-red-500">Telepon wajib diisi</small>
                </div>

                <!-- Role -->
                <div>
                    <label for="role" class="block font-medium mb-2">Role <span class="text-red-500">*</span></label>
                    <Select id="role" v-model="user.role" :options="roles" optionLabel="label" optionValue="value" :invalid="submitted && !user.role" fluid filter filterPlaceholder="Cari..." placeholder="Pilih role" />
                    <small v-if="submitted && !user.role" class="text-red-500">Role wajib dipilih</small>
                </div>

                <!-- Status -->
                <div>
                    <label for="status" class="block font-medium mb-2">Status <span class="text-red-500">*</span></label>
                    <Select
                        id="status"
                        v-model="user.status"
                        :options="[
                            { label: 'Aktif', value: 'active' },
                            { label: 'Nonaktif', value: 'inactive' }
                        ]"
                        optionLabel="label"
                        optionValue="value"
                        :invalid="submitted && !user.status"
                        fluid
                        filter
                        filterPlaceholder="Cari..."
                    />
                    <small v-if="submitted && !user.status" class="text-red-500">Status wajib dipilih</small>
                </div>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveUser" :loading="saving" />
            </template>
        </Dialog>

        <!-- Delete Confirmation Dialog -->
        <Dialog v-model:visible="deleteUserDialog" :style="{ width: '450px' }" header="Konfirmasi Hapus" :modal="true">
            <div class="flex items-center gap-4">
                <i class="pi pi-exclamation-triangle text-3xl text-orange-500" />
                <span v-if="user">
                    Apakah Anda yakin ingin menghapus user <b>{{ user.name }}</b
                    >?
                </span>
            </div>
            <template #footer>
                <Button label="Tidak" icon="pi pi-times" text @click="deleteUserDialog = false" />
                <Button label="Ya, Hapus" icon="pi pi-check" severity="danger" @click="deleteUser" />
            </template>
        </Dialog>

        <!-- Toggle Status Confirmation Dialog -->
        <Dialog v-model:visible="toggleStatusDialog" :style="{ width: '450px' }" :header="user.status === 'active' ? 'Konfirmasi Nonaktifkan' : 'Konfirmasi Aktifkan'" :modal="true">
            <div class="flex items-center gap-4">
                <i :class="['pi text-3xl', user.status === 'active' ? 'pi-ban text-orange-500' : 'pi-check-circle text-green-500']" />
                <span v-if="user">
                    <template v-if="user.status === 'active'">
                        Apakah Anda yakin ingin menonaktifkan user <b>{{ user.name }}</b
                        >? <br /><small class="text-surface-500">User akan langsung logout jika sedang login.</small>
                    </template>
                    <template v-else>
                        Apakah Anda yakin ingin mengaktifkan user <b>{{ user.name }}</b
                        >?
                    </template>
                </span>
            </div>
            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="toggleStatusDialog = false" />
                <Button :label="user.status === 'active' ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan'" icon="pi pi-check" :severity="user.status === 'active' ? 'warn' : 'success'" @click="toggleStatus" />
            </template>
        </Dialog>

        <!-- Unassign Terminals Confirmation Dialog -->
        <Dialog v-model:visible="unassignTerminalsDialog" :style="{ width: '480px' }" header="Konfirmasi Pencabutan Akses POS" :modal="true">
            <div class="flex items-start gap-4">
                <i class="pi pi-exclamation-triangle text-3xl text-orange-500 mt-1" />
                <div class="flex-1">
                    <p class="mb-3">
                        Role baru <b>tidak memiliki</b> akses POS, tapi user <b>{{ user.name }}</b> masih ter-assign di terminal berikut:
                    </p>
                    <ul class="list-disc pl-5 mb-3 text-surface-700 dark:text-surface-300">
                        <li v-for="t in pendingTerminals" :key="t.id">{{ t.nama_terminal }}</li>
                    </ul>
                    <p class="text-sm text-surface-500">Jika dilanjutkan, user akan otomatis di-unassign dari terminal-terminal tersebut.</p>
                </div>
            </div>
            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="unassignTerminalsDialog = false" />
                <Button label="Lanjutkan & Unassign" icon="pi pi-check" severity="warn" :loading="saving" @click="confirmUnassignAndSave" />
            </template>
        </Dialog>

        <!-- Detail Dialog -->
        <DetailDialog v-model:visible="detailDialog" title="Detail User" :loading="loadingDetail" :created-at="detailData.created_at" :updated-at="detailData.updated_at">
            <template #content>
                <!-- Avatar -->
                <div v-if="detailData.avatar_url" class="flex justify-center mb-4">
                    <img :src="detailData.avatar_url" :alt="detailData.name" class="w-20 h-20 rounded-full object-cover border-2 border-surface-200" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <DetailItem label="Nama" :value="detailData.name" />
                    <DetailItem label="Email" :value="detailData.email" />
                    <DetailItem label="No. Telepon" :value="detailData.phone" />
                    <DetailItem label="Role" :value="getRoleBadge(detailData).label" type="badge" :badge-severity="getRoleBadge(detailData).severity" />
                    <DetailItem label="Status" :value="getStatusLabel(detailData.status)" type="badge" :badge-severity="getStatusSeverity(detailData.status)" />
                    <DetailItem label="Login Terakhir" :value="detailData.last_login_at" type="datetime" />
                </div>
            </template>
        </DetailDialog>
    </div>
</template>
