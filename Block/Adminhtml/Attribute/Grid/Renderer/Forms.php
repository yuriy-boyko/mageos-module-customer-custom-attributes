<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Block\Adminhtml\Attribute\Grid\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;

/**
 * "Shown in" column: the storefront forms of the row (see Service/FormColumn, prepared by the grid). The account forms, where the
 * customer types the value himself, are in bold.
 */
class Forms extends AbstractRenderer
{
    public function render(DataObject $row)
    {
        $view = $row->getData('mageos_forms_view');
        if (!is_array($view)) {
            return '';
        }
        $escaper = $this->_escaper;
        $parts = [];
        foreach ($view['items'] as $item) {
            /** @var string $label escapeHtml of a string is a string */
            $label = $escaper->escapeHtml($item['label']);
            $parts[] = $item['account'] ? '<strong>' . $label . '</strong>' : $label;
        }
        if ($view['other'] > 0) {
            /** @var string $more */
            $more = $escaper->escapeHtml(__('+%1 other', $view['other']));
            $parts[] = $more;
        }
        return $parts === [] ? $escaper->escapeHtml(__('Admin only')) : implode(', ', $parts);
    }
}
