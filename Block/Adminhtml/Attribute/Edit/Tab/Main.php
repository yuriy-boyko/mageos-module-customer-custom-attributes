<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Edit\Tab;

use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use MageOS\CustomerCustomAttributes\Service\InputTypeMap;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Model\Config\Source\YesnoFactory;
use Magento\Eav\Block\Adminhtml\Attribute\Edit\Main\AbstractMain;
use Magento\Eav\Block\Adminhtml\Attribute\PropertyLocker;
use Magento\Eav\Helper\Data as EavHelper;
use Magento\Eav\Model\Adminhtml\System\Config\Source\InputtypeFactory;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;

/**
 * "Properties" tab: label, code, input type, required flag and default value.
 */
class Main extends AbstractMain
{
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        EavHelper $eavData,
        YesnoFactory $yesnoFactory,
        InputtypeFactory $inputTypeFactory,
        PropertyLocker $propertyLocker,
        private readonly InputTypeMap $inputTypes,
        private readonly EntityContext $entityContext,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $formFactory,
            $eavData,
            $yesnoFactory,
            $inputTypeFactory,
            $propertyLocker,
            $data
        );
    }

    protected function _prepareForm()
    {
        parent::_prepareForm();

        $form = $this->getForm();
        $fieldset = $form->getElement('base_fieldset');
        foreach (['is_unique', 'frontend_class', 'default_value_date'] as $fieldId) {
            $fieldset->removeField($fieldId);
        }

        $values = [];
        foreach ($this->inputTypes->getLabels() as $type => $label) {
            $values[] = ['value' => $type, 'label' => __($label)];
        }
        $inputField = $form->getElement('frontend_input')->setValues($values)->setLabel(__('Input Type'))->setTitle(__('Input Type'));

        // An input type of another module (an attribute it left behind): shown as it is, so the page does not pretend it is a text field.
        $attribute = $this->getAttributeObject();
        $type = $attribute !== null && $attribute->getId() ? (string) $attribute->getFrontendInput() : '';
        if ($type !== '' && !$this->inputTypes->isSupported($type)) {
            $values[] = ['value' => $type, 'label' => __('%1 (not supported)', $type)];
            $inputField->setValues($values)->setValue($type)->setNote(__(
                'This input type comes from another module and is not supported here. Saving keeps it: the labels, the forms and the other settings can be changed, but no default value, validation or options. Convert the attribute to a supported type with a data patch (for example a multiple select), or delete it.'
            ));
        }

        $maxLength = $this->entityContext->getMaxCodeLength();
        $form->getElement('attribute_code')
            ->setClass(sprintf('validate-code validate-length maximum-length-%d', $maxLength))
            ->setNote(__(
                'Used internally. Lowercase letters, digits and underscores only, starting with a letter, at most %1 characters. Cannot be changed later.',
                $maxLength
            ));

        return $this;
    }

    /**
     * Core adds a script after this tab that unlocks the input type of a saved attribute and offers the compatible types. The module keeps
     * the type of an attribute fixed once it is saved (the stored values and the rules depend on it, and the save ignores a posted type),
     * so the script is left out and the field stays locked.
     *
     * @param string $html
     */
    protected function _afterToHtml($html)
    {
        return $html;
    }
}
