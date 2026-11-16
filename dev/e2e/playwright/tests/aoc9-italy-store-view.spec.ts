import {
    addAdminOrderProduct, adminOrderIdle, chooseAdminPayment, chooseAdminShipping, createWebsiteCustomer, expect, fillOrderValues, orderViewAddresses,
    openAdminOrder, snapshotOf, startAdminOrder, submitAdminOrder, test, uniqueEmail,
} from './helpers';

/**
 * AOC9: an admin order in the Italy store view (Italian locale, Italian address form, EUR): the dates typed in the admin form are read
 * correctly and the order carries the values. The admin interface stays in its own locale; the date is typed as the admin shows dates.
 */
test('AOC9: an admin order for the Italy store view keeps the typed values and dates', { tag: ['@AOC9'] }, async ({ adminPage: page }) => {
    const email = uniqueEmail('aocita');
    createWebsiteCustomer(email, 2);
    await startAdminOrder(page, email, 'ITA');
    await addAdminOrderProduct(page, '24-MB04');

    const id = (field: string) => page.locator(`#order-billing_address_${field}`);
    await id('firstname').fill('Maria');
    await id('lastname').fill('Rossi');
    await id('street0').fill('Via Roma 1');
    await id('city').fill('Roma');
    await id('country_id').selectOption('IT');
    await adminOrderIdle(page);
    await id('region_id').selectOption({ label: 'Roma' });
    await id('postcode').fill('00100');
    await id('telephone').fill('0612345678');
    await adminOrderIdle(page);
    await fillOrderValues(page, 'billing_address', { e2e_a_req: 'required text', e2e_a_rdep: 'required dep', e2e_a_text: 'testo italiano é', e2e_a_date: '5/6/2026', e2e_a_sel: 'South' });
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    const { incrementId } = await submitAdminOrder(page);
    expect(incrementId.startsWith('2'), 'an order of the Italy store').toBe(true);

    // 5/6/2026 typed in the admin is 6 May 2026, whatever the store locale
    const snapshot = snapshotOf(incrementId);
    expect(snapshot.billing.e2e_a_date).toMatchObject({ value_label: '2026-05-06' });
    expect(snapshot.billing.e2e_a_text).toMatchObject({ value: 'testo italiano é' });
    expect(snapshot.billing.e2e_a_sel).toMatchObject({ value_label: 'South' });

    // shown again in the admin order view, and the form of a later reopening of the order does not move the day
    await openAdminOrder(page, incrementId);
    const shown = (await orderViewAddresses(page)).billing;
    expect(shown).toMatch(/Testo|testo italiano/i);
    expect(shown).toMatch(/(May 6, 2026|5\/6\/26|5\/6\/2026|2026-05-06|06\/05\/2026|6 May)/);
});
