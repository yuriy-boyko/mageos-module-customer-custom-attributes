#!/usr/bin/env node
/** Compares the two ROB4 measurements (with the module, without it): node compare-rob4.mjs on.json off.json <max slower %> <max slower ms> <max more requests> */
import fs from 'node:fs';

const [onFile, offFile, pct, ms, more] = process.argv.slice(2);
const on = JSON.parse(fs.readFileSync(onFile, 'utf8'));
const off = JSON.parse(fs.readFileSync(offFile, 'utf8'));
let failed = 0;
console.log('page'.padEnd(28) + 'off ms'.padStart(8) + 'on ms'.padStart(8) + 'diff'.padStart(8) + '   requests off/on');
for (const name of Object.keys(on)) {
    const a = off[name];
    const b = on[name];
    if (!a) continue;
    const diff = b.ms - a.ms;
    const slower = diff > Number(ms) && (diff / a.ms) * 100 > Number(pct);
    const moreRequests = b.requests - a.requests > Number(more);
    if (slower || moreRequests) failed++;
    console.log(name.padEnd(28) + String(a.ms).padStart(8) + String(b.ms).padStart(8) + String(diff).padStart(8) + `   ${a.requests}/${b.requests}` + (slower || moreRequests ? '   OVER THE LIMIT' : ''));
}
console.log(failed === 0 ? `Within the limits (slower than ${pct}% and ${ms} ms together, or more than ${more} extra requests, fail).` : `${failed} page(s) over the limits.`);
process.exit(failed === 0 ? 0 : 1);
