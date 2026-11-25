<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Observer;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Service\AdditionalInformation;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

/**
 * Event email_order_set_template_vars_before, for the new order email only: adds the template variable
 * `mageos_additional_information_html`, the "Additional Information" section with the values of the customer attributes set to
 * "Show in Order Email" (saved on the order, not admin-only). Plugin\OrderEmailTemplate puts the variable into the template text.
 */
class AddAdditionalInformationToOrderEmail implements ObserverInterface
{
    public function __construct(
        private readonly CustomerAttributeProvider $attributeProvider,
        private readonly ValidateRulesBuilder $rulesBuilder,
        private readonly AdminOnlyAccess $adminOnly,
        private readonly AdditionalInformation $additionalInformation
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$observer->getData('sender') instanceof OrderSender) {
            return;
        }
        $transport = $observer->getData('transportObject');
        $order = $transport?->getData('order');
        if ($order === null) {
            return;
        }

        $codes = [];
        foreach ($this->attributeProvider->getUserDefined() as $code => $attribute) {
            $rules = (array) $attribute->getValidateRules();
            if ($this->rulesBuilder->showsInEmail($rules) && $this->rulesBuilder->savesOnOrder($rules) && !$this->adminOnly->isAdminOnly($rules)) {
                $codes[] = (string) $code;
            }
        }
        // a placed order carries the rows it was given (QuoteSubmitBefore); an order loaded later has them from OrderLoadAfter
        // (OrderLoadAfter sets an empty list on an order that has no rows yet, so the rows of the placing win when there are some)
        $snapshot = (array) $order->getData(QuoteSubmitBefore::DATA_KEY);
        if ($snapshot === []) {
            $snapshot = (array) $order->getData(OrderLoadAfter::DATA_KEY);
        }
        $html = $codes === [] ? '' : $this->additionalInformation->html((string) __('Additional Information'), $this->additionalInformation->rows($snapshot, $codes));
        $transport->setData(AdditionalInformation::VARIABLE, $html);
    }
}
