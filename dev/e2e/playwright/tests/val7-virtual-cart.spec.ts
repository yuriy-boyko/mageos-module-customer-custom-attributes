import { chooseShippingAndPayment, expect, fillCheckout, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, requireFixtures, sections, test } from './helpers';

/**
 * A guest buys only a virtual product (fixture e2e-virtual-product): there is no shipping step.
 * VAL7: the shipping values are not validated; the order is placed with the billing values only.
 * CCO22: the required customer attribute e2e_c_req sits at a shipping position, which a virtual cart does not show: it does not block.
 * CUS10: a guest order with no customer attribute values has no customer rows and no errors.
 */
test('an order with a virtual product is placed with billing values only', { tag: ['@VAL7', '@CCO22', '@CUS10'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page, '/e2e-virtual-product.html');
    await requireFixtures(page, ['e2e_a_req', 'e2e_c_sel']);

    // only the sections that are not at a shipping position are there
    expect(await sections(page)).toEqual({
        payment_above: ['e2e_c_sel', 'e2e_c_date'],
        payment_below: ['e2e_c_multi', 'e2e_c_area'],
        order_summary: ['e2e_c_bool'],
    });
    await expect(page.locator('[name="e2e_c_req"]')).toHaveCount(0);
    await expect(page.locator('[name="shipping-method-option"]')).toHaveCount(0);

    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);

    const { snapshot } = await placedOrderSnapshot(page);
    // the billing address has its values, there is no shipping address, no customer attribute has a value
    expect(snapshot.billing.e2e_a_req?.value).toBe('required value');
    expect(snapshot.shipping).toEqual({});
    expect(snapshot.customer).toEqual({});
});
