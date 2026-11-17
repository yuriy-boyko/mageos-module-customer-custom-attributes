import type { Frame, Page } from '@playwright/test';
import { benchCommand, expect, fillCheckout, openCheckoutAsGuest, placedOrderSnapshot, placeOrder, quoteValues, test, uniqueEmail } from './helpers';

/**
 * ORD4: the card payment of Stripe (test mode) with a card that asks for 3D Secure. The test card and the page of the 3D Secure test are
 * the ones Stripe publishes for test mode; they are used only inside the shop's own checkout on the test store.
 *  - COMPLETE: the order is placed like a guest order (values on the order).
 *  - FAIL: no order and no order rows are left, the values stay on the quote.
 * ORD5 (card declined): the test card that Stripe always declines. Every run creates test payments in the Stripe test account of the store.
 * It needs the Stripe test mode keys of the store and a network connection to Stripe; without a Stripe payment method it is skipped.
 */
const THREE_D_SECURE = { number: '4000002760003184', expiry: '1234', cvc: '123' };
const DECLINED = { number: '4000000000000002', expiry: '1234', cvc: '123' }; // ORD5: the card is declined
let card = THREE_D_SECURE;
const TEXT = 'value before the 3D Secure step';

/** The frame of the Stripe card form: the one that holds the card number input. */
async function cardFrame(page: Page): Promise<Frame | undefined> {
    for (const frame of page.frames()) {
        if (!/stripe/.test(frame.url())) continue;
        if ((await frame.locator('input[name="number"]').count().catch(() => 0)) > 0) return frame;
    }
    return undefined;
}
const describeFrames = (page: Page): string => page.frames().map((frame) => frame.url().replace(/#.*/, '').slice(0, 90)).join(' | ');

async function fillStripeCard(page: Page): Promise<void> {
    await expect.poll(async () => (await cardFrame(page)) !== undefined, { timeout: 45_000, message: `the Stripe card form; frames: ${describeFrames(page)}` }).toBe(true);
    const frame = (await cardFrame(page)) as Frame;
    const field = (name: string) => frame.locator(`input[name="${name}"]`).first();
    await field('number').waitFor({ state: 'visible', timeout: 30_000 });
    await field('number').pressSequentially(card.number, { delay: 40 });
    await field('expiry').pressSequentially(card.expiry, { delay: 40 });
    await field('cvc').pressSequentially(card.cvc, { delay: 40 });
    const postal = frame.locator('input[name="postalCode"]').first();
    if (await postal.isVisible().catch(() => false)) await postal.fill('75001');
}

/** Opens the checkout with a value on the address, chooses Stripe and fills the card of the 3D Secure test. */
async function prepare(page: Page, magewire: { idle(): Promise<void> }, email: string): Promise<void> {
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire as Parameters<typeof fillCheckout>[1], { email_address: email, e2e_a_text: TEXT });
    await page.locator('[name="shipping-method-option"]').first().check();
    await magewire.idle();
    const stripe = page.locator('[name="payment-method-option"][value="stripe_payments"]');
    test.skip((await stripe.count()) === 0, 'the Stripe payment method is not available on this store');
    await stripe.check();
    await magewire.idle();
    await fillStripeCard(page);
}

/** Presses Complete or Fail on the 3D Secure test page of Stripe (a frame inside a frame inside the checkout page). */
async function answerThreeDSecure(page: Page, answer: 'Complete' | 'Fail'): Promise<void> {
    const press = async (): Promise<boolean> => {
        for (const frame of page.frames()) {
            if (!/stripe/.test(frame.url())) continue;
            const button = frame.getByRole('button', { name: new RegExp(`^${answer}$`, 'i') });
            if ((await button.count().catch(() => 0)) > 0 && (await button.first().isVisible().catch(() => false))) {
                await button.first().click();
                return true;
            }
        }
        return false;
    };
    await expect.poll(press, { timeout: 90_000, intervals: [1000], message: `the 3D Secure test page; frames: ${describeFrames(page)}` }).toBe(true);
}

/**
 * Stripe's 3D Secure dialog loads a one-pixel data: image, which the store's CSP ("img-src 'self'") blocks. That belongs to the store's
 * CSP configuration and to Stripe, not to the module: the entry is taken out of the page problems of this test, everything else still counts.
 */
function ignoreStripePixel(problems: string[]): void {
    for (let index = problems.length - 1; index >= 0; index--) {
        if (problems[index].includes("data:image/png;base64,iVBORw0KGgo=")) problems.splice(index, 1);
    }
}

test.beforeEach(() => {
    card = THREE_D_SECURE;
});

test.afterEach(async ({ page, problems }) => {
    await page.waitForTimeout(1500); // the dialog reports the blocked pixel a few times
    ignoreStripePixel(problems);
});

test('ORD4: 3D Secure COMPLETE: the order is placed with its values', { tag: ['@ORD4'] }, async ({ page, magewire }) => {
    test.setTimeout(240_000);
    const email = uniqueEmail('ord4ok');
    await prepare(page, magewire, email);
    await placeOrder(page);
    await answerThreeDSecure(page, 'Complete');
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: TEXT });
    expect(snapshot.billing.e2e_a_text).toMatchObject({ value: TEXT });
});

test('ORD4: 3D Secure FAIL: no order and no order rows, the values stay on the quote', { tag: ['@ORD4'] }, async ({ page, magewire }) => {
    test.setTimeout(240_000);
    const email = uniqueEmail('ord4fail');
    await prepare(page, magewire, email);
    await placeOrder(page);
    await answerThreeDSecure(page, 'Fail');
    await expect(page).toHaveURL(/\/checkout\/?$/, { timeout: 30_000 });
    await expect(page.locator('[role="alert"]').first()).toBeVisible({ timeout: 30_000 });
    // the refused payment leaves no order (an order made before the authentication is cancelled and removed from the count of this e-mail)
    const result = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['orders-of', email]));
    expect(result.customer_rows, 'no order rows').toBe(0);
    expect(result.orders, 'no order').toBe(0);
    const quote = quoteValues('@latest');
    expect(Object.values(quote.addresses ?? {}).some((values) => values.e2e_a_text === TEXT), 'the values stay on the quote').toBe(true);
});

test('ORD5: a declined card: no order and no order rows, the values stay on the quote', { tag: ['@ORD5'] }, async ({ page, magewire }) => {
    test.setTimeout(240_000);
    card = DECLINED;
    const email = uniqueEmail('ord5card');
    await prepare(page, magewire, email);
    await placeOrder(page);
    await expect(page.locator('[role="alert"]').filter({ hasText: /went wrong|declined|card/i }).first()).toBeVisible({ timeout: 60_000 });
    await expect(page).toHaveURL(/\/checkout\/?$/);
    expect(JSON.parse(benchCommand('E2E_ASSERT_CMD', ['orders-of', email])), 'no order and no order rows').toEqual({ orders: 0, customer_rows: 0 });
    const quote = quoteValues('@latest');
    expect(Object.values(quote.addresses ?? {}).some((values) => values.e2e_a_text === TEXT), 'the values stay on the quote').toBe(true);
});
