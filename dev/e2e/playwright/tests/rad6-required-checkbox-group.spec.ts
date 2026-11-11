import { chooseShippingAndPayment, expect, field, fillCheckout, openCheckoutAsGuest, pickRadio, placeOrder, placedOrderSnapshot, requireFixtures, test, tickCheckbox } from './helpers';

/**
 * RAD6: a required checkbox group with nothing ticked blocks the order (the server checks it: a single checkbox cannot carry the
 * browser's own "required"). Fixture: e2e_c_rmulti (Alpha, Beta) is required and, like e2e_c_rdep, shown when e2e_c_sel is Silver.
 */
test('a required checkbox group with nothing ticked blocks the order', { tag: ['@RAD6'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_sel', 'e2e_c_rmulti']);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);

    await pickRadio(page, 'e2e_c_sel', 'Silver');
    await magewire.idle();
    await expect(field(page, 'e2e_c_rmulti')).toBeVisible();
    await field(page, 'e2e_c_rdep').fill('silver text');
    await field(page, 'e2e_c_rdep').press('Tab');
    await magewire.idle();

    // nothing ticked: blocked, the message names the attribute
    await placeOrder(page);
    await expect(page.getByText(/"E2E Customer Required Multi" is a required value/).first()).toBeVisible();
    await expect(page).toHaveURL(/\/checkout\/?$/);

    // positive control: one box ticked, the order is placed with it
    await tickCheckbox(page, 'e2e_c_rmulti', 'Beta');
    await magewire.idle();
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer.e2e_c_rmulti?.value_label).toBe('Beta');
});
