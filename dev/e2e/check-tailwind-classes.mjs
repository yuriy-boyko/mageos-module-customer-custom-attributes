#!/usr/bin/env node
/**
 * INS11 helper: does the compiled Hyvä stylesheet contain the Tailwind utility classes the module's templates use?
 *
 *   node check-tailwind-classes.mjs <compiled styles.css> <template folder>...
 *
 * Reads every class="..." of the .phtml files (PHP parts removed), keeps the tokens that look like Tailwind utilities and lists those
 * the stylesheet has no rule for. Exit code 1 when some are missing, 2 for a usage problem.
 */
import fs from 'node:fs';
import path from 'node:path';

const [cssFile, ...folders] = process.argv.slice(2);
if (!cssFile || folders.length === 0) {
    console.error('Usage: node check-tailwind-classes.mjs <styles.css> <template folder>...');
    process.exit(2);
}
const css = fs.readFileSync(cssFile, 'utf8');
const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    return entry.isDirectory() ? walk(full) : full.endsWith('.phtml') ? [full] : [];
});
const UTILITY = /^(?:[a-z0-9-]+:)*-?(?:p[xytrbl]?|m[xytrbl]?|w|h|size|min-[wh]|max-[wh]|gap(?:-[xy])?|space-[xy]|text|font|leading|tracking|bg|border|rounded|shadow|opacity|flex|grid|col|row|items|justify|self|order|inline|block|hidden|relative|absolute|fixed|top|right|bottom|left|inset|z|overflow|cursor|underline|sr-only|transition|duration|ring|outline|accent|appearance|whitespace|break|truncate|list|align|object|fill|stroke|divide|float|clear|resize|select|pointer-events|italic|uppercase|lowercase|capitalize)(?:-[^\s]+)?$/;
const escape = (token) => token.replace(/([^a-zA-Z0-9_-])/g, '\\$1');
const used = new Map();
for (const folder of folders) {
    for (const file of walk(folder)) {
        const source = fs.readFileSync(file, 'utf8').replace(/<\?[\s\S]*?\?>/g, ' ');
        for (const match of source.matchAll(/\bclass="([^"]*)"/g)) {
            for (const token of match[1].split(/\s+/)) {
                if (token && UTILITY.test(token) && !/[<>{}$]/.test(token)) {
                    if (!used.has(token)) used.set(token, path.relative(folder, file));
                }
            }
        }
    }
}
const missing = [...used].filter(([token]) => !css.includes('.' + escape(token)));
console.log(`${used.size} Tailwind-looking classes in the templates, ${missing.length} without a rule in ${path.basename(cssFile)}`);
for (const [token, file] of missing) console.log(`  missing: ${token}  (${file})`);
process.exit(missing.length === 0 ? 0 : 1);
