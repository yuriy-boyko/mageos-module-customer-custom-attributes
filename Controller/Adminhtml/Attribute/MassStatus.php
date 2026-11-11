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
 * Grid mass action: enables or disables the selected attributes (request parameter "status": 1 or 0). System attributes and
 * attributes of the other entity are skipped.
 */
class MassStatus extends Attribute implements HttpPostActionInterface
{
    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $params = [self::ENTITY_PARAM => $this->entityContext->getKey()];
        $status = (int) $this->getRequest()->getParam('status') ? 1 : 0;

        $changed = 0;
        $skipped = 0;
        foreach ($this->getSelectedIds() as $id) {
            $attribute = $this->createAttribute()->load($id);
            if (!$this->isManageable($attribute)) {
                $skipped++;
                continue;
            }
            try {
                if ((int) $attribute->getIsVisible() !== $status) {
                    $attribute->setData('is_visible', $status)->save();
                }
                $changed++;
            } catch (\Exception $e) {
                $skipped++;
                $this->messageManager->addExceptionMessage($e, __('We can\'t update the attribute "%1".', $attribute->getAttributeCode()));
            }
        }

        $this->addResultMessages(
            $changed,
            $skipped,
            $status ? __('A total of %1 attribute(s) have been enabled.', $changed) : __('A total of %1 attribute(s) have been disabled.', $changed)
        );
        return $redirect->setPath('*/*/', $params);
    }
}
