import {
    chooseShippingAndPayment, expect, field, fillCheckout, openCheckoutAsGuest, placeOrder, placedOrderSnapshot, requireFixtures, test,
} from './helpers';

/**
 * VAL5: a value the server rejects (here a text that is longer than the attribute allows, sent as a hand-made request would send it,
 * past the browser's own checks) gives a readable message at the field (and at the top of the page on Hyvä Checkout 1.3); the order is not placed and nothing the customer
 * typed is lost. Once the value is corrected the same order is placed.
 *
 * Fixture: e2e_a_len is a text with 3 to 6 characters.
 */
test('a rejected value gives a readable message, the typed values are kept, the corrected order is placed', { tag: ['@VAL5'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_len', 'e2e_a_text']);
    await fillCheckout(page, magewire, { e2e_a_text: 'kept text', e2e_c_text: 'kept customer text' });
    await chooseShippingAndPayment(page, magewire);

    // the value goes to the server without the browser's checks
    await page.evaluate(() => {
        const input = document.querySelector('input[data-form="shipping"][name="e2e_a_len"]');
        const root = input && input.closest('[wire\\:id]');
        const id = root && root.getAttribute('wire:id');
        // Magewire 3 (Hyvä Checkout 3) has $set on window.Livewire; Magewire 1 has set on window.Magewire
        type Wire = { set?(property: string, value: string): void; $set?(property: string, value: string): void };
        const globals = window as unknown as { Livewire?: { find(id: string): Wire }; Magewire?: { find(id: string): Wire } };
        const wire = (globals.Livewire ?? globals.Magewire)!.find(id as string);
        if (wire.$set) {
            wire.$set('address.e2e_a_len', 'far too long for the rule');
        } else {
            wire.set!('address.e2e_a_len', 'far too long for the rule');
        }
    });
    await magewire.idle();
    await placeOrder(page);

    // readable: a message at the top of the page and one at the field, which names the attribute by its label and the limit
    // Hyvä Checkout 1.3 also shows a message at the top; Checkout 3 shows only the field errors of a refused save
    const fieldMessage = page.locator('.field-e2e_a_len');
    await expect(fieldMessage).toContainText('E2E Address Length field cannot be longer than 6 characters', { timeout: 15_000 });
    const banner = page.getByRole('alert').filter({ hasText: /\S/ }).first();
    if (await banner.isVisible()) {
        const bannerText = (await banner.innerText()).replace(/\s+/g, ' ');
        expect(bannerText, 'no code, class name or stack trace').not.toMatch(/e2e_a_len|Exception|\\|#\d/);
    }
    await expect(page).toHaveURL(/\/checkout\/?$/);

    // nothing typed is lost, and the shipping and payment choices are still there
    await expect(field(page, 'e2e_a_text')).toHaveValue('kept text');
    await expect(field(page, 'e2e_c_text')).toHaveValue('kept customer text');
    await expect(field(page, 'street[0]')).toHaveValue('1 Test Street');
    await expect(page.locator('[name="shipping-method-option"]').first()).toBeChecked();
    await expect(page.locator('[name="payment-method-option"][value="checkmo"]')).toBeChecked();

    // corrected: the same order is placed
    await field(page, 'e2e_a_len').fill('good');
    await field(page, 'e2e_a_len').press('Tab');
    await magewire.idle();
    await placeOrder(page);
    const { snapshot } = await placedOrderSnapshot(page);
    expect(snapshot.shipping.e2e_a_len).toMatchObject({ value: 'good' });
    expect(snapshot.shipping.e2e_a_text).toMatchObject({ value: 'kept text' });
    expect(snapshot.customer.e2e_c_text).toMatchObject({ value: 'kept customer text' });
});
