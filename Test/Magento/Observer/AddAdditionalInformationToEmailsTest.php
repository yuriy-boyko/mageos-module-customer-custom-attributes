<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Observer;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Observer\AddAdditionalInformationToEmails;
use MageOS\CustomerCustomAttributes\Observer\OrderLoadAfter;
use MageOS\CustomerCustomAttributes\Observer\QuoteSubmitBefore;
use MageOS\CustomerCustomAttributes\Service\AdditionalInformation;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Customer\Model\Attribute;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Email\Sender\CreditmemoSender;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;
use PHPUnit\Framework\TestCase;

/**
 * The new order email variable `mageos_additional_information_html`: the section of the attributes set to "Show in Emails".
 */
class AddAdditionalInformationToEmailsTest extends TestCase
{
    private const SHOWN = [ValidateRulesBuilder::EMAIL_KEY => 1];

    private function row(string $code, string $value): array
    {
        return ['attribute_code' => $code, 'attribute_label' => 'Label ' . $code, 'input_type' => 'text', 'value' => $value, 'value_label' => null];
    }

    /**
     * @param array<string, array<string, mixed>> $rulesByCode
     * @param array<string, mixed> $orderData
     */
    private function transport(object $sender, array $rulesByCode, array $orderData, bool $insert = true): DataObject
    {
        $attributes = [];
        foreach ($rulesByCode as $code => $rules) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getValidateRules')->willReturn($rules);
            $attributes[$code] = $attribute;
        }
        $provider = $this->createStub(CustomerAttributeProvider::class);
        $provider->method('getUserDefined')->willReturn($attributes);

        $transport = new DataObject(['order' => new DataObject($orderData)]);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn($insert);
        (new AddAdditionalInformationToEmails($provider, new ValidateRulesBuilder(), new AdminOnlyAccess(), new AdditionalInformation(), $config))
            ->execute(new Observer(['sender' => $sender, 'transportObject' => $transport]));

        return $transport;
    }

    private function variable(object $sender, array $rulesByCode, array $orderData): ?string
    {
        return $this->transport($sender, $rulesByCode, $orderData)->getData(AdditionalInformation::VARIABLE);
    }

    public function testOnlyTheAttributesSetToShowInTheEmailAppear(): void
    {
        $rows = [$this->row('shown', 'visible value'), $this->row('hidden', 'internal value')];
        $html = (string) $this->variable($this->createStub(OrderSender::class), ['shown' => self::SHOWN, 'hidden' => []], [OrderLoadAfter::DATA_KEY => $rows]);

        self::assertStringContainsString('Label shown: visible value', $html);
        self::assertStringNotContainsString('internal value', $html);
        self::assertStringContainsString('<h3>Additional Information</h3>', $html);
    }

    public function testAnOrderJustPlacedUsesTheRowsItWasGiven(): void
    {
        $html = (string) $this->variable($this->createStub(OrderSender::class), ['shown' => self::SHOWN], [QuoteSubmitBefore::DATA_KEY => [$this->row('shown', 'fresh')]]);

        self::assertStringContainsString('Label shown: fresh', $html);
    }

    public function testAnEmptyListFromTheLoadDoesNotHideTheRowsOfThePlacing(): void
    {
        $html = (string) $this->variable(
            $this->createStub(OrderSender::class),
            ['shown' => self::SHOWN],
            [OrderLoadAfter::DATA_KEY => [], QuoteSubmitBefore::DATA_KEY => [$this->row('shown', 'fresh')]]
        );
        self::assertStringContainsString('fresh', $html);
    }

    public function testAnAdminOnlyAttributeOrOneNotSavedOnOrdersNeverAppears(): void
    {
        $rules = [
            'internal' => self::SHOWN + [ValidateRulesBuilder::ADMIN_ONLY_KEY => 1],
            'off' => self::SHOWN + [ValidateRulesBuilder::ORDER_SNAPSHOT_KEY => 0],
        ];
        $rows = [$this->row('internal', 'secret'), $this->row('off', 'not saved')];

        self::assertSame('', $this->variable($this->createStub(OrderSender::class), $rules, [OrderLoadAfter::DATA_KEY => $rows]));
    }

    public function testAnEmptySectionIsAnEmptyVariable(): void
    {
        self::assertSame('', $this->variable($this->createStub(OrderSender::class), ['shown' => self::SHOWN], []));
        self::assertSame('', $this->variable($this->createStub(OrderSender::class), ['shown' => []], [OrderLoadAfter::DATA_KEY => [$this->row('shown', 'x')]]));
    }

    public function testOtherEmailsAreNotTouched(): void
    {
        $rows = [$this->row('shown', 'visible value')];

        self::assertNull($this->variable($this->createStub(OrderCommentSender::class), ['shown' => self::SHOWN], [OrderLoadAfter::DATA_KEY => $rows]), 'the order update email');
    }

    public function testTheInvoiceShipmentAndCreditMemoEmailsGetTheVariableToo(): void
    {
        $rows = [$this->row('shown', 'visible value')];
        foreach ([InvoiceSender::class, ShipmentSender::class, CreditmemoSender::class] as $sender) {
            $html = (string) $this->variable($this->createStub($sender), ['shown' => self::SHOWN], [OrderLoadAfter::DATA_KEY => $rows]);

            self::assertStringContainsString('visible value', $html, $sender);
        }
    }

    public function testTheSettingOnlyDecidesTheAutomaticInsertion(): void
    {
        $rows = [OrderLoadAfter::DATA_KEY => [$this->row('shown', 'visible value')]];

        $on = $this->transport($this->createStub(OrderSender::class), ['shown' => self::SHOWN], $rows, true);
        self::assertSame('1', $on->getData(AdditionalInformation::INSERT_VARIABLE));

        $off = $this->transport($this->createStub(OrderSender::class), ['shown' => self::SHOWN], $rows, false);
        self::assertStringContainsString('visible value', (string) $off->getData(AdditionalInformation::VARIABLE), 'the variable stays for a template that places it');
        self::assertNull($off->getData(AdditionalInformation::INSERT_VARIABLE));
    }

    public function testNothingToShowAsksForNoInsertion(): void
    {
        $transport = $this->transport($this->createStub(OrderSender::class), ['shown' => []], [OrderLoadAfter::DATA_KEY => [$this->row('shown', 'x')]], true);

        self::assertNull($transport->getData(AdditionalInformation::INSERT_VARIABLE));
    }

    public function testEachAttributeAppearsOnlyInTheEmailsItIsSetFor(): void
    {
        $rows = [OrderLoadAfter::DATA_KEY => [$this->row('inv', 'for the invoice'), $this->row('ord', 'for the order'), $this->row('all', 'for all'), $this->row('shp', 'for the shipment'), $this->row('cm', 'for the credit memo')]];
        $rules = [
            'inv' => [ValidateRulesBuilder::EMAIL_KEY => ['invoice']],
            'ord' => [ValidateRulesBuilder::EMAIL_KEY => ['order']],
            'all' => [ValidateRulesBuilder::EMAIL_KEY => 1],
            'shp' => [ValidateRulesBuilder::EMAIL_KEY => ['shipment']],
            'cm' => [ValidateRulesBuilder::EMAIL_KEY => ['creditmemo']],
        ];
        $expected = [
            OrderSender::class => ['for the order', 'for all'],
            InvoiceSender::class => ['for the invoice', 'for all'],
            ShipmentSender::class => ['for all', 'for the shipment'],
            CreditmemoSender::class => ['for all', 'for the credit memo'],
        ];
        foreach ($expected as $sender => $texts) {
            $html = (string) $this->variable($this->createStub($sender), $rules, $rows);
            foreach (['for the invoice', 'for the order', 'for all', 'for the shipment', 'for the credit memo'] as $text) {
                in_array($text, $texts, true)
                    ? self::assertStringContainsString($text, $html, "$sender has $text")
                    : self::assertStringNotContainsString($text, $html, "$sender has not $text");
            }
        }
    }

    public function testAnEmailNobodyIsSetForGetsAnEmptyVariableAndNoInsertion(): void
    {
        $transport = $this->transport(
            $this->createStub(ShipmentSender::class),
            ['inv' => [ValidateRulesBuilder::EMAIL_KEY => ['invoice']]],
            [OrderLoadAfter::DATA_KEY => [$this->row('inv', 'x')]]
        );

        self::assertSame('', $transport->getData(AdditionalInformation::VARIABLE));
        self::assertNull($transport->getData(AdditionalInformation::INSERT_VARIABLE));
    }
}
