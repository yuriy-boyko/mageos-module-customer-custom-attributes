<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressText\EntryProvider;
use MageOS\CustomerCustomAttributes\Plugin\AddressRendererCustomAttributes;
use MageOS\CustomerCustomAttributes\Service\AddressText\AddressTextBuilder;
use Magento\Customer\Block\Address\Renderer\DefaultRenderer;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AddressRendererCustomAttributesTest extends TestCase
{
    private function render(string $code, bool $escapeHtml): string
    {
        $entries = $this->createStub(EntryProvider::class);
        $entries->method('entriesFor')->willReturn([['label' => 'Note', 'value' => '<i>x</i>'], ['label' => 'Gate', 'value' => '42']]);
        $renderer = $this->createStub(DefaultRenderer::class);
        $renderer->method('getType')->willReturn(new DataObject(['code' => $code, 'escape_html' => $escapeHtml]));
        $plugin = new AddressRendererCustomAttributes($entries, new AddressTextBuilder(), $this->createStub(LoggerInterface::class));
        return (string) $plugin->afterRenderArray($renderer, 'Ada<br />United States', ['e2e' => 'x'], null);
    }

    public function testAnEscapedFormatWithItsOwnCodeGetsEscapedHtmlLines(): void
    {
        self::assertSame('Ada<br />United States<br />Note: &lt;i&gt;x&lt;/i&gt;<br />Gate: 42', $this->render('hyva_checkout_billing', true));
    }

    public function testATextFormatStaysText(): void
    {
        self::assertSame("Ada<br />United States\nNote: <i>x</i>\nGate: 42", $this->render('text', false));
    }
}
