import {
    attributeRow, benchCommand, chooseShippingAndPayment, createAttributes, deleteCreated, expect, field, fillCheckout, openAttribute, openCheckoutAsGuest,
    placeOrder, placedOrderSnapshot, quoteValues, saveAttribute, test, uniqueEmail,
} from './helpers';

/**
 * SRC1, SRC2: an attribute whose options come from a source model of a developer (a data patch), not from the option table.
 * The tests use Magento's own Yes/No source (`Eav\Model\Entity\Attribute\Source\Boolean`: Yes = 1, No = 0) on a dropdown: it is not the
 * table source and its "No" is the value 0. The attributes are created and deleted by the tests (codes e2e_a_src...).
 *  - SRC1: the edit page lists the options read-only (no option table), saving keeps the source model and writes no option rows; a
 *    normal dropdown still has its option table.
 *  - SRC2: a required dropdown of this kind in the checkout: the value 0 ("No") is a value, reaches the quote, the order and the snapshot
 *    with its label.
 */
const SOURCE = 'Magento\\Eav\\Model\\Entity\\Attribute\\Source\\Boolean';
const DEVELOPER = 'e2e_a_srcdev';
const TABLE = 'e2e_a_srctab';
const CHECKOUT = 'e2e_a_srcreq';
const FORMS = ['customer_address_edit', 'mageos_address_text'];

test.afterEach(() => {
    for (const code of [DEVELOPER, TABLE, CHECKOUT]) {
        deleteCreated(code);
    }
});

test('SRC1: the options of a developer source are listed read-only on the edit page and survive a save', { tag: ['@SRC1'] }, async ({ adminPage: page }) => {
    createAttributes('address', [DEVELOPER], FORMS, undefined, ['--input=select', `--source=${SOURCE}`]);
    const before = attributeRow('address', DEVELOPER);
    expect(before).toMatchObject({ input: 'select', source_model: SOURCE });
    expect(before.options, 'the options are not in the option table').toEqual([]);

    await openAttribute(page, 'address', DEVELOPER);
    const panel = page.locator('#mageos-cca-developer-options');
    await expect(panel).toBeVisible();
    await expect(panel.locator('tbody tr')).toHaveCount(2);
    await expect(panel.locator('tbody tr', { hasText: 'Yes' }).locator('td').first()).toHaveText('1');
    await expect(panel.locator('tbody tr', { hasText: 'No' }).locator('td').first(), 'the value 0 is listed').toHaveText('0');
    await expect(panel).toContainText(SOURCE);
    await expect(page.locator('#manage-options-panel'), 'no editable option table').toHaveCount(0);

    // saving asks for no options and keeps the source model; the rest of the form still saves
    await page.locator('#frontend_label_0, [name="frontend_label[0]"]').first().fill('Developer source renamed');
    await saveAttribute(page, true);
    await expect(page.locator('.message-success')).toBeVisible();
    await expect(page.locator('#mageos-cca-developer-options')).toBeVisible();
    const after = attributeRow('address', DEVELOPER);
    expect(after).toMatchObject({ label: 'Developer source renamed', source_model: SOURCE });
    expect(after.options, 'still no option rows').toEqual([]);
});

test('SRC1: a dropdown of the option table still has its editable option table', { tag: ['@SRC1'] }, async ({ adminPage: page }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'address', `--forms=${FORMS.join(',')}`, '--input=select', '--options=Alpha,Beta', TABLE]);
    await openAttribute(page, 'address', TABLE);
    await expect(page.locator('#manage-options-panel')).toBeVisible();
    await expect(page.locator('#mageos-cca-developer-options')).toHaveCount(0);
});

test('SRC2: a required dropdown of a developer source takes the value 0 in the checkout', { tag: ['@SRC2'] }, async ({ page, magewire }) => {
    createAttributes('address', [CHECKOUT], ['customer_address_edit', 'mageos_hyva_checkout', 'mageos_address_text'], undefined, ['--input=select', `--source=${SOURCE}`]);
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', CHECKOUT, 'required=1', 'forms=customer_address_edit,mageos_hyva_checkout,mageos_address_text']);
    const email = uniqueEmail('src2');
    await openCheckoutAsGuest(page);
    await expect(field(page, CHECKOUT)).toBeAttached();
    await fillCheckout(page, magewire, { email_address: email });
    const labels = (await field(page, CHECKOUT).locator('option').allInnerTexts()).map((label) => label.trim());
    expect(labels, 'the options come from the source').toEqual(expect.arrayContaining(['Yes', 'No']));

    await field(page, CHECKOUT).selectOption({ label: 'No' });
    await magewire.idle();
    await expect.poll(() => {
        try {
            return Object.values(quoteValues('@latest').addresses ?? {}).some((values) => values[CHECKOUT] === '0');
        } catch {
            return false;
        }
    }, { timeout: 20_000 }).toBe(true);

    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    await expect(page.locator('.message-error')).toHaveCount(0);
    const { snapshot } = await placedOrderSnapshot(page);
    for (const address of [snapshot.shipping, snapshot.billing]) {
        expect(address[CHECKOUT], 'the value 0 is on the order with its label').toMatchObject({ value: '0', value_label: 'No' });
    }
});
