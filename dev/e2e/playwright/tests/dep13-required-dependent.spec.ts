import { chooseShippingAndPayment, expect, field, fillCheckout, openCheckoutAsGuest, placeOrder, requireFixtures, test } from './helpers';

/**
 * DEP13: a required dependent field that is shown and left empty blocks the order, with a message naming the field.
 *
 * Fixture: e2e_a_rdep is required and shown when e2e_a_rsel is "Show". Everything else in the form is valid, so the only thing that can
 * block the order is the empty dependent. As a positive control the same order is placed once the dependent has a value.
 */
test('a shown required dependent field left empty blocks the order', { tag: ['@DEP13'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_rsel', 'e2e_a_rdep']);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);

    const parent = field(page, 'e2e_a_rsel');
    const dependent = field(page, 'e2e_a_rdep');
    await expect(dependent).toBeHidden();

    await parent.selectOption({ label: 'Show' });
    await magewire.idle();
    await expect(dependent).toBeVisible();

    // blocked: still on the checkout, the field is marked and its message names it
    await placeOrder(page);
    const wrapper = page.locator('.field-e2e_a_rdep');
    await expect(wrapper).toHaveClass(/field-error/);
    await expect(page.getByText('E2E Address Required Dependent field is required.').first()).toBeVisible();
    await expect(page).toHaveURL(/\/checkout\/?$/);

    // positive control: with a value the same order is placed
    await dependent.fill('now filled');
    await dependent.press('Tab');
    await magewire.idle();
    await placeOrder(page);
    await expect(page).toHaveURL(/checkout\/onepage\/success/, { timeout: 30_000 });
});

test('a required dependent field that is hidden does not block the order', { tag: ['@DEP13~'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_rsel', 'e2e_a_rdep']);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);

    await field(page, 'e2e_a_rsel').selectOption({ label: 'Hide' });
    await magewire.idle();
    await expect(field(page, 'e2e_a_rdep')).toBeHidden();

    await placeOrder(page);
    await expect(page).toHaveURL(/checkout\/onepage\/success/, { timeout: 30_000 });
});
