import { addOption, attributeRow, attributeTab, createAttributes, deleteCreated, expect, fillAttributeMain, gridFilter, gridMassAction, openAttribute, openGrid, openNewAttribute, saveAttribute, test, adminOpen } from './helpers';

/**
 * ADM1 both grids open, ADM3 create (dropdown, text with max length, date with min/max), ADM4 how they are stored,
 * ADM5 input type switch, ADM6 edit page read-only parts, ADM10 delete with confirmation, ADM15 mass actions on the address grid.
 * The tests create their own attributes (e2e_adm_*) and delete them afterwards.
 */
const created: string[] = [];
const track = (...codes: string[]): void => {
    created.push(...codes);
};
test.afterEach(() => {
    for (const code of created.splice(0)) deleteCreated(code);
});

test('both attribute grids open from the Stores menu', { tag: ['@ADM1'] }, async ({ adminPage: page }) => {
    for (const [entity, title] of [['customer', /Customer Attributes/], ['address', /Customer Address Attributes/]] as const) {
        await adminOpen(page, `mageos_cca/attribute/index/entity/${entity}`);
        await expect(page.locator('.page-title')).toContainText(title);
        await expect(page.locator('#mageosCcaAttributeGrid')).toBeVisible();
    }
});

test('a dropdown, a text field with a maximum length and a date with a range are created and stored as expected', { tag: ['@quick', '@ADM3', '@ADM4'] }, async ({ adminPage: page }) => {
    track('e2e_adm_cd', 'e2e_adm_ct', 'e2e_adm_cdt');

    await openNewAttribute(page, 'address');
    await fillAttributeMain(page, { label: 'ADM Create Dropdown', code: 'e2e_adm_cd', input: 'select' });
    await addOption(page, 'One');
    await addOption(page, 'Two');
    await addOption(page, 'Three');
    await attributeTab(page, 'Storefront Properties');
    await page.locator('#used_in_forms').selectOption(['customer_address_edit', 'mageos_hyva_checkout']);
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    await openNewAttribute(page, 'address');
    await fillAttributeMain(page, { label: 'ADM Create Text', code: 'e2e_adm_ct', input: 'text' });
    await page.locator('#max_text_length').fill('12');
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    await openNewAttribute(page, 'customer');
    await fillAttributeMain(page, { label: 'ADM Create Date', code: 'e2e_adm_cdt', input: 'date' });
    await page.locator('#date_range_min').fill('2020-01-31');
    await page.locator('#date_range_max').fill('2030-12-31');
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    // ADM3: the rules and options
    const dropdown = attributeRow('address', 'e2e_adm_cd');
    expect(dropdown.options.map((o) => o.label)).toEqual(['One', 'Two', 'Three']);
    const text = attributeRow('address', 'e2e_adm_ct');
    expect(JSON.stringify(text.rules)).toMatch(/"max_text_length":"?12"?/);
    const date = attributeRow('customer', 'e2e_adm_cdt');
    expect(JSON.stringify(date.rules)).toMatch(/date_range_min/);
    expect(JSON.stringify(date.rules)).toMatch(/date_range_max/);

    // ADM4: entity type (the lookup is by entity), backend type, user defined, forms
    expect(dropdown).toMatchObject({ exists: true, input: 'select', backend_type: 'int', user_defined: true });
    expect(text).toMatchObject({ input: 'text', backend_type: 'varchar', user_defined: true });
    expect(date).toMatchObject({ input: 'date', backend_type: 'datetime', user_defined: true });
    expect(dropdown.forms).toEqual(expect.arrayContaining(['customer_address_edit', 'mageos_hyva_checkout']));
    expect(attributeRow('customer', 'e2e_adm_cd').exists).toBe(false); // not in the other entity
});

test('the input type decides which fields the page shows', { tag: ['@ADM5'] }, async ({ adminPage: page }) => {
    await openNewAttribute(page, 'address');
    const visible = async (selector: string): Promise<boolean> => page.locator(selector).first().isVisible();

    const cases: { input: string; shown: string[]; hidden: string[] }[] = [
        { input: 'text', shown: ['#default_value_text', '#min_text_length', '#max_text_length', '#input_validation'], hidden: ['#add_new_option_button', '#date_range_min'] },
        { input: 'textarea', shown: ['#default_value_textarea', '#min_text_length'], hidden: ['#add_new_option_button', '#default_value_text', '#date_range_min'] },
        { input: 'date', shown: ['#date_range_min', '#date_range_max'], hidden: ['#add_new_option_button', '#min_text_length', '#default_value_text'] },
        { input: 'boolean', shown: ['#default_value_yesno'], hidden: ['#add_new_option_button', '#min_text_length', '#date_range_min'] },
        { input: 'select', shown: ['#add_new_option_button'], hidden: ['#min_text_length', '#date_range_min', '#default_value_text'] },
        { input: 'multiselect', shown: ['#add_new_option_button'], hidden: ['#min_text_length', '#date_range_min', '#default_value_text'] },
    ];
    for (const c of cases) {
        await page.locator('#frontend_input').selectOption(c.input);
        for (const selector of c.shown) expect(await visible(selector), `${c.input}: ${selector} shown`).toBe(true);
        for (const selector of c.hidden) expect(await visible(selector), `${c.input}: ${selector} hidden`).toBe(false);
    }
});

test('the edit page locks the code and the input type and shows what was saved', { tag: ['@ADM6'] }, async ({ adminPage: page }) => {
    track('e2e_adm_ed');
    await openNewAttribute(page, 'address');
    await fillAttributeMain(page, { label: 'ADM Edit', code: 'e2e_adm_ed', input: 'select', required: true });
    await addOption(page, 'First', { 2: 'Primo' });
    await addOption(page, 'Second');
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();

    await openAttribute(page, 'address', 'e2e_adm_ed');
    await expect(page.locator('#attribute_code')).toBeDisabled();
    await expect(page.locator('#attribute_code')).toHaveValue('e2e_adm_ed');
    await expect(page.locator('#frontend_input')).toBeDisabled();
    await expect(page.locator('#frontend_input')).toHaveValue('select');
    await expect(page.locator('#attribute_label')).toHaveValue('ADM Edit');
    await expect(page.locator('#is_required')).toHaveValue('1');
    await expect(page.locator('tr').filter({ has: page.locator('input[name^="option[value]"]') })).toHaveCount(2);
    await expect(page.locator('input[name^="option[value]"][name$="[2]"]').first()).toHaveValue('Primo');
});

test('delete asks for confirmation and removes the attribute', { tag: ['@ADM10'] }, async ({ adminPage: page }) => {
    createAttributes('address', ['e2e_adm_del']);
    track('e2e_adm_del');
    await openAttribute(page, 'address', 'e2e_adm_del');

    // cancel: still there
    await page.locator('#delete').click();
    await page.locator('.modal-popup._show .action-dismiss').click();
    expect(attributeRow('address', 'e2e_adm_del').exists).toBe(true);

    // confirm: gone, with a message, back on the grid
    await page.locator('#delete').click();
    await page.locator('.modal-popup._show .action-accept').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.message-success')).toContainText(/deleted/i);
    await expect(page).toHaveURL(/mageos_cca\/attribute\/index/);
    expect(attributeRow('address', 'e2e_adm_del').exists).toBe(false);
});

test('mass enable, disable and delete on the address grid', { tag: ['@ADM15'] }, async ({ adminPage: page }) => {
    const codes = ['e2e_adm15_a', 'e2e_adm15_b'];
    createAttributes('address', codes);
    track(...codes);
    const visible = (code: string): boolean => attributeRow('address', code).is_visible;

    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'e2e_adm15_' });
    await gridMassAction(page, 'disable', 'all');
    await expect(page.locator('.message-success')).toContainText('2 attribute(s) have been disabled');
    expect(codes.map(visible)).toEqual([false, false]);
    await expect(page.locator('#mageosCcaAttributeGrid tbody td.col-is_visible').first()).toContainText('No');

    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'e2e_adm15_' });
    await gridMassAction(page, 'enable', 'all');
    await expect(page.locator('.message-success')).toContainText('2 attribute(s) have been enabled');
    expect(codes.map(visible)).toEqual([true, true]);

    await openGrid(page, 'address');
    await gridFilter(page, { attribute_code: 'e2e_adm15_' });
    await gridMassAction(page, 'delete', 'all');
    await expect(page.locator('.message-success')).toContainText('2 attribute(s) have been deleted');
    expect(codes.map((code) => attributeRow('address', code).exists)).toEqual([false, false]);
    expect(attributeRow('address', 'e2e_a_text').exists).toBe(true);
});
