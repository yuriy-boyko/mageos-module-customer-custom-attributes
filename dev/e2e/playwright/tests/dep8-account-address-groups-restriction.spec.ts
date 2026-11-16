import { customerEmail, deleteSavedAddresses, expect, test } from './helpers';

/**
 * DEP8: the account address form with a customer group restriction and a dependency rule. e2e_a_ws is limited to the group Wholesale: the
 * fixture customer is in General, so the field is hidden and disabled, so it cannot be sent. e2e_a_dep is shown
 * when e2e_a_sel is North; a field that is hidden is disabled, so it is not sent either.
 */
test.afterEach(() => {
    deleteSavedAddresses(customerEmail());
});

test('DEP8: a field limited to another customer group is not in the form; a dependent is shown for its option and disabled when hidden', { tag: ['@DEP8'] }, async ({ customerPage: page }) => {
    await page.goto('/customer/address/new/');
    await expect(page.locator('[name="e2e_a_text"]'), 'the form is there').toBeVisible();
    const restricted = page.locator('[name="e2e_a_ws"]');
    await expect(restricted, 'a Wholesale-only field is hidden for a General customer').toBeHidden();
    await expect(restricted, 'and disabled, so it is not sent').toBeDisabled();

    const dependent = page.locator('[name="e2e_a_dep"]');
    await expect(dependent).toBeHidden();
    await expect(dependent).toBeDisabled();

    const radio = (label: string) => page.locator('.field.e2e_a_sel, .field-e2e_a_sel').getByRole('radio', { name: new RegExp(`(^|\\s)${label}$`) });
    await radio('North').check();
    await expect(dependent).toBeVisible();
    await expect(dependent).toBeEnabled();
    await radio('South').check();
    await expect(dependent).toBeHidden();
    await expect(dependent).toBeDisabled();
});
