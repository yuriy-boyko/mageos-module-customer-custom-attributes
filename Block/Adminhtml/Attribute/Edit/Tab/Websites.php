<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Edit\Tab;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute as AttributeAction;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeWebsiteStorage;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "Websites" tab: per website, the values that differ from the attribute's own: enabled, required and default value
 * (core's website scope of customer attributes). "Use Default" keeps the value of the Properties tab. Only for existing
 * attributes, because the overrides belong to a saved attribute.
 */
class Websites extends Generic
{
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly AttributeWebsiteStorage $storage,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    public function canShow(): bool
    {
        $attribute = $this->_coreRegistry->registry(AttributeAction::REGISTRY_KEY);
        return $attribute && $attribute->getId() && $attribute->getIsUserDefined() && count($this->storeManager->getWebsites()) > 0;
    }

    protected function _prepareForm()
    {
        $attribute = $this->_coreRegistry->registry(AttributeAction::REGISTRY_KEY);
        $form = $this->_formFactory->create(['data' => ['id' => 'edit_form', 'action' => $this->getData('action'), 'method' => 'post']]);
        if (!$this->canShow()) {
            $this->setForm($form);
            return parent::_prepareForm();
        }

        $input = (string) $attribute->getFrontendInput();
        $saved = $this->storage->load((int) $attribute->getId());
        $yesNo = fn (bool $global): array => [
            ['value' => '', 'label' => $global ? __('Use Default (Yes)') : __('Use Default (No)')],
            ['value' => '1', 'label' => __('Yes')],
            ['value' => '0', 'label' => __('No')],
        ];
        $globalVisible = $attribute->getData('is_visible') === null || (int) $attribute->getData('is_visible') === 1;
        $globalRequired = (int) $attribute->getData('is_required') === 1;

        foreach ($this->storeManager->getWebsites() as $website) {
            $id = (int) $website->getId();
            $row = $saved[$id] ?? [];
            $fieldset = $form->addFieldset('website_' . $id, ['legend' => $website->getName()]);
            $fieldset->addField('scope_visible_' . $id, 'select', [
                'name' => "scope[$id][is_visible]",
                'label' => __('Enabled'),
                'title' => __('Enabled'),
                'values' => $yesNo($globalVisible),
                'value' => $row['is_visible'] ?? '',
            ]);
            $fieldset->addField('scope_required_' . $id, 'select', [
                'name' => "scope[$id][is_required]",
                'label' => __('Values Required'),
                'title' => __('Values Required'),
                'values' => $yesNo($globalRequired),
                'value' => $row['is_required'] ?? '',
            ]);
            if ($input === 'boolean') {
                $fieldset->addField('scope_default_' . $id, 'select', [
                    'name' => "scope[$id][default_value]",
                    'label' => __('Default Value'),
                    'title' => __('Default Value'),
                    'values' => [
                        ['value' => '', 'label' => __('Use Default')],
                        ['value' => '1', 'label' => __('Yes')],
                        ['value' => '0', 'label' => __('No')],
                    ],
                    'value' => $row['default_value'] ?? '',
                ]);
            } elseif ($input === 'text' || $input === 'textarea') {
                $fieldset->addField('scope_default_' . $id, $input === 'textarea' ? 'textarea' : 'text', [
                    'name' => "scope[$id][default_value]",
                    'label' => __('Default Value'),
                    'title' => __('Default Value'),
                    'value' => $row['default_value'] ?? '',
                    'note' => __('Leave empty to use the default of the Properties tab.'),
                ]);
            }
        }

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
