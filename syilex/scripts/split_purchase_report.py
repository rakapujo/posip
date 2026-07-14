"""
Split PurchaseReportController (1057 lines) jadi 6 controllers kecil.
Preserve behavior: extract method ranges dari source file, wrap di class baru.
"""
import os
import re

os.chdir(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

SOURCE = 'app/Http/Controllers/Api/V1/PurchaseReportController.php'
OUT_DIR = 'app/Http/Controllers/Api/V1/PurchaseReport'

# Read source
with open(SOURCE, 'r', encoding='utf-8') as f:
    source = f.read()
lines = source.split('\n')

# Find method boundaries: scan for "public function X" lines
def find_method_ranges():
    """Return dict: method_name -> (start_line, end_line) inclusive 0-indexed."""
    methods = {}
    method_starts = []
    for i, line in enumerate(lines):
        m = re.match(r'\s+public function (\w+)\s*\(', line)
        if m:
            method_starts.append((m.group(1), i))

    # End of each method is line before next method OR class closing brace
    for idx, (name, start) in enumerate(method_starts):
        # Walk back to find docblock start (if any)
        doc_start = start
        j = start - 1
        while j >= 0 and (lines[j].strip().startswith('*') or lines[j].strip() == '' or lines[j].strip() == '/**'):
            if lines[j].strip().startswith('/**'):
                doc_start = j
                break
            j -= 1

        # End = start of next method minus 1 (trimming trailing blank), OR end of class
        if idx + 1 < len(method_starts):
            next_start = method_starts[idx + 1][1]
            # Walk back to skip blanks + docblock of next method
            end = next_start - 1
            while end > start and (lines[end].strip() == '' or lines[end].strip().startswith('*') or lines[end].strip().startswith('/**')):
                end -= 1
        else:
            # Last method — end at class closing brace
            end = len(lines) - 1
            while end > start and lines[end].strip() != '}':
                end -= 1
            end -= 1  # skip the class closing brace itself

        methods[name] = (doc_start, end)
    return methods

methods = find_method_ranges()

def extract_method(name):
    start, end = methods[name]
    return '\n'.join(lines[start:end + 1])

# Common imports for all new controllers
COMMON_IMPORTS = '''use App\\Exports\\PurchaseDiskonExport;
use App\\Exports\\PurchaseHargaTerakhirExport;
use App\\Exports\\PurchasePerBarangExport;
use App\\Exports\\PurchasePerDokumenExport;
use App\\Exports\\PurchasePerSupplierExport;
use App\\Http\\Controllers\\Api\\BaseApiController;
use App\\Models\\DocPurchaseOrder;
use App\\Models\\MasterBrand;
use App\\Models\\MasterKategori;
use App\\Models\\MasterSupplier;
use App\\Models\\MasterWarehouse;
use App\\Services\\ReportHelperService;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Maatwebsite\\Excel\\Facades\\Excel;'''

def make_controller(class_name: str, method_names: list, description: str):
    method_bodies = '\n\n'.join(extract_method(m) for m in method_names)
    content = f'''<?php

namespace App\\Http\\Controllers\\Api\\V1\\PurchaseReport;

{COMMON_IMPORTS}

/**
 * {description}
 * Split dari PurchaseReportController (W3 refactor).
 */
class {class_name} extends BaseApiController
{{
{method_bodies}
}}
'''
    path = os.path.join(OUT_DIR, f'{class_name}.php')
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print(f'  Created {path}')

# Build 6 new controllers
make_controller(
    'DiskonReportController',
    ['diskon', 'exportDiskon'],
    'Laporan Diskon Pembelian — per-PO with 3-level header discounts.'
)

make_controller(
    'PerDokumenReportController',
    ['perDokumen', 'showPo', 'exportPerDokumen'],
    'Laporan Pembelian Per Dokumen — paginated list of approved POs + detail.'
)

make_controller(
    'PerSupplierReportController',
    ['perSupplier', 'showSupplier', 'exportPerSupplier'],
    'Laporan Pembelian Per Supplier — aggregated by supplier.'
)

make_controller(
    'PerBarangReportController',
    ['perBarang', 'showBarang', 'exportPerBarang'],
    'Laporan Pembelian Per Barang — aggregated by product.'
)

make_controller(
    'HargaTerakhirReportController',
    ['hargaTerakhir', 'exportHargaTerakhir'],
    'Laporan Harga Terakhir Pembelian — latest price per product.'
)

make_controller(
    'DropdownsController',
    ['dropdowns'],
    'Shared dropdown data untuk filter laporan pembelian.'
)

print('\nDone.')
