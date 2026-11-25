<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Observer;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Observer\AddAdditionalInformationToOrderEmail;
use MageOS\CustomerCustomAttributes\Observer\OrderLoadAfter;
use MageOS\CustomerCustomAttributes\Observer\QuoteSubmitBefore;
use MageOS\CustomerCustomAttributes\Service\AdditionalInformation;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use Magento\Customer\Model\Attribute;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use PHPUnit\Framework\TestCase;

/**
 * The new order email variable `mageos_additional_information_html`: the section of the attributes set to "Show in Order Email".
 */
class AddAdditionalInformationToOrderEmailTest extends TestCase
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
    private function variable(object $sender, array $rulesByCode, array $orderData): ?string
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
        (new AddAdditionalInformationToOrderEmail($provider, new ValidateRulesBuilder(), new AdminOnlyAccess(), new AdditionalInformation()))
            ->execute(new Observer(['sender' => $sender, 'transportObject' => $transport]));

        return $transport->getData(AdditionalInformation::VARIABLE);
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
}
