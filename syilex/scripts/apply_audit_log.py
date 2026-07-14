"""
Apply HasAuditLog trait to critical models.
Idempotent — re-run aman (skip kalau sudah ada).
"""
import os
import re
import glob

os.chdir(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Models yang WAJIB punya audit log — critical untuk accountability
CRITICAL_MODELS = [
    'app/Models/DocPromo.php',
    'app/Models/DocSales.php',
    'app/Models/DocPurchaseOrder.php',
    'app/Models/DocPurchaseReturn.php',
    'app/Models/DocAdjustment.php',
    'app/Models/DocTransfer.php',
    'app/Models/DocStockOpname.php',
    'app/Models/DocHppCorrection.php',
    'app/Models/DocRepack.php',
    'app/Models/DocPembayaranHutang.php',
    'app/Models/DocPriceChange.php',
    'app/Models/MasterProduk.php',
    'app/Models/MasterCustomer.php',
    'app/Models/MasterSupplier.php',
    'app/Models/User.php',
]

applied = 0
skipped = 0

for path in CRITICAL_MODELS:
    if not os.path.exists(path):
        print(f'  MISSING {path}')
        continue

    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    if 'HasAuditLog' in content:
        skipped += 1
        continue

    original = content

    # Find the use statements block near top, add import before the class declaration
    # Pattern: insert `use App\\Traits\\HasAuditLog;` after last `use ` statement
    def add_import(m):
        return m.group(1) + 'use App\\Traits\\HasAuditLog;\n' + m.group(2)

    content = re.sub(
        r'(^use [^\n]+;\n)(\n*class \w+)',
        add_import,
        content,
        count=1,
        flags=re.MULTILINE
    )

    # Find the existing `use Trait1, Trait2, ...;` inside class and append HasAuditLog
    # Match: inside class body, the first `use X, Y;` line
    pattern = r'(class \w+[^{]*\{[^}]*?use\s+)([A-Za-z_][\w, ]*?)(;)'
    def add_trait_to_list(m):
        traits = m.group(2).rstrip()
        if 'HasAuditLog' in traits:
            return m.group(0)  # already present
        return m.group(1) + traits + ', HasAuditLog' + m.group(3)

    content = re.sub(pattern, add_trait_to_list, content, count=1, flags=re.DOTALL)

    if content != original:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f'  OK {path}')
        applied += 1
    else:
        print(f'  NOCHANGE {path}')

print(f'\nApplied: {applied}, Skipped (already has trait): {skipped}')
