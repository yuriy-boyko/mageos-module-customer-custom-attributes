import type { Page } from '@playwright/test';
import { adminOpen, benchCommand, chooseShippingAndPayment, expect, field, fillCheckout, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, test } from './helpers';

/**
 * VAL6: an address attribute that is required and only in the address book form ("Address Book (Customer Account)").
 *
 * Magento validates every required attribute of the address book form on the address of the quote at the checkout. The Hyvä Checkout
 * address form does not show the attribute, so nobody can fill it and no order can be placed. The module keeps core's behaviour and
 * warns in the admin (on the attribute's edit page) so the shop owner can add the attribute to "Hyvä Checkout Address Forms" or make it
 * optional.
 *
 * Fixture: e2e_a_warn is an optional address attribute in all storefront forms; the tests change it and put it back.
 */
const ALL_FORMS = 'customer_address_edit,customer_register_address,mageos_hyva_checkout,mageos_address_text';
const WARNING = /is required and in the address book form, but not in the Hyvä Checkout address forms/;

const setState = (required: boolean, forms: string) =>
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_warn', `required=${required ? 1 : 0}`, `forms=${forms}`]);

async function openAttribute(page: Page, code: string): Promise<void> {
    await adminOpen(page, 'mageos_cca/attribute/index/entity/address');
    const filter = page.locator('[name="attribute_code"]');
    await filter.fill(code);
    await filter.press('Enter');
    await page.locator('tbody tr', { hasText: code }).first().click();
    await expect(page).toHaveURL(/mageos_cca\/attribute\/edit/);
}

test.afterEach(() => {
    setState(false, ALL_FORMS);
});

test('the admin warns when an attribute is required and only in the address book form', { tag: ['@VAL6'] }, async ({ adminPage: page }) => {
    // required, address book only: the warning shows on the edit page
    setState(true, 'customer_address_edit');
    await openAttribute(page, 'e2e_a_warn');
    await expect(page.locator('.message-warning').filter({ hasText: WARNING })).toBeVisible();

    // also in the Hyvä Checkout address forms: no warning
    setState(true, 'customer_address_edit,mageos_hyva_checkout');
    await openAttribute(page, 'e2e_a_warn');
    await expect(page.locator('.message-warning').filter({ hasText: WARNING })).toHaveCount(0);

    // optional: no warning
    setState(false, 'customer_address_edit');
    await openAttribute(page, 'e2e_a_warn');
    await expect(page.locator('.message-warning').filter({ hasText: WARNING })).toHaveCount(0);
});

test('such an attribute blocks the checkout until it is added to the Hyvä Checkout address forms', { tag: ['@VAL6'] }, async ({ page, magewire }) => {
    setState(true, 'customer_address_edit');
    await openCheckoutAsGuest(page);
    await expect(page.locator('[name="e2e_a_warn"]')).toHaveCount(0);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);

    // core's behaviour: nobody can fill the attribute, the order is refused
    await placeOrder(page);
    await expect(page.getByText(/"E2E Address Warn" is a required value/).first()).toBeVisible();
    await expect(page).toHaveURL(/\/checkout\/?$/);

    // the fix the warning asks for: add it to the Hyvä Checkout address forms, then fill it
    setState(true, 'customer_address_edit,mageos_hyva_checkout');
    await openCheckoutAsGuest(page);
    await expect(field(page, 'e2e_a_warn')).toBeVisible();
    await fillCheckout(page, magewire, { e2e_a_warn: 'now filled' });
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.billing.e2e_a_warn?.value).toBe('now filled');
});
