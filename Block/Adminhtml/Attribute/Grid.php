<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute;

use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Grid\Renderer\Forms;
use MageOS\CustomerCustomAttributes\Model\ResourceModel\AttributeFormStorage;
use MageOS\CustomerCustomAttributes\Service\FormColumn;
use MageOS\CustomerCustomAttributes\Service\InputTypeMap;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Grid\Extended;
use Magento\Backend\Helper\Data as BackendHelper;

/**
 * Grid of the user-defined attributes of the current area.
 */
class Grid extends Extended
{
    public function __construct(
        Context $context,
        BackendHelper $backendHelper,
        private readonly EntityContext $entityContext,
        private readonly InputTypeMap $inputTypes,
        private readonly AttributeFormStorage $formStorage,
        private readonly FormColumn $formColumn,
        array $data = []
    ) {
        parent::__construct($context, $backendHelper, $data);
    }

    protected $_massactionBlockName = Grid\Massaction::class;

    protected function _construct()
    {
        parent::_construct();
        $this->setId('mageosCcaAttributeGrid');
        $this->setDefaultSort('attribute_code');
        $this->setDefaultDir('ASC');
        $this->setSaveParametersInSession(true);
    }

    protected function _prepareCollection()
    {
        $collection = $this->entityContext->createCollection();
        $collection->addFieldToFilter('main_table.is_user_defined', 1);
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    /**
     * The forms of the rows of this page, from one query: the "Shown in" column prints them.
     */
    protected function _afterLoadCollection()
    {
        $items = $this->getCollection()->getItems();
        $forms = $this->formStorage->loadForAttributes(array_map(static fn ($item): int => (int) $item->getAttributeId(), $items));
        $offered = $this->entityContext->getOptionalForms();
        foreach ($items as $item) {
            $item->setData('mageos_forms_view', $this->formColumn->describe($offered, $forms[(int) $item->getAttributeId()] ?? []));
        }
        return parent::_afterLoadCollection();
    }

    protected function _prepareColumns()
    {
        $yesNo = ['1' => __('Yes'), '0' => __('No')];
        $inputTypes = [];
        foreach ($this->inputTypes->getLabels() as $type => $label) {
            $inputTypes[$type] = __($label);
        }

        $this->addColumn('attribute_code', [
            'header' => __('Attribute Code'),
            'index' => 'attribute_code',
            'filter_index' => 'main_table.attribute_code',
            'sortable' => true,
        ]);
        $this->addColumn('frontend_label', [
            'header' => __('Default Label'),
            'index' => 'frontend_label',
            'filter_index' => 'main_table.frontend_label',
            'sortable' => true,
        ]);
        $this->addColumn('frontend_input', [
            'header' => __('Input Type'),
            'index' => 'frontend_input',
            'filter_index' => 'main_table.frontend_input',
            'type' => 'options',
            'options' => $inputTypes,
        ]);
        $this->addColumn('is_required', [
            'header' => __('Required'),
            'index' => 'is_required',
            'filter_index' => 'main_table.is_required',
            'type' => 'options',
            'options' => $yesNo,
        ]);
        $this->addColumn('is_visible', [
            'header' => __('Enabled'),
            'index' => 'is_visible',
            'filter_index' => 'additional_table.is_visible',
            'type' => 'options',
            'options' => $yesNo,
        ]);
        $this->addColumn('mageos_forms', [
            'header' => __('Shown in'),
            'index' => 'attribute_id',
            'renderer' => Forms::class,
            'filter' => false,
            'sortable' => false,
        ]);
        $this->addColumn('sort_order', [
            'header' => __('Sort Order'),
            'index' => 'sort_order',
            'filter_index' => 'additional_table.sort_order',
            'type' => 'number',
        ]);
        return parent::_prepareColumns();
    }

    /**
     * @param \Magento\Framework\DataObject $row
     * @return string
     */
    protected function _prepareMassaction()
    {
        $this->setMassactionIdField('attribute_id');
        $this->setMassactionIdFilter('main_table.attribute_id');
        $block = $this->getMassactionBlock();
        $block->setFormFieldName('attribute_ids');

        $params = [EntityContext::REQUEST_PARAM => $this->entityContext->getKey()];
        $block->addItem('enable', [
            'label' => __('Enable'),
            'url' => $this->getUrl('*/*/massStatus', $params + ['status' => 1]),
        ]);
        $block->addItem('disable', [
            'label' => __('Disable'),
            'url' => $this->getUrl('*/*/massStatus', $params + ['status' => 0]),
        ]);
        $block->addItem('delete', [
            'label' => __('Delete'),
            'url' => $this->getUrl('*/*/massDelete', $params),
            'confirm' => __('Delete the selected attributes? Orders keep their values. This cannot be undone.'),
        ]);
        return $this;
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('*/*/edit', [
            'attribute_id' => $row->getAttributeId(),
            EntityContext::REQUEST_PARAM => $this->entityContext->getKey(),
        ]);
    }
}
