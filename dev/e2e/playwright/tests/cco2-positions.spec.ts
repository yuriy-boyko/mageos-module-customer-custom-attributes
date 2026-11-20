import { expect, fillCheckout, openCheckoutAsGuest, requireFixtures, sections, test, useVariant, variantTag, walkSteps } from './helpers';

/**
 * CCO2: the customer attribute sections render at their positions, on the right step of each Hyvä Checkout variant.
 *
 * Fixtures (customer attributes, one position each unless noted): shipping_address_above e2e_c_text, shipping_address_below e2e_c_below,
 * shipping_methods_above e2e_c_req, shipping_methods_below e2e_c_order, payment_above e2e_c_sel + e2e_c_date,
 * payment_below e2e_c_multi + e2e_c_area (e2e_c_dep and e2e_c_rdep are hidden by their rules), order_summary e2e_c_bool.
 * The order summary column is on every step of the multi-step checkout; the mobile checkout has it on its own last step.
 */
const shippingStep = {
    shipping_address_above: ['e2e_c_text'],
    shipping_address_below: ['e2e_c_below'],
    shipping_methods_above: ['e2e_c_req', 'e2e_c_xsel'],
    shipping_methods_below: ['e2e_c_order'],
};
const paymentStep = { payment_above: ['e2e_c_sel', 'e2e_c_date'], payment_below: ['e2e_c_multi', 'e2e_c_area'] };
const summary = { order_summary: ['e2e_c_bool'] };

test.describe('one-page checkout', () => {
    useVariant('onepage');
    test('all seven positions are on the page', { tag: ['@CCO2'] }, async ({ page }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_c_text', 'e2e_c_bool']);
        expect(await sections(page)).toEqual({ ...shippingStep, ...paymentStep, ...summary });
    });
});

test.describe('multi-step checkout', variantTag('default'), () => {
    useVariant('default');
    test('shipping positions on the first step, payment positions on the payment step, summary on both', { tag: ['@CCO2'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_c_text', 'e2e_c_bool']);
        await fillCheckout(page, magewire);
        const steps = await walkSteps(page, magewire);
        expect(steps.map((s) => s.step)).toEqual(['first', 'payment']);
        expect(steps[0].sections).toEqual({ ...shippingStep, ...summary });
        expect(steps[1].sections).toEqual({ ...paymentStep, ...summary });
    });
});

test.describe('mobile checkout', variantTag('mobile'), () => {
    useVariant('mobile');
    test('shipping, payment and summary positions each on their own step', { tag: ['@CCO2'] }, async ({ page, magewire }) => {
        await openCheckoutAsGuest(page);
        await requireFixtures(page, ['e2e_c_text']);
        await fillCheckout(page, magewire);
        const steps = await walkSteps(page, magewire);
        expect(steps.map((s) => s.step)).toEqual(['first', 'payment', 'summary']);
        expect(steps[0].sections).toEqual(shippingStep);
        expect(steps[1].sections).toEqual(paymentStep);
        expect(steps[2].sections).toEqual(summary);
    });
});
