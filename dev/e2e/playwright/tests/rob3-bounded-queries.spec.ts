import { benchCommand, customerEmail, expect, test } from './helpers';

/**
 * ROB3: 50 more address attributes and 5 saved addresses: the SQL statements the module needs stay bounded.
 * No browser: `assertions.php perf` counts the statements of building the storefront address form fields (a cold build, what one
 * request pays) and of the address text lines for 1 and for all saved addresses of the customer.
 *
 *  - the lines of 5 addresses cost the same as the lines of 1 (no query per address),
 *  - 50 more attributes cost at most one statement per added dropdown (the options of an attribute are read from the database once
 *    per request, like core does for every dropdown); text attributes cost nothing.
 */
const perf = () => JSON.parse(benchCommand('E2E_ASSERT_CMD', ['perf', customerEmail()]));

test('ROB3: 50 attributes and 5 addresses keep the statements bounded', { tag: ['@ROB3'] }, async () => {
    const before = perf();
    let after;
    try {
        benchCommand('E2E_FIXTURE_CMD', ['bulk-attributes', '50']);
        benchCommand('E2E_FIXTURE_CMD', ['bulk-addresses', customerEmail(), '5']);
        after = perf();
    } finally {
        benchCommand('E2E_FIXTURE_CMD', ['bulk-clean']);
    }
    const dropdowns = Math.floor(50 / 3); // every third bulk attribute is a dropdown

    // the load is real: all 50 attributes are in the form, all 5 addresses show all 50 lines
    expect(after.form_fields - before.form_fields).toBe(50);
    expect(after.text_all.addresses).toBe(5);
    expect(after.text_all.lines).toBeGreaterThanOrEqual(5 * 50);

    // no query per address
    expect(after.text_all.statements, 'statements for 5 addresses vs 1: ' + JSON.stringify(after)).toBe(after.text_one.statements);
    // at most one per added dropdown
    expect(after.form_statements - before.form_statements, 'extra statements for 50 more attributes').toBeLessThanOrEqual(dropdowns + 2);
    expect(after.form_statements, 'absolute number of statements for the form').toBeLessThan(60);
});
