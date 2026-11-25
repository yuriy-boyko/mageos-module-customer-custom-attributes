<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Observer;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Checkout\CustomerCheckoutAttributes;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Model\OptionLabels;
use MageOS\CustomerCustomAttributes\Observer\QuoteSubmitBefore;
use MageOS\CustomerCustomAttributes\Service\SnapshotRowBuilder;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueLabelResolver;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Attribute;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;

/**
 * The customer values of an order: every user-defined attribute with a value gets a snapshot row, except those switched off for
 * orders ("Save on Orders").
 */
class QuoteSubmitBeforeTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $rulesByCode validate rules of every attribute, by code
     * @param array<string, string> $values the values the customer has
     * @param string[] $checkoutCodes attributes shown in the checkout
     * @param string[] $messages what the checkout validation reports
     * @param array<string, string> $quoteData data on the quote (the admin order create form puts customer_<code> there)
     * @return list<array<string, mixed>> the snapshot rows put on the order
     */
    private function rows(array $rulesByCode, array $values, array $checkoutCodes = [], array $messages = [], string $area = Area::AREA_FRONTEND, array $quoteData = []): array
    {
        $attributes = [];
        foreach ($rulesByCode as $code => $rules) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getFrontendInput')->willReturn('text');
            $attribute->method('getStoreLabel')->willReturn('Label ' . $code);
            $attribute->method('getValidateRules')->willReturn($rules);
            $attributes[$code] = $attribute;
        }
        $provider = $this->createStub(CustomerAttributeProvider::class);
        $provider->method('getUserDefined')->willReturn($attributes);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturnCallback(function (string $code) use ($values) {
            if (!isset($values[$code])) {
                return null;
            }
            $attribute = $this->createStub(AttributeInterface::class);
            $attribute->method('getValue')->willReturn($values[$code]);
            return $attribute;
        });
        $repository = $this->createStub(CustomerRepositoryInterface::class);
        $repository->method('getById')->willReturn($customer);

        $visibility = $this->createStub(VisibilityService::class);
        $visibility->method('dropHidden')->willReturnArgument(1);
        $checkout = $this->createStub(CustomerCheckoutAttributes::class);
        $checkout->method('getCodes')->willReturn($checkoutCodes);
        $checkout->method('getOrderValues')->willReturn([]);
        $checkout->method('validate')->willReturn($messages);
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);

        $observer = new QuoteSubmitBefore(
            $provider,
            $repository,
            new SnapshotRowBuilder(new ValueLabelResolver()),
            new ValueNormalizer(),
            new OptionLabels(),
            $visibility,
            $checkout,
            $state,
            $modules,
            new ValidateRulesBuilder()
        );
        $order = new DataObject(['store_id' => 1]);
        // the quote's customer fields are magic getters: a real quote without its constructor reads them from its data
        $quote = (new \ReflectionClass(Quote::class))->newInstanceWithoutConstructor();
        $quote->setData(['customer_id' => 7, 'customer_group_id' => 1] + $quoteData);
        $observer->execute(new Observer(['event' => new Event(['order' => $order, 'quote' => $quote])]));

        return (array) $order->getData(QuoteSubmitBefore::DATA_KEY);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed> value by attribute code
     */
    private function valuesOf(array $rows): array
    {
        return array_column($rows, 'value', 'attribute_code');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return string[]
     */
    private function codes(array $rows): array
    {
        $codes = array_map(static fn (array $row): string => (string) $row['attribute_code'], $rows);
        sort($codes);
        return $codes;
    }

    public function testEveryAttributeWithAValueGetsARowByDefault(): void
    {
        $rows = $this->rows(['a' => [], 'b' => ['input_validation' => 'email'], 'c' => []], ['a' => '1', 'b' => 'x@example.com']);

        self::assertSame(['a', 'b'], $this->codes($rows), 'no value, no row');
    }

    public function testAnAttributeSwitchedOffForOrdersLeavesNoRow(): void
    {
        $rules = [
            'kept_a' => [],
            'internal_a' => [ValidateRulesBuilder::ORDER_SNAPSHOT_KEY => 0],
            'kept_b' => [ValidateRulesBuilder::ORDER_SNAPSHOT_KEY => 1],
            'internal_b' => [ValidateRulesBuilder::ORDER_SNAPSHOT_KEY => '0'],
            'internal_c' => [ValidateRulesBuilder::ORDER_SNAPSHOT_KEY => 0],
        ];
        $values = ['kept_a' => '1', 'internal_a' => '2', 'kept_b' => '3', 'internal_b' => '4', 'internal_c' => '5'];

        self::assertSame(['kept_a', 'kept_b'], $this->codes($this->rows($rules, $values)));
    }

    public function testTheCheckoutValidationStillRunsForAnAttributeSwitchedOffForOrders(): void
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('"Label internal" is a required value.');

        $this->rows(
            ['internal' => [ValidateRulesBuilder::ORDER_SNAPSHOT_KEY => 0]],
            [],
            ['internal'],
            ['"Label internal" is a required value.']
        );
    }

    public function testTheAdminOrderFormValuesOnTheQuoteWinInTheAdmin(): void
    {
        $rows = $this->rows(['a' => []], ['a' => 'account'], [], [], Area::AREA_ADMINHTML, ['customer_a' => 'typed in the admin form']);

        self::assertSame(['a' => 'typed in the admin form'], $this->valuesOf($rows));
    }

    public function testCustomerDataOnTheQuoteIsIgnoredOutsideTheAdmin(): void
    {
        // customer_note and the like are fields of the cart that the customer sets through the API
        foreach ([Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST, Area::AREA_WEBAPI_SOAP, 'graphql'] as $area) {
            $rows = $this->rows(['note' => []], ['note' => 'account value'], [], [], $area, ['customer_note' => 'typed by the shopper in the cart']);

            self::assertSame(['note' => 'account value'], $this->valuesOf($rows), $area);
        }
    }
}
