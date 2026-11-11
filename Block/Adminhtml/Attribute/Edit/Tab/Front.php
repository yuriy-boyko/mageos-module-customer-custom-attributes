<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Edit\Tab;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute as AttributeAction;
use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeGroupStorage;
use MageOS\CustomerCustomAttributes\Service\CheckoutPositions;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Config\Model\Config\Source\YesnoFactory;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;

/**
 * "Storefront Properties" tab: status, sort order and the forms the attribute is shown in.
 */
class Front extends Generic
{
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly YesnoFactory $yesnoFactory,
        private readonly EntityContext $entityContext,
        private readonly AttributeGroupStorage $groupStorage,
        private readonly GroupCollectionFactory $groupCollectionFactory,
        private readonly ValidateRulesBuilder $rulesBuilder,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    protected function _prepareForm()
    {
        $attribute = $this->_coreRegistry->registry(AttributeAction::REGISTRY_KEY);
        $optionalForms = $this->entityContext->getOptionalForms();

        $form = $this->_formFactory->create(['data' => ['id' => 'edit_form', 'action' => $this->getData('action'), 'method' => 'post']]);
        $fieldset = $form->addFieldset('front_fieldset', ['legend' => __('Storefront Properties')]);

        $isVisible = $attribute->getData('is_visible');
        $fieldset->addField('is_visible', 'select', [
            'name' => 'is_visible',
            'label' => __('Enabled'),
            'title' => __('Enabled'),
            'values' => $this->yesnoFactory->create()->toOptionArray(),
            'value' => $isVisible === null ? 1 : (int) $isVisible,
        ]);
        $fieldset->addField('sort_order', 'text', [
            'name' => 'sort_order',
            'label' => __('Sort Order'),
            'title' => __('Sort Order'),
            'class' => 'validate-digits',
            'note' => __('Lower numbers are shown first.'),
            'value' => (int) $attribute->getData('sort_order'),
        ]);

        $selected = $attribute->getData('used_in_forms');
        if (!is_array($selected)) {
            $selected = $attribute->getId()
                ? (array) $attribute->getUsedInForms()
                : array_values(array_filter(
                    array_keys($optionalForms),
                    fn (string $code): bool => strpos($code, 'adminhtml_') !== 0
                        && !($code === EntityDefinitions::FORM_HYVA_CHECKOUT && $this->entityContext->getKey() === EntityDefinitions::CUSTOMER)
                ));
        }
        $values = [];
        foreach ($optionalForms as $code => $label) {
            $values[] = ['value' => $code, 'label' => $label];
        }
        $fieldset->addField('used_in_forms', 'multiselect', [
            'name' => 'used_in_forms[]',
            'label' => __('Show in Forms'),
            'title' => __('Show in Forms'),
            'values' => $values,
            'value' => array_values(array_intersect($selected, array_keys($optionalForms))),
            'note' => __('The attribute is always available in the admin forms. Hold Ctrl or Cmd to select several forms.'),
        ]);

        if ($this->entityContext->getKey() === EntityDefinitions::CUSTOMER) {
            $this->addCheckoutFields($fieldset, $attribute);
        }

        $groups = $this->getData('customer_groups_posted');
        if (!is_array($groups)) {
            $groups = $attribute->getId() ? $this->groupStorage->load((int) $attribute->getId()) : [];
        }
        $fieldset->addField('customer_groups', 'multiselect', [
            'name' => 'customer_groups[]',
            'label' => __('Customer Groups'),
            'title' => __('Customer Groups'),
            'values' => $this->groupCollectionFactory->create()->toOptionArray(),
            'value' => $groups,
            'note' => __('Show the attribute only to customers in the selected groups. Leave empty to show it to all groups. Hold Ctrl or Cmd to select several.'),
        ]);

        $this->setForm($form);
        return parent::_prepareForm();
    }

    /**
     * Position, sort order and prefill of a customer attribute in the checkout; the fields are shown by the page script
     * only while "Hyvä Checkout" is selected in "Show in Forms".
     */
    private function addCheckoutFields(\Magento\Framework\Data\Form\Element\Fieldset $fieldset, $attribute): void
    {
        $settings = $this->rulesBuilder->getCheckoutSettings((array) $attribute->getValidateRules());
        $positions = [];
        foreach (CheckoutPositions::labels() as $code => $label) {
            $positions[] = ['value' => $code, 'label' => __($label)];
        }
        $fieldset->addField('checkout_position', 'select', [
            'name' => 'checkout_position',
            'label' => __('Position at Checkout Step'),
            'title' => __('Position at Checkout Step'),
            'values' => $positions,
            'note' => __('Applies when "Hyvä Checkout" is selected in Show in Forms.'),
            'value' => $attribute->getData('checkout_position') ?? $settings['position'],
        ]);
        $fieldset->addField('checkout_sort', 'text', [
            'name' => 'checkout_sort',
            'label' => __('Checkout Sort Order'),
            'title' => __('Checkout Sort Order'),
            'class' => 'validate-digits',
            'note' => __('Lower numbers are shown first inside the position.'),
            'value' => $attribute->getData('checkout_sort') ?? $settings['sort'],
        ]);
        $fieldset->addField('checkout_prefill', 'select', [
            'name' => 'checkout_prefill',
            'label' => __('Prefill from Customer'),
            'title' => __('Prefill from Customer'),
            'values' => $this->yesnoFactory->create()->toOptionArray(),
            'value' => $attribute->getData('checkout_prefill') ?? (int) $settings['prefill'],
            'note' => __('Fill an empty field with the logged-in customer\'s saved value. Values typed in the checkout are kept on the order only; the customer account is not changed.'),
        ]);
    }
}
