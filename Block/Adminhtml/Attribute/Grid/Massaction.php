<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Grid;

use Magento\Backend\Block\Widget\Grid\Massaction\Extended;

/**
 * The core mass action block fetches the ids of "Select All" from a copy of the collection's select without its bound
 * parameters, which fails for EAV attribute collections (they bind the website and entity type). This block asks the
 * collection for its ids instead.
 */
class Massaction extends Extended
{
    public function getGridIdsJson()
    {
        if (!$this->getUseSelectAll()) {
            return '';
        }
        return implode(',', $this->getParentBlock()->getCollection()->getAllIds());
    }
}
