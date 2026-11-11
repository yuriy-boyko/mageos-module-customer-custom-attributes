<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute as AttributeAction;
use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Registry;

/**
 * Container of the attribute edit page: header, buttons and the tabbed form.
 */
class Edit extends Container
{
    protected $_template = 'MageOS_CustomerCustomAttributes::attribute/edit/form.phtml';

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly EntityContext $entityContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct()
    {
        $this->_objectId = 'attribute_id';
        $this->_blockGroup = 'MageOS_CustomerCustomAttributes';
        $this->_controller = 'adminhtml_attribute';
        parent::_construct();

        $this->buttonList->update('save', 'label', __('Save Attribute'));
        $this->buttonList->update('save', 'class', 'save primary');
        $this->buttonList->update(
            'save',
            'data_attribute',
            ['mage-init' => ['button' => ['event' => 'save', 'target' => '#edit_form']]]
        );
        $this->addButton('save_and_edit_button', [
            'label' => __('Save and Continue Edit'),
            'class' => 'save',
            'data_attribute' => ['mage-init' => ['button' => ['event' => 'saveAndContinueEdit', 'target' => '#edit_form']]],
        ]);

        $attribute = $this->registry->registry(AttributeAction::REGISTRY_KEY);
        if (!$attribute || !$attribute->getId()) {
            $this->buttonList->remove('delete');
        } else {
            $this->buttonList->update('delete', 'label', __('Delete Attribute'));
        }
    }

    public function getHeaderText()
    {
        $attribute = $this->registry->registry(AttributeAction::REGISTRY_KEY);
        if ($attribute && $attribute->getId()) {
            $label = $attribute->getFrontendLabel();
            $label = is_array($label) ? (string) reset($label) : (string) $label;
            return __('Edit %1 "%2"', $this->entityContext->getLabel(), $this->escapeHtml($label));
        }
        return __('New %1', $this->entityContext->getLabel());
    }

    /**
     * @inheritDoc
     */
    public function getBackUrl()
    {
        return $this->getUrl('*/*/', [EntityContext::REQUEST_PARAM => $this->entityContext->getKey()]);
    }

    /**
     * @inheritDoc
     */
    public function getDeleteUrl()
    {
        return $this->getUrl('*/*/delete', [
            'attribute_id' => $this->getRequest()->getParam('attribute_id'),
            EntityContext::REQUEST_PARAM => $this->entityContext->getKey(),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getSaveUrl()
    {
        return $this->getUrl('*/*/save', [EntityContext::REQUEST_PARAM => $this->entityContext->getKey()]);
    }

    public function getValidationUrl(): string
    {
        return $this->getUrl('*/*/validate', [EntityContext::REQUEST_PARAM => $this->entityContext->getKey()]);
    }

    public function getEntityKey(): string
    {
        return $this->entityContext->getKey();
    }
}
