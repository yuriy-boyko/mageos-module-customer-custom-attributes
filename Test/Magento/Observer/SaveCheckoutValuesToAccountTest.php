<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Test\Magento\Observer;

use MageOS\CustomerCustomAttributes\Model\Checkout\CustomerCheckoutAttributes;
use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Observer\SaveCheckoutValuesToAccount;
use MageOS\CustomerCustomAttributes\Service\AccountSaveDecider;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use MageOS\CustomerCustomAttributes\Service\ValidateRulesBuilder;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Customer\Model\Attribute;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * After the order: the values typed in the checkout of the attributes set to "Also Save to Customer Account" are written to the
 * account of a logged-in customer, one attribute at a time. A failure is logged and stops nothing.
 */
class SaveCheckoutValuesToAccountTest extends TestCase
{
    private const ON = [ValidateRulesBuilder::SAVE_TO_ACCOUNT_KEY => 1];

    /** @var string[] the codes the resource was asked to save */
    private array $saved = [];
    /** @var string[] the log messages */
    private array $errors = [];
    private int $reindexed = 0;
    private Customer $customer;

    /**
     * @param array<string, array<string, mixed>> $rulesByCode attributes shown in the checkout
     * @param array<string, string> $typed what the customer typed
     * @param array<string, string> $account what the account has
     * @param string[] $failing codes whose save throws
     */
    private function execute(array $rulesByCode, array $typed, array $account, int $customerId = 7, string $area = Area::AREA_FRONTEND, array $failing = []): void
    {
        $this->saved = [];
        $this->errors = [];
        $this->reindexed = 0;
        $attributes = [];
        foreach ($rulesByCode as $code => $rules) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getValidateRules')->willReturn($rules);
            $attributes[$code] = $attribute;
        }
        $provider = $this->createStub(CustomerAttributeProvider::class);
        $provider->method('getUserDefined')->willReturn($attributes);
        $checkout = $this->createStub(CustomerCheckoutAttributes::class);
        $checkout->method('getCodes')->willReturn(array_keys($rulesByCode));
        $checkout->method('getOrderValues')->willReturn($typed);

        // a customer that keeps its data like the model and answers load, getId and reindex without a database
        $test = $this;
        $this->customer = new class ($customerId, $test) extends Customer {
            public function __construct(private readonly int $fakeId, private readonly SaveCheckoutValuesToAccountTest $test)
            {
            }

            public function load($modelId, $field = null)
            {
                return $this;
            }

            public function getId()
            {
                return $this->fakeId;
            }

            public function reindex()
            {
                $this->test->countReindex();
            }
        };
        $this->customer->setData($account);
        $factory = $this->createStub(CustomerFactory::class);
        $factory->method('create')->willReturn($this->customer);

        $resource = $this->createStub(CustomerResource::class);
        $resource->method('saveAttribute')->willReturnCallback(function ($object, $code) use ($resource, $failing) {
            if (in_array($code, $failing, true)) {
                throw new \RuntimeException('save failed');
            }
            $this->saved[] = $code . '=' . $object->getData($code);
            return $resource;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) {
            $this->errors[] = (string) $message;
        });
        $state = $this->createStub(State::class);
        $state->method('getAreaCode')->willReturn($area);

        $quote = (new \ReflectionClass(Quote::class))->newInstanceWithoutConstructor();
        $quote->setData(['customer_id' => $customerId]);

        (new SaveCheckoutValuesToAccount(
            $provider,
            $checkout,
            new AccountSaveDecider(),
            new ValidateRulesBuilder(),
            new AdminOnlyAccess(),
            new ValueNormalizer(),
            $factory,
            $resource,
            $state,
            $logger
        ))->execute(new Observer(['event' => new Event(['quote' => $quote, 'order' => new \Magento\Framework\DataObject()])]));
    }

    public function countReindex(): void
    {
        $this->reindexed++;
    }

    public function testATypedValueIsWrittenToTheAccountAndTheGridRowRefreshed(): void
    {
        $this->execute(['tax' => self::ON, 'plain' => []], ['tax' => 'IT123', 'plain' => 'not saved'], ['tax' => 'old']);

        self::assertSame(['tax=IT123'], $this->saved);
        self::assertSame(1, $this->reindexed);
        self::assertSame([], $this->errors);
    }

    public function testOnlyTheChangedValuesAreWritten(): void
    {
        $rules = ['tax' => self::ON, 'same' => self::ON, 'empty' => self::ON];
        $this->execute($rules, ['tax' => 'new', 'same' => 'kept', 'empty' => ''], ['tax' => 'old', 'same' => 'kept', 'empty' => 'stored']);

        self::assertSame(['tax=new'], $this->saved, 'the unchanged and the empty value are not written');
        self::assertSame('stored', $this->customer->getData('empty'), 'an empty value never erases the stored one');
    }

    public function testNothingToWriteMeansNoSaveAndNoReindex(): void
    {
        $this->execute(['tax' => self::ON], ['tax' => 'same'], ['tax' => 'same']);

        self::assertSame([], $this->saved);
        self::assertSame(0, $this->reindexed);
    }

    public function testGuestsAndTheAdminAreNotCovered(): void
    {
        $this->execute(['tax' => self::ON], ['tax' => 'x'], [], 0);
        self::assertSame([], $this->saved, 'a guest');

        $this->execute(['tax' => self::ON], ['tax' => 'x'], [], 7, Area::AREA_ADMINHTML);
        self::assertSame([], $this->saved, 'the admin order create page does not write to the account');

        $this->execute(['tax' => self::ON], ['tax' => 'x'], [], 7, Area::AREA_WEBAPI_REST);
        self::assertSame([], $this->saved, 'an API order');
    }

    public function testAnAdminOnlyAttributeIsNeverWritten(): void
    {
        $this->execute(['internal' => self::ON + [ValidateRulesBuilder::ADMIN_ONLY_KEY => 1]], ['internal' => 'forced'], ['internal' => 'by admin']);

        self::assertSame([], $this->saved);
    }

    public function testAFailureIsLoggedAndTheOtherAttributesAreStillSaved(): void
    {
        $this->execute(['first' => self::ON, 'second' => self::ON], ['first' => 'a', 'second' => 'b'], [], 7, Area::AREA_FRONTEND, ['first']);

        self::assertSame(['second=b'], $this->saved);
        self::assertCount(1, $this->errors);
        self::assertStringContainsString('"first"', $this->errors[0]);
        self::assertSame(1, $this->reindexed, 'the grid row is still refreshed');
    }
}
