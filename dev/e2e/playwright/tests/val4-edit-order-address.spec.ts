import { completeAdminOrder, customerEmail, deleteSavedAddresses, expect, openAdminOrder, openOrderAddressEdit, setAccountValues, snapshotOf, startAdminOrder, test } from './helpers';

/**
 * VAL4 (browser): Edit Order Address in the admin with a value that breaks a rule (e2e_a_len: text of 3 to 6 characters). The form
 * refuses it; when the browser's own check is bypassed the server refuses it with a message; nothing is saved either way. A valid value
 * is saved (control).
 */
const email = customerEmail();

test.afterEach(() => {
    deleteSavedAddresses(email);
});

test('VAL4: Edit Order Address refuses an invalid value, in the browser and on the server, and saves a valid one', { tag: ['@VAL4'] }, async ({ adminPage: page }) => {
    setAccountValues(email, { e2e_c_text: 'seed text' });
    await startAdminOrder(page, email);
    const { incrementId } = await completeAdminOrder(page, { e2e_a_len: 'okay' });
    expect(snapshotOf(incrementId).billing.e2e_a_len).toMatchObject({ value: 'okay' });

    // the form refuses a text that is too long
    await openOrderAddressEdit(page, 'billing');
    // (the required dependent value was hidden by its rule on the order, so the form asks for it: filled, to leave one problem only)
    await page.locator('#e2e_a_rdep').fill('required dep');
    await page.locator('#e2e_a_len').fill('far too long for the rule');
    await page.locator('button:has-text("Save Order Address")').first().click();
    await expect(page.locator('#e2e_a_len')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByText('Please enter less or equal than 6 symbols.')).toBeVisible();
    await expect(page).toHaveURL(/sales\/order\/address/);

    // the browser check bypassed: the server refuses with a message and the page stays on the form
    await page.evaluate(() => {
        const form = document.querySelector('#edit_form') as HTMLFormElement;
        form.querySelectorAll('[class*="maximum-length"], [class*="validate-length"]').forEach((node) => {
            node.className = node.className.replace(/(maximum|minimum)-length-\d+|validate-length/g, '');
        });
        form.noValidate = true;
        form.submit();
    });
    await page.waitForLoadState('load');
    await expect(page.locator('.message-error, .messages .error').first()).toBeVisible();
    expect(snapshotOf(incrementId).billing.e2e_a_len, 'nothing was saved').toMatchObject({ value: 'okay' });
    expect(snapshotOf(incrementId).shipping.e2e_a_len, 'the shipping address is untouched').toMatchObject({ value: 'okay' });

    // control: a valid value is saved
    await openAdminOrder(page, incrementId);
    await openOrderAddressEdit(page, 'billing');
    await page.locator('#e2e_a_rdep').fill('required dep');
    await page.locator('#e2e_a_len').fill('fixed');
    await page.locator('button:has-text("Save Order Address")').first().click();
    await expect(page.locator('.message-success')).toBeVisible();
    expect(snapshotOf(incrementId).billing.e2e_a_len).toMatchObject({ value: 'fixed' });
    expect(snapshotOf(incrementId).shipping.e2e_a_len, 'the shipping address keeps its value').toMatchObject({ value: 'okay' });
});
