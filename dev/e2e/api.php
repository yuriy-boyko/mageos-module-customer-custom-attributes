<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

/**
 * REST API test suite: places orders through the REST API against a running store and checks the module's data on the order,
 * the server-side validation and the rules. It needs the fixtures (`php dev/e2e/fixtures.php seed`).
 *
 *   E2E_BASE_URL=https://shop.example E2E_ADMIN_USER=... E2E_ADMIN_PASSWORD=... E2E_CUSTOMER_PASSWORD=... php dev/e2e/api.php
 *
 * Environment:
 *   E2E_BASE_URL           store base URL, without a trailing slash (required)
 *   E2E_ADMIN_USER, E2E_ADMIN_PASSWORD   an admin account that may read orders and customers (required)
 *   E2E_CUSTOMER_PASSWORD  password of the fixture customer, enables the logged-in cases (optional)
 *   E2E_CUSTOMER_EMAIL     default e2e.customer@example.com
 *   E2E_SKU                a simple in-stock product, default 24-MB04
 *   E2E_INSECURE=1         accept a self-signed certificate
 *   E2E_HOST_HEADER        send this Host header (to reach the store by another address, e.g. from inside a container)
 *   E2E_ITA_HOST           host name of the second website (default ita.magento249.test); the REST7 cases are skipped when its store code `ita` does not answer
 *
 * Every case prints ok or FAIL; the exit code is 1 when one failed. Orders it places stay in the store until `fixtures.php purge` (run-all.sh does it) removes them
 * (e-mail api.e2e@example.com). The store must not have other required address attributes (the cases send values for the fixture attributes only).
 * The credentials are read from the environment and never printed. No class is declared here.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$base = rtrim((string) getenv('E2E_BASE_URL'), '/');
$adminUser = (string) getenv('E2E_ADMIN_USER');
$adminPassword = (string) getenv('E2E_ADMIN_PASSWORD');
if ($base === '' || $adminUser === '' || $adminPassword === '') {
    fwrite(STDERR, "Set E2E_BASE_URL, E2E_ADMIN_USER and E2E_ADMIN_PASSWORD (see the header of this file).\n");
    exit(2);
}
$customerEmail = (string) (getenv('E2E_CUSTOMER_EMAIL') ?: 'e2e.customer@example.com');
$customerPassword = (string) getenv('E2E_CUSTOMER_PASSWORD');
$sku = (string) (getenv('E2E_SKU') ?: '24-MB04');
$insecure = getenv('E2E_INSECURE') === '1';
$hostHeader = (string) getenv('E2E_HOST_HEADER');
$itaHost = (string) (getenv('E2E_ITA_HOST') ?: 'ita.magento249.test');
/** Runs a fixtures.php command in this container (the REST7 cases set per-website overrides and put them back). */
$bench = static function (string ...$args): string {
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
};

/** @return array{0: int, 1: mixed} status and decoded body */
$api = static function (string $method, string $path, ?array $body = null, ?string $token = null, ?string $host = null, string $store = '') use ($base, $insecure, $hostHeader): array {
    $handle = curl_init($base . '/rest/' . ($store !== '' ? $store . '/' : '') . 'V1' . $path);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if (($host ?? $hostHeader) !== '') {
        $headers[] = 'Host: ' . ($host ?? $hostHeader);
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    return [$status, is_string($response) ? json_decode($response, true) : null];
};

$failed = 0;
$total = 0;
$check = static function (string $name, bool $condition, string $detail = '') use (&$failed, &$total): bool {
    $total++;
    echo ($condition ? 'ok    ' : 'FAIL  ') . $name . (!$condition && $detail !== '' ? "\n      " . $detail : '') . "\n";
    if (!$condition) {
        $failed++;
    }
    return $condition;
};
/** A short message from an error response (the module's and core's messages carry the attribute name). */
$message = static function (mixed $body): string {
    if (!is_array($body)) {
        return '';
    }
    $text = (string) ($body['message'] ?? '');
    foreach ((array) ($body['parameters'] ?? []) as $parameter) {
        $text = str_replace('%message', (string) $parameter, $text);
    }
    if (isset($body['parameters']['message'])) {
        $text .= ' ' . $body['parameters']['message'];
    }
    return substr(trim($text), 0, 200);
};

echo "== Access\n";
[$status] = $api('GET', '/orders?searchCriteria[pageSize]=1');
$check('[API2] orders without a token are refused', $status === 401, "status $status");
[$status, $token] = $api('POST', '/integration/admin/token', ['username' => $adminUser, 'password' => $adminPassword]);
if (!$check('[API2] admin token', $status === 200 && is_string($token))) {
    exit(1);
}
$admin = (string) $token;

// option ids of the fixture attributes (select values are option ids)
$options = static function (string $code) use ($api, $admin): array {
    [, $meta] = $api('GET', '/attributeMetadata/customerAddress/attribute/' . $code, null, $admin);
    $map = [];
    foreach ((array) ($meta['options'] ?? []) as $option) {
        if (($option['value'] ?? '') !== '') {
            $map[(string) $option['label']] = (string) $option['value'];
        }
    }
    return $map;
};
$customerOptions = static function (string $code) use ($api, $admin): array {
    [, $meta] = $api('GET', '/attributeMetadata/customer/attribute/' . $code, null, $admin);
    $map = [];
    foreach ((array) ($meta['options'] ?? []) as $option) {
        if (($option['value'] ?? '') !== '') {
            $map[(string) $option['label']] = (string) $option['value'];
        }
    }
    return $map;
};
$sel = $options('e2e_a_sel');
$multi = $options('e2e_a_multi');
if (!$check('fixture options readable (run fixtures.php seed first)', isset($sel['North'], $sel['South'], $multi['Red'], $multi['Blue']))) {
    exit(1);
}

$address = static function (array $custom, string $email = 'api.e2e@example.com'): array {
    $attributes = [];
    foreach ($custom as $code => $value) {
        $attributes[] = ['attribute_code' => $code, 'value' => $value];
    }
    return [
        'region' => 'Texas', 'region_id' => 57, 'country_id' => 'US', 'street' => ['1 Api Street'], 'postcode' => '75001',
        'city' => 'Dallas', 'firstname' => 'Api', 'lastname' => 'Tester', 'telephone' => '5551230000', 'email' => $email,
        'custom_attributes' => $attributes,
    ];
};

/**
 * Guest checkout through the REST API. Returns ['status' => step status, 'step' => which step answered, 'message' => text, 'order_id' => id].
 */
$guestOrder = static function (array $custom) use ($api, $address, $sku, $message): array {
    [, $cart] = $api('POST', '/guest-carts');
    $cart = (string) $cart;
    $api('POST', "/guest-carts/$cart/items", ['cartItem' => ['sku' => $sku, 'qty' => 1, 'quote_id' => $cart]]);
    $addr = $address($custom);
    [$status, $body] = $api('POST', "/guest-carts/$cart/shipping-information", ['addressInformation' => [
        'shipping_address' => $addr, 'billing_address' => $addr, 'shipping_method_code' => 'flatrate', 'shipping_carrier_code' => 'flatrate',
    ]]);
    if ($status !== 200) {
        return ['status' => $status, 'step' => 'shipping', 'message' => $message($body), 'order_id' => 0];
    }
    [$status, $body] = $api('POST', "/guest-carts/$cart/payment-information", [
        'email' => 'api.e2e@example.com', 'paymentMethod' => ['method' => 'checkmo'], 'billing_address' => $addr,
    ]);
    return ['status' => $status, 'step' => 'payment', 'message' => $status === 200 ? '' : $message($body), 'order_id' => $status === 200 ? (int) $body : 0];
};

/** @return array<string, array{value: string, value_label: string}> custom attribute values of an address of the order, by code */
$byCode = static function (?array $rows): array {
    $result = [];
    foreach ((array) $rows as $row) {
        $result[(string) $row['attribute_code']] = $row;
    }
    return $result;
};
$readOrder = static function (int $orderId) use ($api, $admin, $byCode): array {
    [, $order] = $api('GET', "/orders/$orderId", null, $admin);
    $shipping = [];
    foreach ((array) ($order['extension_attributes']['shipping_assignments'] ?? []) as $assignment) {
        $shipping = $byCode($assignment['shipping']['address']['extension_attributes']['custom_attribute_values'] ?? []);
    }
    return [
        'billing' => $byCode($order['billing_address']['extension_attributes']['custom_attribute_values'] ?? []),
        'shipping' => $shipping,
        'customer' => $byCode($order['extension_attributes']['customer_attribute_values'] ?? []),
    ];
};

echo "== Validation of address values (rejected before the order is placed)\n";
$required = ['e2e_a_req' => 'required value'];
$result = $guestOrder([]);
$check('[REST2][VAL2] a missing required address attribute is refused', $result['status'] !== 200 && stripos($result['message'], 'E2E Address Required') !== false, $result['message']);
$result = $guestOrder($required + ['e2e_a_len' => 'ab']);
$check('[REST2][VAL1~] a value shorter than the minimum length is refused', $result['status'] !== 200 && stripos($result['message'], 'E2E Address Length') !== false, $result['message']);
$result = $guestOrder($required + ['e2e_a_sel' => '99999']);
$check('[REST2][VAL1~] an unknown option id is refused', $result['status'] !== 200 && stripos($result['message'], 'e2e_a_sel') !== false, $result['message']);
$result = $guestOrder($required + ['e2e_a_date' => '2030-01-01']);
$check('[VAL1~] a date after the maximum is refused', $result['status'] !== 200 && stripos($result['message'], 'valid date') !== false, $result['message']);

$result = $guestOrder($required + ['e2e_a_multi' => $multi['Red'] . ',99999']);
$check('[VAL8] a multiple select with an unknown option id is refused', $result['status'] !== 200 && stripos($result['message'], 'e2e_a_multi') !== false, $result['message']);
$result = $guestOrder($required + ['e2e_a_multi' => '99999']);
$check('[VAL8] a multiple select with only an unknown option id is refused', $result['status'] !== 200 && stripos($result['message'], 'e2e_a_multi') !== false, $result['message']);

echo "== Guest order with values on both addresses\n";
$values = $required + [
    'e2e_a_text' => 'api note', 'e2e_a_sel' => $sel['North'], 'e2e_a_multi' => $multi['Red'] . ',' . $multi['Blue'],
    'e2e_a_date' => '2026-05-05', 'e2e_a_area' => "line one\nline two", 'e2e_a_bool' => '1', 'e2e_a_dep' => 'shown because North',
];
$result = $guestOrder($values);
$orderId = $result['order_id'];
$check('[CCO15] the order is placed although the customer attribute e2e_c_req is required and empty', $orderId > 0, $result['step'] . ': ' . $result['message']);
if ($orderId > 0) {
    $order = $readOrder($orderId);
    foreach (['billing', 'shipping'] as $type) {
        $row = $order[$type];
        $check("[REST1][API2] $type: the text value is on the order", ($row['e2e_a_text']['value'] ?? '') === 'api note');
        $check("[REST1] $type: the dropdown shows its label", ($row['e2e_a_sel']['value_label'] ?? '') === 'North', json_encode($row['e2e_a_sel'] ?? null));
        $check("[REST1] $type: the multiple select shows its labels", ($row['e2e_a_multi']['value_label'] ?? '') === 'Red, Blue', json_encode($row['e2e_a_multi'] ?? null));
        $check("[REST1] $type: the date label has no time", ($row['e2e_a_date']['value_label'] ?? '') === '2026-05-05', json_encode($row['e2e_a_date'] ?? null));
        $check("[REST1] $type: the yes/no label", ($row['e2e_a_bool']['value_label'] ?? '') === 'Yes', json_encode($row['e2e_a_bool'] ?? null));
        $check("[REST3~] $type: the dependent value is kept when its parent option matches", ($row['e2e_a_dep']['value'] ?? '') === 'shown because North');
    }
    $check('[CUS10~] a guest order has no customer attribute values for checkout-only attributes', !isset($order['customer']['e2e_c_text']) && !isset($order['customer']['e2e_c_order']));

    [$status, $list] = $api('GET', '/orders?searchCriteria[filterGroups][0][filters][0][field]=entity_id&searchCriteria[filterGroups][0][filters][0][value]=' . $orderId, null, $admin);
    $listed = $byCode($list['items'][0]['billing_address']['extension_attributes']['custom_attribute_values'] ?? []);
    $check('[API2] the order list returns the same address values', $status === 200 && ($listed['e2e_a_text']['value'] ?? '') === 'api note');
}

$result = $guestOrder($required + ['e2e_a_noform' => 'sent by api']);
$check('[REG5] an order with a value for an attribute that is in no storefront form is placed', $result['order_id'] > 0, $result['step'] . ': ' . $result['message']);
if ($result['order_id'] > 0) {
    $order = $readOrder($result['order_id']);
    $check('[REG5] the value of an attribute that is in no storefront form is on both addresses', ($order['billing']['e2e_a_noform']['value'] ?? '') === 'sent by api' && ($order['shipping']['e2e_a_noform']['value'] ?? '') === 'sent by api', json_encode([$order['billing']['e2e_a_noform'] ?? null, $order['shipping']['e2e_a_noform'] ?? null]));
}

echo "== Rules\n";
$result = $guestOrder($required + ['e2e_a_sel' => $sel['South'], 'e2e_a_dep' => 'must be dropped']);
$check('[REST3~] the order with a hidden dependent value is placed', $result['order_id'] > 0, $result['step'] . ': ' . $result['message']);
if ($result['order_id'] > 0) {
    $order = $readOrder($result['order_id']);
    $check('[REST3~] billing: a dependent value whose parent option does not match is dropped', !isset($order['billing']['e2e_a_dep']));
    $check('[REST3~] shipping: a dependent value whose parent option does not match is dropped', !isset($order['shipping']['e2e_a_dep']));
}

echo "== Logged-in customer\n";
if ($customerPassword === '') {
    echo "skip  (set E2E_CUSTOMER_PASSWORD to run the logged-in cases)\n";
} else {
    [$status, $customerToken] = $api('POST', '/integration/customer/token', ['username' => $customerEmail, 'password' => $customerPassword]);
    if ($check('[REST4~] customer token', $status === 200 && is_string($customerToken))) {
        $customerToken = (string) $customerToken;
        [, $customer] = $api('GET', '/customers/search?searchCriteria[filterGroups][0][filters][0][field]=email&searchCriteria[filterGroups][0][filters][0][value]=' . rawurlencode($customerEmail), null, $admin);
        $record = $customer['items'][0] ?? null;
        if ($record !== null) {
            // an attribute that is only on the account forms: its value is read from the customer when the order is placed
            // (the admin customer form always includes every custom customer attribute, so the required ones need a value too)
            $record['custom_attributes'] = [
                ['attribute_code' => 'e2e_c_account', 'value' => 'account value'],
                ['attribute_code' => 'e2e_c_req', 'value' => 'admin value'],
                ['attribute_code' => 'e2e_c_rdep', 'value' => 'admin value'],
                ['attribute_code' => 'e2e_c_areq', 'value' => 'admin value'],
                ['attribute_code' => 'e2e_c_rmulti', 'value' => (string) (array_values($customerOptions('e2e_c_rmulti'))[0] ?? '')],
            ];
            [$status, $saved] = $api('PUT', '/customers/' . $record['id'], ['customer' => $record], $admin);
            $check('an admin can write custom customer attributes', $status === 200, "status $status " . $message($saved));

            [, $cart] = $api('POST', '/carts/mine', null, $customerToken);
            $api('POST', '/carts/mine/items', ['cartItem' => ['sku' => $sku, 'qty' => 1, 'quote_id' => (string) $cart]], $customerToken);
            $addr = $address($required + ['e2e_a_text' => 'customer order'], $customerEmail);
            $api('POST', '/carts/mine/shipping-information', ['addressInformation' => [
                'shipping_address' => $addr, 'billing_address' => $addr, 'shipping_method_code' => 'flatrate', 'shipping_carrier_code' => 'flatrate',
            ]], $customerToken);
            [$status, $body] = $api('POST', '/carts/mine/payment-information', ['paymentMethod' => ['method' => 'checkmo'], 'billing_address' => $addr], $customerToken);
            $check('[CUS4~][REST5] the logged-in order with a new address is placed', $status === 200, $message($body));
            if ($status === 200) {
                $order = $readOrder((int) $body);
                $check('[CUS4~] the account-only customer attribute is on the order', ($order['customer']['e2e_c_account']['value'] ?? '') === 'account value', json_encode($order['customer']));
                $check('[CCO16~] checkout-only customer attributes are not copied from the account', !isset($order['customer']['e2e_c_order']) && !isset($order['customer']['e2e_c_text']) && !isset($order['customer']['e2e_c_req']), json_encode(array_keys($order['customer'])));
                $check('[CUS4~][REST5] new address: the address values are on the order', ($order['shipping']['e2e_a_text']['value'] ?? '') === 'customer order' && ($order['billing']['e2e_a_text']['value'] ?? '') === 'customer order');
            }

            // a saved address: the customer saves one with values (customers/me), the cart refers to it by id
            [, $me] = $api('GET', '/customers/me', null, $customerToken);
            $saved = $address($required + ['e2e_a_text' => 'saved address text', 'e2e_a_sel' => $sel['South'], 'e2e_a_multi' => $multi['Red'] . ',' . $multi['Blue'], 'e2e_a_date' => '2026-06-07'], $customerEmail);
            unset($saved['email']);
            $me['addresses'] = [$saved + ['default_billing' => true, 'default_shipping' => true]];
            [$status, $updated] = $api('PUT', '/customers/me', ['customer' => $me], $customerToken);
            $savedId = (int) ($updated['addresses'][0]['id'] ?? 0);
            if ($check('[REST5] the customer can save an address with custom values', $status === 200 && $savedId > 0, "status $status " . $message($updated))) {
                [, $cart] = $api('POST', '/carts/mine', null, $customerToken);
                $api('POST', '/carts/mine/items', ['cartItem' => ['sku' => $sku, 'qty' => 1, 'quote_id' => (string) $cart]], $customerToken);
                // what an API client does: read the saved address from customers/me and use it, with its id, as the address of the cart
                $reference = $updated['addresses'][0];
                $reference['customer_address_id'] = $savedId;
                $reference['email'] = $customerEmail;
                // the customer address API returns the region as an object, the cart expects its name
                $reference['region_id'] = $reference['region']['region_id'] ?? $reference['region_id'] ?? 0;
                $reference['region'] = $reference['region']['region'] ?? '';
                unset($reference['id'], $reference['customer_id'], $reference['default_billing'], $reference['default_shipping']);
                $check('[REST5] the saved address read back carries its custom values', count((array) ($reference['custom_attributes'] ?? [])) >= 4, json_encode($reference['custom_attributes'] ?? null));
                $api('POST', '/carts/mine/shipping-information', ['addressInformation' => [
                    'shipping_address' => $reference, 'billing_address' => $reference, 'shipping_method_code' => 'flatrate', 'shipping_carrier_code' => 'flatrate',
                ]], $customerToken);
                [$status, $body] = $api('POST', '/carts/mine/payment-information', ['paymentMethod' => ['method' => 'checkmo'], 'billing_address' => $reference], $customerToken);
                $check('[REST5] the order with a saved address is placed', $status === 200, $message($body));
                if ($status === 200) {
                    $order = $readOrder((int) $body);
                    foreach (['billing', 'shipping'] as $type) {
                        $row = $order[$type];
                        $check("[REST5] saved address: $type has the saved values", ($row['e2e_a_text']['value'] ?? '') === 'saved address text'
                            && ($row['e2e_a_sel']['value_label'] ?? '') === 'South' && ($row['e2e_a_multi']['value_label'] ?? '') === 'Red, Blue'
                            && ($row['e2e_a_date']['value_label'] ?? '') === '2026-06-07', json_encode($row));
                    }
                }
                // the fixture customer must end without a saved address (the browser specs expect none)
                $api('DELETE', '/addresses/' . $savedId, null, $admin);
            }
        }
    }
}

echo "== Second website (REST7)\n";
[$status, $italy] = $api('GET', '/directory/countries/IT', null, null, $itaHost, 'ita');
$regionId = 0;
foreach ((array) ($italy['available_regions'] ?? []) as $region) {
    if (($region['code'] ?? '') === 'RM') {
        $regionId = (int) $region['id'];
    }
}
if ($status !== 200 || $regionId === 0) {
    echo "skip  (the Italy website does not answer on $itaHost, store code ita)\n";
} else {
    $italian = static function (array $custom) use ($address, $regionId): array {
        return ['region' => 'Roma', 'region_code' => 'RM', 'region_id' => $regionId, 'country_id' => 'IT', 'postcode' => '00100', 'city' => 'Roma'] + $address($custom);
    };
    $italianOrder = static function (array $custom) use ($api, $italian, $sku, $message, $itaHost): array {
        [, $cart] = $api('POST', '/guest-carts', null, null, $itaHost, 'ita');
        $cart = (string) $cart;
        $api('POST', "/guest-carts/$cart/items", ['cartItem' => ['sku' => $sku, 'qty' => 1, 'quote_id' => $cart]], null, $itaHost, 'ita');
        $addr = $italian($custom);
        [$status, $body] = $api('POST', "/guest-carts/$cart/shipping-information", ['addressInformation' => [
            'shipping_address' => $addr, 'billing_address' => $addr, 'shipping_method_code' => 'flatrate', 'shipping_carrier_code' => 'flatrate',
        ]], null, $itaHost, 'ita');
        if ($status !== 200) {
            return ['order_id' => 0, 'message' => $message($body)];
        }
        [$status, $body] = $api('POST', "/guest-carts/$cart/payment-information", [
            'email' => 'api.e2e@example.com', 'paymentMethod' => ['method' => 'checkmo'], 'billing_address' => $addr,
        ], null, $itaHost, 'ita');
        return ['order_id' => $status === 200 ? (int) $body : 0, 'message' => $status === 200 ? '' : $message($body)];
    };

    $metadataRequired = static function (?string $host, string $store) use ($api, $admin): ?bool {
        [, $meta] = $api('GET', '/attributeMetadata/customerAddress/attribute/e2e_a_text', null, $admin, $host, $store);
        return isset($meta['required']) ? (bool) $meta['required'] : null;
    };
    try {
        $bench('set-website', 'e2e_a_text', '2', 'required=1');
        $check('[REST7][WEB3~] attribute metadata: required on the Italy website', $metadataRequired($itaHost, 'ita') === true);
        $check('[REST7][WEB3~] attribute metadata: still optional on the main website', $metadataRequired(null, '') === false);

        $result = $italianOrder($required);
        $check('[REST7] Italy: an order without the value that is required there is refused', $result['order_id'] === 0 && stripos($result['message'], 'E2E Address Text') !== false, $result['message']);
        $result = $italianOrder($required + ['e2e_a_text' => 'ita api note']);
        $check('[REST7] Italy: the order with the value is placed', $result['order_id'] > 0, $result['message']);
        if ($result['order_id'] > 0) {
            $order = $readOrder($result['order_id']);
            $check('[REST7] Italy: the values are on both addresses', ($order['billing']['e2e_a_text']['value'] ?? '') === 'ita api note' && ($order['shipping']['e2e_a_text']['value'] ?? '') === 'ita api note');
            [, $placed] = $api('GET', '/orders/' . $result['order_id'], null, $admin);
            $check('[REST7] Italy: the order belongs to the Italy store (order number starts with 2)', str_starts_with((string) ($placed['increment_id'] ?? ''), '2'), (string) ($placed['increment_id'] ?? ''));
        }
        $result = $guestOrder($required);
        $check('[REST7] main website: the same order without the value is placed', $result['order_id'] > 0, $result['step'] . ': ' . $result['message']);

        // disabled on the Italy website: the attribute is not part of its metadata, the main website keeps it
        $bench('set-website', 'e2e_a_text', '2', 'required=-', 'enabled=0');
        [$status, $meta] = $api('GET', '/attributeMetadata/customerAddress/attribute/e2e_a_text', null, $admin, $itaHost, 'ita');
        [$mainStatus] = $api('GET', '/attributeMetadata/customerAddress/attribute/e2e_a_text', null, $admin);
        $check('[REST7][WEB2~] metadata of an attribute disabled for the Italy website is not offered there but on the main website', $mainStatus === 200 && ($status === 404 || (isset($meta['visible']) && !$meta['visible'])), "status $status " . json_encode($meta));
    } finally {
        $bench('set-website', 'e2e_a_text', '2', 'enabled=-', 'required=-', 'default=-');
    }
}

echo "\n" . ($failed === 0 ? "All $total checks passed.\n" : "$failed of $total checks failed.\n");
exit($failed === 0 ? 0 : 1);
