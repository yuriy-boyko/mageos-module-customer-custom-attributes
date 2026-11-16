import { attributeRow, attributeTab, createAttributes, deleteCreated, expect, openAttribute, saveAttribute, test } from './helpers';

/**
 * DEP7: the customer groups of an attribute are saved and shown again. The attribute edit page lists the groups in a multiple select
 * ("Storefront Properties" tab); what is selected is stored in the group table and selected again when the page is opened.
 * An empty selection means every group. Uses an attribute the test creates itself.
 */
const CODE = 'e2e_adm_grp';

test.afterEach(() => {
    deleteCreated(CODE);
});

const groupOptions = async (page: import('@playwright/test').Page) =>
    page.locator('#customer_groups option').evaluateAll((options) => (options as HTMLOptionElement[]).map((option) => ({ id: Number(option.value), label: option.text.trim(), selected: option.selected })));

test('DEP7: the selected groups are stored and selected again when the page is opened', { tag: ['@DEP7'] }, async ({ adminPage: page }) => {
    createAttributes('address', [CODE]);
    expect(attributeRow('address', CODE).groups, 'a new attribute has no group limit').toEqual([]);

    await openAttribute(page, 'address', CODE);
    await attributeTab(page, 'Storefront Properties');
    const all = await groupOptions(page);
    expect(all.length).toBeGreaterThanOrEqual(3);
    expect(all.every((option) => !option.selected), 'nothing selected at the start').toBe(true);
    const wanted = all.filter((option) => ['General', 'Wholesale'].includes(option.label));
    expect(wanted).toHaveLength(2);

    await page.locator('#customer_groups').selectOption(wanted.map((option) => String(option.id)));
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('address', CODE).groups).toEqual(wanted.map((option) => option.id).sort((a, b) => a - b));

    // shown again after the save, and after opening the page from the grid
    await attributeTab(page, 'Storefront Properties');
    expect((await groupOptions(page)).filter((option) => option.selected).map((option) => option.id).sort((a, b) => a - b)).toEqual(wanted.map((option) => option.id).sort((a, b) => a - b));
    await openAttribute(page, 'address', CODE);
    await attributeTab(page, 'Storefront Properties');
    expect((await groupOptions(page)).filter((option) => option.selected).map((option) => option.label).sort()).toEqual(['General', 'Wholesale']);

    // changed to one group, then cleared
    await page.locator('#customer_groups').selectOption([String(wanted[0].id)]);
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('address', CODE).groups).toEqual([wanted[0].id]);
    await attributeTab(page, 'Storefront Properties');
    await page.locator('#customer_groups').selectOption([]);
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('address', CODE).groups, 'cleared: every group').toEqual([]);
    await attributeTab(page, 'Storefront Properties');
    expect((await groupOptions(page)).some((option) => option.selected)).toBe(false);
});
