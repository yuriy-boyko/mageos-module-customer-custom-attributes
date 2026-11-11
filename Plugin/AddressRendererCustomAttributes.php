<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressText\EntryProvider;
use MageOS\CustomerCustomAttributes\Service\AddressText\AddressTextBuilder;
use Magento\Customer\Block\Address\Renderer\DefaultRenderer;
use Psr\Log\LoggerInterface;

/**
 * Appends the custom address attributes to the standard address text, after the last standard line. Every address output
 * of the shop goes through this renderer: checkout address cards, account dashboard and address book, order pages, order
 * emails, PDFs and the admin order views. It never throws: on any problem the standard text is returned unchanged.
 */
class AddressRendererCustomAttributes
{
    public function __construct(
        private readonly EntryProvider $entryProvider,
        private readonly AddressTextBuilder $textBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $addressAttributes
     * @param mixed $format
     * @param mixed $result
     * @return mixed
     */
    public function afterRenderArray(DefaultRenderer $subject, $result, $addressAttributes, $format = null)
    {
        if (!is_string($result) || !is_array($addressAttributes)) {
            return $result;
        }
        try {
            $entries = $this->entryProvider->entriesFor($addressAttributes);
            if ($entries === []) {
                return $result;
            }
            return $result . $this->textBuilder->build((string) $subject->getType()->getCode(), $entries, $result);
        } catch (\Throwable $e) {
            $this->logger->warning('[MageOS_CustomerCustomAttributes] could not add custom attributes to the address text: ' . $e->getMessage());
            return $result;
        }
    }
}
