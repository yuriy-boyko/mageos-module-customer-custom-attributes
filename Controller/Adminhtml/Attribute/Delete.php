<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Deletes a user-defined attribute. Values already copied to orders are kept (orders hold their own snapshot).
 */
class Delete extends Attribute implements HttpPostActionInterface
{
    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $params = ['entity' => $this->entityContext->getKey()];

        $attribute = $this->createAttribute();
        $attribute->load((int) $this->getRequest()->getParam('attribute_id'));
        if (!$this->isManageable($attribute)) {
            $this->messageManager->addErrorMessage(__('This attribute no longer exists or cannot be deleted.'));
            return $redirect->setPath('*/*/', $params);
        }

        try {
            $attribute->delete();
            $this->messageManager->addSuccessMessage(__('You deleted the attribute.'));
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t delete the attribute right now.'));
            return $redirect->setPath('*/*/edit', $params + ['attribute_id' => $attribute->getId()]);
        }
        return $redirect->setPath('*/*/', $params);
    }
}
