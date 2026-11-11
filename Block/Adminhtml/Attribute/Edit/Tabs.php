<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Edit;

use Magento\Backend\Block\Widget\Tabs as WidgetTabs;

/**
 * Tabs of the attribute edit page: Properties, Manage Labels, Storefront Properties.
 */
class Tabs extends WidgetTabs
{
    protected function _construct()
    {
        parent::_construct();
        $this->setId('mageos_cca_attribute_tabs');
        $this->setDestElementId('edit_form');
        $this->setTitle(__('Attribute Information'));
    }

    protected function _beforeToHtml()
    {
        $this->addTab('main', [
            'label' => __('Properties'),
            'title' => __('Properties'),
            'content' => $this->getChildHtml('main'),
            'active' => true,
        ]);
        $this->addTab('labels', [
            'label' => __('Manage Labels'),
            'title' => __('Manage Labels'),
            'content' => $this->getChildHtml('labels'),
        ]);
        $this->addTab('front', [
            'label' => __('Storefront Properties'),
            'title' => __('Storefront Properties'),
            'content' => $this->getChildHtml('front'),
        ]);
        $websites = $this->getChildBlock('websites');
        if ($websites !== false && $websites->canShow()) {
            $this->addTab('websites', [
                'label' => __('Websites'),
                'title' => __('Websites'),
                'content' => $this->getChildHtml('websites'),
            ]);
        }
        return parent::_beforeToHtml();
    }
}
