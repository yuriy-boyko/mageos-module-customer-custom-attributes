import { attributeRow, benchCommand, deleteCreated, expect, openAttribute, saveAttribute, test } from './helpers';

/**
 * ADM21: an attribute with an input type the module does not offer (another module left it, for example "checkboxs"). The edit page shows
 * the type as it is with a note, instead of pretending it is a text field; saving keeps the type.
 */
const CODE = 'e2e_c_unknowntype';

test.afterEach(() => {
    deleteCreated(CODE);
});

test('ADM21: an unknown input type is shown as not supported and kept when saving', { tag: ['@ADM21'] }, async ({ adminPage: page }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-raw-attribute', 'customer', CODE, '--input=checkboxs']);
    await openAttribute(page, 'customer', CODE);

    const input = page.locator('#frontend_input');
    await expect(input).toHaveValue('checkboxs');
    await expect(input.locator('option:checked')).toHaveText('checkboxs (not supported)');
    await expect(page.locator('#frontend_input-note, .field-frontend_input .note').first()).toContainText('comes from another module');
    await expect(page.locator('#min_text_length')).toBeHidden();

    await page.locator('#attribute_label').fill('Renamed unknown type');
    await saveAttribute(page);
    await expect(page.locator('.message-success')).toBeVisible();
    const row = attributeRow('customer', CODE);
    expect(row.input, 'the type is kept').toBe('checkboxs');
    expect(row.label).toBe('Renamed unknown type');
});
