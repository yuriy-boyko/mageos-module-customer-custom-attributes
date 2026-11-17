import {
    chooseShippingAndPayment, expect, field, fillCheckout, openCheckoutAsGuest, pickRadio, placeOrder, placedOrderSnapshot, quoteValues,
    requireFixtures, sendMagewireValue, test, tickCheckbox, uniqueEmail,
} from './helpers';

/**
 * Guest checkout with the customer attribute sections (one-page variant).
 * CCO9: a guest sees the fields, the values are saved on the guest quote and the order carries them.
 * CCO7: a required customer attribute left empty blocks the order with a message naming it.
 * CCO8: a dependency rule across two positions (e2e_c_sel at "above the payment methods" shows e2e_c_dep at "below the payment methods").
 */
test('a guest types values at every position; they are saved on the quote and on the order', { tag: ['@CCO9', '@CCO6~', '@CCO4~', '@ORD2', '@CCO13'] }, async ({ page, magewire }) => {
    const email = uniqueEmail('guest');
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_text', 'e2e_c_bool']);
    await fillCheckout(page, magewire, { email_address: email });
    await chooseShippingAndPayment(page, magewire);

    // one value per position and input type
    const typed = async (code: string, value: string) => {
        await field(page, code).fill(value);
        await field(page, code).press('Tab');
        await magewire.idle();
    };
    await typed('e2e_c_text', 'guest text');
    await typed('e2e_c_below', 'guest below');
    await typed('e2e_c_order', 'guest order only');
    await pickRadio(page, 'e2e_c_sel', 'Gold');
    await magewire.idle();
    await typed('e2e_c_dep', 'guest dependent');
    await typed('e2e_c_date', '2026-08-08');
    await tickCheckbox(page, 'e2e_c_multi', 'Opt 1');
    await magewire.idle();
    await tickCheckbox(page, 'e2e_c_multi', 'Opt 3');
    await magewire.idle();
    await typed('e2e_c_area', 'guest area');
    await pickRadio(page, 'e2e_c_bool', 'Yes');
    await magewire.idle();

    // saved on the guest quote (a guest has no account to compare with)
    const quote = quoteValues('@latest');
    expect(quote.customer_attributes.e2e_c_text).toBe('guest text');
    expect(quote.customer_attributes.e2e_c_below).toBe('guest below');
    expect(quote.customer_attributes.e2e_c_order).toBe('guest order only');
    expect(quote.customer_attributes.e2e_c_date).toBe('2026-08-08 00:00:00');
    expect(quote.customer_attributes.e2e_c_area).toBe('guest area');
    expect(quote.customer_attributes.e2e_c_bool).toBe('1');
    expect(quote.customer_attributes.e2e_c_multi.split(',')).toHaveLength(2);

    // the order carries the values with their labels
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer.e2e_c_text?.value).toBe('guest text');
    expect(snapshot.customer.e2e_c_below?.value).toBe('guest below');
    expect(snapshot.customer.e2e_c_order?.value).toBe('guest order only');
    expect(snapshot.customer.e2e_c_sel?.value_label).toBe('Gold');
    expect(snapshot.customer.e2e_c_dep?.value).toBe('guest dependent');
    expect(snapshot.customer.e2e_c_date?.value_label).toBe('2026-08-08');
    expect(snapshot.customer.e2e_c_multi?.value_label).toBe('Opt 1, Opt 3');
    expect(snapshot.customer.e2e_c_area?.value).toBe('guest area');
    expect(snapshot.customer.e2e_c_bool?.value_label).toBe('Yes');
    // a guest has no account: attributes that are only on the account forms are not on the order
    expect(snapshot.customer.e2e_c_account).toBeUndefined();
});

test('a required customer attribute left empty blocks the order', { tag: ['@CCO7'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_req']);
    await fillCheckout(page, magewire, { e2e_c_req: '' });
    await chooseShippingAndPayment(page, magewire);

    await placeOrder(page);
    await expect(page.getByText(/"E2E Customer Required" is a required value/).first()).toBeVisible();
    await expect(page).toHaveURL(/\/checkout\/?$/);

    // positive control: with a value the same order is placed
    await field(page, 'e2e_c_req').fill('now typed');
    await field(page, 'e2e_c_req').press('Tab');
    await magewire.idle();
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer.e2e_c_req?.value).toBe('now typed');
});

test('a dependency rule across two positions shows and hides instantly; hidden values are dropped and a hidden required one does not block', { tag: ['@CCO8'] }, async ({ page, magewire }) => {
    const email = uniqueEmail('guest');
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_c_sel', 'e2e_c_dep', 'e2e_c_rdep']);
    await fillCheckout(page, magewire, { email_address: email });
    await chooseShippingAndPayment(page, magewire);

    const dependent = field(page, 'e2e_c_dep');
    const requiredDependent = field(page, 'e2e_c_rdep');
    await expect(dependent).toBeHidden();
    await expect(requiredDependent).toBeHidden();

    // Gold shows the text field, Silver the required one
    await pickRadio(page, 'e2e_c_sel', 'Gold');
    await expect(dependent).toBeVisible();
    await expect(requiredDependent).toBeHidden();
    await dependent.fill('typed while Gold');
    await dependent.press('Tab');
    await magewire.idle();
    await pickRadio(page, 'e2e_c_sel', 'Silver');
    await expect(dependent).toBeHidden();
    await expect(requiredDependent).toBeVisible();

    // a shown required dependent left empty blocks the order
    await placeOrder(page);
    await expect(page.getByText(/"E2E Customer Required Dependent" is a required value/).first()).toBeVisible();
    await expect(page).toHaveURL(/\/checkout\/?$/);

    // back to Gold: the required dependent is hidden and does not block; the hidden text value is dropped
    await pickRadio(page, 'e2e_c_sel', 'Gold');
    await magewire.idle();
    await expect(requiredDependent).toBeHidden();
    await expect(dependent).toBeVisible();
    await expect(dependent).toHaveValue('');
    // a value for a hidden attribute sent by hand (the form would never send it) is dropped by the server
    await pickRadio(page, 'e2e_c_sel', 'Silver');
    await magewire.idle();
    await sendMagewireValue(page, 'payment_below', 'e2e_c_dep', 'sent by hand');
    await magewire.idle();
    expect(quoteValues('@latest').customer_attributes.e2e_c_dep ?? '').toBe('');

    await pickRadio(page, 'e2e_c_sel', 'Gold');
    await magewire.idle();
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.customer.e2e_c_sel?.value_label).toBe('Gold');
    expect(snapshot.customer.e2e_c_dep).toBeUndefined();
    expect(snapshot.customer.e2e_c_rdep).toBeUndefined();
});
