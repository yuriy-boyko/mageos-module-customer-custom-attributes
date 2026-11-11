<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Edit\Tab;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute as AttributeAction;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;

/**
 * Input validation fields of the "Properties" tab: validation type and text length for text types, date range for
 * dates. The input type script shows only the rows that apply to the selected type.
 */
class Advanced extends Generic
{
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly ValidateRulesBuilder $rulesBuilder,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    protected function _prepareForm()
    {
        $attribute = $this->_coreRegistry->registry(AttributeAction::REGISTRY_KEY);
        $rules = (array) $attribute->getValidateRules();

        $form = $this->_formFactory->create(['data' => ['id' => 'edit_form', 'action' => $this->getData('action'), 'method' => 'post']]);
        $fieldset = $form->addFieldset('validation_fieldset', ['legend' => __('Input Validation')]);

        $validationOptions = [['value' => '', 'label' => __('None')]];
        $labels = [
            'alphanumeric' => __('Letters (a-z, A-Z) or Numbers (0-9)'),
            'numeric' => __('Numbers (0-9)'),
            'alpha' => __('Letters (a-z, A-Z)'),
            'url' => __('URL'),
            'email' => __('Email'),
            'length' => __('Length Only'),
        ];
        foreach (array_keys(ValidateRulesBuilder::INPUT_VALIDATIONS) as $rule) {
            $validationOptions[] = ['value' => $rule, 'label' => $labels[$rule]];
        }

        $fieldset->addField('input_validation', 'select', [
            'name' => 'input_validation',
            'label' => __('Input Validation'),
            'title' => __('Input Validation'),
            'values' => $validationOptions,
            'value' => $attribute->getData('input_validation') ?? ($rules['input_validation'] ?? ''),
        ]);
        $fieldset->addField('min_text_length', 'text', [
            'name' => 'min_text_length',
            'label' => __('Minimum Text Length'),
            'title' => __('Minimum Text Length'),
            'class' => 'validate-digits',
            'value' => $attribute->getData('min_text_length') ?? ($rules['min_text_length'] ?? ''),
        ]);
        $fieldset->addField('max_text_length', 'text', [
            'name' => 'max_text_length',
            'label' => __('Maximum Text Length'),
            'title' => __('Maximum Text Length'),
            'class' => 'validate-digits',
            'value' => $attribute->getData('max_text_length') ?? ($rules['max_text_length'] ?? ''),
        ]);
        $fieldset->addField('date_range_min', 'text', [
            'name' => 'date_range_min',
            'label' => __('Minimum Date'),
            'title' => __('Minimum Date'),
            'note' => __('Format YYYY-MM-DD. Leave empty for no limit.'),
            'value' => $attribute->getData('date_range_min') ?? $this->rulesBuilder->formatDate($rules['date_range_min'] ?? null),
        ]);
        $fieldset->addField('date_range_max', 'text', [
            'name' => 'date_range_max',
            'label' => __('Maximum Date'),
            'title' => __('Maximum Date'),
            'note' => __('Format YYYY-MM-DD. Leave empty for no limit.'),
            'value' => $attribute->getData('date_range_max') ?? $this->rulesBuilder->formatDate($rules['date_range_max'] ?? null),
        ]);

        $displayFieldset = $form->addFieldset('display_fieldset', ['legend' => __('Storefront Display')]);
        $displayFieldset->addField('display_as', 'select', [
            'name' => 'display_as',
            'label' => __('Display As'),
            'title' => __('Display As'),
            'values' => [
                ['value' => '', 'label' => __('Default (dropdown or list)')],
                ['value' => ValidateRulesBuilder::DISPLAY_RADIO, 'label' => __('Radio buttons')],
                ['value' => ValidateRulesBuilder::DISPLAY_CHECKBOX, 'label' => __('Checkboxes')],
            ],
            'value' => $attribute->getData('display_as') ?? ($rules[ValidateRulesBuilder::DISPLAY_KEY] ?? ''),
            'note' => __('Dropdown and Yes/No attributes can be shown as radio buttons, multiple select attributes as checkboxes. The saved values do not change.'),
        ]);

        if ($attribute->getId() && !$attribute->getIsUserDefined()) {
            $fieldset->setDisabled(true);
            $displayFieldset->setDisabled(true);
        }

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
