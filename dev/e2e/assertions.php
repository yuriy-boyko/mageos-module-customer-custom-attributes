<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

/**
 * Database assertions for the end-to-end runs. Read-only: it never changes data.
 *
 *   php dev/e2e/assertions.php check                       invariants that must always hold (exit 1 when one fails)
 *   php dev/e2e/assertions.php order <increment_id>        the module's snapshot rows of one order as JSON
 *   php dev/e2e/assertions.php customer <email>            the stored values of all custom customer attributes as JSON
 *   php dev/e2e/assertions.php quote <email>               the module's values on the customer's latest quote as JSON
 *   php dev/e2e/assertions.php queries                     how many SQL statements loading order and order address collections of several sizes takes (JSON), to show the module adds none per row
 *   php dev/e2e/assertions.php attribute <entity> <code>   how an attribute is stored (labels, options, forms, rules) as JSON; entity customer|address
 *
 * `customer` before and after a checkout, compared with `diff`, proves the account was not changed:
 *
 *   php dev/e2e/assertions.php customer a@b.c > before.json ; ...checkout... ; php dev/e2e/assertions.php customer a@b.c | diff before.json -
 *
 * Run it from the Magento root (or set MAGENTO_ROOT). This file declares no class, so `setup:di:compile` ignores it.
 */

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$command = $argv[1] ?? '';
$argument = $argv[2] ?? '';
if (!in_array($command, ['check', 'order', 'customer', 'quote', 'attribute', 'queries'], true) || (!in_array($command, ['check', 'queries'], true) && $argument === '')) {
    fwrite(STDERR, "Usage: php dev/e2e/assertions.php check | order <increment_id> | customer <email> | quote <email> | attribute <customer|address> <code>\n");
    exit(2);
}

$root = rtrim((string) (getenv('MAGENTO_ROOT') ?: getcwd()), '/');
if (!is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Run from the Magento root or set MAGENTO_ROOT (app/bootstrap.php not found in $root).\n");
    exit(2);
}
require $root . '/app/bootstrap.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
/** @var ResourceConnection $resource */
$resource = $objectManager->get(ResourceConnection::class);
$db = $resource->getConnection();
/** Replaces {{table}} with the real table name. */
$sql = static fn (string $query): string => (string) preg_replace_callback(
    '/\{\{(\w+)\}\}/',
    static fn (array $m): string => $resource->getTableName($m[1]),
    $query
);

if ($command === 'check') {
    // Each query selects the rows that BREAK an invariant; no rows means the check passes.
    $checks = [
        '[SNAP6] order snapshot rows belong to an order' =>
            'SELECT v.value_id FROM {{mageos_cca_order_value}} v LEFT JOIN {{sales_order}} o ON o.entity_id = v.order_id WHERE o.entity_id IS NULL',
        'order address snapshot rows belong to an order address' =>
            'SELECT v.value_id FROM {{mageos_cca_order_address_value}} v LEFT JOIN {{sales_order_address}} a ON a.entity_id = v.order_address_id WHERE a.entity_id IS NULL',
        '[SNAP7~] quote value rows belong to a quote' =>
            'SELECT v.value_id FROM {{mageos_cca_quote_value}} v LEFT JOIN {{quote}} q ON q.entity_id = v.quote_id WHERE q.entity_id IS NULL',
        '[SNAP7~] quote address value rows belong to a quote address' =>
            'SELECT v.value_id FROM {{mageos_cca_quote_address_value}} v LEFT JOIN {{quote_address}} a ON a.address_id = v.quote_address_id WHERE a.address_id IS NULL',
        'relation rules point to an existing relation and existing attributes' =>
            'SELECT d.detail_id FROM {{mageos_cca_relation_detail}} d
             LEFT JOIN {{mageos_cca_relation}} r ON r.relation_id = d.relation_id
             LEFT JOIN {{eav_attribute}} p ON p.attribute_id = d.parent_attribute_id
             LEFT JOIN {{eav_attribute}} c ON c.attribute_id = d.dependent_attribute_id
             WHERE r.relation_id IS NULL OR p.attribute_id IS NULL OR c.attribute_id IS NULL',
        'group restrictions point to an existing attribute and group' =>
            'SELECT g.attribute_id FROM {{mageos_cca_attribute_group}} g
             LEFT JOIN {{eav_attribute}} a ON a.attribute_id = g.attribute_id
             LEFT JOIN {{customer_group}} cg ON cg.customer_group_id = g.customer_group_id
             WHERE a.attribute_id IS NULL OR cg.customer_group_id IS NULL',
        'no relation rule points an attribute to itself' =>
            'SELECT detail_id FROM {{mageos_cca_relation_detail}} WHERE parent_attribute_id = dependent_attribute_id',
        'a rule parent is a dropdown, multiple select or yes/no' =>
            "SELECT d.detail_id FROM {{mageos_cca_relation_detail}} d JOIN {{eav_attribute}} p ON p.attribute_id = d.parent_attribute_id
             WHERE p.frontend_input NOT IN ('select', 'multiselect', 'boolean')",
        'relation rules only use attributes of the relation\'s entity' =>
            "SELECT d.detail_id FROM {{mageos_cca_relation_detail}} d
             JOIN {{mageos_cca_relation}} r ON r.relation_id = d.relation_id
             JOIN {{eav_attribute}} p ON p.attribute_id = d.parent_attribute_id
             JOIN {{eav_attribute}} c ON c.attribute_id = d.dependent_attribute_id
             JOIN {{eav_entity_type}} pt ON pt.entity_type_id = p.entity_type_id
             JOIN {{eav_entity_type}} ct ON ct.entity_type_id = c.entity_type_id
             WHERE pt.entity_type_code <> IF(r.entity_type = 'address', 'customer_address', 'customer')
                OR ct.entity_type_code <> IF(r.entity_type = 'address', 'customer_address', 'customer')",
        '[REG4~] system attributes never get quote values' =>
            'SELECT v.value_id FROM {{mageos_cca_quote_value}} v JOIN {{eav_attribute}} a ON a.attribute_id = v.attribute_id WHERE a.is_user_defined = 0
             UNION ALL
             SELECT v.value_id FROM {{mageos_cca_quote_address_value}} v JOIN {{eav_attribute}} a ON a.attribute_id = v.attribute_id WHERE a.is_user_defined = 0',
        '[REG4~] system attributes never get order snapshot rows' =>
            'SELECT v.value_id FROM {{mageos_cca_order_value}} v JOIN {{eav_attribute}} a ON a.attribute_code = v.attribute_code AND a.is_user_defined = 0
             JOIN {{eav_entity_type}} t ON t.entity_type_id = a.entity_type_id AND t.entity_type_code = \'customer\'
             UNION ALL
             SELECT v.value_id FROM {{mageos_cca_order_address_value}} v JOIN {{eav_attribute}} a ON a.attribute_code = v.attribute_code AND a.is_user_defined = 0
             JOIN {{eav_entity_type}} t ON t.entity_type_id = a.entity_type_id AND t.entity_type_code = \'customer_address\'',
        '[VAL7~] a virtual order has no shipping address snapshot' =>
            "SELECT v.value_id FROM {{mageos_cca_order_address_value}} v
             JOIN {{sales_order_address}} a ON a.entity_id = v.order_address_id AND a.address_type = 'shipping'
             JOIN {{sales_order}} o ON o.entity_id = a.parent_id AND o.is_virtual = 1",
        'order snapshot rows carry a value and a label' =>
            "SELECT value_id FROM {{mageos_cca_order_value}} WHERE value IS NULL OR value = '' OR value_label IS NULL OR attribute_label = ''
             UNION ALL
             SELECT value_id FROM {{mageos_cca_order_address_value}} WHERE value IS NULL OR value = '' OR value_label IS NULL OR attribute_label = ''",
        'order snapshot rows are unique per order and attribute' =>
            'SELECT order_id FROM {{mageos_cca_order_value}} GROUP BY order_id, attribute_code HAVING COUNT(*) > 1
             UNION ALL
             SELECT order_address_id FROM {{mageos_cca_order_address_value}} GROUP BY order_address_id, attribute_code HAVING COUNT(*) > 1',
        'quote values are unique per quote and attribute' =>
            'SELECT quote_id FROM {{mageos_cca_quote_value}} GROUP BY quote_id, attribute_id HAVING COUNT(*) > 1
             UNION ALL
             SELECT quote_address_id FROM {{mageos_cca_quote_address_value}} GROUP BY quote_address_id, attribute_id HAVING COUNT(*) > 1',
        'address attributes carry no customer checkout settings' =>
            "SELECT e.attribute_id FROM {{customer_eav_attribute}} e
             JOIN {{eav_attribute}} a ON a.attribute_id = e.attribute_id
             JOIN {{eav_entity_type}} t ON t.entity_type_id = a.entity_type_id AND t.entity_type_code = 'customer_address'
             WHERE e.validate_rules LIKE '%mageos_checkout_position%'",
    ];

    $failed = 0;
    foreach ($checks as $name => $query) {
        $rows = $db->fetchCol($sql($query));
        $count = count($rows);
        echo ($count === 0 ? 'ok    ' : 'FAIL  ') . $name . ($count === 0 ? '' : ' (' . $count . ' rows, first ids: ' . implode(', ', array_slice($rows, 0, 5)) . ')') . "\n";
        $failed += $count === 0 ? 0 : 1;
    }
    echo $failed === 0 ? "All checks passed.\n" : "$failed check(s) failed.\n";
    exit($failed === 0 ? 0 : 1);
}

if ($command === 'queries') {
    $statements = static fn (): int => (int) ($db->fetchRow('SHOW SESSION STATUS LIKE \'Questions\'')['Value'] ?? 0);
    $measure = static function (string $factoryClass, int $size, callable $afterLoad) use ($objectManager, $statements): array {
        $collection = $objectManager->create($factoryClass)->create()->setPageSize($size)->setOrder('entity_id', 'DESC');
        $before = $statements();
        $collection->load();
        $items = $collection->getItems();
        $afterLoad($items);
        return ['items' => count($items), 'statements' => $statements() - $before - 1]; // the second reading counts itself
    };
    $orderFactory = \Magento\Sales\Model\ResourceModel\Order\CollectionFactory::class;
    $addressFactory = \Magento\Sales\Model\ResourceModel\Order\Address\CollectionFactory::class;
    $touch = static function (array $items): void {
        foreach ($items as $item) {
            $item->getData(); // reading what the observers set costs no query
        }
    };
    $measure($orderFactory, 2, $touch); // warm up: configuration and EAV are read once per process
    $measure($addressFactory, 2, $touch);
    $result = ['orders' => [], 'addresses' => []];
    foreach ([2, 20, 100] as $size) {
        $result['orders'][(string) $size] = $measure($orderFactory, $size, $touch);
        $result['addresses'][(string) $size] = $measure($addressFactory, $size, $touch);
    }
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

$json = static function (array $data): void {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
};

if ($command === 'attribute') {
    $code = (string) ($argv[3] ?? '');
    $typeCode = $argument === 'address' ? 'customer_address' : 'customer';
    $row = $db->fetchRow(
        $sql('SELECT a.attribute_id, a.frontend_label, a.frontend_input, a.backend_type, a.is_required, a.is_user_defined, a.default_value,
                     e.is_visible, e.sort_order, e.validate_rules
              FROM {{eav_attribute}} a
              JOIN {{eav_entity_type}} t ON t.entity_type_id = a.entity_type_id AND t.entity_type_code = ?
              LEFT JOIN {{customer_eav_attribute}} e ON e.attribute_id = a.attribute_id
              WHERE a.attribute_code = ?'),
        [$typeCode, $code]
    );
    if (!$row) {
        $json(['exists' => false, 'entity' => $argument, 'code' => $code]);
        exit(0);
    }
    $id = (int) $row['attribute_id'];
    $options = [];
    $defaults = array_filter(explode(',', (string) $row['default_value']), 'strlen');
    foreach ($db->fetchAll($sql('SELECT o.option_id, o.sort_order, v.value FROM {{eav_attribute_option}} o
            LEFT JOIN {{eav_attribute_option_value}} v ON v.option_id = o.option_id AND v.store_id = 0 WHERE o.attribute_id = ? ORDER BY o.sort_order, o.option_id'), [$id]) as $option) {
        $labels = $db->fetchPairs($sql('SELECT store_id, value FROM {{eav_attribute_option_value}} WHERE option_id = ? ORDER BY store_id'), [$option['option_id']]);
        $options[] = [
            'label' => (string) $option['value'],
            'sort_order' => (int) $option['sort_order'],
            'is_default' => in_array((string) $option['option_id'], $defaults, true),
            'store_labels' => array_filter($labels, static fn ($value, $store) => (int) $store !== 0, ARRAY_FILTER_USE_BOTH),
        ];
    }
    $rules = (array) json_decode((string) $row['validate_rules'], true);
    ksort($rules);
    $json([
        'exists' => true,
        'id' => $id,
        'entity' => $argument,
        'code' => $code,
        'label' => (string) $row['frontend_label'],
        'store_labels' => $db->fetchPairs($sql('SELECT store_id, value FROM {{eav_attribute_label}} WHERE attribute_id = ? ORDER BY store_id'), [$id]),
        'input' => (string) $row['frontend_input'],
        'backend_type' => (string) $row['backend_type'],
        'required' => (bool) $row['is_required'],
        'user_defined' => (bool) $row['is_user_defined'],
        'default_value' => $row['default_value'],
        'is_visible' => (bool) $row['is_visible'],
        'sort_order' => (int) $row['sort_order'],
        'forms' => $db->fetchCol($sql('SELECT form_code FROM {{customer_form_attribute}} WHERE attribute_id = ? ORDER BY form_code'), [$id]),
        'options' => $options,
        'rules' => $rules,
    ]);
    exit(0);
}

if ($command === 'order') {
    $orderId = (int) $db->fetchOne($sql('SELECT entity_id FROM {{sales_order}} WHERE increment_id = ?'), [$argument]);
    if ($orderId === 0) {
        fwrite(STDERR, "Order $argument not found.\n");
        exit(1);
    }
    $customer = $db->fetchAll(
        $sql('SELECT attribute_code, attribute_label, input_type, value, value_label FROM {{mageos_cca_order_value}} WHERE order_id = ? ORDER BY attribute_code'),
        [$orderId]
    );
    $addresses = [];
    foreach ($db->fetchAll($sql('SELECT entity_id, address_type FROM {{sales_order_address}} WHERE parent_id = ? ORDER BY address_type'), [$orderId]) as $address) {
        $addresses[$address['address_type']] = $db->fetchAll(
            $sql('SELECT attribute_code, attribute_label, input_type, value, value_label FROM {{mageos_cca_order_address_value}} WHERE order_address_id = ? ORDER BY attribute_code'),
            [$address['entity_id']]
        );
    }
    $json(['order' => $argument, 'customer_attributes' => $customer, 'addresses' => $addresses]);
    exit(0);
}

// customer and quote commands work from the customer's e-mail
$customerRow = $db->fetchRow($sql('SELECT entity_id FROM {{customer_entity}} WHERE email = ?'), [$argument]);
if (!$customerRow && $command === 'customer') {
    fwrite(STDERR, "Customer $argument not found.\n");
    exit(1);
}
$customerId = $customerRow ? (int) $customerRow['entity_id'] : 0;

if ($command === 'customer') {
    // Read through the EAV models so every backend type is covered; only user-defined attributes of the customer and its addresses.
    $customer = $objectManager->create(\Magento\Customer\Model\Customer::class)->load($customerId);
    $values = [];
    $codes = $db->fetchCol($sql("SELECT a.attribute_code FROM {{eav_attribute}} a JOIN {{eav_entity_type}} t ON t.entity_type_id = a.entity_type_id
        WHERE t.entity_type_code = 'customer' AND a.is_user_defined = 1 ORDER BY a.attribute_code"));
    foreach ($codes as $code) {
        $value = $customer->getData($code);
        $values[$code] = is_array($value) ? implode(',', $value) : ($value === null ? null : (string) $value);
    }
    $addressCodes = $db->fetchCol($sql("SELECT a.attribute_code FROM {{eav_attribute}} a JOIN {{eav_entity_type}} t ON t.entity_type_id = a.entity_type_id
        WHERE t.entity_type_code = 'customer_address' AND a.is_user_defined = 1 ORDER BY a.attribute_code"));
    $addresses = [];
    foreach ($customer->getAddressesCollection() as $address) {
        $row = [];
        foreach ($addressCodes as $code) {
            $value = $address->getData($code);
            $row[$code] = is_array($value) ? implode(',', $value) : ($value === null ? null : (string) $value);
        }
        $addresses[(string) $address->getId()] = $row;
    }
    ksort($addresses);
    $json(['email' => $argument, 'customer_id' => $customerId, 'customer_attributes' => $values, 'addresses' => $addresses]);
    exit(0);
}

// quote
// a customer's latest quote, or (a guest has no account) the latest quote with that e-mail
// (a guest quote has no e-mail until the order is placed: `@latest` is the newest quote of all, which is the one a test just created)
if ($argument === '@latest') {
    $quoteId = (int) $db->fetchOne($sql('SELECT MAX(entity_id) FROM {{quote}}'));
} else {
    $quoteId = $customerId > 0
        ? (int) $db->fetchOne($sql('SELECT MAX(entity_id) FROM {{quote}} WHERE customer_id = ?'), [$customerId])
        : (int) $db->fetchOne($sql('SELECT MAX(entity_id) FROM {{quote}} WHERE customer_email = ?'), [$argument]);
}
if ($quoteId === 0) {
    fwrite(STDERR, "No quote for $argument.\n");
    exit(1);
}
$customerValues = $db->fetchPairs(
    $sql('SELECT a.attribute_code, v.value FROM {{mageos_cca_quote_value}} v JOIN {{eav_attribute}} a ON a.attribute_id = v.attribute_id WHERE v.quote_id = ? ORDER BY a.attribute_code'),
    [$quoteId]
);
$addresses = [];
foreach ($db->fetchAll($sql('SELECT address_id, address_type FROM {{quote_address}} WHERE quote_id = ? ORDER BY address_type'), [$quoteId]) as $address) {
    $addresses[$address['address_type']] = $db->fetchPairs(
        $sql('SELECT a.attribute_code, v.value FROM {{mageos_cca_quote_address_value}} v JOIN {{eav_attribute}} a ON a.attribute_id = v.attribute_id WHERE v.quote_address_id = ? ORDER BY a.attribute_code'),
        [$address['address_id']]
    );
}
$json(['quote_id' => $quoteId, 'active' => (bool) $db->fetchOne($sql('SELECT is_active FROM {{quote}} WHERE entity_id = ?'), [$quoteId]), 'customer_attributes' => $customerValues, 'addresses' => $addresses]);
