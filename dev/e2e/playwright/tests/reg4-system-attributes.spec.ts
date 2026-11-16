import { benchCommand, expect, test } from './helpers';

/**
 * REG4: the system attributes of customers and addresses (firstname, street, email, ...) are never picked up by the module: the codes it
 * works with are exactly the user defined attributes of the two EAV entities. No browser needed.
 */
test('REG4: the module picks up the user defined attributes only', { tag: ['@REG4'] }, async () => {
    const data = JSON.parse(benchCommand('E2E_ASSERT_CMD', ['providers']));

    expect(data.customer, 'customer attributes picked up').toEqual(data.eav_user_defined.customer);
    expect(data.address, 'address attributes picked up').toEqual(data.eav_user_defined.customer_address);

    // the usual system attributes exist and are not among them
    for (const code of ['firstname', 'lastname', 'email', 'dob', 'gender', 'taxvat', 'group_id']) {
        expect(data.eav_system.customer, `${code} is a system customer attribute`).toContain(code);
        expect(data.customer, `${code} is not picked up`).not.toContain(code);
    }
    for (const code of ['firstname', 'lastname', 'street', 'city', 'postcode', 'telephone', 'country_id', 'region', 'region_id', 'company', 'vat_id']) {
        expect(data.eav_system.customer_address, `${code} is a system address attribute`).toContain(code);
        expect(data.address, `${code} is not picked up`).not.toContain(code);
    }
    // the fixtures are there, so the control is not an empty list
    expect(data.customer).toContain('e2e_c_text');
    expect(data.address).toContain('e2e_a_text');
});
