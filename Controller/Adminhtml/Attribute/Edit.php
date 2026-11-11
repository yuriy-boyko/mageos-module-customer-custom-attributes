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
 * Edit form of an existing user-defined attribute.
 */
class Edit extends Attribute implements HttpGetActionInterface
{
    public function execute()
    {
        $attribute = $this->createAttribute();
        $attribute->load((int) $this->getRequest()->getParam('attribute_id'));
        if (!$this->isManageable($attribute)) {
            $this->messageManager->addErrorMessage(__('This attribute no longer exists or cannot be edited.'));
            return $this->resultRedirect('*/*/', ['entity' => $this->entityContext->getKey()]);
        }

        $data = $this->_getSession()->getAttributeData(true);
        if (is_array($data) && (int) ($data['attribute_id'] ?? 0) === (int) $attribute->getId()) {
            $attribute->addData($data);
        }
        $this->coreRegistry->register(self::REGISTRY_KEY, $attribute);

        $label = $attribute->getFrontendLabel();
        $label = is_array($label) ? (string) reset($label) : (string) $label;
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        return $this->preparePage($page, $label !== '' ? $label : $attribute->getAttributeCode());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resultRedirect(string $path, array $params)
    {
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath($path, $params);
    }
}
