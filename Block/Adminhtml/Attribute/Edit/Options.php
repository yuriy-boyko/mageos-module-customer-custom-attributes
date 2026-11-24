<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Edit;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Attribute as AttributeAction;
use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use MageOS\CustomerCustomAttributes\Service\InputTypeMap;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

/**
 * Option table of the edit page. An attribute whose options come from a developer's source model (see
 * InputTypeMap::hasDeveloperOptions()) shows them as a read-only list instead of the editable table.
 */
class Options extends \Magento\Eav\Block\Adminhtml\Attribute\Edit\Options\Options
{
    public function __construct(
        Context $context,
        Registry $registry,
        \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory $attrOptionCollectionFactory,
        \Magento\Framework\Validator\UniversalFactory $universalFactory,
        private readonly InputTypeMap $inputTypes,
        private readonly OptionLabels $optionLabels,
        array $data = []
    ) {
        parent::__construct($context, $registry, $attrOptionCollectionFactory, $universalFactory, $data);
    }

    protected function _toHtml()
    {
        return $this->developerOptionsHtml() ?? parent::_toHtml();
    }

    /**
     * The read-only list of the options of the attribute being edited, or null when its options are managed here (the table source).
     */
    public function developerOptionsHtml(): ?string
    {
        $attribute = $this->_registry->registry(AttributeAction::REGISTRY_KEY);
        if (!$attribute || !$this->inputTypes->hasDeveloperOptions((string) $attribute->getFrontendInput(), (string) $attribute->getSourceModel())) {
            return null;
        }

        $escaper = $this->_escaper;
        $rows = '';
        foreach ($this->optionLabels->forAttribute($attribute) as $value => $label) {
            $rows .= '<tr><td>' . $escaper->escapeHtml((string) $value) . '</td><td>' . $escaper->escapeHtml($label) . '</td></tr>';
        }
        return '<fieldset class="fieldset" id="mageos-cca-developer-options"><legend class="legend"><span>'
            . $escaper->escapeHtml(__('Options')) . '</span></legend>'
            . '<div class="message message-notice"><div>'
            . $escaper->escapeHtml(__('These options come from a source model written by a developer (%1) and cannot be changed here.', (string) $attribute->getSourceModel()))
            . '</div></div><table class="admin__table-secondary"><thead><tr><th>' . $escaper->escapeHtml(__('Value')) . '</th><th>'
            . $escaper->escapeHtml(__('Label')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></fieldset>';
    }
}
