#!/usr/bin/env node
/**
 * One-off: apply UsesExportSheetStyles to export classes that still inline styles().
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const exportDir = path.join(__dirname, '../app/Exports');

const styleBlockRe = /\n    public function styles\(Worksheet \$sheet\): array\s*\{\s*return \[\s*1 => \[\s*'font' => \['bold' => true\],\s*'fill' => \[\s*'fillType' => \\PhpOffice\\PhpSpreadsheet\\Style\\Fill::FILL_SOLID,\s*'startColor' => \['argb' => 'FFE2E8F0'\],\s*\],\s*\],\s*\];\s*\}/s;

const files = fs.readdirSync(exportDir)
    .filter((f) => f.endsWith('.php'))
    .map((f) => path.join(exportDir, f));

let updated = 0;

for (const file of files) {
    let content = fs.readFileSync(file, 'utf8');
    if (!content.includes('function styles(Worksheet')) {
        continue;
    }
    if (content.includes('UsesExportSheetStyles') || content.includes('ExportSheetStyles')) {
        continue;
    }

    content = content.replace(styleBlockRe, '');
    content = content.replace(
        "use PhpOffice\\PhpSpreadsheet\\Worksheet\\Worksheet;\n",
        "use App\\Exports\\Concerns\\UsesExportSheetStyles;\n"
    );
    content = content.replace(
        /class (\w+) implements([^{]+)\{/,
        'class $1 implements$2{\n    use UsesExportSheetStyles;\n'
    );

    fs.writeFileSync(file, content);
    updated++;
    console.log('Updated', path.basename(file));
}

console.log(`Done. ${updated} files updated.`);
