"""
Script one-shot untuk apply RequiresAuthenticatedUser trait ke semua Action class.
Idempotent — re-run aman (skip file yang sudah ada trait).
"""
import os
import re
import glob

os.chdir(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

files = sorted(glob.glob('app/Actions/*/*.php'))
# Filter: skip Concerns/ folder
files = [f for f in files if 'Concerns' not in f]

processed = 0
skipped = 0

for f in files:
    with open(f, 'r', encoding='utf-8') as fp:
        content = fp.read()

    if 'RequiresAuthenticatedUser' in content:
        skipped += 1
        continue

    original = content

    # 1. Insert import line BEFORE 'class XxxAction' line.
    # Use a function to build the replacement to avoid backref issues.
    def add_import(m):
        return m.group(1) + 'use App\\Actions\\Concerns\\RequiresAuthenticatedUser;\n' + m.group(2)

    content = re.sub(
        r'(^use [^\n]+;\n)(\n*class \w+Action)',
        add_import,
        content,
        count=1,
        flags=re.MULTILINE
    )

    # 2. Insert 'use RequiresAuthenticatedUser;' after class opening brace
    def add_trait(m):
        return m.group(1) + '    use RequiresAuthenticatedUser;\n\n'

    content = re.sub(
        r'(class \w+Action\s*\n\{\n)',
        add_trait,
        content,
        count=1
    )

    # 3. Insert ensureAuthenticated call at start of execute() body
    def add_ensure(m):
        return m.group(1) + '        $this->ensureAuthenticated();\n\n'

    content = re.sub(
        r'(public function execute\([^)]*\)[^{]*\{\n)',
        add_ensure,
        content,
        count=1
    )

    if content != original:
        with open(f, 'w', encoding='utf-8') as fp:
            fp.write(content)
        print(f'  OK {f}')
        processed += 1
    else:
        print(f'  NOCHANGE {f}')

print(f'\nProcessed: {processed}, Skipped (already has trait): {skipped}')
