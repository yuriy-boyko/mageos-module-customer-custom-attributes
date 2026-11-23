import { attributeRow, benchCommand, createAttributes, deleteCreated, expect, openAttribute, saveAttribute, test } from './helpers';

/**
 * ADM18 and ADM19: what an attribute save must not touch.
 *  - ADM18: a form assignment the attribute screen does not offer (written by core or another module) survives a save; saving
 *    rewrites all form rows of the attribute.
 *  - ADM19: the option ids posted with the form must belong to the attribute: the EAV resource updates and deletes options by id
 *    only, so a foreign id would change the option of another attribute.
 * Uses attributes the tests create themselves.
 */
const FORM_CODE = 'e2e_adm_forms';
const OPTION_CODE = 'e2e_adm_opts';

test.afterEach(() => {
    deleteCreated(FORM_CODE);
    deleteCreated(OPTION_CODE);
});

test('ADM18: a form row of another module stays when the attribute is saved', { tag: ['@ADM18'] }, async ({ adminPage: page }) => {
    createAttributes('address', [FORM_CODE]);
    benchCommand('E2E_FIXTURE_CMD', ['add-form', FORM_CODE, 'other_module_form']);
    expect(attributeRow('address', FORM_CODE).forms).toContain('other_module_form');

    await openAttribute(page, 'address', FORM_CODE);
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    expect(attributeRow('address', FORM_CODE).forms, 'kept after the save').toContain('other_module_form');
});

test('ADM19: an option id of another attribute posted with the form is refused', { tag: ['@ADM19'] }, async ({ adminPage: page }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'address', '--label=E2E Options', '--input=select', '--options=First,Second', OPTION_CODE]);
    const foreign = attributeRow('address', 'e2e_a_sel').options[0];
    expect(foreign.id).toBeGreaterThan(0);

    await openAttribute(page, 'address', OPTION_CODE);
    // a hand-made request: an option of e2e_a_sel renamed through this attribute's form
    await page.locator('#edit_form').evaluate((form, id) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `option[value][${id}][0]`;
        input.value = 'Renamed through another attribute';
        form.appendChild(input);
    }, foreign.id);
    await saveAttribute(page, true);
    await expect(page.locator('body')).toContainText('The options do not belong to this attribute.');
    expect(attributeRow('address', 'e2e_a_sel').options[0].label, 'the other attribute is unchanged').toBe(foreign.label);
});
