<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Observer\OrderLoadAfter;
use Magento\Framework\Escaper;
use Magento\Sales\Block\Adminhtml\Order\View\Info;

/**
 * Adds the customer attributes of the order (snapshot) to the "Account Information" block of the admin order view.
 */
class AdminOrderAccountData
{
    public function __construct(private readonly Escaper $escaper)
    {
    }

    /**
     * @param array<int|string, array{label: string, value: string}> $result
     * @return array<int|string, array{label: string, value: string}>
     */
    public function afterGetCustomerAccountData(Info $subject, array $result): array
    {
        $rows = $subject->getOrder()->getData(OrderLoadAfter::DATA_KEY);
        foreach ((array) $rows as $row) {
            $text = (string) ($row['value_label'] ?? $row['value']);
            if (($row['input_type'] ?? '') === 'date') {
                $text = substr($text, 0, 10);
            }
            $result[] = [
                'label' => (string) $row['attribute_label'],
                'value' => $this->escaper->escapeHtml($text, ['br']),
            ];
        }
        return $result;
    }
}
