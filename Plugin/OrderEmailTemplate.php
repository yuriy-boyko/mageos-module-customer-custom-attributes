<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Service\AdditionalInformation;
use MageOS\CustomerCustomAttributes\Service\EmailTemplateInserter;
use Magento\Email\Model\Template;

/**
 * Renders an order email (new order, invoice, shipment, credit memo) with the "Additional Information" section below the payment and shipping method: when the variable has a
 * value and the setting asks for it (AddAdditionalInformationToEmails) and the template does not use it yet, the variable is put into the template text for this
 * rendering only. The stored template is not changed.
 */
class OrderEmailTemplate
{
    public function __construct(private readonly EmailTemplateInserter $inserter)
    {
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function aroundGetProcessedTemplate(Template $subject, callable $proceed, array $variables = []): string
    {
        if (($variables[AdditionalInformation::VARIABLE] ?? '') === '' || ($variables[AdditionalInformation::INSERT_VARIABLE] ?? '') === '') {
            return $proceed($variables);
        }
        $original = (string) $subject->getTemplateText();
        $changed = $this->inserter->insert($original, '{{var ' . AdditionalInformation::VARIABLE . '|raw}}');
        if ($changed === null) {
            return $proceed($variables);
        }
        $subject->setTemplateText($changed);
        try {
            return $proceed($variables);
        } finally {
            $subject->setTemplateText($original);
        }
    }
}
