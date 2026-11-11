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
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use MageOS\CustomerCustomAttributes\Model\Dependency\RelationFormWarnings;
use Magento\Framework\Registry;

class Edit extends Relation implements HttpGetActionInterface
{
    public const REGISTRY_KEY = 'mageos_cca_relation';

    public function __construct(
        Context $context,
        private readonly RelationStorage $storage,
        private readonly Registry $registry,
        private readonly RelationFormWarnings $warnings
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('relation_id');
        $relation = $id ? $this->storage->load($id) : null;
        if ($id && $relation === null) {
            $this->messageManager->addErrorMessage(__('This relation no longer exists.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        // After a failed save the posted values are shown again.
        $posted = $this->_getSession()->getData(self::FORM_SESSION_KEY, true);
        if (is_array($posted)) {
            $relation = $posted + ($relation ?? []);
        }
        // Rules that cannot work because the parent is not in a form where the dependent is shown (also when the forms changed later).
        if ($relation !== null && empty($posted)) {
            foreach ($this->warnings->forRules((string) ($relation['entity_type'] ?? ''), (array) ($relation['rules'] ?? [])) as $warning) {
                $this->messageManager->addWarningMessage($warning);
            }
        }
        $this->registry->register(self::REGISTRY_KEY, $relation ?? ['relation_id' => 0, 'name' => '', 'entity_type' => '', 'rules' => []]);

        $title = $id ? (string) __('Edit Relation') : (string) __('New Relation');
        return $this->preparePage($this->resultFactory->create(ResultFactory::TYPE_PAGE), $title);
    }
}
