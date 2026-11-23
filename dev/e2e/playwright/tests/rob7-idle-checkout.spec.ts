import type { Page } from '@playwright/test';
import { benchCommand, customerEmail, deleteSavedAddresses, dropQuotes, expect, openCheckoutAsGuest, requireFixtures, setAccountValues, setSavedAddress, test } from './helpers';

/**
 * ROB7: the module's checkout scripts leave the page alone while nobody types. The visibility script and the saved-address notice
 * watch the page for Magewire updates; they once rewrote the field classes and the notice text on every pass and so woke
 * themselves up again without end (about 6,000 changes a second). Counted here: the class changes and the added or removed nodes
 * of the whole page over two seconds of an idle checkout, with and without the notice.
 */
const IDLE_MS = 2000;
const LIMIT = 50;

async function countChanges(page: Page): Promise<{ classChanges: number; nodeChanges: number }> {
    return page.evaluate((duration) => new Promise((resolve) => {
        let classChanges = 0;
        let nodeChanges = 0;
        const observer = new MutationObserver((records) => {
            for (const record of records) {
                if (record.type === 'attributes') { classChanges++; } else { nodeChanges++; }
            }
        });
        observer.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['class'] });
        setTimeout(() => { observer.disconnect(); resolve({ classChanges, nodeChanges }); }, duration);
    }), IDLE_MS);
}

test('ROB7: an idle guest checkout with dependency rules does not change the page', { tag: ['@ROB7'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_sel', 'e2e_a_dep']);
    await magewire.idle();
    await page.waitForTimeout(1000);
    const changes = await countChanges(page);
    expect(changes.classChanges, JSON.stringify(changes)).toBeLessThan(LIMIT);
    expect(changes.nodeChanges, JSON.stringify(changes)).toBeLessThan(LIMIT);
});

test('ROB7: an idle checkout with the saved-address notice does not change the page', { tag: ['@ROB7'] }, async ({ customerPage: page, customerMagewire: magewire }) => {
    const email = customerEmail();
    const forms = 'customer_address_edit,customer_register_address,mageos_hyva_checkout,mageos_address_text';
    dropQuotes(email);
    deleteSavedAddresses(email);
    setAccountValues(email, { e2e_c_text: 'account text', e2e_c_req: 'account required' });
    setSavedAddress(email, { e2e_a_req: 'saved required' });
    benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_warn', 'required=1', `forms=${forms}`]);
    try {
        await openCheckoutAsGuest(page);
        await magewire.idle();
        await expect(page.locator('.mageos-cca-address-notice').first()).toBeVisible();
        await page.waitForTimeout(1000);
        const changes = await countChanges(page);
        expect(changes.classChanges, JSON.stringify(changes)).toBeLessThan(LIMIT);
        expect(changes.nodeChanges, JSON.stringify(changes)).toBeLessThan(LIMIT);
    } finally {
        benchCommand('E2E_FIXTURE_CMD', ['set-attribute', 'e2e_a_warn', 'required=0', `forms=${forms}`]);
        deleteSavedAddresses(email);
    }
});
