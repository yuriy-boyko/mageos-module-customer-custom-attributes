<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml\Relation;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Relation;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\RelationStorage;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;

class Delete extends Relation implements HttpPostActionInterface
{
    public function __construct(Context $context, private readonly RelationStorage $storage)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('relation_id');
        if ($id && $this->storage->load($id) !== null) {
            $this->storage->delete($id);
            $this->messageManager->addSuccessMessage(__('You deleted the relation.'));
        } else {
            $this->messageManager->addErrorMessage(__('This relation no longer exists.'));
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
