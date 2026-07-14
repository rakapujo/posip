#!/usr/bin/env node
/**
 * One-off: convert PHPUnit doc-comment metadata (@test) to #[Test] attributes.
 */
import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const testsDir = path.resolve('tests');

async function walk(dir) {
    const entries = await readdir(dir, { withFileTypes: true });
    const files = [];
    for (const entry of entries) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            files.push(...(await walk(full)));
        } else if (entry.name.endsWith('Test.php')) {
            files.push(full);
        }
    }
    return files;
}

function migrateContent(content) {
    if (!content.includes('@test')) {
        return { content, changed: false };
    }

    let next = content;

    // Single-line docblock ending with inline @test before */
    next = next.replace(
        /^(\s*\/\*\*(?:(?!\*\/)[\s\S])*?)\s+@test\s*\*\/\s*\r?\n(\s*)public function /gm,
        (_, doc, indent) => `${doc.trim()} */\n${indent}#[Test]\n${indent}public function `
    );

    // Docblock whose first line is * @test (optional blank * line after)
    next = next.replace(
        /(\/\*\*)\r?\n\s*\*\s*@test\s*\r?\n(\s*\*\s*\r?\n)?([\s\S]*?\*\/\r?\n)(\s*)public function /g,
        (_, open, _blank, body, indent) => `${open}\n${body}${indent}#[Test]\n${indent}public function `
    );

    // /** @test description */ and /** @test */
    next = next.replace(
        /^(\s*)\/\*\*\s*@test\s+(.+?)\s*\*\/\s*\r?\n/gm,
        (_, indent, description) => `${indent}/**\n${indent} * ${description.trim()}\n${indent} */\n${indent}#[Test]\n`
    );
    next = next.replace(/^\s*\/\*\*\s*@test\s*\*\/\s*\r?\n/gm, '    #[Test]\n');

    // Trailing * @test line before */
    next = next.replace(
        /(\/\*\*[\s\S]*?)\r?\n\s*\*\s*@test\s*\r?\n(\s*\*\/\r?\n)(\s*)(?=public function )/g,
        '$1\n$2$3#[Test]\n$3'
    );

    // * @test with description on same line, or lone * @test
    next = next.replace(/^\s*\*\s*@test\s+(.*)$/gm, '     * $1');
    next = next.replace(/^\s*\*\s*@test\s*$/gm, '');

    if (next === content) {
        return { content, changed: false };
    }

    if (next.includes('#[Test]') && !next.includes('PHPUnit\\Framework\\Attributes\\Test')) {
        const importLine = 'use PHPUnit\\Framework\\Attributes\\Test;\n';
        const useMatches = [...next.matchAll(/^use .+;$/gm)];
        if (useMatches.length > 0) {
            const last = useMatches[useMatches.length - 1];
            const insertAt = last.index + last[0].length + 1;
            next = next.slice(0, insertAt) + importLine + next.slice(insertAt);
        } else {
            next = next.replace(/(namespace [^;]+;\r?\n\r?\n)/, `$1${importLine}`);
        }
    }

    return { content: next, changed: true };
}

async function main() {
    const files = await walk(testsDir);
    let changed = 0;

    for (const file of files) {
        const original = await readFile(file, 'utf8');
        const { content, changed: didChange } = migrateContent(original);
        if (didChange) {
            await writeFile(file, content, 'utf8');
            changed++;
            console.log('updated', path.relative(process.cwd(), file));
        }
    }

    console.log(`Done. ${changed} file(s) updated.`);
}

main().catch((error) => {
    console.error(error);
    process.exit(1);
});
