import { chooseShippingAndPayment, expect, field, fillCheckout, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, requireFixtures, test } from './helpers';

/**
 * DEP15: nested rules A -> B -> C. The leaf C shows only when B is shown and has its option, and B shows only when A has its option.
 *
 * Fixture "E2E address nested relation": e2e_a_nsel (B) shows when e2e_a_nroot (A) is "On"; e2e_a_ndep (C) shows when B is "Yes".
 */
test('the leaf of a nested rule shows only when the whole chain matches', { tag: ['@DEP15'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_nroot', 'e2e_a_nsel', 'e2e_a_ndep']);
    const root = field(page, 'e2e_a_nroot');
    const middle = field(page, 'e2e_a_nsel');
    const leaf = field(page, 'e2e_a_ndep');

    // nothing chosen: only the root is there
    await expect(root).toBeVisible();
    await expect(middle).toBeHidden();
    await expect(leaf).toBeHidden();

    // A matches: B appears, C still waits for B
    await root.selectOption({ label: 'On' });
    await magewire.idle();
    await expect(middle).toBeVisible();
    await expect(leaf).toBeHidden();

    // B matches: C appears
    await middle.selectOption({ label: 'Yes' });
    await magewire.idle();
    await expect(leaf).toBeVisible();
    await leaf.fill('leaf value');

    // B stops matching: C goes away and loses its value
    await middle.selectOption({ label: 'No' });
    await magewire.idle();
    await expect(leaf).toBeHidden();
    await middle.selectOption({ label: 'Yes' });
    await magewire.idle();
    await expect(leaf).toBeVisible();
    await expect(leaf).toHaveValue('');

    // A stops matching: B and C both go away (C because its parent is hidden), B loses its value
    await leaf.fill('typed again');
    await root.selectOption({ label: 'Off' });
    await magewire.idle();
    await expect(middle).toBeHidden();
    await expect(leaf).toBeHidden();

    // A matches again: B is back and empty, so C stays hidden
    await root.selectOption({ label: 'On' });
    await magewire.idle();
    await expect(middle).toBeVisible();
    await expect(middle).toHaveValue('');
    await expect(leaf).toBeHidden();
});

test('an order keeps the whole matching chain and drops the leaf when the chain is broken', { tag: ['@DEP15~'] }, async ({ page, magewire }) => {
    // complete chain: all three values are on the order, on both addresses
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_nroot', 'e2e_a_nsel', 'e2e_a_ndep']);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await field(page, 'e2e_a_nroot').selectOption({ label: 'On' });
    await magewire.idle();
    await field(page, 'e2e_a_nsel').selectOption({ label: 'Yes' });
    await magewire.idle();
    await field(page, 'e2e_a_ndep').fill('chain complete');
    await field(page, 'e2e_a_ndep').press('Tab');
    await magewire.idle();
    await placeOrder(page);
    const complete = (await placedOrderSnapshot(page)).snapshot;
    for (const address of [complete.billing, complete.shipping]) {
        expect(address.e2e_a_nroot?.value_label).toBe('On');
        expect(address.e2e_a_nsel?.value_label).toBe('Yes');
        expect(address.e2e_a_ndep?.value).toBe('chain complete');
    }

    // chain broken at B ("No"): the leaf is not on the order
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire);
    await chooseShippingAndPayment(page, magewire);
    await field(page, 'e2e_a_nroot').selectOption({ label: 'On' });
    await magewire.idle();
    await field(page, 'e2e_a_nsel').selectOption({ label: 'No' });
    await magewire.idle();
    await placeOrder(page);
    const broken = (await placedOrderSnapshot(page)).snapshot;
    for (const address of [broken.billing, broken.shipping]) {
        expect(address.e2e_a_nsel?.value_label).toBe('No');
        expect(address.e2e_a_ndep).toBeUndefined();
    }
});
