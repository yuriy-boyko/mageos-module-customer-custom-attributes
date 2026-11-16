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
 *   php dev/e2e/fixtures.php create-attributes <customer|address> [--forms=a,b] [--position=payment_above] <code>...   create plain optional text attributes (codes start with e2e_), label "ADM <code>", in these forms (default: the account / address book form); remove them with delete-created
 *   php dev/e2e/fixtures.php set-admin <username> <resource,...>   create or change a test admin (username starts with e2e_acl) whose role allows only these ACL resources (E2E_CUSTOMER_PASSWORD is its password); delete-admin <username> removes it
 *   php dev/e2e/fixtures.php secret-key <route/controller/action> <form key>   the admin URL secret key of an action for a session (what Magento puts into its links), to open a screen no link leads to
 *   php dev/e2e/fixtures.php delete-created <code>                delete an attribute a test created through the admin (its code starts with e2e_; any entity), no error when absent
 *   php dev/e2e/fixtures.php set-attribute <code> <required=0|1> <forms=a,b,...>  change whether a fixture attribute is required and the forms it is in
 *   php dev/e2e/fixtures.php set-customer <email> <code=value>...  set custom attribute values on a customer (select: option label, several: a|b)
 *   php dev/e2e/fixtures.php set-address <email> [--add] <code=value>...   give a customer one saved address (`--add` adds a second one, not a default, and keeps the first) (default billing and shipping) with these custom address values (select: option label, several: a|b, date: YYYY-MM-DD); `--delete` instead of the pairs removes all saved addresses
 *   php dev/e2e/fixtures.php quote-address-saves   save a throw-away quote address four times (new values, identical, changed, identical) and print how many rows the value table was asked to write each time (JSON)
 *   php dev/e2e/fixtures.php set-website <code> <website_id> [enabled=0|1|-] [required=0|1|-] [default=text|-]   the per-website override of a fixture attribute (`-` = use the default of the attribute)
 *   php dev/e2e/fixtures.php create-customer <email> <website_id>   a customer on that website (E2E_CUSTOMER_PASSWORD is the password); purge deletes it when the e-mail contains e2e
 *   php dev/e2e/fixtures.php bulk-attributes <n>   create n address attributes e2e_bulk_01... (every third a dropdown with 3 options) in all storefront address forms (ROB3); bulk-addresses <email> <n> gives a customer n saved addresses with values for them; bulk-clean deletes them and the addresses
 *   php dev/e2e/fixtures.php drop-quotes <email>   delete the quotes of a customer or e-mail (a customer without an active cart)
 *   php dev/e2e/fixtures.php purge       delete the orders, quotes and customers of test runs (e-mail like %e2e%@example.com)
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
if (!in_array($command, ['seed', 'teardown', 'status', 'purge', 'delete-option', 'delete-attribute', 'set-customer', 'drop-quotes', 'set-attribute', 'delete-created', 'create-attributes', 'set-admin', 'delete-admin', 'secret-key', 'set-address', 'quote-address-saves', 'set-website', 'create-customer', 'bulk-attributes', 'bulk-addresses', 'bulk-clean'], true)) {
    fwrite(STDERR, "Usage: php dev/e2e/fixtures.php seed|teardown|status|purge|delete-option <code> <label>|delete-attribute <code>|set-customer <email> <code=value>...|delete-created <code>|drop-quotes <email>|set-attribute <code> required=0|1 forms=a,b\n");
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
    // accounts are per website: look on every website (the default lookup only covers the first)
    foreach ([null, 2, 3] as $websiteId) {
        try {
            return $customerRepository->get($email, $websiteId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            continue;
        }
    }
    return null;
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
        $created = $objectManager->get(AccountManagementInterface::class)->createAccount($customer, $password);
        // the customer grid is updated on a schedule on the bench; the admin tests search the grid for this customer
        $objectManager->get(\Magento\Framework\Indexer\IndexerRegistry::class)->get('customer_grid')->reindexRow((int) $created->getId());
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

    // test products (a virtual product for carts without shipping)
    foreach ($fixtures['products'] ?? [] as $definition) {
        $productRepository = $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        try {
            $productRepository->get($definition['sku']);
            echo 'exists   product ' . $definition['sku'] . "\n";
            continue;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            // not there yet
        }
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $objectManager->create(\Magento\Catalog\Model\Product::class);
        $product->setTypeId($definition['type'])->setSku($definition['sku'])->setName($definition['name'])
            ->setAttributeSetId((int) $product->getDefaultAttributeSetId())->setPrice($definition['price'])
            ->setStatus(1)->setVisibility(4)->setUrlKey($definition['url_key'])->setWebsiteIds([1, 2])
            ->setStockData(['qty' => $definition['qty'], 'is_in_stock' => 1, 'manage_stock' => 1, 'use_config_manage_stock' => 0]);
        $product = $productRepository->save($product);
        // the stock status index decides whether the product can be bought: update it for this product now
        $objectManager->get(\Magento\CatalogInventory\Model\Indexer\Stock\Processor::class)->reindexRow((int) $product->getId(), true);
        echo 'created  product ' . $definition['sku'] . "\n";
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

if ($command === 'secret-key') {
    [$route, $controller, $action] = array_pad(explode('/', (string) ($argv[2] ?? '')), 3, '');
    echo $objectManager->get(\Magento\Framework\Encryption\EncryptorInterface::class)->getHash($route . $controller . $action . (string) ($argv[3] ?? '')) . "\n";
    exit(0);
}

if ($command === 'set-admin' || $command === 'delete-admin') {
    $username = (string) ($argv[2] ?? '');
    if (!str_starts_with($username, 'e2e_acl')) {
        fwrite(STDERR, "Only admins with a username starting with e2e_acl are handled.\n");
        exit(2);
    }
    $objectManager->get(Registry::class)->register('isSecureArea', true);
    $roleName = $username . '_role';
    $user = $objectManager->create(\Magento\User\Model\User::class)->loadByUsername($username);
    $roles = $objectManager->create(\Magento\Authorization\Model\ResourceModel\Role\Collection::class)->addFieldToFilter('role_name', $roleName)->addFieldToFilter('role_type', 'G');
    if ($command === 'delete-admin') {
        if ($user->getId()) {
            $user->delete();
        }
        foreach ($roles as $role) {
            $role->delete();
        }
        echo "deleted  admin $username\n";
        exit(0);
    }
    $resources = array_merge(['Magento_Backend::admin', 'Magento_Backend::dashboard'], array_filter(explode(',', (string) ($argv[3] ?? ''))));
    $role = $roles->getFirstItem();
    if (!$role->getId()) {
        $role = $objectManager->create(\Magento\Authorization\Model\Role::class);
        $role->setRoleName($roleName)->setPid(0)->setRoleType('G')->setUserType((string) \Magento\Authorization\Model\UserContextInterface::USER_TYPE_ADMIN)->save();
    }
    $objectManager->create(\Magento\Authorization\Model\Rules::class)->setRoleId($role->getId())->setResources($resources)->saveRel();
    if (!$user->getId()) {
        $password = (string) getenv('E2E_CUSTOMER_PASSWORD');
        if ($password === '') {
            fwrite(STDERR, "Set E2E_CUSTOMER_PASSWORD (the password of the test admin).\n");
            exit(1);
        }
        $user->setData(['username' => $username, 'firstname' => 'E2E', 'lastname' => 'Acl', 'email' => $username . '@example.com', 'password' => $password, 'is_active' => 1]);
        $user->setRoleId($role->getId())->save();
    }
    echo "set      admin $username: " . implode(', ', array_slice($resources, 2)) . "\n";
    exit(0);
}

if ($command === 'create-attributes') {
    $entity = (string) ($argv[2] ?? '');
    if (!isset($entityTypeCodes[$entity])) {
        fwrite(STDERR, "The entity must be customer or address.\n");
        exit(2);
    }
    $forms = $entity === 'customer' ? ['customer_account_edit'] : ['customer_address_edit'];
    $position = null;
    $codes = [];
    foreach (array_slice($argv, 3) as $argument) {
        if (str_starts_with($argument, '--forms=')) {
            $forms = array_values(array_filter(explode(',', substr($argument, 8))));
        } elseif (str_starts_with($argument, '--position=')) {
            $position = substr($argument, 11);
        } else {
            $codes[] = $argument;
        }
    }
    foreach ($codes as $code) {
        if (!str_starts_with($code, 'e2e_')) {
            fwrite(STDERR, "Only codes starting with e2e_ are created: $code\n");
            exit(2);
        }
        $seedAttribute(['entity' => $entity, 'code' => $code, 'label' => 'ADM ' . $code, 'input' => 'text', 'forms' => $forms]
            + ($position !== null ? ['checkout' => ['position' => $position, 'sort' => 5, 'prefill' => false]] : []));
        echo "created  $code\n";
    }
    exit(0);
}

if ($command === 'delete-created') {
    $code = (string) ($argv[2] ?? '');
    if (!str_starts_with($code, 'e2e_')) {
        fwrite(STDERR, "Only attributes with a code starting with e2e_ are deleted.\n");
        exit(2);
    }
    $deleted = 0;
    foreach (['customer', 'customer_address'] as $entity) {
        $attribute = $findAttribute($entity === 'customer' ? 'customer' : 'address', $code);
        if ($attribute !== null && $attribute->getId()) {
            $attribute->delete();
            $deleted++;
        }
    }
    $eavConfig->clear();
    echo $deleted > 0 ? "deleted  $code\n" : "absent   $code\n";
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

if ($command === 'set-attribute') {
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
    $settings = [];
    foreach (array_slice($argv, 3) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $settings[$key] = $value;
    }
    $attribute = $findAttribute($definition['entity'], $code);
    if ($attribute === null) {
        fwrite(STDERR, "$code does not exist (seed first).\n");
        exit(1);
    }
    $objectManager->get(HttpRequest::class)->setParam(EntityContext::REQUEST_PARAM, $definition['entity']);
    if (isset($settings['required'])) {
        $attribute->setIsRequired($settings['required'] === '1' ? 1 : 0);
    }
    if (isset($settings['forms'])) {
        // the forms the admin always adds are kept, like in the admin form
        $attribute->setData('used_in_forms', $objectManager->get(EntityContext::class)->resolveForms(array_filter(explode(',', $settings['forms']))));
    }
    $attribute->save();
    $eavConfig->clear();
    echo "set      $code: " . implode(' ', array_map(static fn ($k, $v) => "$k=$v", array_keys($settings), $settings)) . "\n";
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
    // the first option of every required dropdown: the default value of an account that has none
    $firstOption = [];
    foreach ($definitions as $code => $definition) {
        if (!empty($definition['required']) && in_array($definition['input'], ['select', 'multiselect'], true)) {
            $options = $findAttribute('customer', $code)->getSource()->getAllOptions(false);
            $firstOption[$code] = (string) $options[0]['value'];
        }
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
                if (in_array($definition['input'], ['select', 'multiselect'], true)) {
                    // an option id: the first option of the attribute
                    $given[$code] = $firstOption[$code];
                } else {
                    $given[$code] = 'account value';
                }
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

if ($command === 'set-address') {
    $email = (string) ($argv[2] ?? '');
    $customer = $findCustomer($email);
    if ($customer === null) {
        fwrite(STDERR, "Customer $email not found (seed first).\n");
        exit(1);
    }
    $objectManager->get(Registry::class)->register('isSecureArea', true);
    $addressRepository = $objectManager->get(\Magento\Customer\Api\AddressRepositoryInterface::class);
    // `--add` keeps the saved addresses and adds a second one (not a default)
    $add = ($argv[3] ?? '') === '--add';
    if ($add) {
        array_splice($argv, 3, 1);
    } else {
        foreach ($objectManager->get(\Magento\Customer\Model\ResourceModel\Address\CollectionFactory::class)->create()->setCustomerFilter($customer) as $old) {
            $addressRepository->deleteById((int) $old->getId());
        }
    }
    if (($argv[3] ?? '') === '--delete') {
        echo "removed  the saved addresses of $email\n";
        exit(0);
    }
    $definitions = [];
    foreach ($fixtures['attributes'] as $candidate) {
        if ($candidate['entity'] === 'address') {
            $definitions[$candidate['code']] = $candidate;
        }
    }
    $address = $objectManager->create(\Magento\Customer\Model\Address::class);
    $address->setCustomerId((int) $customer->getId())->addData([
        'firstname' => $add ? 'Bob' : 'Ada', 'lastname' => 'Saved', 'street' => [$add ? '2 Other Street' : '1 Saved Street'], 'city' => 'Austin', 'region_id' => 57,
        'postcode' => '78701', 'country_id' => 'US', 'telephone' => '5125550100', 'is_default_billing' => $add ? 0 : 1, 'is_default_shipping' => $add ? 0 : 1,
    ]);
    foreach (array_slice($argv, 3) as $pair) {
        [$code, $value] = array_pad(explode('=', $pair, 2), 2, '');
        if (!isset($definitions[$code])) {
            fwrite(STDERR, "$code is not a fixture address attribute.\n");
            exit(2);
        }
        if ($value !== '' && in_array($definitions[$code]['input'], ['select', 'multiselect'], true)) {
            $attribute = $findAttribute('address', $code);
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
        $address->setData($code, $value);
    }
    $address->save();
    $eavConfig->clear();
    echo 'set      saved address ' . $address->getId() . ' of ' . $email . "\n";
    exit(0);
}

if ($command === 'quote-address-saves') {
    $resource = $objectManager->get(ResourceConnection::class);
    $connection = $resource->getConnection('checkout');
    $table = $resource->getTableName('mageos_cca_quote_address_value', 'checkout');
    $connection->query('SET SESSION information_schema_stats_expiry = 0');
    // inserts (also "insert on duplicate") use up auto increment numbers: the counter moves only when the table was asked to write
    $counter = static fn (): int => (int) $connection->fetchOne(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    );
    $quote = $objectManager->create(\Magento\Quote\Model\Quote::class);
    $quote->setStoreId(1)->setIsActive(0)->setCustomerEmail('snap8-e2e-' . time() . '@example.com');
    $quote->save();
    $out = [];
    try {
        $address = $quote->getBillingAddress();
        $address->addData(['firstname' => 'Snap', 'lastname' => 'Eight', 'street' => '1 Test Street', 'city' => 'Austin', 'region_id' => 57,
            'postcode' => '78701', 'country_id' => 'US', 'telephone' => '5125550100']);
        $save = static function (string $value) use ($address, $counter): int {
            $before = $counter();
            $address->setCustomAttribute('e2e_a_text', $value);
            $address->save();
            return $counter() - $before;
        };
        $out['first_save'] = $save('one');
        $out['identical_save'] = $save('one');
        $out['changed_save'] = $save('two');
        $out['identical_again'] = $save('two');
        $out['rows'] = $connection->fetchPairs('SELECT a.attribute_code, v.value FROM ' . $table . ' v JOIN ' . $resource->getTableName('eav_attribute')
            . ' a ON a.attribute_id = v.attribute_id WHERE v.quote_address_id = ' . (int) $address->getId());
    } finally {
        $quote->delete();
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

if ($command === 'set-website') {
    $code = (string) ($argv[2] ?? '');
    $websiteId = (int) ($argv[3] ?? 0);
    $definition = null;
    foreach ($fixtures['attributes'] as $candidate) {
        if ($candidate['code'] === $code) {
            $definition = $candidate;
        }
    }
    $attribute = $definition === null ? null : $findAttribute($definition['entity'], $code);
    if ($attribute === null || $websiteId <= 0) {
        fwrite(STDERR, "Usage: set-website <fixture attribute code (seeded)> <website id> [enabled=..] [required=..] [default=..]\n");
        exit(2);
    }
    $storage = $objectManager->get(\MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeWebsiteStorage::class);
    $row = $storage->load((int) $attribute->getId())[$websiteId] ?? ['is_visible' => null, 'is_required' => null, 'default_value' => null];
    foreach (array_slice($argv, 4) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $value = $value === '-' ? null : $value;
        match ($key) {
            'enabled' => $row['is_visible'] = $value === null ? null : (int) $value,
            'required' => $row['is_required'] = $value === null ? null : (int) $value,
            'default' => $row['default_value'] = $value,
            default => throw new RuntimeException("Unknown setting $key"),
        };
    }
    $storage->save((int) $attribute->getId(), [$websiteId => $row]);
    $eavConfig->clear();
    $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('eav');
    $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('full_page');
    echo "set      $code on website $websiteId: " . json_encode($row) . "\n";
    exit(0);
}

if ($command === 'create-customer') {
    $email = (string) ($argv[2] ?? '');
    $websiteId = (int) ($argv[3] ?? 1);
    $password = (string) getenv($fixtures['customers'][0]['password_env']);
    if ($email === '' || $password === '') {
        fwrite(STDERR, "Usage: create-customer <email> <website id> (and E2E_CUSTOMER_PASSWORD in the environment)\n");
        exit(2);
    }
    if ($findCustomer($email) !== null) {
        echo "exists   customer $email\n";
        exit(0);
    }
    $storeId = (int) $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getWebsite($websiteId)->getDefaultStore()->getId();
    $customer = $objectManager->get(CustomerInterfaceFactory::class)->create();
    $customer->setEmail($email)->setFirstname('Web')->setLastname('Customer')->setGroupId(1)->setWebsiteId($websiteId)->setStoreId($storeId);
    // the required fixture attributes need a value, like in the account form
    foreach ($fixtures['attributes'] as $definition) {
        if ($definition['entity'] === 'customer' && !empty($definition['required'])) {
            if (in_array($definition['input'], ['select', 'multiselect'], true)) {
                $options = $findAttribute('customer', $definition['code'])->getSource()->getAllOptions(false);
                $customer->setCustomAttribute($definition['code'], (string) $options[0]['value']);
            } else {
                $customer->setCustomAttribute($definition['code'], 'account value');
            }
        }
    }
    $created = $objectManager->get(AccountManagementInterface::class)->createAccount($customer, $password);
    $objectManager->get(\Magento\Framework\Indexer\IndexerRegistry::class)->get('customer_grid')->reindexRow((int) $created->getId());
    echo "created  customer $email on website $websiteId\n";
    exit(0);
}

if ($command === 'bulk-attributes') {
    $count = max(1, (int) ($argv[2] ?? 50));
    $forms = ['customer_address_edit', 'customer_register_address', 'mageos_hyva_checkout', 'mageos_address_text'];
    for ($i = 1; $i <= $count; $i++) {
        $definition = ['entity' => 'address', 'code' => sprintf('e2e_bulk_%02d', $i), 'label' => 'Bulk ' . $i, 'forms' => $forms, 'input' => 'text'];
        if ($i % 3 === 0) {
            $definition['input'] = 'select';
            $definition['options'] = ['One', 'Two', 'Three'];
        }
        $seedAttribute($definition);
    }
    $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('eav');
    echo "created  $count bulk address attributes\n";
    exit(0);
}

if ($command === 'bulk-addresses') {
    $email = (string) ($argv[2] ?? '');
    $count = max(1, (int) ($argv[3] ?? 5));
    $customer = $findCustomer($email);
    if ($customer === null) {
        fwrite(STDERR, "Customer $email not found (seed first).\n");
        exit(1);
    }
    $objectManager->get(Registry::class)->register('isSecureArea', true);
    $addressRepository = $objectManager->get(\Magento\Customer\Api\AddressRepositoryInterface::class);
    foreach ($objectManager->get(\Magento\Customer\Model\ResourceModel\Address\CollectionFactory::class)->create()->setCustomerFilter($customer) as $old) {
        $addressRepository->deleteById((int) $old->getId());
    }
    $eavConfig->clear();
    $bulk = [];
    foreach ($objectManager->get(\Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection::class)->setEntityTypeFilter($eavConfig->getEntityType('customer_address')->getId()) as $attribute) {
        if (str_starts_with((string) $attribute->getAttributeCode(), 'e2e_bulk_')) {
            $bulk[] = $findAttribute('address', (string) $attribute->getAttributeCode());
        }
    }
    for ($n = 1; $n <= $count; $n++) {
        $address = $objectManager->create(\Magento\Customer\Model\Address::class);
        $address->setCustomerId((int) $customer->getId())->addData([
            'firstname' => 'Bulk', 'lastname' => 'Address ' . $n, 'street' => [$n . ' Bulk Street'], 'city' => 'Austin', 'region_id' => 57,
            'postcode' => '78701', 'country_id' => 'US', 'telephone' => '5125550100', 'e2e_a_req' => 'required',
            'is_default_billing' => $n === 1 ? 1 : 0, 'is_default_shipping' => $n === 1 ? 1 : 0,
        ]);
        foreach ($bulk as $attribute) {
            if ($attribute->getFrontendInput() === 'select') {
                $address->setData($attribute->getAttributeCode(), (string) $attribute->getSource()->getOptionId('Two'));
            } else {
                $address->setData($attribute->getAttributeCode(), 'value ' . $n);
            }
        }
        $address->save();
    }
    echo "set      $count addresses with " . count($bulk) . " bulk values each\n";
    exit(0);
}

if ($command === 'bulk-clean') {
    $objectManager->get(Registry::class)->register('isSecureArea', true);
    $email = (string) ($argv[2] ?? $fixtures['customers'][0]['email']);
    $customer = $findCustomer($email);
    if ($customer !== null) {
        $addressRepository = $objectManager->get(\Magento\Customer\Api\AddressRepositoryInterface::class);
        foreach ($objectManager->get(\Magento\Customer\Model\ResourceModel\Address\CollectionFactory::class)->create()->setCustomerFilter($customer) as $old) {
            $addressRepository->deleteById((int) $old->getId());
        }
    }
    $eavConfig->clear();
    $deleted = 0;
    foreach ($objectManager->get(\Magento\Eav\Model\ResourceModel\Entity\Attribute\Collection::class)->setEntityTypeFilter($eavConfig->getEntityType('customer_address')->getId()) as $attribute) {
        if (str_starts_with((string) $attribute->getAttributeCode(), 'e2e_bulk_')) {
            $attribute->delete();
            $deleted++;
        }
    }
    $eavConfig->clear();
    $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('eav');
    echo "removed  $deleted bulk attributes\n";
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
    // Deleting an order leaves its stock reservations behind: every test order would take its quantity from the salable stock for good and
    // the product would sell out after a few hundred orders. The reservations of orders that no longer exist go too.
    $reservations = $connection->query(
        'DELETE r FROM ' . $resource->getTableName('inventory_reservation') . ' r
         LEFT JOIN ' . $resource->getTableName('sales_order') . ' o ON o.increment_id = JSON_UNQUOTE(JSON_EXTRACT(r.metadata, \'$.object_increment_id\'))
         WHERE JSON_UNQUOTE(JSON_EXTRACT(r.metadata, \'$.object_type\')) = \'order\' AND o.entity_id IS NULL'
    )->rowCount();
    echo "removed  $reservations stock reservations of deleted orders\n";
    // customers a test created (admin order for a new customer, registration): the fixture customer is recreated by seed
    foreach ($connection->fetchCol('SELECT email FROM ' . $resource->getTableName('customer_entity') . ' WHERE email LIKE ?', [$pattern]) as $leftoverEmail) {
        $leftoverCustomer = $findCustomer($leftoverEmail);
        if ($leftoverCustomer !== null) {
            $customerRepository->delete($leftoverCustomer);
            echo 'removed  customer ' . $leftoverEmail . "\n";
        }
    }
    foreach ($connection->fetchCol('SELECT username FROM ' . $resource->getTableName('admin_user') . " WHERE username LIKE 'e2e\\_acl%'") as $leftover) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' delete-admin ' . escapeshellarg($leftover));
    }
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
foreach ($fixtures['products'] ?? [] as $definition) {
    try {
        $objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->deleteById($definition['sku']);
        echo 'removed  product ' . $definition['sku'] . "\n";
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        // already gone
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
