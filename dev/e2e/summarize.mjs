#!/usr/bin/env node
/**
 * Summarises an end-to-end run in a few lines and, on request, updates the status column of docs/TESTING.md.
 *
 *   node summarize.mjs --run-dir <dir> [--docs <path to TESTING.md>] [--update-docs]
 *
 * <dir> holds the raw results written by run-all.sh: api.txt and assert.txt (lines `ok   name` / `FAIL name` with indented detail),
 * pw.json (Playwright's JSON report) and meta.json ({started, seconds, variant}). Every test or check can carry row IDs of
 * docs/TESTING.md as `[REST1]` (the row is proven by it) or `[VAL1~]` (it proves part of the row). A row counts as automated and
 * passing when all of its full tests pass, and as failing when one fails. Partial tests are listed but never change a row.
 * Writes last-run.json and last-run.md next to the run directory.
 */
import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
const option = (name) => {
    const index = args.indexOf(name);
    return index >= 0 ? args[index + 1] : undefined;
};
const runDir = option('--run-dir');
if (!runDir) {
    console.error('Usage: node summarize.mjs --run-dir <dir> [--docs <TESTING.md>] [--update-docs]');
    process.exit(2);
}
const read = (file) => (fs.existsSync(path.join(runDir, file)) ? fs.readFileSync(path.join(runDir, file), 'utf8') : '');
const meta = read('meta.json') ? JSON.parse(read('meta.json')) : {};

/** @type {{suite: string, name: string, ok: boolean, detail: string, rows: {id: string, partial: boolean}[], source: string}[]} */
const results = [];
const rowsOf = (name) => [...name.matchAll(/\[([A-Z]+\d+)(~?)\]/g)].map((m) => ({ id: m[1], partial: m[2] === '~' }));

// api.txt and assert.txt: `ok    name`, `FAIL  name` followed by indented detail lines
for (const [suite, file] of [['api', 'api.txt'], ['assertions', 'assert.txt']]) {
    let last = null;
    for (const line of read(file).split('\n')) {
        const match = line.match(/^(ok|FAIL)\s+(.*)$/);
        if (match) {
            last = { suite, name: match[2], ok: match[1] === 'ok', detail: '', rows: rowsOf(match[2]), source: suite === 'api' ? 'dev/e2e/api.php' : 'dev/e2e/assertions.php' };
            results.push(last);
        } else if (last && !last.ok && /^\s{4,}\S/.test(line)) {
            last.detail += (last.detail ? ' ' : '') + line.trim();
        } else if (/^(All|\d+ )/.test(line) || line.trim() === '') {
            last = null;
        }
    }
}

// Playwright JSON report
const pwText = read('pw.json');
let pwSkipped = 0;
if (pwText) {
    const report = JSON.parse(pwText);
    const walk = (suite) => {
        for (const spec of suite.specs ?? []) {
            for (const test of spec.tests ?? []) {
                const status = test.status;
                if (status === 'skipped') {
                    pwSkipped++;
                    continue;
                }
                const failure = (test.results ?? []).flatMap((r) => (r.errors ?? []).map((e) => (e.message ?? '').replace(/\u001b\[[0-9;]*m/g, '').split('\n').slice(0, 4).join(' ').trim()));
                const attachments = (test.results ?? []).flatMap((r) => (r.attachments ?? []).map((a) => a.path).filter(Boolean));
                results.push({
                    suite: 'playwright',
                    name: `${spec.title}${(spec.tags ?? []).length ? ' ' + spec.tags.map((t) => `[${t}]`).join('') : ''}`,
                    ok: status === 'expected' || status === 'flaky',
                    detail: (failure.join(' | ').slice(0, 400)) + (attachments.length ? ` (artifacts: ${attachments.join(', ')})` : ''),
                    rows: (spec.tags ?? []).filter((tag) => /^@?[A-Z]+\d+~?$/.test(tag)).map((tag) => ({ id: tag.replace(/^@/, '').replace(/~$/, ''), partial: tag.endsWith('~') })),
                    source: `dev/e2e/playwright/tests/${spec.file}`,
                });
            }
        }
        for (const child of suite.suites ?? []) walk(child);
    };
    for (const suite of report.suites ?? []) walk(suite);
}

// per suite counts
const suites = ['api', 'assertions', 'playwright'];
const count = Object.fromEntries(suites.map((s) => [s, { pass: results.filter((r) => r.suite === s && r.ok).length, fail: results.filter((r) => r.suite === s && !r.ok).length }]));

// per row
/** @type {Map<string, {full: typeof results, partial: typeof results}>} */
const rows = new Map();
for (const result of results) {
    for (const row of result.rows) {
        if (!rows.has(row.id)) rows.set(row.id, { full: [], partial: [] });
        rows.get(row.id)[row.partial ? 'partial' : 'full'].push(result);
    }
}
const rowStatus = (entry) => (entry.full.length === 0 ? 'partial' : entry.full.every((r) => r.ok) ? 'pass' : 'fail');
const rowList = [...rows.entries()].sort((a, b) => a[0].localeCompare(b[0], 'en', { numeric: true }));

// output
const failures = results.filter((r) => !r.ok);
const lines = [];
lines.push(`E2E run ${meta.started ?? ''} (${meta.seconds ?? '?'} s${meta.variant ? ', variant ' + meta.variant : ''})`);
for (const suite of suites) {
    const c = count[suite];
    if (c.pass + c.fail > 0) lines.push(`  ${suite.padEnd(11)} ${String(c.pass).padStart(3)} passed  ${String(c.fail).padStart(3)} failed`);
}
if (pwSkipped > 0) lines.push(`  playwright  ${pwSkipped} skipped (missing session or variant command)`);
for (const failure of failures) {
    lines.push(`FAIL [${failure.suite}] ${failure.name}`);
    if (failure.detail) lines.push(`     ${failure.detail.slice(0, 500)}`);
}
const counts = { pass: 0, fail: 0, partial: 0 };
for (const [, entry] of rowList) counts[rowStatus(entry)]++;
lines.push(`Rows: ${counts.pass} automated and passing, ${counts.fail} failing, ${counts.partial} only partly covered`);
const failingRows = rowList.filter(([, e]) => rowStatus(e) === 'fail').map(([id]) => id);
if (failingRows.length) lines.push(`Failing rows: ${failingRows.join(', ')}`);

// docs
const docs = option('--docs');
if (args.includes('--update-docs') && docs && fs.existsSync(docs)) {
    const source = (entry) => [...new Set(entry.full.map((r) => r.source.replace(/^dev\/e2e\//, '')))].join(', ');
    const text = fs.readFileSync(docs, 'utf8').split('\n');
    let changed = 0;
    for (const [id, entry] of rowList) {
        const status = rowStatus(entry);
        if (status === 'partial') continue;
        const index = text.findIndex((line) => new RegExp(`^\\| ${id}( ★)? \\|`).test(line));
        if (index < 0) continue;
        const cells = text[index].replace(/ \|$/, '').split(' | ');
        const current = cells[cells.length - 1];
        // keep a note in parentheses after the sources, e.g. `✅ automated: x.spec.ts (fails when the rule is broken, checked)`
        const noteMatch = current.match(/automated: [^(]*?(\s\(.*\))?$/);
        const note = noteMatch && noteMatch[1] ? noteMatch[1] : '';
        const base = current.replace(/\s*[·]?\s*(✅ )?automated: .*$/, '').replace(/^❌ automated failing: .*? · was: /, '').trim();
        let next;
        if (status === 'fail') {
            next = `❌ automated failing: ${source(entry)} · was: ${base || '☐'}`;
        } else if (['', '☐', '**manual**'].includes(base) || base.startsWith('❌')) {
            next = `✅ automated: ${source(entry)}${note}`;
        } else {
            next = `${base} · automated: ${source(entry)}${note}`;
        }
        if (next !== current) {
            cells[cells.length - 1] = next;
            text[index] = cells.join(' | ') + ' |';
            changed++;
        }
    }
    fs.writeFileSync(docs, text.join('\n'));
    lines.push(`docs/TESTING.md: ${changed} row(s) updated`);
} else if (rowList.length) {
    lines.push('docs/TESTING.md not changed (run-all.sh --update-docs updates the status of automated rows)');
}

console.log(lines.join('\n'));
const out = path.dirname(path.resolve(runDir));
fs.writeFileSync(path.join(out, 'last-run.md'), lines.join('\n') + '\n');
fs.writeFileSync(path.join(out, 'last-run.json'), JSON.stringify({ meta, counts: count, rows: Object.fromEntries(rowList.map(([id, e]) => [id, rowStatus(e)])), failures }, null, 2));
process.exit(failures.length ? 1 : 0);
