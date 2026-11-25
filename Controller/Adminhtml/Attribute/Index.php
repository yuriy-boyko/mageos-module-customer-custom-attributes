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
use Magento\Backend\App\Action\Context;
use Magento\Customer\Model\AttributeFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Registry;
use Magento\Store\Model\WebsiteFactory;

/**
 * Grid of the user-defined attributes of one area. Warns about attributes with a reserved code: the admin form refuses such a code, but
 * a data patch can create it, and its value can then be mixed up with a field of the customer, the cart or the order (note, for example).
 */
class Index extends Attribute implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        EntityContext $entityContext,
        AttributeFactory $attributeFactory,
        Registry $coreRegistry,
        WebsiteFactory $websiteFactory,
        private readonly ReservedCodeProvider $reservedCodes
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

        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        return $this->preparePage($page, (string) $this->entityContext->getPluralLabel());
    }
}
