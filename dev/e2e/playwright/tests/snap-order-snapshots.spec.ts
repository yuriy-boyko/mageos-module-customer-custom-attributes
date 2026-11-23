import {
    benchCommand, completeAdminOrder, createAttributes, chooseShippingAndPayment, customerEmail, deleteCreated, deleteSavedAddresses, expect, fillCheckout,
    openAdminOrder, openCheckoutAsGuest, orderViewAddresses, placeOrder, placedOrderSnapshot, quoteValues, setAccountValues, snapshotOf, startAdminOrder, test, uniqueEmail,
} from './helpers';

/**
 * SNAP3, SNAP4, SNAP5, SNAP7 and ORD6 with throw-away attributes (e2e_a_snapo, e2e_a_snapt) that are created and deleted by the test.
 *
 * Raw order rows (label, input type, value, value label) come from `assertions.php order`.
 */
const SELECT = 'e2e_a_snapo';
const TEXT = 'e2e_a_snapt';

type Row = { attribute_code: string; attribute_label: string; input_type: string; value: string; value_label: string };
const orderRows = (incrementId: string): Row[] => JSON.parse(benchCommand('E2E_ASSERT_CMD', ['order', incrementId])).addresses.billing;
const rowOf = (incrementId: string, code: string): Row | undefined => orderRows(incrementId).find((row) => row.attribute_code === code);

test.beforeEach(() => {
    setAccountValues(customerEmail(), { e2e_c_text: 'seed text' });
});

test.afterEach(() => {
    deleteCreated(SELECT);
    deleteCreated(TEXT);
    deleteSavedAddresses(customerEmail());
});

test('SNAP3: an option deleted after the order: the order keeps the label of the value', { tag: ['@SNAP3'] }, async ({ adminPage: page }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'address', '--forms=customer_address_edit,mageos_address_text', '--input=select', '--options=Alpha,Beta', '--label=Snap option', SELECT]);
    await startAdminOrder(page, customerEmail());
    const { incrementId } = await completeAdminOrder(page, { [SELECT]: 'Alpha' });
    const before = rowOf(incrementId, SELECT);
    expect(before).toMatchObject({ attribute_label: 'Snap option', input_type: 'select', value_label: 'Alpha' });

    expect(benchCommand('E2E_FIXTURE_CMD', ['delete-option', SELECT, 'Alpha'])).toContain('deleted  option "Alpha"');
    expect(rowOf(incrementId, SELECT), 'the order row is untouched').toEqual(before);
    await openAdminOrder(page, incrementId);
    expect((await orderViewAddresses(page)).billing).toContain('Alpha');
});

test('SNAP3: an option renamed after the order: the old order keeps the old label, a new order has the new one', { tag: ['@SNAP3'] }, async ({ adminPage: page }) => {
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'address', '--forms=customer_address_edit,mageos_address_text', '--input=select', '--options=Alpha,Beta', '--label=Snap option', SELECT]);
    await startAdminOrder(page, customerEmail());
    const first = await completeAdminOrder(page, { [SELECT]: 'Alpha' });
    const before = rowOf(first.incrementId, SELECT);
    expect(before).toMatchObject({ value_label: 'Alpha' });

    expect(benchCommand('E2E_FIXTURE_CMD', ['rename-option', SELECT, 'Alpha', 'Alpine'])).toContain('renamed  option "Alpha" to "Alpine"');
    expect(rowOf(first.incrementId, SELECT), 'the old order keeps the label it had').toEqual(before);
    await openAdminOrder(page, first.incrementId);
    expect((await orderViewAddresses(page)).billing).toContain('Alpha');
    expect((await orderViewAddresses(page)).billing).not.toContain('Alpine');

    await startAdminOrder(page, customerEmail());
    const second = await completeAdminOrder(page, { [SELECT]: 'Alpine' });
    expect(rowOf(second.incrementId, SELECT)).toMatchObject({ value_label: 'Alpine' });
    expect(rowOf(first.incrementId, SELECT)).toEqual(before);
});

test('SNAP4 and SNAP5: a new attribute with the code of a deleted one: old orders keep the old label and input type', { tag: ['@SNAP4', '@SNAP5'] }, async ({ adminPage: page }) => {
    createAttributes('address', [TEXT], ['customer_address_edit', 'mageos_address_text']); // the address text form decides what the order view shows
    await startAdminOrder(page, customerEmail());
    const first = await completeAdminOrder(page, { [TEXT]: 'old value' });
    const before = rowOf(first.incrementId, TEXT);
    expect(before).toMatchObject({ attribute_label: `ADM ${TEXT}`, input_type: 'text', value: 'old value' });

    // the same code again, with another label and another input type
    deleteCreated(TEXT);
    benchCommand('E2E_FIXTURE_CMD', ['create-attributes', 'address', '--forms=customer_address_edit,mageos_address_text', '--input=select', '--options=Zed,Yan', '--label=Replacement', TEXT]);
    expect(rowOf(first.incrementId, TEXT), 'the old order keeps its row').toEqual(before);

    // a new order has the new label and type
    await startAdminOrder(page, customerEmail());
    const second = await completeAdminOrder(page, { [TEXT]: 'Zed' });
    expect(rowOf(second.incrementId, TEXT)).toMatchObject({ attribute_label: 'Replacement', input_type: 'select', value_label: 'Zed' });
    expect(rowOf(first.incrementId, TEXT), 'still unchanged').toEqual(before);
    await openAdminOrder(page, first.incrementId);
    expect((await orderViewAddresses(page)).billing).toContain('old value');
});

test('SNAP7: deleting the quote deletes the module rows of the quote and its addresses', { tag: ['@SNAP7'] }, async ({ page, magewire }) => {
    const email = uniqueEmail('snap7'); // the guest quote is dropped by its id
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: email, e2e_a_text: 'quote value' });
    await expect.poll(() => {
        try {
            return Object.values(quoteValues('@latest').addresses ?? {}).some((values) => values.e2e_a_text === 'quote value');
        } catch {
            return false;
        }
    }, { timeout: 20_000 }).toBe(true);
    const quoteId = quoteValues('@latest').quote_id;
    const before = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['quote-rows', String(quoteId)]));
    expect(before.quote_exists).toBe(true);
    expect(before.address_values, 'the address rows exist before').toBeGreaterThan(0);

    // leave the checkout first: a payment widget still on the page would reload for an empty cart and throw (not the module's error)
    await page.goto('about:blank');
    benchCommand('E2E_FIXTURE_CMD', ['drop-quotes', `#${quoteId}`]);
    const after = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['quote-rows', `${quoteId}:${before.address_ids.join(',')}`]));
    expect(after).toMatchObject({ quote_exists: false, values: 0, address_values: 0 });
});

test('ORD6: an order with only the required values has rows for those and no empty rows', { tag: ['@ORD6'] }, async ({ page, magewire }) => {
    await openCheckoutAsGuest(page);
    await fillCheckout(page, magewire, { email_address: uniqueEmail('ord6') });
    await chooseShippingAndPayment(page, magewire);
    await placeOrder(page);
    await expect(page.locator('.message-error')).toHaveCount(0);
    const { incrementId, snapshot } = await placedOrderSnapshot(page);
    expect(Object.keys(snapshot.billing).sort(), 'rows of the required attributes only').toEqual(['e2e_a_req']);
    expect(Object.keys(snapshot.shipping).sort()).toEqual(['e2e_a_req']);
    for (const row of orderRows(incrementId)) {
        expect(row.value, `${row.attribute_code} has a value`).not.toBe('');
    }
    expect(snapshotOf(incrementId).customer.e2e_c_text, 'no row for a customer value never given').toBeUndefined();
});
