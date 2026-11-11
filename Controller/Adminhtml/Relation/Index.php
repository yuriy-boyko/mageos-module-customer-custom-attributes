<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Controller\Adminhtml\Relation;

use MageOS\CustomerCustomAttributes\Controller\Adminhtml\Relation;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

class Index extends Relation implements HttpGetActionInterface
{
    public function execute()
    {
        return $this->preparePage($this->resultFactory->create(ResultFactory::TYPE_PAGE), (string) __('Attribute Relations'));
    }
}
