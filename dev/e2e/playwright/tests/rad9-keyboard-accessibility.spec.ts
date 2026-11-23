import type { Page } from '@playwright/test';
import { customerEmail, deleteSavedAddresses, expect, field, fillCheckout, openCheckoutAsGuest, setAccountValues, test, uniqueEmail } from './helpers';

/**
 * RAD9: radio buttons and checkbox groups can be used with the keyboard and are announced with their label. What is checked here is what a
 * screen reader gets from the page (roles and accessible names) and what the keyboard does; the real screen reader output of a person
 * is not.
 *  - every option is a radio button or checkbox with an accessible name (its own label text), reachable with Tab;
 *  - the group has a role (radiogroup / group) and an accessible name (the label of the attribute);
 *  - arrow keys move and select in a radio group, Space ticks a checkbox, in the checkout and in the account address form;
 *  - nothing in the groups is removed from the focus order (no positive tabindex, no hidden-but-focusable input).
 */
const email = customerEmail();

test.afterEach(() => {
    deleteSavedAddresses(email);
});

async function expectGroupsAreNamed(page: Page, labels: { radio: string; checkbox: string }): Promise<void> {
    await expect(page.getByRole('radiogroup', { name: labels.radio }), 'the radio group is announced with the label of the attribute').toHaveCount(1);
    await expect(page.getByRole('group', { name: labels.checkbox }), 'the checkbox group is announced with the label of the attribute').toHaveCount(1);
}

test('RAD9: the checkout groups are named, reachable and operable with the keyboard', { tag: ['@RAD9~'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('rad9') });
    await expectGroupsAreNamed(page, { radio: 'E2E Address Radio Yes No', checkbox: 'E2E Address Check' });

    // every option has an accessible name of its own and is reachable by the keyboard
    const radios = page.getByRole('radiogroup', { name: 'E2E Address Radio Yes No' }).getByRole('radio');
    const count = await radios.count();
    expect(count, 'None, Yes, No').toBe(3);
    for (let index = 0; index < count; index++) {
        expect((await radios.nth(index).getAttribute('tabindex')) ?? '0', 'no positive tabindex').not.toMatch(/^[1-9]/);
        expect(await radios.nth(index).evaluate((node) => (node as HTMLInputElement).disabled)).toBe(false);
    }
    const names = await radios.evaluateAll((nodes) => nodes.map((node) => (node as HTMLInputElement).labels?.[0]?.innerText.trim() ?? ''));
    expect(names.map((name) => name.replace(/\s+/g, ' ')).filter((name) => name !== ''), 'each radio has its label text').toEqual(expect.arrayContaining(['Yes', 'No']));

    // arrow keys in the radio group (no commit in between: on Hyvä Checkout 3 the address fields are deferred, and the re-render
    // of a commit would move the focus out of the group)
    await radios.first().focus();
    await expect(radios.first()).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await magewire.idle({ commit: false });
    await expect(radios.nth(1), 'ArrowDown moves to the next radio and selects it').toBeChecked();
    await page.keyboard.press('ArrowDown');
    await magewire.idle({ commit: false });
    await expect(radios.nth(2)).toBeChecked();
    await page.keyboard.press('ArrowUp');
    await magewire.idle({ commit: false });
    await expect(radios.nth(1)).toBeChecked();

    // Space ticks and unticks a checkbox; Tab moves from one to the next
    const boxes = page.getByRole('group', { name: 'E2E Address Check' }).getByRole('checkbox');
    expect(await boxes.count()).toBe(3);
    await boxes.first().focus();
    await page.keyboard.press('Space');
    await magewire.idle({ commit: false });
    await expect(boxes.first()).toBeChecked();
    await page.keyboard.press('Tab');
    await expect(boxes.nth(1), 'Tab reaches the next checkbox').toBeFocused();
    await page.keyboard.press('Space');
    await magewire.idle({ commit: false });
    await expect(boxes.nth(1)).toBeChecked();
    await page.keyboard.press('Space');
    await magewire.idle({ commit: false });
    await expect(boxes.nth(1)).not.toBeChecked();
});

test('RAD9: the account address form groups are named and operable with the keyboard', { tag: ['@RAD9~'] }, async ({ customerPage: page }) => {
    deleteSavedAddresses(email);
    setAccountValues(email, { e2e_c_areq: 'account required' });
    await page.goto('/customer/address/new/');
    const radioGroup = page.getByRole('radiogroup', { name: 'E2E Address Radio Yes No' });
    const checkGroup = page.getByRole('group', { name: 'E2E Address Check' });
    await expect(radioGroup, 'the radio group is named').toHaveCount(1);
    await expect(checkGroup, 'the checkbox group is named').toHaveCount(1);
    const radios = radioGroup.getByRole('radio');
    await radios.first().focus();
    await page.keyboard.press('ArrowDown');
    await expect(radios.nth(1)).toBeChecked();
    const boxes = checkGroup.getByRole('checkbox');
    await boxes.first().focus();
    await page.keyboard.press('Space');
    await expect(boxes.first()).toBeChecked();
    // a label click and the keyboard agree: the label text of the option is what a screen reader reads
    await expect(page.getByRole('checkbox', { name: /^(E2E Address Check )?One$/ }).first()).toBeChecked();
});

test('RAD10: a click on the label of a group selects no option, in the checkout and the account address form', { tag: ['@RAD10'] }, async ({ page, magewire, customerPage }) => {
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('rad10') });
    const checkoutGroup = page.getByRole('group', { name: 'E2E Address Check' }).first();
    await expect(checkoutGroup).toBeVisible();
    // the group label of the field (not the label of an option)
    await page.locator('.field-e2e_a_chk').first().locator('label').filter({ hasText: 'E2E Address Check' }).first().click();
    await expect(checkoutGroup.getByRole('checkbox', { checked: true }), 'the checkout label points at no option').toHaveCount(0);

    deleteSavedAddresses(email);
    setAccountValues(email, { e2e_c_areq: 'account required' });
    await customerPage.goto('/customer/address/new/');
    const accountGroup = customerPage.getByRole('group', { name: 'E2E Address Check' });
    await customerPage.locator('.field.e2e_a_chk > label.label').click();
    await expect(accountGroup.getByRole('checkbox', { checked: true }), 'the account form label points at no option').toHaveCount(0);
});
