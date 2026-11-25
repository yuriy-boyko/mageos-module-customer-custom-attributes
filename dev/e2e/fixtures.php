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
 *   php dev/e2e/fixtures.php create-attributes <customer|address> [--forms=a,b] [--position=payment_above] [--label=text] [--input=select --options=A,B] [--source=Class] [--snapshot=0] [--admin-only] [--show-in-email[=order,invoice,shipment,creditmemo]] [--save-to-account] <code>...   create plain optional text attributes (codes start with e2e_), label "ADM <code>", in these forms (default: the account / address book form); remove them with delete-created
 *   php dev/e2e/fixtures.php set-admin <username> <resource,...>   create or change a test admin (username starts with e2e_acl) whose role allows only these ACL resources (E2E_CUSTOMER_PASSWORD is its password); delete-admin <username> removes it
 *   php dev/e2e/fixtures.php secret-key <route/controller/action> <form key>   the admin URL secret key of an action for a session (what Magento puts into its links), to open a screen no link leads to
 *   php dev/e2e/fixtures.php delete-created <code>                delete an attribute a test created through the admin (its code starts with e2e_, or it has the label "ADM reserved column" of the ADM11 reserved-code test; any entity), no error when absent
 *   php dev/e2e/fixtures.php set-attribute <code> <required=0|1> <forms=a,b,...> <save_to_account=0|1>  change whether a fixture attribute is required and the forms it is in
 *   php dev/e2e/fixtures.php set-customer <email> <code=value>...  set custom attribute values on a customer (select: option label, several: a|b)
 *   php dev/e2e/fixtures.php set-address <email> [--add] <code=value>...   give a customer one saved address (`--add` adds a second one, not a default, and keeps the first) (default billing and shipping) with these custom address values (select: option label, several: a|b, date: YYYY-MM-DD); `--delete` instead of the pairs removes all saved addresses
 *   php dev/e2e/fixtures.php quote-address-saves   save a throw-away quote address four times (new values, identical, changed, identical) and print how many rows the value table was asked to write each time (JSON)
 *   php dev/e2e/fixtures.php set-website <code> <website_id> [enabled=0|1|-] [required=0|1|-] [default=text|-]   the per-website override of a fixture attribute (`-` = use the default of the attribute)
 *   php dev/e2e/fixtures.php create-customer <email> <website_id>   a customer on that website (E2E_CUSTOMER_PASSWORD is the password); purge deletes it when the e-mail contains e2e
 *   php dev/e2e/fixtures.php bulk-attributes <n>   create n address attributes e2e_bulk_01... (every third a dropdown with 3 options) in all storefront address forms (ROB3); bulk-addresses <email> <n> gives a customer n saved addresses with values for them; bulk-clean deletes them and the addresses
 *   php dev/e2e/fixtures.php add-form <code> <form>   assign an attribute a test created (code starts with e2e_) to a form this module does not offer, like another module would (ADM18)
 *   php dev/e2e/fixtures.php drop-quotes <email>   delete the quotes of a customer or e-mail (a customer without an active cart)
 *   php dev/e2e/fixtures.php create-raw-attribute <customer|address> <code>   a user defined text attribute made like a data patch does (no form processor, so no reserved code check), label "ADM reserved column"; delete-created removes it (SEC2)
 *   php dev/e2e/fixtures.php quote-snapshot <quote id> [area]   run the order snapshot code (QuoteSubmitBefore) on a stored quote without placing an order, in the area given (default adminhtml, e.g. webapi_rest for a cart API call): the customer values it would put on the order (JSON)
 *   php dev/e2e/fixtures.php set-email-section <0|1|default>   the setting "Add the Section Automatically" (Sales Emails) for all scopes: off, on, or the module's default (the row is removed)
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
if (!in_array($command, ['seed', 'teardown', 'status', 'purge', 'delete-option', 'delete-attribute', 'set-customer', 'drop-quotes', 'set-attribute', 'delete-created', 'create-attributes', 'set-admin', 'delete-admin', 'secret-key', 'set-address', 'quote-address-saves', 'set-website', 'create-customer', 'bulk-attributes', 'bulk-addresses', 'bulk-clean', 'ensure-stock', 'set-visible-all', 'set-customer-group', 'rename-option', 'set-stock', 'add-form', 'create-raw-attribute', 'quote-snapshot', 'set-email-section'], true)) {
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
    $label = null;
    $input = 'text';
    $options = [];
    $source = '';
    $snapshot = null;
    $adminOnly = false;
    $showInEmail = false;
    $saveToAccount = false;
    $codes = [];
    foreach (array_slice($argv, 3) as $argument) {
        if (str_starts_with($argument, '--forms=')) {
            $forms = array_values(array_filter(explode(',', substr($argument, 8))));
        } elseif (str_starts_with($argument, '--position=')) {
            $position = substr($argument, 11);
        } elseif (str_starts_with($argument, '--label=')) {
            $label = substr($argument, 8);
        } elseif (str_starts_with($argument, '--input=')) {
            $input = substr($argument, 8);
        } elseif (str_starts_with($argument, '--options=')) {
            $options = array_values(array_filter(explode(',', substr($argument, 10))));
        } elseif ($argument === '--show-in-email' || str_starts_with($argument, '--show-in-email=')) {
            $showInEmail = $argument === '--show-in-email' ? ['order', 'invoice', 'shipment', 'creditmemo'] : explode(',', substr($argument, 16)); // "Show in Emails"
        } elseif ($argument === '--save-to-account') {
            $saveToAccount = true; // "Also Save to Customer Account" (needs --position and the checkout form)
        } elseif ($argument === '--admin-only') {
            $adminOnly = true; // "Customer Cannot Change This Value"
        } elseif (str_starts_with($argument, '--snapshot=')) {
            $snapshot = substr($argument, 11); // 0: the attribute is not saved on orders ("Save on Orders" off)
        } elseif (str_starts_with($argument, '--source=')) {
            $source = substr($argument, 9); // a source model of its own, like a developer's data patch gives: the options come from its class
        } else {
            $codes[] = $argument;
        }
    }
    foreach ($codes as $code) {
        if (!str_starts_with($code, 'e2e_')) {
            fwrite(STDERR, "Only codes starting with e2e_ are created: $code\n");
            exit(2);
        }
        // the admin form refuses a dropdown without options, so a source model is put in place after the save, and the option rows go
        $seedAttribute(['entity' => $entity, 'code' => $code, 'label' => $label ?? 'ADM ' . $code, 'input' => $input, 'forms' => $forms]
            + ($options !== [] || $source !== '' ? ['options' => $options !== [] ? $options : ['placeholder']] : [])
            + ($position !== null ? ['checkout' => ['position' => $position, 'sort' => 5, 'prefill' => false]] : [])
            + (($snapshot !== null || $adminOnly || $showInEmail || $saveToAccount) ? ['rules' => ($snapshot !== null ? ['save_on_order' => $snapshot] : [])
                + ($adminOnly ? ['admin_only' => '1'] : []) + ($showInEmail ? ['show_in_email' => $showInEmail] : []) + ($saveToAccount ? ['checkout_save_to_account' => '1'] : [])] : []));
        if ($source !== '') {
            $attribute = $findAttribute($entity, $code);
            $attribute->setSourceModel($source)->save();
            $objectManager->get(ResourceConnection::class)->getConnection()->delete(
                $objectManager->get(ResourceConnection::class)->getTableName('eav_attribute_option'),
                ['attribute_id = ?' => (int) $attribute->getId()]
            );
            $eavConfig->clear();
        }
        echo "created  $code\n";
    }
    exit(0);
}

if ($command === 'create-raw-attribute') {
    $entity = (string) ($argv[2] ?? '');
    $code = (string) ($argv[3] ?? '');
    if (!isset($entityTypeCodes[$entity]) || preg_match('/^[a-z][a-z0-9_]{0,50}$/', $code) !== 1) {
        fwrite(STDERR, "Usage: create-raw-attribute <customer|address> <code>\n");
        exit(2);
    }
    if ($findAttribute($entity, $code) !== null) {
        fwrite(STDERR, "$code already exists.\n");
        exit(1);
    }
    $type = $eavConfig->getEntityType($entityTypeCodes[$entity]);
    $setId = (int) $type->getDefaultAttributeSetId();
    $attribute = $objectManager->create(Attribute::class);
    $attribute->setData([
        'attribute_code' => $code,
        'entity_type_id' => $type->getId(),
        'frontend_input' => 'text',
        'backend_type' => 'varchar',
        'frontend_label' => 'ADM reserved column',
        'is_user_defined' => 1,
        'is_system' => 0,
        'is_required' => 0,
        'is_visible' => 1,
        'sort_order' => 100,
        'attribute_set_id' => $setId,
        'attribute_group_id' => $objectManager->create(\Magento\Eav\Model\Entity\Attribute\Set::class)->getDefaultGroupId($setId),
        'used_in_forms' => [$entity === 'customer' ? 'adminhtml_customer' : 'adminhtml_customer_address'],
    ])->save();
    $eavConfig->clear();
    echo "created  $code (raw)\n";
    exit(0);
}

if ($command === 'quote-snapshot') {
    $quote = $objectManager->create(\Magento\Quote\Model\Quote::class)->load((int) ($argv[2] ?? 0));
    if (!$quote->getId()) {
        fwrite(STDERR, "Quote not found.\n");
        exit(1);
    }
    $order = new \Magento\Framework\DataObject(['store_id' => $quote->getStoreId()]);
    $snapshot = static fn () => $objectManager->get(\MageOS\CustomerCustomAttributes\Observer\QuoteSubmitBefore::class)->execute(
        new \Magento\Framework\Event\Observer(['event' => new \Magento\Framework\Event(['order' => $order, 'quote' => $quote])])
    );
    $area = (string) ($argv[3] ?? '');
    if ($area !== '' && $area !== 'adminhtml') {
        $objectManager->get(State::class)->emulateAreaCode($area, $snapshot);
    } else {
        $snapshot();
    }
    $values = [];
    foreach ((array) $order->getData(\MageOS\CustomerCustomAttributes\Observer\QuoteSubmitBefore::DATA_KEY) as $row) {
        $values[$row['attribute_code']] = $row['value'];
    }
    echo json_encode(['values' => $values, 'customer_note' => $quote->getCustomerNote()], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

if ($command === 'delete-created') {
    $code = (string) ($argv[2] ?? '');
    // ADM11 tries codes that must be refused (note, comment ...); when a broken build accepts one, the attribute it created carries the
    // test's label and is removed by that label, never by the code alone
    $reservedTest = !str_starts_with($code, 'e2e_');
    $deleted = 0;
    foreach (['customer', 'customer_address'] as $entity) {
        $attribute = $findAttribute($entity === 'customer' ? 'customer' : 'address', $code);
        if ($reservedTest && ($attribute === null || !$attribute->getIsUserDefined() || $attribute->getFrontendLabel() !== 'ADM reserved column')) {
            continue;
        }
        if ($attribute !== null && $attribute->getId()) {
            $attribute->delete();
            $deleted++;
        }
    }
    $eavConfig->clear();
    echo $deleted > 0 ? "deleted  $code\n" : "absent   $code\n";
    exit(0);
}

if ($command === 'delete-option' || $command === 'delete-attribute' || $command === 'rename-option') {
    $code = (string) ($argv[2] ?? '');
    $definition = null;
    foreach ($fixtures['attributes'] as $candidate) {
        if ($candidate['code'] === $code) {
            $definition = $candidate;
        }
    }
    if ($definition === null && preg_match('/^e2e_(a|c)_/', $code, $kind) === 1) {
        // an attribute a test created (create-attributes): the entity is in the code
        $definition = ['entity' => $kind[1] === 'a' ? 'address' : 'customer', 'code' => $code];
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
    $newLabel = $command === 'rename-option' ? (string) ($argv[4] ?? '') : null; // rename-option <code> <old label> <new label>
    $objectManager->get(HttpRequest::class)->setParam(EntityContext::REQUEST_PARAM, $definition['entity']);
    $values = [];
    $order = [];
    $delete = [];
    $found = false;
    foreach ($attribute->setStoreId(0)->getSource()->getAllOptions(false) as $index => $option) {
        $key = (string) $option['value'];
        $values[$key] = [0 => (string) $option['label']];
        $order[$key] = $index + 1;
        $matches = (string) $option['label'] === $label;
        if ($newLabel !== null) {
            $values[$key] = [0 => $matches ? $newLabel : (string) $option['label']];
            $delete[$key] = 0;
        } else {
            $delete[$key] = $matches ? 1 : 0;
        }
        $found = $found || $matches;
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
    echo ($newLabel !== null ? "renamed  option \"$label\" to \"$newLabel\" of $code" : "deleted  option \"$label\" of $code") . "\n";
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
    if ($definition === null && preg_match('/^e2e_(a|c)_/', $code, $kind) === 1) {
        // an attribute a test created (create-attributes)
        $definition = ['entity' => $kind[1] === 'a' ? 'address' : 'customer', 'code' => $code];
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
    if (isset($settings['save_to_account'])) {
        // "Also Save to Customer Account" of an attribute shown in the checkout: the key is removed when off
        $rules = (array) $attribute->getValidateRules();
        $key = \MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder::SAVE_TO_ACCOUNT_KEY;
        if ($settings['save_to_account'] === '1') {
            $rules[$key] = 1;
        } else {
            unset($rules[$key]);
        }
        $attribute->setData('validate_rules', $rules);
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
    $deleted = str_starts_with($email, '#')
        ? $connection->delete($resource->getTableName('quote'), ['entity_id = ?' => (int) substr($email, 1)])
        : $connection->delete($resource->getTableName('quote'), ['customer_email = ?' => $email]);
    $customer = $email !== '' && $email[0] !== '#' ? $findCustomer($email) : null;
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
        if (!isset($definitions[$code]) && preg_match('/^e2e_c_/', $code) === 1 && ($created = $findAttribute('customer', $code)) !== null) {
            // an attribute a test created (create-attributes): text unless it is a dropdown
            $definitions[$code] = ['code' => $code, 'entity' => 'customer', 'input' => (string) $created->getFrontendInput()];
        }
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
        if (!isset($definitions[$code]) && preg_match('/^e2e_a_/', $code) === 1 && $findAttribute('address', $code) !== null) {
            // an attribute a test created (create-attributes)
            $definitions[$code] = ['input' => (string) $findAttribute('address', $code)->getFrontendInput()];
        }
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

if ($command === 'set-email-section') {
    $value = (string) ($argv[2] ?? '');
    if (!in_array($value, ['0', '1', 'default'], true)) {
        fwrite(STDERR, "Usage: set-email-section <0|1|default>\n");
        exit(2);
    }
    $path = \MageOS\CustomerCustomAttributes\Observer\AddAdditionalInformationToEmails::CONFIG_PATH;
    $writer = $objectManager->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
    if ($value === 'default') {
        $writer->delete($path);
    } else {
        $writer->save($path, $value);
    }
    $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
    echo "ok\n";
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

if ($command === 'set-visible-all') {
    // REG1: switch every user defined customer and address attribute off (0) and back on (1). The ones that were on are written to a
    // file when switching off, and only those are switched on again, so the attributes that were off before stay off.
    $resource = $objectManager->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $file = sys_get_temp_dir() . '/mageos-cca-e2e-visible.json';
    $eavTable = $resource->getTableName('customer_eav_attribute');
    if (($argv[2] ?? '') === '0') {
        $ids = $connection->fetchCol(
            'SELECT c.attribute_id FROM ' . $eavTable . ' c JOIN ' . $resource->getTableName('eav_attribute') . ' a ON a.attribute_id = c.attribute_id
             JOIN ' . $resource->getTableName('eav_entity_type') . ' t ON t.entity_type_id = a.entity_type_id
             WHERE a.is_user_defined = 1 AND t.entity_type_code IN (\'customer\', \'customer_address\') AND c.is_visible = 1'
        );
        if (!is_file($file)) {
            file_put_contents($file, json_encode(array_map('intval', $ids)));
        }
        $count = $ids === [] ? 0 : $connection->update($eavTable, ['is_visible' => 0], ['attribute_id IN (?)' => $ids]);
        echo "switched off $count attributes\n";
    } else {
        $ids = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        $count = $ids === [] ? 0 : $connection->update($eavTable, ['is_visible' => 1], ['attribute_id IN (?)' => $ids]);
        @unlink($file);
        echo "switched on $count attributes\n";
    }
    $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('eav');
    $eavConfig->clear();
    exit(0);
}

if ($command === 'set-customer-group') {
    // CCO11: move a customer to another customer group (by group id), like the admin customer form does
    $email = (string) ($argv[2] ?? '');
    $groupId = (int) ($argv[3] ?? 0);
    $customer = $findCustomer($email);
    if ($customer === null || $groupId === 0) {
        fwrite(STDERR, "Usage: set-customer-group <email> <group id>\n");
        exit(2);
    }
    // Written directly: a repository save validates the saved addresses, and SAV5 moves the customer into a group whose required
    // attribute the saved address lacks on purpose.
    $resource = $objectManager->get(ResourceConnection::class);
    $resource->getConnection()->update(
        $resource->getTableName('customer_entity'),
        ['group_id' => $groupId],
        ['entity_id = ?' => (int) $customer->getId()]
    );
    echo "set      customer group of $email to $groupId\n";
    exit(0);
}

if ($command === 'add-form') {
    // ADM18: a form row written by another module (the attribute screens do not offer the form); removed with the attribute
    $code = (string) ($argv[2] ?? '');
    $form = (string) ($argv[3] ?? '');
    $resource = $objectManager->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $attributeId = (int) $connection->fetchOne(
        'SELECT a.attribute_id FROM ' . $resource->getTableName('eav_attribute') . ' a JOIN ' . $resource->getTableName('eav_entity_type')
        . " t ON t.entity_type_id = a.entity_type_id WHERE a.attribute_code = ? AND t.entity_type_code IN ('customer', 'customer_address')",
        [$code]
    );
    if (!str_starts_with($code, 'e2e_') || $attributeId === 0 || preg_match('/^[a-z0-9_]+$/', $form) !== 1) {
        fwrite(STDERR, "Usage: add-form <e2e_ code> <form code>\n");
        exit(2);
    }
    $connection->insertOnDuplicate($resource->getTableName('customer_form_attribute'), ['form_code' => $form, 'attribute_id' => $attributeId], ['form_code']);
    echo "assigned $code to $form\n";
    exit(0);
}

if ($command === 'set-stock') {
    // ORD5: make a product unsalable (quantity 0) in the middle of a checkout; `ensure-stock` puts the stock back
    $sku = (string) ($argv[2] ?? '');
    $qty = (float) ($argv[3] ?? 0);
    $resource = $objectManager->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $productId = (int) $connection->fetchOne('SELECT entity_id FROM ' . $resource->getTableName('catalog_product_entity') . ' WHERE sku = ?', [$sku]);
    if ($productId === 0) {
        fwrite(STDERR, "No product with the SKU $sku.\n");
        exit(2);
    }
    $inStock = $qty > 0 ? 1 : 0;
    $connection->update($resource->getTableName('inventory_source_item'), ['quantity' => $qty, 'status' => $inStock], ['sku = ?' => $sku]);
    $connection->update($resource->getTableName('cataloginventory_stock_item'), ['qty' => $qty, 'is_in_stock' => $inStock], ['product_id = ?' => $productId]);
    $objectManager->get(\Magento\CatalogInventory\Model\Indexer\Stock\Processor::class)->reindexRow($productId, true);
    // the stock status table decides whether the product can be added to a cart: write it too, and drop the cached pages
    $connection->update($resource->getTableName('cataloginventory_stock_status'), ['stock_status' => $inStock, 'qty' => $qty], ['product_id = ?' => $productId]);
    $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('full_page');
    echo "set      stock of $sku to $qty\n";
    exit(0);
}

if ($command === 'ensure-stock') {
    // Test orders take stock: before a run every test product must have enough salable quantity (source quantity plus the reservations,
    // which are negative). When it is low the source quantity is raised and the stock status index of the product is updated.
    $resource = $objectManager->get(ResourceConnection::class);
    $connection = $resource->getConnection();
    $minimum = 300.0;
    $skus = array_merge(['24-MB04', 'e2e-virtual-product'], array_slice($argv, 2));
    foreach (array_unique($skus) as $sku) {
        $items = $connection->fetchAll('SELECT source_code, quantity FROM ' . $resource->getTableName('inventory_source_item') . ' WHERE sku = ?', [$sku]);
        if ($items === []) {
            echo "skipped  stock of $sku: no source item\n";
            continue;
        }
        $reserved = (float) $connection->fetchOne('SELECT COALESCE(SUM(quantity), 0) FROM ' . $resource->getTableName('inventory_reservation') . ' WHERE sku = ?', [$sku]);
        $salable = array_sum(array_column($items, 'quantity')) + $reserved;
        $statusProductId = (int) $connection->fetchOne('SELECT entity_id FROM ' . $resource->getTableName('catalog_product_entity') . ' WHERE sku = ?', [$sku]);
        $status = $statusProductId === 0 ? 1 : (int) $connection->fetchOne('SELECT MIN(stock_status) FROM ' . $resource->getTableName('cataloginventory_stock_status') . ' WHERE product_id = ?', [$statusProductId]);
        if ($salable >= $minimum && $status === 1) {
            echo "ok       stock of $sku: salable $salable\n";
            continue;
        }
        $productId = (int) $connection->fetchOne('SELECT entity_id FROM ' . $resource->getTableName('catalog_product_entity') . ' WHERE sku = ?', [$sku]);
        if ($productId === 0) {
            echo "skipped  stock of $sku: no product\n";
            continue;
        }
        $connection->update($resource->getTableName('inventory_source_item'), ['quantity' => 1000 - $reserved, 'status' => 1], ['sku = ?' => $sku, 'source_code = ?' => $items[0]['source_code']]);
        $connection->update($resource->getTableName('cataloginventory_stock_item'), ['qty' => 1000 - $reserved, 'is_in_stock' => 1], ['product_id = ?' => $productId]);
        $objectManager->get(\Magento\CatalogInventory\Model\Indexer\Stock\Processor::class)->reindexRow($productId, true);
        $connection->update($resource->getTableName('cataloginventory_stock_status'), ['stock_status' => 1, 'qty' => 1000 - $reserved], ['product_id = ?' => $productId]);
        $objectManager->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('full_page');
        echo "raised   stock of $sku: salable was $salable, now 1000\n";
    }
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
