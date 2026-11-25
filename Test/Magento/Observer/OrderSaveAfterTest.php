<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Observer;

use MageOS\CustomerCustomAttributes\Model\ResourceModel\OrderValueStorage;
use MageOS\CustomerCustomAttributes\Observer\OrderLoadAfter;
use MageOS\CustomerCustomAttributes\Observer\OrderSaveAfter;
use MageOS\CustomerCustomAttributes\Observer\QuoteSubmitBefore;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

/**
 * The rows collected at order placement are written once; the saved order then carries them like a loaded order (the new order email
 * is rendered from this same object).
 */
class OrderSaveAfterTest extends TestCase
{
    private function observer(DataObject $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order])]);
    }

    public function testTheRowsAreWrittenAndStayOnTheOrderUnderTheLoadKey(): void
    {
        $rows = [['attribute_code' => 'a', 'value' => 'x']];
        $saved = [];
        $storage = $this->createStub(OrderValueStorage::class);
        $storage->method('save')->willReturnCallback(function (int $orderId, array $given) use (&$saved): void {
            $saved = [$orderId, $given];
        });
        $order = new DataObject(['id' => 7, QuoteSubmitBefore::DATA_KEY => $rows]);

        (new OrderSaveAfter($storage))->execute($this->observer($order));

        self::assertSame([7, $rows], $saved);
        self::assertNull($order->getData(QuoteSubmitBefore::DATA_KEY), 'written only once');
        self::assertSame($rows, $order->getData(OrderLoadAfter::DATA_KEY));
    }

    public function testNothingIsWrittenWithoutRowsOrOrderId(): void
    {
        $calls = 0;
        $storage = $this->createStub(OrderValueStorage::class);
        $storage->method('save')->willReturnCallback(function () use (&$calls): void {
            $calls++;
        });
        $observer = new OrderSaveAfter($storage);
        $observer->execute($this->observer(new DataObject(['id' => 7, QuoteSubmitBefore::DATA_KEY => []])));
        $observer->execute($this->observer(new DataObject([QuoteSubmitBefore::DATA_KEY => [['attribute_code' => 'a']]])));
        self::assertSame(0, $calls);
    }
}
