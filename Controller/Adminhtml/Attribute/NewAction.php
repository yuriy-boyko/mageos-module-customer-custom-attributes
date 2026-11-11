<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Empty attribute form. Values of a failed save are kept in the session and shown again.
 */
class NewAction extends Attribute implements HttpGetActionInterface
{
    public function execute()
    {
        $attribute = $this->createAttribute();
        $data = $this->_getSession()->getAttributeData(true);
        if (is_array($data)) {
            $attribute->addData($data);
        }
        $this->coreRegistry->register(self::REGISTRY_KEY, $attribute);

        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        return $this->preparePage($page, (string) __('New %1', $this->entityContext->getLabel()));
    }
}
