import { attributeRow, attributeTab, createAttributes, deleteCreated, expect, openAttribute, saveAttribute, test } from './helpers';

/**
 * ADM23: the four core flags of the customer grid (Customers > All Customers) on the attribute screen ("Customer Grid" on the
 * Storefront Properties tab). They are stored in core's own columns; address attributes do not have the fields.
 */
const CODE = 'e2e_c_gridflags';

test.afterEach(() => {
    deleteCreated(CODE);
});

test('ADM23: the customer grid flags are shown, saved and read back', { tag: ['@ADM23'] }, async ({ adminPage: page }) => {
    createAttributes('customer', [CODE]);
    expect(attributeRow('customer', CODE).grid).toEqual({ used: false, visible: false, filterable: false, searchable: false });

    await openAttribute(page, 'customer', CODE);
    await attributeTab(page, 'Storefront Properties');
    await expect(page.locator('#grid_fieldset')).toContainText('Customer Grid');
    await page.locator('#is_used_in_grid').selectOption('1');
    await page.locator('#is_visible_in_grid').selectOption('1');
    await page.locator('#is_filterable_in_grid').selectOption('1');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('customer', CODE).grid).toEqual({ used: true, visible: true, filterable: true, searchable: false });

    // read back on the page, then switched off again
    await attributeTab(page, 'Storefront Properties');
    await expect(page.locator('#is_used_in_grid')).toHaveValue('1');
    await expect(page.locator('#is_searchable_in_grid')).toHaveValue('0');
    await page.locator('#is_used_in_grid').selectOption('0');
    await page.locator('#is_visible_in_grid').selectOption('0');
    await page.locator('#is_filterable_in_grid').selectOption('0');
    await saveAttribute(page, true);
    expect(attributeRow('customer', CODE).grid).toEqual({ used: false, visible: false, filterable: false, searchable: false });
});

test('ADM23: address attributes have no customer grid fields', { tag: ['@ADM23'] }, async ({ adminPage: page }) => {
    const address = 'e2e_a_gridflags';
    createAttributes('address', [address]);
    try {
        await openAttribute(page, 'address', address);
        await attributeTab(page, 'Storefront Properties');
        await expect(page.locator('#is_used_in_grid')).toHaveCount(0);
    } finally {
        deleteCreated(address);
    }
});
