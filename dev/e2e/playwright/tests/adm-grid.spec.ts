import { attributeRow, createAttributes, deleteCreated, expect, gridColumn, gridFilter, gridMassAction, openGrid, test } from './helpers';

/**
 * ADM2: grid filters, sorting and pagination, and system attributes are not listed (address grid; 21 attributes e2e_adm2_01..21 are created).
 * ADM16: mass actions on the customer grid: "Select All" with a filter reaches the filtered rows only; the rows of other attributes stay.
 * Nothing selected and a system attribute posted by hand are answered by the server (see the second test).
 */
const pad = (n: number): string => String(n).padStart(2, '0');
const adm2 = Array.from({ length: 21 }, (_, i) => `e2e_adm2_${pad(i + 1)}`);
const adm16 = ['e2e_adm16_a', 'e2e_adm16_b', 'e2e_adm16_c'];

test.afterEach(() => {
    [...adm2, ...adm16].forEach(deleteCreated);
});

test('grid filters, sorting and pagination work, and system attributes are not listed', { tag: ['@ADM2'] }, async ({ adminPage: page }) => {
    createAttributes('address', adm2);
    await openGrid(page, 'address');

    // pagination: 21 matches, 20 per page
    await gridFilter(page, { attribute_code: 'e2e_adm2_' });
    expect(await gridColumn(page, 'attribute_code')).toEqual(adm2.slice(0, 20));
    await page.locator('#mageosCcaAttributeGrid button.action-next').first().click();
    await page.waitForLoadState('networkidle');
    expect(await gridColumn(page, 'attribute_code')).toEqual([adm2[20]]);

    // sorting: a click on the header reverses the default order (code, ascending); the filter stays
    await page.locator('#mageosCcaAttributeGrid thead th.col-attribute_code').first().click();
    await page.waitForLoadState('networkidle');
    const descending = await gridColumn(page, 'attribute_code');
    expect(descending.length).toBeGreaterThan(0);
    expect(descending).toEqual([...descending].sort().reverse());
    expect(descending.every((code) => code.startsWith('e2e_adm2_'))).toBe(true);

    // text filters
    await openGrid(page, 'address');
    await gridFilter(page, { frontend_label: 'ADM e2e_adm2_07' });
    expect(await gridColumn(page, 'attribute_code')).toEqual(['e2e_adm2_07']);

    // select filters: the other fixtures include dropdowns and required attributes
    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'e2e_a_', frontend_input: 'select' });
    const inputs = await gridColumn(page, 'frontend_input');
    expect(inputs.length).toBeGreaterThan(0);
    expect(new Set(inputs)).toEqual(new Set(['Dropdown']));
    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'e2e_a_', is_required: '1' });
    const required = await gridColumn(page, 'is_required');
    expect(required.length).toBeGreaterThan(0);
    expect(new Set(required)).toEqual(new Set(['Yes']));

    // system attributes are not listed
    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'city' });
    expect(await gridColumn(page, 'attribute_code')).toEqual([]);
    await expect(page.locator('#mageosCcaAttributeGrid')).toContainText(/couldn't find any records/i);
});

test('mass actions on the customer grid reach the filtered rows only', { tag: ['@ADM16'] }, async ({ adminPage: page }) => {
    createAttributes('customer', adm16);
    const visible = (code: string): boolean => attributeRow('customer', code).is_visible;

    await openGrid(page, 'customer');
    await gridFilter(page, { attribute_code: 'e2e_adm16_' });
    expect((await gridColumn(page, 'attribute_code')).length).toBe(3);
    await gridMassAction(page, 'disable', 'all');
    await expect(page.locator('.message-success')).toContainText('3 attribute(s) have been disabled');
    expect(adm16.map(visible)).toEqual([false, false, false]);
    expect(visible('e2e_c_text')).toBe(true); // outside the filter: untouched

    // two rows ticked by hand
    await openGrid(page, 'customer');
    await gridFilter(page, { attribute_code: 'e2e_adm16_' });
    await gridMassAction(page, 'enable', ['e2e_adm16_a', 'e2e_adm16_c']);
    await expect(page.locator('.message-success')).toContainText('2 attribute(s) have been enabled');
    expect(adm16.map(visible)).toEqual([true, false, true]);

    // delete with "Select All" and the filter: only these three go
    await openGrid(page, 'customer');
    await gridFilter(page, { attribute_code: 'e2e_adm16_' });
    await gridMassAction(page, 'delete', 'all');
    await expect(page.locator('.message-success')).toContainText('3 attribute(s) have been deleted');
    expect(adm16.map((code) => attributeRow('customer', code).exists)).toEqual([false, false, false]);
    expect(attributeRow('customer', 'e2e_c_text').exists).toBe(true);
});

test('the server answers a mass action without a selection and skips a system attribute posted by hand', { tag: ['@ADM16'] }, async ({ adminPage: page }) => {
    await openGrid(page, 'customer');
    const formKey = await page.locator('input[name="form_key"]').first().inputValue();
    const urls = await page.evaluate(() => {
        const items = (window as unknown as { mageosCcaAttributeGrid_massactionJsObject: { items: Record<string, { url: string }> } }).mageosCcaAttributeGrid_massactionJsObject.items;
        return { disable: items.disable.url, remove: items.delete.url };
    });
    const post = async (url: string, ids: number[]): Promise<string> => {
        const response = await page.request.post(url, { form: { form_key: formKey, ...Object.fromEntries(ids.map((id, i) => [`attribute_ids[${i}]`, String(id)])) } });
        return response.text();
    };

    expect(await post(urls.disable, [])).toContain('Please select attributes.');

    const system = attributeRow('customer', 'firstname');
    expect(system.exists && !system.user_defined).toBe(true);
    for (const url of [urls.remove, urls.disable]) {
        expect(await post(url, [system.id])).toContain('1 attribute(s) were skipped');
    }
    const after = attributeRow('customer', 'firstname');
    expect(after.exists).toBe(true);
    expect(after.is_visible).toBe(system.is_visible);
});
