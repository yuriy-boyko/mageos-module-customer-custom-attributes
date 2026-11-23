import type { Page } from '@playwright/test';
import {
    chooseShippingAndPayment, customerEmail, deleteSavedAddresses, dropQuotes, expect, field, openCheckoutAsGuest, placeOrder, placedOrderSnapshot,
    quoteValues, setAccountValues, setSavedAddress, test, websiteOverride,
} from './helpers';

/**
 * CHK21: outside the admin, the quote address and the order keep only the attributes of the Hyvä Checkout address forms that are
 * enabled for the website. A saved address brings the values of every attribute; those of an attribute that is in no storefront form
 * (e2e_a_noform) or that is disabled for the website (e2e_a_text with a website override) are dropped. The REST side is in api.php
 * (REG5).
 */
const email = customerEmail();

test.beforeEach(() => {
    dropQuotes(email);
    deleteSavedAddresses(email);
    setAccountValues(email, { e2e_c_text: 'account text', e2e_c_req: 'account required' });
});

test.afterEach(() => {
    websiteOverride('e2e_a_text', 1, { enabled: '-' });
    deleteSavedAddresses(email);
});

async function orderWithSavedAddress(page: Page, magewire: { idle(): Promise<void> }) {
    await openCheckoutAsGuest(page);
    await magewire.idle();
    const typed = field(page, 'e2e_c_req');
    if ((await typed.count()) > 0 && (await typed.inputValue()) === '') {
        await typed.fill('required customer value');
        await typed.press('Tab');
        await magewire.idle();
    }
    const quote = Object.values(quoteValues(email).addresses ?? {});
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    return { quote, snapshot: (await placedOrderSnapshot(page)).snapshot };
}

test('CHK21: the value of an attribute in no storefront form does not reach the quote or the order', { tag: ['@CHK21', '@REG5~'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    setSavedAddress(email, { e2e_a_req: 'saved required', e2e_a_text: 'saved text', e2e_a_noform: 'set in the admin' });
    const { quote, snapshot } = await orderWithSavedAddress(page, magewire);
    expect(quote.length).toBeGreaterThan(0);
    expect(quote.every((values) => values.e2e_a_noform === undefined), 'not on the quote').toBe(true);
    for (const address of [snapshot.shipping, snapshot.billing]) {
        expect(address.e2e_a_text, 'a checkout attribute is kept').toMatchObject({ value: 'saved text' });
        expect(address.e2e_a_noform, 'not on the order').toBeUndefined();
    }
});

test('CHK21: the value of an attribute disabled for the website does not reach the order', { tag: ['@CHK21', '@WEB2~'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    setSavedAddress(email, { e2e_a_req: 'saved required', e2e_a_text: 'saved text', e2e_a_sel: 'South' });
    websiteOverride('e2e_a_text', 1, { enabled: '0' });
    const { snapshot } = await orderWithSavedAddress(page, magewire);
    for (const address of [snapshot.shipping, snapshot.billing]) {
        expect(address.e2e_a_text, 'disabled for the website').toBeUndefined();
        expect(address.e2e_a_sel, 'the others are kept').toMatchObject({ value_label: 'South' });
    }
});
