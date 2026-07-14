"""
Generator untuk Policy class per resource.
Pakai template dari MasterProdukPolicy.
"""
import os
import textwrap

os.chdir(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# (ModelClass, PolicyName, permission_prefix, has_approve_workflow?)
RESOURCES = [
    # Master Data
    ('MasterBrand', 'brand'),
    ('MasterTipe', 'tipe'),
    ('MasterKategori', 'kategori'),
    ('MasterGrup', 'grup'),
    ('MasterSupplier', 'supplier'),
    ('MasterCustomer', 'customer'),
    ('MasterTipeCustomer', 'tipe-customer'),
    ('MasterKategoriCustomer', 'kategori-customer'),
    ('MasterWarehouse', 'warehouse'),
    ('MasterMetodePembayaran', 'metode-pembayaran'),
    ('MasterPosTerminal', 'terminal'),
    # Documents
    ('DocSales', 'sales'),
    ('DocAdjustment', 'adjustment'),
    ('DocTransfer', 'transfer'),
    ('DocRepack', 'repack'),
    ('DocStockOpname', 'stock-opname'),
    ('DocHppCorrection', 'hpp-correction'),
    ('DocPurchaseOrder', 'purchase-order'),
    ('DocPurchaseReturn', 'purchase-return'),
    ('DocPembayaranHutang', 'pembayaran-hutang'),
    ('DocPriceChange', 'price-change'),
    ('DocPromo', 'promo'),
    # User/Role
    ('User', 'user'),
]

TEMPLATE = '''<?php

namespace App\\Policies;

use App\\Models\\{model};
use App\\Models\\User;

/**
 * Policy untuk resource {model}.
 * Auto-generated dari template MasterProdukPolicy.
 * Customize method spesifik (view/update/delete) jika butuh ownership/scope check.
 */
class {policy_name}
{{
    /**
     * Super admin bypass semua check.
     */
    public function before(User $user, string $ability): ?bool
    {{
        if ($user->hasRole('super-admin')) {{
            return true;
        }}
        return null;
    }}

    public function viewAny(User $user): bool
    {{
        return $user->can('{perm}.view');
    }}

    public function view(User $user, {model} ${model_var}): bool
    {{
        return $user->can('{perm}.view');
    }}

    public function create(User $user): bool
    {{
        return $user->can('{perm}.create');
    }}

    public function update(User $user, {model} ${model_var}): bool
    {{
        return $user->can('{perm}.update');
    }}

    public function delete(User $user, {model} ${model_var}): bool
    {{
        return $user->can('{perm}.delete');
    }}
}}
'''

generated = 0
skipped = 0
for model, perm in RESOURCES:
    policy_name = f'{model}Policy'
    path = f'app/Policies/{policy_name}.php'

    if os.path.exists(path):
        print(f'  SKIP {policy_name} (sudah ada)')
        skipped += 1
        continue

    # Convert model name to lowercase var name
    # e.g., MasterProduk → masterProduk, DocSales → docSales
    model_var = model[0].lower() + model[1:]

    content = TEMPLATE.format(
        model=model,
        policy_name=policy_name,
        perm=perm,
        model_var=model_var,
    )

    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print(f'  OK {policy_name} (perm: {perm}.*)')
    generated += 1

print(f'\nGenerated: {generated}, Skipped: {skipped}')
