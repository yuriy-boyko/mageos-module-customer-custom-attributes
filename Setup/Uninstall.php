<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Setup;

use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Runs with `bin/magento module:uninstall --remove-data MageOS_CustomerCustomAttributes`.
 *
 * Removes the module's own tables and its form assignments. The customer and customer address attributes themselves are
 * standard EAV attributes and stay (with their values in the core customer tables); delete them in the admin first when they
 * are not wanted any more.
 */
class Uninstall implements UninstallInterface
{
    private const TABLES = [
        'mageos_cca_relation_detail',
        'mageos_cca_relation',
        'mageos_cca_attribute_group',
        'mageos_cca_quote_value',
        'mageos_cca_quote_address_value',
        'mageos_cca_order_value',
        'mageos_cca_order_address_value',
    ];

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        $connection = $setup->getConnection();

        foreach (self::TABLES as $table) {
            $name = $setup->getTable($table);
            if ($connection->isTableExists($name)) {
                $connection->dropTable($name);
            }
        }

        $formTable = $setup->getTable('customer_form_attribute');
        if ($connection->isTableExists($formTable)) {
            $connection->delete($formTable, ['form_code IN (?)' => [
                EntityDefinitions::FORM_HYVA_CHECKOUT,
                EntityDefinitions::FORM_ADDRESS_TEXT,
            ]]);
        }

        $setup->endSetup();
    }
}
