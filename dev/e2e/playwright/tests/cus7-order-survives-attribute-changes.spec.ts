import { addAdminOrderProduct, attributeRow, completeAdminOrder, createAttributes, customerEmail, deleteCreated, expect, fillAttributeMain, fillOrderValues, openAttribute, orderViewAddresses, saveAttribute, setAccountValues, snapshotOf, startAdminOrder, test } from './helpers';

/**
 * CUS7: renaming or deleting an attribute after an order leaves the order's rows unchanged.
 * The test creates one customer and one address attribute (e2e_cus7c, e2e_cus7a), places an admin order with a value in each, then
 * renames both through the admin and deletes both, and compares the order's snapshot and the order view each time.
 */
const CUSTOMER = 'e2e_cus7c';
const ADDRESS = 'e2e_cus7a';

test.afterEach(() => {
    deleteCreated(CUSTOMER);
    deleteCreated(ADDRESS);
});

test('an order keeps its customer and address values when the attributes are renamed or deleted', { tag: ['@CUS7', '@SNAP1', '@SNAP2', '@DSP3'] }, async ({ adminPage: page }) => {
    createAttributes('customer', [CUSTOMER], ['customer_account_edit', 'adminhtml_checkout']);
    createAttributes('address', [ADDRESS], ['customer_address_edit', 'mageos_address_text']); // the address text form decides what the order view shows
    setAccountValues(customerEmail(), { e2e_c_text: 'seed text' });

    await startAdminOrder(page, customerEmail());
    await fillOrderValues(page, 'account', { [CUSTOMER]: 'customer value' });
    const { incrementId } = await completeAdminOrder(page, { [ADDRESS]: 'address value' });
    const viewUrl = page.url();

    const before = snapshotOf(incrementId);
    expect(before.customer[CUSTOMER]).toMatchObject({ value: 'customer value' });
    expect(before.billing[ADDRESS]).toMatchObject({ value: 'address value' });
    expect(before.shipping[ADDRESS]).toMatchObject({ value: 'address value' });

    const expectUnchanged = async (step: string) => {
        expect(snapshotOf(incrementId), `${step}: snapshot rows`).toEqual(before);
        await page.goto(viewUrl);
        const view = await orderViewAddresses(page);
        expect(view.billing, `${step}: billing on the order view`).toContain(`ADM ${ADDRESS}: address value`);
        expect(view.shipping, `${step}: shipping on the order view`).toContain(`ADM ${ADDRESS}: address value`);
        expect(await page.locator('.order-account-information').innerText(), `${step}: account information`).toContain(`ADM ${CUSTOMER}`);
        expect(await page.locator('.order-account-information').innerText()).toContain('customer value');
    };

    // rename
    for (const [entity, code] of [['customer', CUSTOMER], ['address', ADDRESS]] as const) {
        await openAttribute(page, entity, code);
        await fillAttributeMain(page, { label: `Renamed ${code}` });
        await saveAttribute(page);
        await expect(page.locator('.message-success')).toBeVisible();
        expect(attributeRow(entity, code).label).toBe(`Renamed ${code}`);
    }
    await expectUnchanged('after the rename');

    // delete
    for (const [entity, code] of [['customer', CUSTOMER], ['address', ADDRESS]] as const) {
        await openAttribute(page, entity, code);
        await page.locator('#delete').click();
        await page.locator('.modal-popup._show .action-accept').click();
        await page.waitForLoadState('networkidle');
        await expect(page.locator('.message-success')).toContainText(/deleted/i);
        expect(attributeRow(entity, code).exists).toBe(false);
    }
    await expectUnchanged('after the delete');
});
