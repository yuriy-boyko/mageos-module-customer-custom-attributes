import * as zlib from 'zlib';

/**
 * The text of a PDF made by Magento (Zend PDF: standard fonts, text in `(...) Tj` / `[...] TJ` operators, streams compressed with Flate or
 * not). Enough to find the lines of an address in an invoice, shipment or credit memo; it is not a general PDF reader. The text is returned
 * line by line, in the order of the page content, one entry per text operator.
 */
export function pdfLines(buffer: Buffer): string[] {
    const raw = buffer.toString('latin1');
    const lines: string[] = [];
    const streams = raw.matchAll(/stream\r?\n/g);
    for (const start of streams) {
        const from = (start.index ?? 0) + start[0].length;
        const end = raw.indexOf('endstream', from);
        if (end < 0) continue;
        const chunk = Buffer.from(raw.slice(from, end), 'latin1');
        let content = '';
        try {
            content = zlib.inflateSync(chunk).toString('latin1');
        } catch {
            try {
                content = zlib.inflateSync(chunk.subarray(0, chunk.length - 1)).toString('latin1');
            } catch {
                content = chunk.toString('latin1');
            }
        }
        if (!/\bT[jJ]\b/.test(content)) continue;
        for (const match of content.matchAll(/\[((?:[^\]\\]|\\.)*)\]\s*TJ|\(((?:[^()\\]|\\.)*)\)\s*Tj|<([0-9A-Fa-f\s]+)>\s*Tj/g)) {
            if (match[1] !== undefined) {
                const parts = [...match[1].matchAll(/\(((?:[^()\\]|\\.)*)\)/g)].map((part) => unescapePdf(part[1]));
                lines.push(parts.join(''));
            } else if (match[2] !== undefined) {
                lines.push(unescapePdf(match[2]));
            } else {
                const hex = match[3].replace(/\s+/g, '');
                lines.push(hex.match(/../g)?.map((pair) => String.fromCharCode(parseInt(pair, 16))).join('') ?? '');
            }
        }
    }
    return lines.map((line) => line.replace(/\0/g, '').trim()).filter((line) => line !== '');
}

function unescapePdf(text: string): string {
    return text.replace(/\\([nrtbf()\\]|[0-7]{1,3})/g, (_all, code: string) => {
        if (/^[0-7]+$/.test(code)) return String.fromCharCode(parseInt(code, 8));
        return ({ n: '\n', r: '\r', t: '\t', b: '\b', f: '\f' } as Record<string, string>)[code] ?? code;
    });
}
