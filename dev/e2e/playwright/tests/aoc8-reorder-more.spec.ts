import type { Page } from '@playwright/test';
import {
    addAdminOrderProduct, adminOrderIdle, benchCommand, chooseAdminPayment, chooseAdminShipping, chosenLabels, completeAdminOrder, createAttributes, customerEmail, deleteCreated,
    deleteSavedAddresses, expect, fillCheckout, fillOrderAddress, fillOrderValues, ITA_PRODUCT, requireSecondWebsite, openAdminOrder, openCheckoutAsGuest, orderField, placeOrder,
    placedOrderSnapshot, setAccountValues, snapshotOf, startAdminOrder, submitAdminOrder, test, chooseShippingAndPayment, uniqueEmail,
} from './helpers';

/**
 * AOC8 (more): reorder from the admin when the old order is not the plain case: an address attribute deleted since, a guest order, and an
 * order of the Italy store. The first reorder test is in aoc-admin-order-more.spec.ts.
 */
const email = customerEmail();
const TYPED = { e2e_a_text: 'Typed text é', e2e_a_sel: 'South', e2e_a_multi: ['Red', 'Blue'], e2e_a_date: '5/6/2026', e2e_a_area: 'First line\nSecond line', e2e_a_bool: 'Yes' };
const ITALY = { postcode: '00100', city: 'Roma', telephone: '0612345678' };

test.afterEach(() => {
    deleteSavedAddresses(email);
});

/**
 * e2e_c_areq is required and only in the account forms: core refuses an admin order without it when the order has no customer account to
 * take it from (as in AOC7). It is optional for the test and put back afterwards.
 */
async function withoutAccountRequired(run: () => Promise<void>): Promise<void> {
    const forms = 'forms=customer_account_create,customer_account_edit';
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_c_areq', 'required=0', forms]);
    try {
        await run();
    } finally {
        benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_c_areq', 'required=1', forms]);
    }
}

/** Opens the Reorder form of the order that is open in the admin. */
async function reorder(page: Page): Promise<void> {
    await page.locator('#order_reorder, button:has-text("Reorder")').first().click();
    await expect(page).toHaveURL(/sales\/order_create/);
    await adminOrderIdle(page);
    await expect(page.locator('#order-items')).toContainText('24-MB04');
}

/** Like `chooseAdminPayment`, but looks at the radio without waiting for it, then loads the payment methods and chooses. */
async function choosePaymentWithoutWaiting(page: Page): Promise<void> {
    const payment = page.locator('#p_method_checkmo');
    if (await payment.isChecked({ timeout: 1000 }).catch(() => false)) {
        return;
    }
    await expect(async () => {
        const link = page.locator('#order-billing_method_summary a').first();
        if (await link.count()) {
            await link.click({ timeout: 2000 }).catch(() => undefined);
        }
        await payment.click({ timeout: 4000 });
        await adminOrderIdle(page);
        await expect(page.locator('#p_method_checkmo')).toBeChecked({ timeout: 3000 });
    }).toPass({ timeout: 40_000 });
}

/** Fills what the form asks for again (values hidden by a rule on the old order, required customer values of a guest) and places the order. */
async function placeReorder(page: Page, order: 'customer' | 'guest' = 'customer'): Promise<{ incrementId: string; orderId: string }> {
    await fillOrderValues(page, 'billing_address', { e2e_a_rdep: 'required dep' });
    for (const code of ['e2e_c_req', 'e2e_c_rdep', 'e2e_c_rmulti']) {
        const field = orderField(page, 'account', code);
        if ((await field.count()) === 0 || !(await field.first().isVisible())) {
            continue;
        }
        if ((await field.first().evaluate((node) => node.tagName.toLowerCase())) === 'select') {
            if ((await chosenLabels(field.first())).length === 0) {
                await field.first().selectOption({ index: 1 }, { timeout: 5000 });
            }
        } else if ((await field.first().inputValue()) === '') {
            await field.first().fill('filled again', { timeout: 5000 });
        }
    }
    // the payment block reloads the shipping block of a reorder, so payment first. A guest order has no payment method listed until
    // the block is loaded, so this does not wait for the radio as the shared helper does when nothing is chosen yet.
    if (order === 'guest') {
        await choosePaymentWithoutWaiting(page);
    } else {
        await chooseAdminPayment(page);
    }
    await chooseAdminShipping(page);
    return submitAdminOrder(page);
}

test('AOC8: reorder after an attribute was deleted: its value is left out, the others are copied', { tag: ['@AOC8'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    createAttributes('address', ['e2e_a_gone']);
    let first: { incrementId: string; orderId: string };
    try {
        await startAdminOrder(page, email);
        first = await completeAdminOrder(page, { ...TYPED, e2e_a_gone: 'value of a deleted attribute' });
        expect(snapshotOf(first.incrementId).billing.e2e_a_gone).toMatchObject({ value: 'value of a deleted attribute' });
    } finally {
        deleteCreated('e2e_a_gone');
    }

    await openAdminOrder(page, first.incrementId);
    await reorder(page);
    await expect(orderField(page, 'billing_address', 'e2e_a_gone'), 'the deleted attribute has no field').toHaveCount(0);
    await expect(orderField(page, 'billing_address', 'e2e_a_text')).toHaveValue('Typed text é');
    expect(await chosenLabels(orderField(page, 'billing_address', 'e2e_a_multi'))).toEqual(['Red', 'Blue']);
    const second = await placeReorder(page);
    await expect(page.locator('.message-error')).toHaveCount(0);

    // the old order keeps the value of the deleted attribute; the new one has no row for it
    const a = snapshotOf(first.incrementId);
    const b = snapshotOf(second.incrementId);
    expect(a.billing.e2e_a_gone, 'the old order keeps its snapshot').toMatchObject({ value: 'value of a deleted attribute' });
    expect(b.billing.e2e_a_gone).toBeUndefined();
    expect(b.shipping.e2e_a_gone).toBeUndefined();
    const { e2e_a_gone: _gone, ...rest } = a.billing;
    expect(b.billing).toEqual(rest);
});

test('AOC8: reorder of a guest order copies the address values', { tag: ['@AOC8'] }, async ({ page, adminPage, magewire }) => {
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('reguest'), e2e_a_text: 'guest address text' });
    await chooseShippingAndPlace(page, magewire);
    const { incrementId } = await placedOrderSnapshot(page);
    const before = snapshotOf(incrementId);
    expect(before.billing.e2e_a_text, 'the guest order has the address value').toMatchObject({ value: 'guest address text' });

    await withoutAccountRequired(async () => {
        await openAdminOrder(adminPage, incrementId);
        await reorder(adminPage);
        await expect(orderField(adminPage, 'billing_address', 'e2e_a_text')).toHaveValue('guest address text');
        const second = await placeReorder(adminPage, 'guest');
        await expect(adminPage.locator('.message-error')).toHaveCount(0);
        const after = snapshotOf(second.incrementId);
        expect(after.billing.e2e_a_text).toMatchObject({ value: 'guest address text' });
        expect(after.billing.e2e_a_req).toEqual(before.billing.e2e_a_req);
    });
});

test('AOC8: reorder of an Italy store order keeps the store and the values', { tag: ['@AOC8'] }, async ({ page, adminPage, magewire }) => {
    requireSecondWebsite();
    await openCheckoutAsGuest(page, ITA_PRODUCT);
    await fillCheckout(page, magewire, { ...ITALY, email_address: uniqueEmail('reita'), e2e_a_text: 'italian address text' }, 'Roma');
    await chooseShippingAndPlace(page, magewire);
    const { incrementId } = await placedOrderSnapshot(page);
    expect(incrementId.startsWith('2'), 'Italy order numbers start with 2').toBe(true);
    const before = snapshotOf(incrementId);
    expect(before.billing.e2e_a_text).toMatchObject({ value: 'italian address text' });

    await withoutAccountRequired(async () => {
        await openAdminOrder(adminPage, incrementId);
        await reorder(adminPage);
        await expect(orderField(adminPage, 'billing_address', 'e2e_a_text')).toHaveValue('italian address text');
        const second = await placeReorder(adminPage, 'guest');
        await expect(adminPage.locator('.message-error')).toHaveCount(0);
        expect(second.incrementId.startsWith('2'), 'the new order is on the Italy store too').toBe(true);
        const after = snapshotOf(second.incrementId);
        expect(after.billing.e2e_a_text).toMatchObject({ value: 'italian address text' });
    });
});

/** Shipping, payment and "Place order" of the storefront checkout. */
async function chooseShippingAndPlace(page: Page, magewire: Parameters<typeof chooseShippingAndPayment>[1]): Promise<void> {
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
}

test('AOC12: reorder carries the customer attribute values of the order to Account Information', { tag: ['@AOC12'] }, async ({ adminPage: page }) => {
    // e2e_c_order is in the admin order create form only: the customer account never has a value, only the order does
    setAccountValues(email, { e2e_c_text: 'seed text', e2e_c_order: '' });
    await startAdminOrder(page, email);
    await addAdminOrderProduct(page, '24-MB04');
    await fillOrderAddress(page, 'billing_address');
    await fillOrderValues(page, 'billing_address', { e2e_a_req: 'required text', e2e_a_rdep: 'required dep' });
    await fillOrderValues(page, 'account', { e2e_c_order: 'from the first order' });
    await chooseAdminShipping(page);
    await chooseAdminPayment(page);
    const first = await submitAdminOrder(page);
    expect(snapshotOf(first.incrementId).customer.e2e_c_order).toMatchObject({ value: 'from the first order' });
    setAccountValues(email, { e2e_c_order: '' });

    await openAdminOrder(page, first.incrementId);
    await reorder(page);
    await expect(orderField(page, 'account', 'e2e_c_order'), 'shown from the old order').toHaveValue('from the first order');
    const second = await placeReorder(page);
    expect(snapshotOf(second.incrementId).customer.e2e_c_order, 'on the new order').toMatchObject({ value: 'from the first order' });
});
