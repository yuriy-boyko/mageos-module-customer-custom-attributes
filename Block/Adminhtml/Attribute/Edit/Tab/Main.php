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
        $form->getElement('frontend_input')->setValues($values)->setLabel(__('Input Type'))->setTitle(__('Input Type'));

        $maxLength = $this->entityContext->getMaxCodeLength();
        $form->getElement('attribute_code')
            ->setClass(sprintf('validate-code validate-length maximum-length-%d', $maxLength))
            ->setNote(__(
                'Used internally. Lowercase letters, digits and underscores only, starting with a letter, at most %1 characters. Cannot be changed later.',
                $maxLength
            ));

        return $this;
    }
}
