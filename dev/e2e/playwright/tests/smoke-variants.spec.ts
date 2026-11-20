import { expect, field, openCheckoutAsGuest, requireFixtures, test, useVariant, variantTag } from './helpers';

/**
 * Smoke test of the three Hyvä Checkout variants: the checkout opens, the address fields of the module are there and a customer
 * attribute section (position "above the shipping address", on the first step of every variant) renders. It also proves that the
 * variant switch of the test setup works. Every test also fails on a CSP violation, a script error or a failed Magewire call.
 */
for (const variant of ['onepage', 'default', 'mobile'] as const) {
    test.describe(`variant ${variant}`, variantTag(variant), () => {
        useVariant(variant);

        test(`the checkout renders the module's fields (${variant})`, { tag: ['@quick', '@SMOKE'] }, async ({ page, magewire }) => {
            await openCheckoutAsGuest(page);
            await requireFixtures(page, ['e2e_a_text', 'e2e_c_text']);
            await magewire.idle();
            await expect(field(page, 'e2e_a_text')).toBeVisible();
            await expect(field(page, 'e2e_c_text')).toBeVisible();
            // what tells the variants apart: the one-page checkout has the Place Order button on the same page, the step variants
            // lead to the next step first
            const placeOrder = page.getByRole('button', { name: /^place order$/i });
            const nextStep = page.getByRole('button', { name: /^proceed to /i });
            if (variant === 'onepage') {
                await expect(placeOrder.first()).toBeVisible();
                await expect(nextStep).toHaveCount(0);
            } else {
                await expect(nextStep.first()).toBeVisible();
                await expect(placeOrder.filter({ visible: true })).toHaveCount(0);
            }
        });
    });
}
