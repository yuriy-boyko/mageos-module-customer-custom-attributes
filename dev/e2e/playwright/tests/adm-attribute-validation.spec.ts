import { attributeRow, adminOpen, expect, fillAttributeMain, gridFilter, openGrid, openNewAttribute, saveAttribute, test } from './helpers';

/**
 * ADM11 (browser side): what the shop owner sees when a new attribute is refused. Every case stays on the "new" page and creates nothing.
 * The same rules are proven on the validate endpoint by the REST suite.
 * ADM13: URL tampering on the edit and index pages.
 */
const CODE = 'e2e_adm11';

type Case = { name: string; prepare: (page: import('@playwright/test').Page) => Promise<void>; message: RegExp };
let entityName = 'customer';
const cases: Case[] = [
    {
        name: 'duplicate code',
        prepare: (page) => fillAttributeMain(page, { label: 'ADM dup', code: entityName === 'customer' ? 'e2e_c_text' : 'e2e_a_text', input: 'text' }),
        message: /already exists/i,
    },
    {
        name: 'reserved code',
        prepare: (page) => fillAttributeMain(page, { label: 'ADM reserved', code: 'entity_id', input: 'text' }),
        message: /reserved/i,
    },
    {
        name: 'malformed code',
        prepare: (page) => fillAttributeMain(page, { label: 'ADM malformed', code: 'Bad-Code', input: 'text' }),
        message: /lowercase letters|letters, digits|valid/i,
    },
    {
        name: 'missing label',
        prepare: (page) => fillAttributeMain(page, { label: '', code: CODE, input: 'text' }),
        message: /required field|default label is required/i,
    },
    {
        name: 'unsupported input type',
        prepare: async (page) => {
            await fillAttributeMain(page, { label: 'ADM type', code: CODE, input: 'text' });
            await page.locator('#frontend_input').evaluate((select: HTMLSelectElement) => {
                select.add(new Option('Bogus', 'bogus'));
                select.value = 'bogus';
            });
        },
        message: /valid input type/i,
    },
    {
        name: 'minimum text length above the maximum',
        prepare: async (page) => {
            await fillAttributeMain(page, { label: 'ADM min max', code: CODE, input: 'text' });
            await page.locator('#min_text_length').fill('10');
            await page.locator('#max_text_length').fill('5');
        },
        message: /Minimum text length cannot be greater than the maximum/i,
    },
    {
        name: 'dropdown without options',
        prepare: (page) => fillAttributeMain(page, { label: 'ADM no options', code: CODE, input: 'select' }),
        message: /at least one option|option/i,
    },
];

for (const entity of ['customer', 'address'] as const) {
    for (const c of cases) {
        test(`${entity}: ${c.name} is refused with a clear message`, { tag: ['@ADM11'] }, async ({ adminPage: page }) => {
            entityName = entity;
            await openNewAttribute(page, entity);
            await c.prepare(page);
            await saveAttribute(page);
            await expect(page).toHaveURL(/mageos_cca\/attribute\/new/);
            await expect(page.locator('body')).toContainText(c.message);
            expect(attributeRow(entity, CODE).exists).toBe(false);
        });
    }
}

test('URL tampering: unknown entity, an attribute of the other area, a system attribute, a bad id', { tag: ['@ADM13'] }, async ({ adminPage: page }) => {
    const NOT_EDITABLE = /This attribute no longer exists or cannot be edited/;
    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'e2e_a_text' });
    const editUrl = (await page.locator('#mageosCcaAttributeGrid tbody tr').first().getAttribute('title')) as string;
    expect(editUrl).toMatch(/attribute_id\/\d+\/entity\/address\//);

    // the address attribute asked for through the customer area
    await page.goto(editUrl.replace('/entity/address/', '/entity/customer/'));
    await expect(page.locator('.message-error')).toContainText(NOT_EDITABLE);
    await expect(page).toHaveURL(/mageos_cca\/attribute\/(index|edit)/);

    // a system attribute
    const system = attributeRow('customer', 'firstname');
    await page.goto(editUrl.replace(/attribute_id\/\d+/, `attribute_id/${system.id}`).replace('/entity/address/', '/entity/customer/'));
    await expect(page.locator('.message-error')).toContainText(NOT_EDITABLE);

    // an id that does not exist, and ids that are not numbers
    for (const id of ['99999999', '0', 'abc', '-5']) {
        await page.goto(editUrl.replace(/attribute_id\/\d+/, `attribute_id/${id}`));
        await expect(page.locator('.message-error')).toContainText(NOT_EDITABLE);
    }

    // an unknown entity falls back to a known area and shows its grid without an error
    await page.goto('/backend/');
    const indexUrl = (await page.locator('a[href*="mageos_cca/attribute/index/entity/customer"]').first().getAttribute('href')) as string;
    await page.goto(indexUrl.replace('/entity/customer/', '/entity/foo/'));
    await expect(page.locator('.page-title')).toContainText(/Customer( Address)? Attributes/);
    await expect(page.locator('#mageosCcaAttributeGrid')).toBeVisible();
    await expect(page.locator('.message-error')).toHaveCount(0);
});
