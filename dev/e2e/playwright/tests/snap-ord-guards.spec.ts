import { benchCommand, expect, test } from './helpers';

/**
 * SNAP8: saving a quote address twice with identical values writes nothing the second time.
 * ORD7: loading order and order address collections costs the same number of SQL statements for 2, 20 or 100 rows
 * (the module reads its rows in one batch per collection, not one query per row).
 *
 * Both run a bench command, no browser: `fixtures.php quote-address-saves` saves a throw-away quote address four times and reports how
 * far the auto increment counter of the value table moved (an insert, also "insert on duplicate", uses a number; no insert, no move);
 * `assertions.php queries` counts the statements of loading the collections.
 */
test('SNAP8: an identical second save of a quote address writes nothing', { tag: ['@SNAP8'] }, async () => {
    const result = JSON.parse(benchCommand('E2E_FIXTURE_CMD', ['quote-address-saves']));
    // the counters prove the measurement: new and changed values write, identical ones do not
    expect(result.first_save, 'new value is written').toBeGreaterThan(0);
    expect(result.identical_save, 'identical value').toBe(0);
    expect(result.changed_save, 'changed value is written').toBeGreaterThan(0);
    expect(result.identical_again, 'identical value again').toBe(0);
    expect(result.rows, 'one row with the last value').toEqual({ e2e_a_text: 'two' });
});

test('ORD7: order and order address collections need no extra query per row', { tag: ['@ORD7'] }, async () => {
    const result = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['queries']));
    for (const kind of ['orders', 'addresses'] as const) {
        const small = result[kind]['2'];
        const medium = result[kind]['20'];
        const large = result[kind]['100'];
        expect(small.items, `${kind}: the bench has enough rows to compare`).toBe(2);
        expect(medium.items, kind).toBeGreaterThanOrEqual(20);
        expect(large.items, kind).toBeGreaterThan(medium.items);
        expect(medium.statements, `${kind}: 20 rows cost as many statements as 2`).toBe(small.statements);
        expect(large.statements, `${kind}: ${large.items} rows cost as many statements as 2`).toBe(small.statements);
    }
});
