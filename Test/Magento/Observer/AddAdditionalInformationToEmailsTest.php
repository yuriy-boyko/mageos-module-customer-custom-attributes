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
use Magento\Framework\Event;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Creditmemo\Sender\EmailSender as CreditmemoEmailSender;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Invoice\Sender\EmailSender as InvoiceEmailSender;
use Magento\Sales\Model\Order\Shipment\Sender\EmailSender as ShipmentEmailSender;
use PHPUnit\Framework\TestCase;

/**
 * The new order email variable `mageos_additional_information_html`: the section of the attributes set to "Show in Emails".
 */
class AddAdditionalInformationToEmailsTest extends TestCase
{
    private const SHOWN = [ValidateRulesBuilder::EMAIL_KEY => 1];
    private const ORDER = 'email_order_set_template_vars_before';
    private const INVOICE = 'email_invoice_set_template_vars_before';
    private const SHIPMENT = 'email_shipment_set_template_vars_before';
    private const CREDITMEMO = 'email_creditmemo_set_template_vars_before';

    private function row(string $code, string $value): array
    {
        return ['attribute_code' => $code, 'attribute_label' => 'Label ' . $code, 'input_type' => 'text', 'value' => $value, 'value_label' => null];
    }

    /**
     * @param array<string, array<string, mixed>> $rulesByCode
     * @param array<string, mixed> $orderData
     */
    private function transport(string $event, array $rulesByCode, array $orderData, bool $insert = true, ?object $sender = null, ?Emulation $emulation = null): DataObject
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
        (new AddAdditionalInformationToEmails($provider, new ValidateRulesBuilder(), new AdminOnlyAccess(), new AdditionalInformation(), $config, $emulation ?? $this->createStub(Emulation::class)))
            ->execute(new Observer(['event' => new Event(['name' => $event]), 'sender' => $sender, 'transportObject' => $transport]));

        return $transport;
    }

    private function variable(string $event, array $rulesByCode, array $orderData): ?string
    {
        return $this->transport($event, $rulesByCode, $orderData)->getData(AdditionalInformation::VARIABLE);
    }

    public function testOnlyTheAttributesSetToShowInTheEmailAppear(): void
    {
        $rows = [$this->row('shown', 'visible value'), $this->row('hidden', 'internal value')];
        $html = (string) $this->variable(self::ORDER, ['shown' => self::SHOWN, 'hidden' => []], [OrderLoadAfter::DATA_KEY => $rows]);

        self::assertStringContainsString('Label shown: visible value', $html);
        self::assertStringNotContainsString('internal value', $html);
        self::assertStringContainsString('<h3>Additional Information</h3>', $html);
    }

    public function testAnOrderJustPlacedUsesTheRowsItWasGiven(): void
    {
        $html = (string) $this->variable(self::ORDER, ['shown' => self::SHOWN], [QuoteSubmitBefore::DATA_KEY => [$this->row('shown', 'fresh')]]);

        self::assertStringContainsString('Label shown: fresh', $html);
    }

    public function testAnEmptyListFromTheLoadDoesNotHideTheRowsOfThePlacing(): void
    {
        $html = (string) $this->variable(
            self::ORDER,
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

        self::assertSame('', $this->variable(self::ORDER, $rules, [OrderLoadAfter::DATA_KEY => $rows]));
    }

    public function testAnEmptySectionIsAnEmptyVariable(): void
    {
        self::assertSame('', $this->variable(self::ORDER, ['shown' => self::SHOWN], []));
        self::assertSame('', $this->variable(self::ORDER, ['shown' => []], [OrderLoadAfter::DATA_KEY => [$this->row('shown', 'x')]]));
    }

    public function testOtherEmailsAreNotTouched(): void
    {
        $rows = [$this->row('shown', 'visible value')];

        self::assertNull($this->variable('email_order_comment_set_template_vars_before', ['shown' => self::SHOWN], [OrderLoadAfter::DATA_KEY => $rows]), 'the order update email');
    }

    public function testTheInvoiceShipmentAndCreditMemoEmailsGetTheVariableToo(): void
    {
        $rows = [$this->row('shown', 'visible value')];
        foreach ([self::INVOICE, self::SHIPMENT, self::CREDITMEMO] as $sender) {
            $html = (string) $this->variable($sender, ['shown' => self::SHOWN], [OrderLoadAfter::DATA_KEY => $rows]);

            self::assertStringContainsString('visible value', $html, $sender);
        }
    }

    public function testTheSettingOnlyDecidesTheAutomaticInsertion(): void
    {
        $rows = [OrderLoadAfter::DATA_KEY => [$this->row('shown', 'visible value')]];

        $on = $this->transport(self::ORDER, ['shown' => self::SHOWN], $rows, true);
        self::assertSame('1', $on->getData(AdditionalInformation::INSERT_VARIABLE));

        $off = $this->transport(self::ORDER, ['shown' => self::SHOWN], $rows, false);
        self::assertStringContainsString('visible value', (string) $off->getData(AdditionalInformation::VARIABLE), 'the variable stays for a template that places it');
        self::assertNull($off->getData(AdditionalInformation::INSERT_VARIABLE));
    }

    public function testNothingToShowAsksForNoInsertion(): void
    {
        $transport = $this->transport(self::ORDER, ['shown' => []], [OrderLoadAfter::DATA_KEY => [$this->row('shown', 'x')]], true);

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
            self::ORDER => ['for the order', 'for all'],
            self::INVOICE => ['for the invoice', 'for all'],
            self::SHIPMENT => ['for all', 'for the shipment'],
            self::CREDITMEMO => ['for all', 'for the credit memo'],
        ];
        foreach ($expected as $sender => $texts) {
            $html = (string) $this->variable($sender, $rules, $rows);
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
            self::SHIPMENT,
            ['inv' => [ValidateRulesBuilder::EMAIL_KEY => ['invoice']]],
            [OrderLoadAfter::DATA_KEY => [$this->row('inv', 'x')]]
        );

        self::assertSame('', $transport->getData(AdditionalInformation::VARIABLE));
        self::assertNull($transport->getData(AdditionalInformation::INSERT_VARIABLE));
    }

    /**
     * Core fires the events from two kinds of senders: the Order\Email\Sender classes (admin, checkout, cron) and the service-contract
     * EmailSender classes (REST invoice, ship and refund with notify). The email is known from the event, whichever class sends it.
     */
    public function testTheServiceContractSendersGetTheSectionToo(): void
    {
        $rows = [OrderLoadAfter::DATA_KEY => [$this->row('shown', 'visible value')]];
        $senders = [
            self::INVOICE => InvoiceEmailSender::class,
            self::SHIPMENT => ShipmentEmailSender::class,
            self::CREDITMEMO => CreditmemoEmailSender::class,
        ];
        foreach ($senders as $event => $sender) {
            $transport = $this->transport($event, ['shown' => self::SHOWN], $rows, true, $this->createStub($sender));

            self::assertStringContainsString('visible value', (string) $transport->getData(AdditionalInformation::VARIABLE), $sender);
            self::assertSame('1', $transport->getData(AdditionalInformation::INSERT_VARIABLE), $sender);
        }
    }

    public function testTheSenderClassDoesNotDecideTheEmail(): void
    {
        $rows = [OrderLoadAfter::DATA_KEY => [$this->row('inv', 'for the invoice')]];
        $rules = ['inv' => [ValidateRulesBuilder::EMAIL_KEY => ['invoice']]];

        $html = (string) $this->transport(self::SHIPMENT, $rules, $rows, true, $this->createStub(InvoiceSender::class))->getData(AdditionalInformation::VARIABLE);
        self::assertSame('', $html, 'a shipment event is the shipment email');
    }

    /**
     * The event fires before core switches to the order's store for the template: the section is built inside a store emulation of its
     * own, so the heading is in the store's language for an email sent from the admin, REST or cron.
     */
    public function testTheHeadingIsTranslatedInTheOrdersStore(): void
    {
        $log = [];
        $previous = Phrase::getRenderer();
        Phrase::setRenderer($this->renderer($log));
        try {
            $transport = $this->transport(self::INVOICE, ['shown' => self::SHOWN], ['store_id' => 2, OrderLoadAfter::DATA_KEY => [$this->row('shown', 'x')]], true, null, $this->emulations($log));
        } finally {
            Phrase::setRenderer($previous);
        }

        self::assertStringContainsString('<h3>Informazioni Aggiuntive</h3>', (string) $transport->getData(AdditionalInformation::VARIABLE));
        self::assertSame(['start 2 frontend forced', 'translate in store 2', 'stop'], $log);
    }

    public function testTheEmulationAlwaysEnds(): void
    {
        $log = [];
        $previous = Phrase::getRenderer();
        Phrase::setRenderer($this->renderer($log, true));
        try {
            $this->transport(self::ORDER, ['shown' => self::SHOWN], ['store_id' => 2, OrderLoadAfter::DATA_KEY => [$this->row('shown', 'x')]], true, null, $this->emulations($log));
            self::fail('the error of the translation reaches the caller');
        } catch (\RuntimeException) {
            self::assertSame('stop', end($log), 'the store emulation ends');
        } finally {
            Phrase::setRenderer($previous);
        }
    }

    public function testNoEmulationWhenNothingIsShown(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::never())->method('startEnvironmentEmulation');

        $this->transport(self::ORDER, ['shown' => []], ['store_id' => 2, OrderLoadAfter::DATA_KEY => [$this->row('shown', 'x')]], true, null, $emulation);
    }

    /** the observer's own Emulation (the shared one ignores a nested start, and its stop would end the caller's emulation) */
    private function emulations(array &$log): Emulation
    {
        $emulation = $this->createStub(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(function ($storeId, $area, $force) use (&$log): void {
            $log[] = sprintf('start %d %s %s', $storeId, $area, $force ? 'forced' : 'not forced');
            $log['store'] = $storeId;
        });
        $emulation->method('stopEnvironmentEmulation')->willReturnCallback(function () use (&$log): void {
            unset($log['store']);
            $log[] = 'stop';
        });
        return $emulation;
    }

    /** translates "Additional Information" into Italian only while the store 2 is emulated */
    private function renderer(array &$log, bool $fail = false): RendererInterface
    {
        return new class ($log, $fail) implements RendererInterface {
            public function __construct(private array &$log, private readonly bool $fail)
            {
            }

            public function render(array $source, array $arguments)
            {
                $text = end($source);
                if ($text === 'Additional Information' && ($this->log['store'] ?? null) === 2) {
                    if ($this->fail) {
                        throw new \RuntimeException('translation failed');
                    }
                    $this->log[] = 'translate in store 2';
                    return 'Informazioni Aggiuntive';
                }
                return $text;
            }
        };
    }
}
