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
use MageOS\CustomerCustomAttributes\Service\EmailTypes;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Events email_order_set_template_vars_before, email_invoice_..., email_shipment_... and email_creditmemo_... (the new order, invoice,
 * shipment and credit memo emails, not the comment emails): adds the template variable `mageos_additional_information_html`, the
 * "Additional Information" section with the values of the customer attributes set to "Show in Emails" (saved on the order, not
 * admin-only) for that email. The variable is always there, so a template can place it by hand. When "Add the Section Automatically" is on
 * (Stores > Configuration > Sales > Sales Emails), the flag variable `mageos_additional_information_insert` is set too and
 * Plugin\OrderEmailTemplate puts the variable into the template text.
 *
 * The email is known from the event name, not from the sender: core fires these events from two kinds of senders, the
 * Order\Email\Sender\*Sender classes (admin screens, checkout, asynchronous sending by cron) and the service-contract senders
 * Order\Invoice\Sender\EmailSender, Order\Shipment\Sender\EmailSender and Order\Creditmemo\Sender\EmailSender (REST
 * POST /V1/order/:id/invoice, /ship and /refund with notify).
 */
class AddAdditionalInformationToEmails implements ObserverInterface
{
    /** Stores > Configuration > Sales > Sales Emails > Additional Information > Add the Section Automatically */
    public const CONFIG_PATH = 'sales_email/mageos_cca/add_section';

    /** the events this observer handles and their email (a code of EmailTypes) */
    public const EVENTS = [
        'email_order_set_template_vars_before' => EmailTypes::ORDER,
        'email_invoice_set_template_vars_before' => EmailTypes::INVOICE,
        'email_shipment_set_template_vars_before' => EmailTypes::SHIPMENT,
        'email_creditmemo_set_template_vars_before' => EmailTypes::CREDITMEMO,
    ];

    public function __construct(
        private readonly CustomerAttributeProvider $attributeProvider,
        private readonly ValidateRulesBuilder $rulesBuilder,
        private readonly AdminOnlyAccess $adminOnly,
        private readonly AdditionalInformation $additionalInformation,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function execute(Observer $observer): void
    {
        $type = self::EVENTS[(string) $observer->getEvent()->getName()] ?? null;
        if ($type === null) {
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
            if ($this->rulesBuilder->showsInEmail($rules, $type) && $this->rulesBuilder->savesOnOrder($rules) && !$this->adminOnly->isAdminOnly($rules)) {
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
        if ($html !== '' && $this->scopeConfig->isSetFlag(self::CONFIG_PATH, ScopeInterface::SCOPE_STORE, (int) $order->getStoreId())) {
            $transport->setData(AdditionalInformation::INSERT_VARIABLE, '1');
        }
    }
}
