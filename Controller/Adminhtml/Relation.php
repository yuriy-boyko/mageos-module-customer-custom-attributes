<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;

/**
 * Base of the dependency relation screens (Stores → Attributes → Attribute Relations).
 */
abstract class Relation extends Action
{
    public const ADMIN_RESOURCE = 'MageOS_CustomerCustomAttributes::relations';
    public const FORM_SESSION_KEY = 'mageos_cca_relation_form_data';

    protected function preparePage(Page $page, string $title): Page
    {
        $page->setActiveMenu('MageOS_CustomerCustomAttributes::relations');
        $page->getConfig()->getTitle()->prepend(__('Attribute Relations'));
        $page->getConfig()->getTitle()->prepend($title);
        $page->addBreadcrumb(__('Stores'), __('Stores'));
        $page->addBreadcrumb(__('Attribute Relations'), __('Attribute Relations'));
        return $page;
    }
}
