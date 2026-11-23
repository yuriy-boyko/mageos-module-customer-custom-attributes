<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\AddressAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Model\Dependency\VisibilityService;
use MageOS\CustomerCustomAttributes\Service\EntityDefinitions;
use MageOS\CustomerCustomAttributes\Service\ValueNormalizer;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\CustomAttributesDataInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The storefront account forms hide (and disable) the fields of attributes whose dependency rule does not match or whose customer
 * group restriction excludes the customer, so such values are not sent. A value posted anyway (a field enabled again in the
 * browser, or a hand-made request) is not saved: the attribute keeps the value it had before. Applies to the registration,
 * account information and address book forms only (frontend area, see etc/frontend/di.xml); other saves are not touched, so a
 * value the admin set for an attribute the customer cannot see stays.
 *
 * Registered on CustomerRepositoryInterface::save() and AddressRepositoryInterface::save(); plugin methods are matched by name,
 * so one untyped method serves both.
 */
class StorefrontFormHiddenValues
{
    /** full action name (lower case) => entity of the form */
    private const FORM_ACTIONS = [
        'customer_account_createpost' => EntityDefinitions::CUSTOMER,
        'customer_account_editpost' => EntityDefinitions::CUSTOMER,
        'customer_address_formpost' => EntityDefinitions::ADDRESS,
    ];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerAttributeProvider $customerAttributes,
        private readonly AddressAttributeProvider $addressAttributes,
        private readonly VisibilityService $visibility,
        private readonly ValueNormalizer $normalizer,
        private readonly CustomerSession $customerSession,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param CustomerRepositoryInterface|AddressRepositoryInterface $subject
     * @param CustomerInterface|AddressInterface $entity
     * @param mixed ...$rest the password hash of CustomerRepositoryInterface::save()
     * @return array<int, mixed>
     */
    public function beforeSave($subject, $entity, ...$rest): array
    {
        $formEntity = $this->formEntity();
        try {
            if ($formEntity === EntityDefinitions::CUSTOMER && $entity instanceof CustomerInterface && $subject instanceof CustomerRepositoryInterface) {
                $groupId = $entity->getGroupId() !== null ? (int) $entity->getGroupId() : $this->defaultGroupId();
                $stored = $entity->getId() ? $subject->getById((int) $entity->getId()) : null;
                $this->restoreHidden(EntityDefinitions::CUSTOMER, $entity, $stored, $groupId);
            } elseif ($formEntity === EntityDefinitions::ADDRESS && $entity instanceof AddressInterface && $subject instanceof AddressRepositoryInterface) {
                $groupId = $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerGroupId() : $this->defaultGroupId();
                $stored = $entity->getId() ? $subject->getById((int) $entity->getId()) : null;
                $this->restoreHidden(EntityDefinitions::ADDRESS, $entity, $stored, $groupId);
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            // a new entity: nothing stored to keep
        }
        return array_merge([$entity], $rest);
    }

    /**
     * Gives every posted attribute that is hidden for the values being saved its stored value back (none for a new entity).
     */
    private function restoreHidden(string $entityKey, CustomAttributesDataInterface $entity, ?CustomAttributesDataInterface $stored, ?int $groupId): void
    {
        $attributes = $entityKey === EntityDefinitions::CUSTOMER
            ? $this->customerAttributes->getUserDefined()
            : $this->addressAttributes->getUserDefined();
        $posted = (array) $this->request->getPostValue();
        if ($attributes === [] || $posted === []) {
            return;
        }
        $values = [];
        foreach ($attributes as $code => $attribute) {
            $values[$code] = $this->normalizer->normalizeForInput(
                (string) $attribute->getFrontendInput(),
                $entity->getCustomAttribute($code)?->getValue()
            );
        }
        $visible = $this->visibility->getVisibleCodes($entityKey, array_keys($values), $values, $groupId);
        foreach (array_diff(array_keys($values), $visible) as $hidden) {
            if (!array_key_exists($hidden, $posted)) {
                continue;
            }
            $entity->setCustomAttribute($hidden, $stored?->getCustomAttribute($hidden)?->getValue());
        }
    }

    private function formEntity(): ?string
    {
        if (!method_exists($this->request, 'getFullActionName') || strtoupper((string) $this->request->getMethod()) !== 'POST') {
            return null;
        }
        return self::FORM_ACTIONS[strtolower((string) $this->request->getFullActionName())] ?? null;
    }

    private function defaultGroupId(): int
    {
        return (int) $this->scopeConfig->getValue('customer/create_account/default_group', ScopeInterface::SCOPE_STORE);
    }
}
