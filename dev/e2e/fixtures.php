<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

/**
 * Creates and removes the end-to-end test fixtures (attributes, relations, a test customer) described in
 * fixtures.json, so a test run starts from a known state and leaves nothing behind.
 *
 *   php dev/e2e/fixtures.php seed        create what is missing, update the rest
 *   php dev/e2e/fixtures.php teardown    remove everything seed created (codes start with e2e_)
 *   php dev/e2e/fixtures.php status      list what exists
 *   php dev/e2e/fixtures.php delete-option <code> <option label>   delete one option of a fixture attribute, like the admin does
 *   php dev/e2e/fixtures.php delete-attribute <code>              delete one fixture attribute, like the admin does
 *   php dev/e2e/fixtures.php set-customer <email> <code=value>...  set custom attribute values on a customer (select: option label, several: a|b)
 *   php dev/e2e/fixtures.php drop-quotes <email>   delete the quotes of a customer or e-mail (a customer without an active cart)
 *   php dev/e2e/fixtures.php purge       delete the orders and quotes of test runs (e-mail like %e2e%@example.com)
 *
 * Run it from the Magento root (or set MAGENTO_ROOT). The test customer's password is read from the environment variable
 * named in fixtures.json (E2E_CUSTOMER_PASSWORD); the script never stores or prints it.
 * Attributes go through the module's own form processor, so they get the same validation as in the admin.
 * This file declares no class, so `setup:di:compile` ignores it.
 */

use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use MageOS\CustomerCustomAttributes\Model\Attribute\FormProcessor;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeGroupStorage;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeWebsiteStorage;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\RelationStorage;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Registry;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$command = $argv[1] ?? '';
if (!in_array($command, ['seed', 'teardown', 'status', 'purge', 'delete-option', 'delete-attribute', 'set-customer', 'drop-quotes'], true)) {
    fwrite(STDERR, "Usage: php dev/e2e/fixtures.php seed|teardown|status|purge|delete-option <code> <label>|delete-attribute <code>|set-customer <email> <code=value>...|drop-quotes <email>\n");
    exit(2);
}

$root = rtrim((string) (getenv('MAGENTO_ROOT') ?: getcwd()), '/');
if (!is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Run from the Magento root or set MAGENTO_ROOT (app/bootstrap.php not found in $root).\n");
    exit(2);
}
require $root . '/app/bootstrap.php';

$fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();
$objectManager->get(State::class)->setAreaCode('adminhtml');

/** @var EavConfig $eavConfig */
$eavConfig = $objectManager->get(EavConfig::class);
$entityTypeCodes = ['customer' => 'customer', 'address' => 'customer_address'];
$prefix = 'e2e_';

/** Loads an attribute of the fixtures by entity key and code, null when it does not exist. */
$findAttribute = static function (string $entity, string $code) use ($eavConfig, $entityTypeCodes): ?Attribute {
    $eavConfig->clear();
    $attribute = $eavConfig->getAttribute($entityTypeCodes[$entity], $code);
    return $attribute && $attribute->getId() ? $attribute : null;
};

$seedAttribute = static function (array $definition) use ($objectManager, $fixtures, $findAttribute, $eavConfig): int {
    $entity = $definition['entity'];
    $code = $definition['code'];
    $existing = $findAttribute($entity, $code);
    if ($existing !== null) {
        return (int) $existing->getId();
    }

    // The module's context reads the area from the request parameter, like the admin form does.
    $objectManager->get(HttpRequest::class)->setParam(EntityContext::REQUEST_PARAM, $entity);
    $context = $objectManager->get(EntityContext::class);

    $forms = $definition['forms'] ?? $fixtures[$entity . '_forms_default'];
    if ($entity === 'customer' && !isset($definition['checkout'])) {
        $forms = array_values(array_diff($forms, ['mageos_hyva_checkout']));
    }
    $post = [
        'attribute_code' => $code,
        'frontend_input' => $definition['input'],
        'frontend_label' => [0 => $definition['label']],
        'is_required' => !empty($definition['required']) ? 1 : 0,
        'sort_order' => 100,
        'used_in_forms' => $forms,
    ];
    if (isset($definition['display'])) {
        $post['display_as'] = $definition['display'];
    }
    foreach ($definition['rules'] ?? [] as $key => $value) {
        $post[$key] = $value;
    }
    if (isset($definition['checkout'])) {
        $post['checkout_position'] = $definition['checkout']['position'];
        $post['checkout_sort'] = (string) ($definition['checkout']['sort'] ?? '');
        $post['checkout_prefill'] = !empty($definition['checkout']['prefill']) ? 1 : 0;
    }
    if (!empty($definition['options'])) {
        $values = [];
        $order = [];
        foreach ($definition['options'] as $index => $label) {
            $values['option_' . $index] = [0 => $label];
            $order['option_' . $index] = $index + 1;
        }
        $post['option'] = ['value' => $values, 'order' => $order, 'delete' => []];
    }

    $result = $objectManager->get(FormProcessor::class)->process($context, $post, null);
    if ($result['errors'] !== []) {
        throw new RuntimeException($code . ': ' . implode(' ', $result['errors']));
    }
    /** @var Attribute $attribute */
    $attribute = $objectManager->create(Attribute::class);
    $attribute->addData($result['data']);
    $attribute->save();
    $objectManager->get(AttributeGroupStorage::class)->save((int) $attribute->getId(), $definition['groups'] ?? []);
    if (!empty($definition['websites'])) {
        $objectManager->get(AttributeWebsiteStorage::class)->save((int) $attribute->getId(), $definition['websites']);
    }
    $eavConfig->clear();
    return (int) $attribute->getId();
};

/** @return int[] relation ids of the fixtures (by name) */
$findRelations = static function () use ($objectManager, $fixtures): array {
    $names = array_column($fixtures['relations'], 'name');
    $found = [];
    foreach ($objectManager->get(RelationStorage::class)->getAll() as $relation) {
        if (in_array($relation['name'], $names, true)) {
            $found[(string) $relation['name']] = (int) $relation['relation_id'];
        }
    }
    return $found;
};

$customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
$findCustomer = static function (string $email) use ($customerRepository) {
    try {
        return $customerRepository->get($email);
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        return null;
    }
};

if ($command === 'seed') {
    // The customer comes first: a required customer attribute would block creating an account without a value.
    foreach ($fixtures['customers'] as $definition) {
        if ($findCustomer($definition['email']) !== null) {
            echo 'exists   customer ' . $definition['email'] . "\n";
            continue;
        }
        $password = (string) getenv($definition['password_env']);
        if ($password === '') {
            fwrite(STDERR, 'Set ' . $definition['password_env'] . " to create the test customer.\n");
            exit(1);
        }
        $groupId = 1;
        $customer = $objectManager->get(CustomerInterfaceFactory::class)->create();
        $customer->setEmail($definition['email'])->setFirstname($definition['firstname'])->setLastname($definition['lastname'])
            ->setGroupId($groupId)->setWebsiteId(1)->setStoreId(1);
        $objectManager->get(AccountManagementInterface::class)->createAccount($customer, $password);
        echo 'created  customer ' . $definition['email'] . "\n";
    }
    $ids = [];
    foreach ($fixtures['attributes'] as $definition) {
        $existed = $findAttribute($definition['entity'], $definition['code']) !== null;
        $ids[$definition['code']] = $seedAttribute($definition);
        echo ($existed ? 'exists   ' : 'created  ') . $definition['entity'] . ' ' . $definition['code'] . ' (' . $ids[$definition['code']] . ")\n";
    }

    $relationStorage = $objectManager->get(RelationStorage::class);
    $existingRelations = $findRelations();
    foreach ($fixtures['relations'] as $relation) {
        if (isset($existingRelations[$relation['name']])) {
            echo 'exists   relation ' . $relation['name'] . "\n";
            continue;
        }
        // A rule stores the option id of the parent; the fixtures name the option by its admin label.
        $rules = [];
        $resource = $objectManager->get(ResourceConnection::class);
        foreach ($relation['rules'] as $rule) {
            $optionId = $resource->getConnection()->fetchOne(
                'SELECT o.option_id FROM ' . $resource->getTableName('eav_attribute_option') . ' o JOIN '
                . $resource->getTableName('eav_attribute_option_value') . ' v ON v.option_id = o.option_id AND v.store_id = 0'
                . ' WHERE o.attribute_id = ? AND v.value = ?',
                [$ids[$rule['parent']], $rule['option']]
            );
            $rule['option'] = $optionId !== false ? (string) $optionId : (string) $rule['option'];
            $rules[] = $rule;
        }
        $relationStorage->save(null, $relation['name'], $relation['entity'], $rules, $ids);
        echo 'created  relation ' . $relation['name'] . "\n";
    }

    $eavConfig->clear();
    echo "Flush the cache before testing: bin/magento cache:flush\n";
    exit(0);
}

if ($command === 'status') {
    foreach ($fixtures['attributes'] as $definition) {
        $attribute = $findAttribute($definition['entity'], $definition['code']);
        echo str_pad($definition['code'], 16) . ($attribute ? 'present (' . $attribute->getId() . ')' : 'missing') . "\n";
    }
    $relations = $findRelations();
    foreach ($fixtures['relations'] as $relation) {
        echo str_pad('relation', 16) . $relation['name'] . ': ' . (isset($relations[$relation['name']]) ? 'present' : 'missing') . "\n";
    }
    foreach ($fixtures['customers'] as $definition) {
        echo str_pad('customer', 16) . $definition['email'] . ': ' . ($findCustomer($definition['email']) ? 'present' : 'missing') . "\n";
    }
    exit(0);
}

if ($command === 'delete-option' || $command === 'delete-attribute') {
    $code = (string) ($argv[2] ?? '');
    $definition = null;
    foreach ($fixtures['attributes'] as $candidate) {
        if ($candidate['code'] === $code) {
            $definition = $candidate;
        }
    }
    if ($definition === null) {
        fwrite(STDERR, "$code is not a fixture attribute.\n");
        exit(2);
    }
    $attribute = $findAttribute($definition['entity'], $code);
    if ($attribute === null) {
        fwrite(STDERR, "$code does not exist (seed first).\n");
        exit(1);
    }
    $resource = $objectManager->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $attributeId = (int) $attribute->getId();
    // what still refers to the attribute: relation rules (as parent or dependent) and customer group restrictions
    $references = static fn (): string => 'rules=' . $connection->fetchOne(
        'SELECT COUNT(*) FROM ' . $resource->getTableName('mageos_cca_relation_detail') . ' WHERE parent_attribute_id = ? OR dependent_attribute_id = ?',
        [$attributeId, $attributeId]
    ) . ' groups=' . $connection->fetchOne(
        'SELECT COUNT(*) FROM ' . $resource->getTableName('mageos_cca_attribute_group') . ' WHERE attribute_id = ?',
        [$attributeId]
    );
    echo "before   $code " . $references() . "\n";

    if ($command === 'delete-attribute') {
        $attribute->delete();
        $eavConfig->clear();
        echo "deleted  attribute $code\n";
        echo "after    $code " . $references() . "\n";
        exit(0);
    }

    // delete-option: saved through the module's form processor, like the admin form with the option marked as deleted
    $label = (string) ($argv[3] ?? '');
    $objectManager->get(HttpRequest::class)->setParam(EntityContext::REQUEST_PARAM, $definition['entity']);
    $values = [];
    $order = [];
    $delete = [];
    $found = false;
    foreach ($attribute->setStoreId(0)->getSource()->getAllOptions(false) as $index => $option) {
        $key = (string) $option['value'];
        $values[$key] = [0 => (string) $option['label']];
        $order[$key] = $index + 1;
        $delete[$key] = (string) $option['label'] === $label ? 1 : 0;
        $found = $found || $delete[$key] === 1;
    }
    if (!$found) {
        fwrite(STDERR, "$code has no option \"$label\".\n");
        exit(1);
    }
    $rules = (array) $attribute->getValidateRules();
    $post = [
        'frontend_label' => [0 => (string) $attribute->getFrontendLabel()],
        'is_required' => (int) $attribute->getIsRequired(),
        'sort_order' => (int) $attribute->getSortOrder(),
        'used_in_forms' => (array) $attribute->getUsedInForms(),
        'option' => ['value' => $values, 'order' => $order, 'delete' => $delete],
    ];
    if (isset($rules['mageos_display'])) {
        $post['display_as'] = $rules['mageos_display'];
    }
    $context = $objectManager->get(EntityContext::class);
    $result = $objectManager->get(FormProcessor::class)->process($context, $post + ['frontend_input' => $attribute->getFrontendInput()], $attribute);
    if ($result['errors'] !== []) {
        throw new RuntimeException($code . ': ' . implode(' ', $result['errors']));
    }
    $attribute->addData($result['data']);
    $attribute->save();
    $eavConfig->clear();
    echo "deleted  option \"$label\" of $code\n";
    echo "after    $code " . $references() . "\n";
    exit(0);
}

if ($command === 'drop-quotes') {
    $email = (string) ($argv[2] ?? '');
    $resource = $objectManager->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $deleted = $connection->delete($resource->getTableName('quote'), ['customer_email = ?' => $email]);
    $customer = $email !== '' ? $findCustomer($email) : null;
    if ($customer !== null) {
        $deleted += $connection->delete($resource->getTableName('quote'), ['customer_id = ?' => (int) $customer->getId()]);
    }
    echo "removed  $deleted quote(s) of $email\n";
    exit(0);
}

if ($command === 'set-customer') {
    $email = (string) ($argv[2] ?? '');
    $definitions = [];
    foreach ($fixtures['attributes'] as $candidate) {
        if ($candidate['entity'] === 'customer') {
            $definitions[$candidate['code']] = $candidate;
        }
    }
    $given = [];
    foreach (array_slice($argv, 3) as $pair) {
        [$code, $value] = array_pad(explode('=', $pair, 2), 2, '');
        if (!isset($definitions[$code])) {
            fwrite(STDERR, "$code is not a fixture customer attribute.\n");
            exit(2);
        }
        $attribute = $findAttribute('customer', $code);
        // select and multiple select values are option labels: the store keeps option ids
        if ($value !== '' && in_array($definitions[$code]['input'], ['select', 'multiselect'], true)) {
            $ids = [];
            foreach (explode('|', $value) as $label) {
                $id = $attribute->getSource()->getOptionId($label);
                if ($id === null || $id === '') {
                    fwrite(STDERR, "$code has no option \"$label\".\n");
                    exit(2);
                }
                $ids[] = (string) $id;
            }
            $value = implode(',', $ids);
        }
        $given[$code] = $value;
    }
    // Load the customer only now: resolving the option labels above clears the EAV cache, and a customer loaded before that loses the
    // values set on it when it is saved.
    $customer = $findCustomer($email);
    if ($customer === null) {
        fwrite(STDERR, "Customer $email not found (seed first).\n");
        exit(1);
    }
    // the customer form always asks for required attributes: keep them filled unless a value is given
    foreach ($definitions as $code => $definition) {
        if (!empty($definition['required']) && !isset($given[$code])) {
            $current = $customer->getCustomAttribute($code);
            if ($current === null || (string) $current->getValue() === '') {
                $given[$code] = 'account value';
            }
        }
    }
    foreach ($given as $code => $value) {
        $customer->setCustomAttribute($code, $value);
    }
    $customerRepository->save($customer);
    $eavConfig->clear();
    echo 'set      ' . $email . ': ' . implode(', ', array_keys($given)) . "\n";
    exit(0);
}

if ($command === 'purge') {
    // Orders and quotes of test runs are recognised by their e-mail: every test e-mail contains "e2e" and ends with @example.com.
    $objectManager->get(Registry::class)->register('isSecureArea', true);
    $resource = $objectManager->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $pattern = '%e2e%@example.com';
    $orderIds = $connection->fetchCol('SELECT entity_id FROM ' . $resource->getTableName('sales_order') . ' WHERE customer_email LIKE ?', [$pattern]);
    $orderRepository = $objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
    foreach ($orderIds as $orderId) {
        $orderRepository->deleteById((int) $orderId);
    }
    // The module's snapshot rows go with the orders and quotes (foreign keys); sales_order_grid is cleaned by the core.
    $quotes = $connection->delete($resource->getTableName('quote'), ['customer_email LIKE ?' => $pattern]);
    echo 'removed  ' . count($orderIds) . " orders, $quotes quotes\n";
    exit(0);
}

// teardown
$objectManager->get(Registry::class)->register('isSecureArea', true);
$relationStorage = $objectManager->get(RelationStorage::class);
foreach ($findRelations() as $name => $relationId) {
    $relationStorage->delete($relationId);
    echo "removed  relation $name\n";
}
foreach ($fixtures['attributes'] as $definition) {
    if (strpos($definition['code'], $prefix) !== 0) {
        continue;
    }
    $attribute = $findAttribute($definition['entity'], $definition['code']);
    if ($attribute !== null) {
        $attribute->delete();
        echo 'removed  ' . $definition['entity'] . ' ' . $definition['code'] . "\n";
    }
}
foreach ($fixtures['customers'] as $definition) {
    $customer = $findCustomer($definition['email']);
    if ($customer !== null) {
        $customerRepository->delete($customer);
        echo 'removed  customer ' . $definition['email'] . "\n";
    }
}
$eavConfig->clear();
echo "Orders and quotes created by tests are not removed; identify them by the test customer or e-mail.\n";
