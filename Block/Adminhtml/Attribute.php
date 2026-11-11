<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml;

use MageOS\CustomerCustomAttributes\Model\Attribute\EntityContext;
use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Grid\Container;

/**
 * Page content of the attribute grid: header, "Add New" button and the grid block.
 */
class Attribute extends Container
{
    public function __construct(
        Context $context,
        private readonly EntityContext $entityContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _construct()
    {
        $this->_blockGroup = 'MageOS_CustomerCustomAttributes';
        $this->_controller = 'adminhtml_attribute';
        $this->_headerText = $this->entityContext->getPluralLabel();
        $this->_addButtonLabel = (string) __('Add New %1', $this->entityContext->getLabel());
        parent::_construct();
    }

    /**
     * @inheritDoc
     */
    public function getCreateUrl()
    {
        return $this->getUrl('*/*/new', [EntityContext::REQUEST_PARAM => $this->entityContext->getKey()]);
    }
}
