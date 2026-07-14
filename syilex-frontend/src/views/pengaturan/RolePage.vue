<script setup>
import { ref, computed, onMounted } from 'vue';
import { rolesApi } from '@/api';
import { useAuthStore } from '@/stores/auth';
import { useConfirm } from 'primevue/useconfirm';
import { useNotification } from '@/composables/useNotification';
import DataTableHeader from '@/components/common/DataTableHeader.vue';

const authStore = useAuthStore();
const confirm = useConfirm();
const notify = useNotification();

// Permission checks
const canCreate = computed(() => authStore.can('role.create'));
const canUpdate = computed(() => authStore.can('role.update'));
const canDelete = computed(() => authStore.can('role.delete'));

// Data
const dt = ref();
const roles = ref([]);
const loading = ref(false);
const totalRecords = ref(0);

// Search
const searchQuery = ref('');

// Pagination & Sort
const lazyParams = ref({
    first: 0,
    rows: 10,
    sortField: 'name',
    sortOrder: 1
});

// Dialog states
const roleDialog = ref(false);
const submitted = ref(false);
const saving = ref(false);

// Role form
const role = ref({ name: '', permissions: [] });
const isEdit = computed(() => !!role.value.id);

// Permission matrix data (from API)
const permissionGroups = ref([]);
const allPermissionNames = ref([]);
const loadingPermissions = ref(false);

// Selected permissions (reactive Set workaround)
const selectedPermissions = ref(new Set());

// Column labels for display
const columnLabels = {
    view: 'View',
    create: 'Create',
    update: 'Update',
    edit: 'Update',
    delete: 'Delete',
    approve: 'Approve',
    reset: 'Reset Database',
    manage: 'Manage',
    master: 'Import Master',
    view_hpp: 'View HPP',
    view_harga: 'View Harga',
    view_nominal: 'View Nominal',
    lock: 'Lock',
    apply: 'Apply',
    complete: 'Complete',
    toggle: 'Toggle',
    'toggle-status': 'Toggle',
    'force-release': 'Force Release',
    'print-barcode': 'Print Barcode',
    access: 'Access',
    discount: 'Discount',
    void: 'Void',
    retur: 'Retur',
    export: 'Export',
    // Laporan — akses per-kategori
    penjualan: 'Lap. Penjualan',
    pembelian: 'Lap. Pembelian',
    keuangan: 'Lap. Keuangan',
    performa: 'Lap. Performa',
    promo: 'Lap. Promo',
    inventory: 'Lap. Inventory'
};

// Computed: selected count
const selectedCount = computed(() => selectedPermissions.value.size);
const totalCount = computed(() => allPermissionNames.value.length);

// Load roles
async function loadRoles() {
    loading.value = true;
    try {
        const params = {
            page: Math.floor(lazyParams.value.first / lazyParams.value.rows) + 1,
            per_page: lazyParams.value.rows,
            sort_field: lazyParams.value.sortField || 'name',
            sort_order: lazyParams.value.sortOrder === 1 ? 'asc' : 'desc'
        };
        if (searchQuery.value) params.search = searchQuery.value;

        const response = await rolesApi.getAll(params);
        if (response.data.success) {
            roles.value = response.data.data.roles;
            totalRecords.value = response.data.data.pagination.total;
        }
    } catch (error) {
        notify.loadListError('role');
    } finally {
        loading.value = false;
    }
}

// Load permissions for matrix
async function loadPermissions() {
    if (permissionGroups.value.length > 0) return; // Already loaded
    loadingPermissions.value = true;
    try {
        const response = await rolesApi.getPermissions();
        if (response.data.success) {
            permissionGroups.value = response.data.data.groups;
            allPermissionNames.value = response.data.data.all_permissions;
        }
    } catch (error) {
        notify.loadDropdownError('permission');
    } finally {
        loadingPermissions.value = false;
    }
}

// Pagination & Sort handlers
function onPage(event) {
    lazyParams.value.first = event.first;
    lazyParams.value.rows = event.rows;
    loadRoles();
}

function onSort(event) {
    lazyParams.value.sortField = event.sortField;
    lazyParams.value.sortOrder = event.sortOrder;
    loadRoles();
}

function doSearch() {
    lazyParams.value.first = 0;
    loadRoles();
}

function clearSearch() {
    searchQuery.value = '';
    lazyParams.value.first = 0;
    loadRoles();
}

// Dialog management
async function openNew() {
    role.value = { name: '', permissions: [] };
    selectedPermissions.value = new Set();
    submitted.value = false;
    await loadPermissions();
    roleDialog.value = true;
}

async function editRole(data) {
    await loadPermissions();
    try {
        const response = await rolesApi.get(data.id);
        if (response.data.success) {
            const roleData = response.data.data.role;
            role.value = {
                id: roleData.id,
                name: roleData.name,
                permissions: roleData.permissions
            };
            selectedPermissions.value = new Set(roleData.permissions);
            submitted.value = false;
            roleDialog.value = true;
        }
    } catch (error) {
        notify.loadDetailError('role');
    }
}

function hideDialog() {
    roleDialog.value = false;
    submitted.value = false;
}

// Save role
async function saveRole() {
    submitted.value = true;

    if (!role.value.name?.trim()) return;
    if (selectedPermissions.value.size === 0) {
        notify.warn('Pilih minimal 1 permission');
        return;
    }

    saving.value = true;
    try {
        const payload = {
            name: role.value.name.trim(),
            permissions: Array.from(selectedPermissions.value)
        };

        if (isEdit.value) {
            await rolesApi.update(role.value.id, payload);
            notify.saveSuccess('Role', true);
        } else {
            await rolesApi.create(payload);
            notify.saveSuccess('Role', false);
        }

        roleDialog.value = false;
        loadRoles();
    } catch (error) {
        notify.saveError(error);
    } finally {
        saving.value = false;
    }
}

// Delete role
function confirmDeleteRole(data) {
    confirm.require({
        message: `Apakah Anda yakin ingin menghapus role "${data.name}"?`,
        header: 'Konfirmasi Hapus',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Batal',
        acceptLabel: 'Hapus',
        rejectProps: { severity: 'secondary', text: true },
        acceptProps: { severity: 'danger' },
        accept: async () => {
            try {
                await rolesApi.delete(data.id);
                notify.deleted('Role');
                loadRoles();
            } catch (error) {
                notify.deleteError(error);
            }
        }
    });
}

// Permission toggle helpers
function togglePermission(permName) {
    const next = new Set(selectedPermissions.value);
    if (next.has(permName)) {
        next.delete(permName);
    } else {
        next.add(permName);
    }
    selectedPermissions.value = next;
}

function toggleModulePermissions(mod) {
    const permNames = Object.values(mod.permissions);
    const allSelected = permNames.every((p) => selectedPermissions.value.has(p));
    const next = new Set(selectedPermissions.value);
    if (allSelected) {
        permNames.forEach((p) => next.delete(p));
    } else {
        permNames.forEach((p) => next.add(p));
    }
    selectedPermissions.value = next;
}

function toggleGroupPermissions(group) {
    const permNames = group.modules.flatMap((m) => Object.values(m.permissions));
    const allSelected = permNames.every((p) => selectedPermissions.value.has(p));
    const next = new Set(selectedPermissions.value);
    if (allSelected) {
        permNames.forEach((p) => next.delete(p));
    } else {
        permNames.forEach((p) => next.add(p));
    }
    selectedPermissions.value = next;
}

function selectAll() {
    selectedPermissions.value = new Set(allPermissionNames.value);
}

function clearAll() {
    selectedPermissions.value = new Set();
}

// Check if group header checkbox is checked/indeterminate
function isGroupAllSelected(group) {
    const permNames = group.modules.flatMap((m) => Object.values(m.permissions));
    return permNames.length > 0 && permNames.every((p) => selectedPermissions.value.has(p));
}

function isGroupIndeterminate(group) {
    const permNames = group.modules.flatMap((m) => Object.values(m.permissions));
    const some = permNames.some((p) => selectedPermissions.value.has(p));
    const all = permNames.every((p) => selectedPermissions.value.has(p));
    return some && !all;
}

// Check if module row is all selected / indeterminate
function isModuleAllSelected(mod) {
    const permNames = Object.values(mod.permissions);
    return permNames.length > 0 && permNames.every((p) => selectedPermissions.value.has(p));
}

function isModuleIndeterminate(mod) {
    const permNames = Object.values(mod.permissions);
    const some = permNames.some((p) => selectedPermissions.value.has(p));
    const all = permNames.every((p) => selectedPermissions.value.has(p));
    return some && !all;
}

// Is super-admin role
const isSuperAdmin = computed(() => isEdit.value && role.value.name === 'super-admin');

onMounted(() => {
    loadRoles();
});
</script>

<template>
    <div>
        <div class="card">
            <Toolbar class="mb-6">
                <template #start>
                    <Button v-if="canCreate" label="Tambah Role" icon="pi pi-plus" severity="primary" @click="openNew" />
                </template>
            </Toolbar>

            <DataTable
                ref="dt"
                :value="roles"
                :lazy="true"
                :paginator="true"
                :rows="lazyParams.rows"
                :totalRecords="totalRecords"
                :loading="loading"
                :rowsPerPageOptions="[10, 25, 50]"
                :first="lazyParams.first"
                dataKey="id"
                @page="onPage"
                @sort="onSort"
                stripedRows
                showGridlines
                scrollable
            >
                <template #header>
                    <DataTableHeader v-model="searchQuery" title="Role & Permission" placeholder="Cari nama role..." @search="doSearch" @clear="clearSearch" />
                </template>

                <template #empty>
                    <div class="text-center py-4">
                        <i class="pi pi-lock text-4xl text-surface-400 mb-2"></i>
                        <p class="text-surface-500">Tidak ada data role</p>
                    </div>
                </template>

                <Column field="name" header="Nama Role" sortable style="min-width: 200px">
                    <template #body="slotProps">
                        <span class="font-medium">{{ slotProps.data.name }}</span>
                    </template>
                </Column>
                <Column field="users_count" header="Jumlah User" style="min-width: 130px">
                    <template #body="slotProps">
                        <Tag :value="`${slotProps.data.users_count} user`" severity="info" />
                    </template>
                </Column>
                <Column :exportable="false" header="Aksi" style="min-width: 180px" alignFrozen="right" frozen>
                    <template #body="slotProps">
                        <Button v-if="canUpdate" icon="pi pi-pencil" outlined rounded class="mr-2" @click="editRole(slotProps.data)" v-tooltip.top="'Edit'" />
                        <Button v-if="canDelete" icon="pi pi-trash" outlined rounded severity="danger" @click="confirmDeleteRole(slotProps.data)" v-tooltip.top="'Hapus'" :disabled="slotProps.data.name === 'super-admin'" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Role Dialog with Permission Matrix -->
        <Dialog v-model:visible="roleDialog" :style="{ width: '900px' }" :header="isEdit ? 'Edit Role' : 'Tambah Role'" :modal="true" :closable="!saving">
            <div class="flex flex-col gap-4">
                <!-- Nama Role -->
                <div>
                    <label class="block font-medium mb-2"> Nama Role <span class="text-red-500">*</span> </label>
                    <InputText v-model.trim="role.name" :invalid="submitted && !role.name" fluid placeholder="contoh: supervisor" autocomplete="off" style="text-transform: lowercase" :disabled="isSuperAdmin" />
                    <small v-if="submitted && !role.name" class="text-red-500">Nama role wajib diisi</small>
                    <small v-else class="text-surface-500">Huruf kecil, angka, dan tanda hubung (-) saja</small>
                </div>

                <!-- Super-admin notice -->
                <Message v-if="isSuperAdmin" severity="info" :closable="false"> Role super-admin selalu memiliki semua permission. Perubahan permission akan di-override oleh sistem. </Message>

                <!-- Bulk actions bar -->
                <div class="flex items-center justify-between bg-surface-50 dark:bg-surface-800 rounded-lg px-4 py-3">
                    <span class="text-sm font-medium"> {{ selectedCount }} / {{ totalCount }} permission dipilih </span>
                    <div class="flex gap-2">
                        <Button label="Pilih Semua" size="small" severity="secondary" outlined @click="selectAll" :disabled="isSuperAdmin" />
                        <Button label="Hapus Semua" size="small" severity="secondary" outlined @click="clearAll" :disabled="isSuperAdmin" />
                    </div>
                </div>

                <!-- Permission Matrix — card-based vertical layout (no horizontal scroll) -->
                <div v-if="permissionGroups.length > 0" class="space-y-3">
                    <div v-for="group in permissionGroups" :key="group.label" class="border border-surface-200 dark:border-surface-700 rounded-lg overflow-hidden">
                        <!-- Group Header -->
                        <div class="flex items-center gap-2 px-3 py-2.5 bg-surface-100 dark:bg-surface-800 cursor-pointer select-none hover:bg-surface-200 dark:hover:bg-surface-700" @click="toggleGroupPermissions(group)">
                            <Checkbox :modelValue="isGroupAllSelected(group)" :binary="true" :indeterminate="isGroupIndeterminate(group)" @click.stop="toggleGroupPermissions(group)" :disabled="isSuperAdmin" />
                            <i class="pi pi-folder text-primary text-sm"></i>
                            <span class="font-semibold text-primary">{{ group.label }}</span>
                        </div>

                        <!-- Modules inside group -->
                        <div class="divide-y divide-surface-200 dark:divide-surface-700">
                            <div v-for="mod in group.modules" :key="mod.prefix" class="px-3 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-800/50">
                                <!-- Module Name (toggle all) -->
                                <div class="flex items-center gap-2 mb-2 cursor-pointer select-none" @click="toggleModulePermissions(mod)">
                                    <Checkbox :modelValue="isModuleAllSelected(mod)" :binary="true" :indeterminate="isModuleIndeterminate(mod)" @click.stop="toggleModulePermissions(mod)" :disabled="isSuperAdmin" />
                                    <span class="font-medium text-sm">{{ mod.label }}</span>
                                </div>

                                <!-- Permission chips — inline labeled checkboxes, wrap naturally -->
                                <div class="flex flex-wrap gap-2 pl-7">
                                    <template v-for="(permName, action) in mod.permissions" :key="action">
                                        <label
                                            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-sm cursor-pointer select-none transition-colors whitespace-nowrap"
                                            :class="
                                                selectedPermissions.has(permName)
                                                    ? 'bg-primary/10 text-primary border border-primary/30 font-medium'
                                                    : 'bg-surface-50 dark:bg-surface-700 text-surface-600 dark:text-surface-300 border border-surface-200 dark:border-surface-600'
                                            "
                                        >
                                            <Checkbox :modelValue="selectedPermissions.has(permName)" :binary="true" @update:modelValue="togglePermission(permName)" :disabled="isSuperAdmin" />
                                            {{ columnLabels[action] || action }}
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading state for permissions -->
                <div v-else-if="loadingPermissions" class="flex items-center justify-center py-8">
                    <ProgressSpinner style="width: 40px; height: 40px" />
                </div>

                <!-- Validation message -->
                <small v-if="submitted && selectedPermissions.size === 0" class="text-red-500"> Pilih minimal 1 permission </small>
            </div>

            <template #footer>
                <Button label="Batal" icon="pi pi-times" text @click="hideDialog" :disabled="saving" />
                <Button label="Simpan" icon="pi pi-check" @click="saveRole" :loading="saving" />
            </template>
        </Dialog>
    </div>
</template>
