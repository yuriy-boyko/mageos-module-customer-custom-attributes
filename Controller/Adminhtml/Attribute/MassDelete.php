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
 * Grid mass action: deletes the selected attributes. System attributes and attributes of the other entity are skipped.
 * Orders keep their values (they are a snapshot without a link to the attribute).
 */
class MassDelete extends Attribute implements HttpPostActionInterface
{
    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $params = [self::ENTITY_PARAM => $this->entityContext->getKey()];

        $deleted = 0;
        $skipped = 0;
        foreach ($this->getSelectedIds() as $id) {
            $attribute = $this->createAttribute()->load($id);
            if (!$this->isManageable($attribute)) {
                $skipped++;
                continue;
            }
            try {
                $attribute->delete();
                $deleted++;
            } catch (\Exception $e) {
                $skipped++;
                $this->messageManager->addExceptionMessage($e, __('We can\'t delete the attribute "%1".', $attribute->getAttributeCode()));
            }
        }

        $this->addResultMessages($deleted, $skipped, __('A total of %1 attribute(s) have been deleted.', $deleted));
        return $redirect->setPath('*/*/', $params);
    }
}
