<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute;
use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use MageOS\CustomerCustomAttributes\Model\Attribute\ReservedCodeProvider;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeFormStorage;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\RequiredCustomerFormCheck;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Model\AttributeFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Registry;
use Magento\Store\Model\WebsiteFactory;

/**
 * Grid of the user-defined attributes of one area. Warns about attributes with a reserved code: the admin form refuses such a code, but
 * a data patch can create it, and its value can then be mixed up with a field of the customer, the cart or the order (note, for example).
 * Warns too about required customer attributes that customers cannot fill in (not in the registration or account edit form, or
 * "Customer Cannot Change This Value"): every registration or account save of a customer without a value fails. The edit page of such an
 * attribute says which form is missing (the per-website "required" setting is checked there).
 */
class Index extends Attribute implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        EntityContext $entityContext,
        AttributeFactory $attributeFactory,
        Registry $coreRegistry,
        WebsiteFactory $websiteFactory,
        private readonly ReservedCodeProvider $reservedCodes,
        private readonly AttributeFormStorage $formStorage,
        private readonly RequiredCustomerFormCheck $requiredCheck,
        private readonly AdminOnlyAccess $adminOnly
    ) {
        parent::__construct($context, $entityContext, $attributeFactory, $coreRegistry, $websiteFactory);
    }

    public function execute()
    {
        $collection = $this->entityContext->createCollection();
        $collection->addFieldToFilter('main_table.is_user_defined', 1);
        $codes = array_map('strval', $collection->getColumnValues('attribute_code'));
        $reserved = array_values(array_intersect($codes, $this->reservedCodes->get($this->entityContext->getKey())));
        if ($reserved !== []) {
            $this->messageManager->addWarningMessage(__(
                'The code of these attributes is reserved (it is also a field of the customer, the cart or the order): %1. Their value can be mixed up with that field. Create the attribute again with another code.',
                implode(', ', $reserved)
            ));
        }

        if ($this->entityContext->getKey() === EntityDefinitions::CUSTOMER) {
            $this->warnRequiredCustomerAttributes($collection->getItems());
        }

        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        return $this->preparePage($page, (string) $this->entityContext->getPluralLabel());
    }

    /**
     * @param \Magento\Framework\DataObject[] $attributes
     */
    private function warnRequiredCustomerAttributes(array $attributes): void
    {
        $required = array_filter($attributes, static fn ($attribute): bool => (bool) $attribute->getData('is_required'));
        if ($required === []) {
            return;
        }
        $forms = $this->formStorage->loadForAttributes(array_map(static fn ($attribute): int => (int) $attribute->getData('attribute_id'), $required));
        $codes = [];
        foreach ($required as $attribute) {
            $rules = $attribute->getData('validate_rules');
            $rules = is_string($rules) ? (array) json_decode($rules, true) : (array) $rules;
            $missing = $this->requiredCheck->missingForms(
                true,
                $forms[(int) $attribute->getData('attribute_id')] ?? [],
                $this->adminOnly->isAdminOnly($rules)
            );
            if ($missing !== []) {
                $codes[] = (string) $attribute->getData('attribute_code');
            }
        }
        if ($codes !== []) {
            $this->messageManager->addWarningMessage(__(
                'These attributes are required, but customers cannot fill them in (not in the registration or account edit form, or the customer cannot change them): %1. Magento checks every required attribute when a customer is saved, so registrations or account saves of customers without a value fail. Open an attribute to see which form is missing, or make it optional.',
                implode(', ', $codes)
            ));
        }
    }
}
