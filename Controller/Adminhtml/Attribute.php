<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml;

use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Customer\Model\Attribute as CustomerAttribute;
use Magento\Customer\Model\AttributeFactory;
use Magento\Framework\Registry;
use Magento\Store\Model\WebsiteFactory;

/**
 * Base of the attribute screens. One set of actions serves both areas; the area comes from the `entity` request
 * parameter (see EntityContext) and decides the ACL resource.
 */
abstract class Attribute extends Action
{
    public const REGISTRY_KEY = 'entity_attribute';
    public const ENTITY_PARAM = EntityContext::REQUEST_PARAM;
    public const ADMIN_RESOURCE = 'MageOS_CustomerCustomAttributes::customer_attributes';

    public function __construct(
        Context $context,
        protected readonly EntityContext $entityContext,
        protected readonly AttributeFactory $attributeFactory,
        protected readonly Registry $coreRegistry,
        protected readonly WebsiteFactory $websiteFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed($this->entityContext->getAclResource());
    }

    /**
     * A new, empty attribute of the current area. The (empty) website must be set: the EAV resource model reads its
     * id when saving.
     */
    protected function createAttribute(): CustomerAttribute
    {
        $attribute = $this->attributeFactory->create();
        $attribute->setWebsite($this->websiteFactory->create());
        $attribute->setEntityTypeId($this->entityContext->getEntityType()->getId());
        return $attribute;
    }

    /**
     * True when the attribute belongs to the current area and was created in the admin (system attributes are not
     * managed here).
     */
    protected function isManageable(CustomerAttribute $attribute): bool
    {
        return $attribute->getId()
            && (int) $attribute->getEntityTypeId() === (int) $this->entityContext->getEntityType()->getId()
            && (int) $attribute->getIsUserDefined() === 1;
    }

    /**
     * Attribute ids selected in the grid (request parameter "attribute_ids").
     *
     * @return int[]
     */
    protected function getSelectedIds(): array
    {
        $ids = $this->getRequest()->getParam('attribute_ids');
        return array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));
    }

    /**
     * Success message for the processed attributes and a notice for the skipped ones.
     */
    protected function addResultMessages(int $processed, int $skipped, \Magento\Framework\Phrase $success): void
    {
        if ($processed > 0) {
            $this->messageManager->addSuccessMessage($success);
        }
        if ($skipped > 0) {
            $this->messageManager->addNoticeMessage(__('%1 attribute(s) were skipped (not found, not editable or an error).', $skipped));
        }
        if ($processed === 0 && $skipped === 0) {
            $this->messageManager->addErrorMessage(__('Please select attributes.'));
        }
    }

    protected function preparePage(Page $page, string $title): Page
    {
        $page->setActiveMenu($this->entityContext->getMenuId());
        $page->getConfig()->getTitle()->prepend($this->entityContext->getPluralLabel());
        $page->getConfig()->getTitle()->prepend($title);
        $page->addBreadcrumb(__('Stores'), __('Stores'));
        $page->addBreadcrumb($this->entityContext->getPluralLabel(), $this->entityContext->getPluralLabel());
        return $page;
    }
}
