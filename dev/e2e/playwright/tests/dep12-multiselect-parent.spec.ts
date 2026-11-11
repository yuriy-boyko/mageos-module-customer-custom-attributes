import { expect, field, openCheckoutAsGuest, requireFixtures, test } from './helpers';

/**
 * DEP12: a multiple select as the parent of a rule in the Hyvä Checkout address form. The dependent field follows: it shows when any
 * selected option matches a rule and hides (and loses its value) when none does.
 *
 * Fixture (dev/e2e/fixtures.json): address relation "E2E address multiselect relation": e2e_a_mdep shows when e2e_a_multi has Red or Blue;
 * Green has no rule.
 */
test('the dependent field follows a multiple select parent', { tag: ['@DEP12'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await requireFixtures(page, ['e2e_a_multi', 'e2e_a_mdep']);

    const parent = field(page, 'e2e_a_multi');
    const dependent = field(page, 'e2e_a_mdep');
    await expect(parent).toBeVisible();

    // nothing selected: the dependent is hidden
    await expect(dependent).toBeHidden();

    // an option without a rule keeps it hidden
    await parent.selectOption([{ label: 'Green' }]);
    await magewire.idle();
    await expect(dependent).toBeHidden();

    // one matching option shows it
    await parent.selectOption([{ label: 'Red' }]);
    await expect(dependent).toBeVisible();
    await dependent.fill('typed while Red is selected');

    // another matching option as well: still shown, the value stays
    await parent.selectOption([{ label: 'Red' }, { label: 'Blue' }]);
    await expect(dependent).toBeVisible();
    await expect(dependent).toHaveValue('typed while Red is selected');

    // dropping one of two matching options keeps it
    await parent.selectOption([{ label: 'Blue' }]);
    await expect(dependent).toBeVisible();

    // matching and non-matching mixed: shown
    await parent.selectOption([{ label: 'Blue' }, { label: 'Green' }]);
    await expect(dependent).toBeVisible();

    // only the option without a rule left: hidden, and the typed value is cleared
    await parent.selectOption([{ label: 'Green' }]);
    await expect(dependent).toBeHidden();

    // it comes back empty
    await parent.selectOption([{ label: 'Red' }]);
    await expect(dependent).toBeVisible();
    await expect(dependent).toHaveValue('');

    // nothing selected again: hidden
    await parent.selectOption([]);
    await expect(dependent).toBeHidden();
});
