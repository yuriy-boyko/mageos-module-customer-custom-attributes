import { benchCommand, expect, test } from './helpers';

/**
 * DEP19: the dependency rules and group restrictions are worked out twice, on the server by Service\VisibilityResolver and in the
 * browser by the inline scripts of form/visibility.phtml (account forms) and hyva-checkout/visibility.phtml (checkout address forms).
 * The two must agree. The cases are in dev/e2e/rule-cases.json; `assertions.php resolve cases` gives the PHP result and the restrictions
 * the browser gets. Each script runs on a page of its own with the case's fields and values (no store involved), and the fields it shows
 * are compared with the PHP result.
 */
type Case = {
    name: string;
    fields: Record<string, 'select' | 'multiselect' | 'text'>;
    options: Record<string, string[]>;
    values: Record<string, string>;
    rules: { parent: string; option: string; dependent: string }[];
    groupId: number;
    php: string[];
    browserRestrictions: Record<string, number[]>;
};

const cases = (): Case[] => JSON.parse(benchCommand('E2E_ASSERT_CMD', ['resolve', 'cases'])) as Case[];
const script = (which: 'account' | 'checkout'): string => benchCommand('E2E_ASSERT_CMD', ['browser-script', which]);

function control(code: string, input: Case['fields'][string], c: Case): string {
    const value = c.values[code] ?? '';
    if (input === 'text') {
        return `<input type="text" name="${code}" value="${value}">`;
    }
    const chosen = value.split(',');
    const options = ['', ...(c.options[code] ?? [])]
        .filter((option) => !(input === 'multiselect' && option === ''))
        .map((option) => `<option value="${option}"${chosen.includes(option) && option !== '' ? ' selected' : ''}>${option || '-'}</option>`)
        .join('');
    return `<select name="${code}"${input === 'multiselect' ? ' multiple' : ''}>${options}</select>`;
}

for (const which of ['account', 'checkout'] as const) {
    test(`DEP19: the ${which} script shows the same fields as the server`, { tag: ['@DEP19'] }, async ({ page }) => {
        const source = script(which);
        expect(source).toContain('__CONFIG__');
        for (const c of cases()) {
            const codes = Object.keys(c.fields);
            const config = which === 'account'
                ? { codes, rules: c.rules, restrictions: c.browserRestrictions, groupId: c.groupId }
                : { codes, rules: c.rules, restrictions: c.browserRestrictions, groupId: c.groupId, customer: { codes: [], rules: [], restrictions: {} } };
            const fields = codes.map((code) => which === 'account'
                ? `<div class="field ${code}">${control(code, c.fields[code], c)}</div>`
                : `<div class="field-wrapper">${control(code, c.fields[code], c)}</div>`).join('');
            const markup = which === 'account' ? `<form><div class="root">${fields}</div>` : `<form id="shipping">${fields}`;
            await page.setContent(`<!doctype html><html><body>${markup}<script>${source.replace('__CONFIG__', JSON.stringify(config))}</script></form></body></html>`);
            const shown = await page.evaluate(({ codes, which }) => codes.filter((code) => {
                const element = document.querySelector(`[name="${code}"]`) as HTMLElement;
                const box = (which === 'account' ? element.closest('.field') : element.closest('.field-wrapper')) as HTMLElement;
                return !box.classList.contains('hidden') && box.style.display !== 'none';
            }), { codes, which });
            expect(shown, `${which}: ${c.name}`).toEqual(c.php);
        }
    });
}
