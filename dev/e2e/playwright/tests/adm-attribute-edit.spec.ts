import { addOption, adminMessages, attributeRow, attributeTab, deleteCreated, expect, fillAttributeMain, openAttribute, openNewAttribute, optionRows, saveAttribute, test } from './helpers';

/**
 * ADM7, ADM8, ADM9, ADM12, ADM14: the attribute edit page of the admin, driven through the browser; what was saved is read back from
 * the database. The tests create their own attributes (codes e2e_adm_*) and delete them afterwards, so they need no fixtures.
 * The store view labels use store ids 1 (default) and 2 (ITA).
 */
const created: string[] = [];
const track = (code: string): string => {
    created.push(code);
    return code;
};

test.afterEach(() => {
    for (const code of created.splice(0)) {
        deleteCreated(code);
    }
});

test('options can be changed, added and deleted, and the default option changed', { tag: ['@ADM7'] }, async ({ adminPage: page }) => {
    const code = track('e2e_adm_opt');
    await openNewAttribute(page, 'address');
    await fillAttributeMain(page, { label: 'ADM Options', code, input: 'select' });
    await addOption(page, 'Alpha');
    const beta = await addOption(page, 'Beta');
    await addOption(page, 'Gamma');
    await beta.locator('input[name="default[]"]').check();
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    let row = attributeRow('address', code);
    expect(row.options.map((option) => option.label)).toEqual(['Alpha', 'Beta', 'Gamma']);
    expect(row.options.filter((option) => option.is_default).map((option) => option.label)).toEqual(['Beta']);

    // change Beta, delete Alpha, add Delta and make it the default
    await openAttribute(page, 'address', code);
    await expect(optionRows(page)).toHaveCount(3);
    await optionRows(page).nth(1).locator('input[name$="[0]"]').fill('Beta renamed');
    await optionRows(page).nth(0).locator('button[id^="delete_button_"]').click();
    const delta = await addOption(page, 'Delta');
    await delta.locator('input[name="default[]"]').check();
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();

    row = attributeRow('address', code);
    expect(row.options.map((option) => option.label)).toEqual(['Beta renamed', 'Gamma', 'Delta']);
    expect(row.options.filter((option) => option.is_default).map((option) => option.label)).toEqual(['Delta']);
    // the page after "Save and Continue Edit" shows the saved options
    await expect(optionRows(page)).toHaveCount(3);
    await expect(optionRows(page).nth(0).locator('input[name$="[0]"]')).toHaveValue('Beta renamed');
});

test('labels per store view are saved, changed and removed', { tag: ['@ADM8'] }, async ({ adminPage: page }) => {
    const code = track('e2e_adm_lab');
    await openNewAttribute(page, 'customer');
    await fillAttributeMain(page, { label: 'ADM Labels', code, input: 'text' });
    await attributeTab(page, 'Manage Labels');
    await page.locator('input[name="frontend_label[1]"]').fill('Default view label');
    await page.locator('input[name="frontend_label[2]"]').fill('Etichetta ITA');
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    let row = attributeRow('customer', code);
    expect(row.label).toBe('ADM Labels');
    expect(row.store_labels).toEqual({ 1: 'Default view label', 2: 'Etichetta ITA' });

    // change one, remove the other
    await openAttribute(page, 'customer', code);
    await attributeTab(page, 'Manage Labels');
    await expect(page.locator('input[name="frontend_label[2]"]')).toHaveValue('Etichetta ITA');
    await page.locator('input[name="frontend_label[1]"]').fill('Changed view label');
    await page.locator('input[name="frontend_label[2]"]').fill('');
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    row = attributeRow('customer', code);
    expect(row.store_labels).toEqual({ 1: 'Changed view label' });
});

test('storefront properties: enabled, sort order and forms', { tag: ['@ADM9'] }, async ({ adminPage: page }) => {
    const code = track('e2e_adm_front');
    await openNewAttribute(page, 'address');
    await fillAttributeMain(page, { label: 'ADM Front', code, input: 'text' });
    await attributeTab(page, 'Storefront Properties');
    await page.locator('#is_visible').selectOption('0');
    await page.locator('#sort_order').fill('7');
    await page.locator('#used_in_forms').selectOption(['customer_address_edit', 'mageos_hyva_checkout']);
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    let row = attributeRow('address', code);
    expect(row.is_visible).toBe(false);
    expect(row.sort_order).toBe(7);
    expect(row.forms).toEqual(expect.arrayContaining(['customer_address_edit', 'mageos_hyva_checkout']));
    expect(row.forms).not.toContain('customer_register_address');
    expect(row.forms).not.toContain('mageos_address_text');

    // the page shows what was saved, then a change is saved
    await openAttribute(page, 'address', code);
    await attributeTab(page, 'Storefront Properties');
    await expect(page.locator('#is_visible')).toHaveValue('0');
    await expect(page.locator('#sort_order')).toHaveValue('7');
    await page.locator('#is_visible').selectOption('1');
    await page.locator('#sort_order').fill('3');
    await page.locator('#used_in_forms').selectOption(['customer_register_address', 'mageos_address_text']);
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    row = attributeRow('address', code);
    expect(row.is_visible).toBe(true);
    expect(row.sort_order).toBe(3);
    expect(row.forms).toEqual(expect.arrayContaining(['customer_register_address', 'mageos_address_text']));
    expect(row.forms).not.toContain('customer_address_edit');
    expect(row.forms).not.toContain('mageos_hyva_checkout');
});

test('a failed save keeps what was entered', { tag: ['@ADM12'] }, async ({ adminPage: page }) => {
    // e2e_a_text is a fixture: its code is taken, so the save is refused
    await openNewAttribute(page, 'address');
    await fillAttributeMain(page, { label: 'ADM Kept', code: 'e2e_a_text', input: 'select' });
    await addOption(page, 'Kept one', { 2: 'Kept ITA' });
    await addOption(page, 'Kept two');
    await page.locator('#is_required').selectOption('1');
    await attributeTab(page, 'Manage Labels');
    await page.locator('input[name="frontend_label[2]"]').fill('Etichetta tenuta');
    await attributeTab(page, 'Storefront Properties');
    await page.locator('#sort_order').fill('42');
    await page.locator('#used_in_forms').selectOption(['mageos_hyva_checkout']);
    await saveAttribute(page);

    expect(await adminMessages(page)).toMatch(/already|exists|used/i);
    await expect(page).toHaveURL(/mageos_cca\/attribute\/(new|edit|save)/);
    // nothing was created or changed
    expect(attributeRow('address', 'e2e_a_text').label).not.toBe('ADM Kept');

    await expect(page.locator('#attribute_label')).toHaveValue('ADM Kept');
    await expect(page.locator('#attribute_code')).toHaveValue('e2e_a_text');
    await expect(page.locator('#frontend_input')).toHaveValue('select');
    await expect(page.locator('#is_required')).toHaveValue('1');
    await expect(optionRows(page)).toHaveCount(2);
    await expect(optionRows(page).nth(0).locator('input[name$="[0]"]')).toHaveValue('Kept one');
    await expect(optionRows(page).nth(0).locator('input[name$="[2]"]')).toHaveValue('Kept ITA');
    await expect(optionRows(page).nth(1).locator('input[name$="[0]"]')).toHaveValue('Kept two');
    await attributeTab(page, 'Manage Labels');
    await expect(page.locator('input[name="frontend_label[2]"]')).toHaveValue('Etichetta tenuta');
    await attributeTab(page, 'Storefront Properties');
    await expect(page.locator('#sort_order')).toHaveValue('42');
    await expect(page.locator('#used_in_forms')).toHaveValues(['mageos_hyva_checkout']);
});

test('the code length limit is enforced: 51 characters for customer attributes, 60 for address attributes', { tag: ['@ADM14'] }, async ({ adminPage: page }) => {
    const longCode = (length: number): string => ('e2e_adm14_' + 'a'.repeat(length)).slice(0, length);
    for (const [entity, limit] of [['customer', 51], ['address', 60]] as const) {
        // one character too many: refused in the browser, nothing is created
        const tooLong = longCode(limit + 1);
        track(tooLong);
        await openNewAttribute(page, entity);
        await fillAttributeMain(page, { label: `ADM ${entity} too long`, code: tooLong, input: 'text' });
        await saveAttribute(page);
        await expect(page.locator('#attribute_code')).toHaveClass(/mage-error/);
        await expect(page).toHaveURL(/mageos_cca\/attribute\/new/);
        expect(attributeRow(entity, tooLong).exists).toBe(false);

        // exactly the limit: saved
        const atLimit = longCode(limit);
        track(atLimit);
        await page.locator('#attribute_code').fill(atLimit);
        await page.locator('#attribute_label').fill(`ADM ${entity} at limit`);
        await saveAttribute(page);
        await expect(page.locator('.message-success')).toBeVisible();
        expect(attributeRow(entity, atLimit).exists).toBe(true);
    }
});
