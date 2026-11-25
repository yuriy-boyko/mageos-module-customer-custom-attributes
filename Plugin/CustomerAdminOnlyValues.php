<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Plugin;

use MageOS\CustomerCustomAttributes\Model\CustomerAttributeProvider;
use MageOS\CustomerCustomAttributes\Service\AdminOnlyAccess;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * "Customer Cannot Change This Value": core accepts every user-defined custom attribute in the customer that a customer sends, in the
 * registration and account edit posts and in the API (REST PUT /V1/customers/me, GraphQL). A value of an admin-only attribute in such a
 * save is replaced by the stored one (removed for a new customer). Saves of the admin, integrations, imports and the command line are
 * not touched (AdminOnlyAccess decides).
 */
class CustomerAdminOnlyValues
{
    public function __construct(
        private readonly CustomerAttributeProvider $attributes,
        private readonly AdminOnlyAccess $access,
        private readonly State $appState,
        private readonly RequestInterface $request,
        private readonly UserContextInterface $userContext
    ) {
    }

    /**
     * @param mixed ...$rest the password hash of CustomerRepositoryInterface::save()
     * @return array<int, mixed>
     */
    public function beforeSave(CustomerRepositoryInterface $subject, CustomerInterface $customer, mixed ...$rest): array
    {
        $flagged = $this->flaggedCodes($customer);
        if ($flagged === [] || !$this->access->appliesTo($this->area(), $this->userType(), $this->action(), $this->method())) {
            return array_merge([$customer], $rest);
        }

        $stored = null;
        if ($customer->getId()) {
            try {
                $stored = $subject->getById((int) $customer->getId());
            } catch (NoSuchEntityException|LocalizedException) {
                $stored = null;
            }
        }
        foreach ($flagged as $code) {
            $customer->setCustomAttribute($code, $stored?->getCustomAttribute($code)?->getValue());
        }
        return array_merge([$customer], $rest);
    }

    /**
     * The admin-only attributes the customer being saved carries a value for (nothing to protect in the others).
     *
     * @return string[]
     */
    private function flaggedCodes(CustomerInterface $customer): array
    {
        $codes = [];
        foreach ($this->attributes->getUserDefined() as $code => $attribute) {
            if ($this->access->isAdminOnly((array) $attribute->getValidateRules()) && $customer->getCustomAttribute($code) !== null) {
                $codes[] = (string) $code;
            }
        }
        return $codes;
    }

    private function area(): ?string
    {
        try {
            return $this->appState->getAreaCode();
        } catch (LocalizedException) {
            return null;
        }
    }

    private function userType(): ?int
    {
        $type = $this->userContext->getUserType();
        return $type === null ? null : (int) $type;
    }

    private function action(): string
    {
        return method_exists($this->request, 'getFullActionName') ? (string) $this->request->getFullActionName() : '';
    }

    private function method(): string
    {
        return method_exists($this->request, 'getMethod') ? (string) $this->request->getMethod() : '';
    }
}
