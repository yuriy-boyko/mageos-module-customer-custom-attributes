<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * The decisions behind "Customer Cannot Change This Value" (a customer attribute only the admin, an integration or the code
 * of the shop may write). The setting is the key mageos_admin_only in the validate rules; a missing key means the customer may
 * change the value (the behaviour of core).
 *
 * The value is protected where core saves the customer, whoever sent it: the storefront registration and account edit posts, and
 * the API calls made with a customer token or without a token (REST, SOAP, GraphQL). Everything else writes freely: the admin,
 * integration and admin tokens, imports, the command line, setup scripts.
 *
 * Pure PHP, no Magento imports (the user type numbers are those of Magento\Authorization\Model\UserContextInterface).
 */
class AdminOnlyAccess
{
    public const USER_TYPE_CUSTOMER = 3;
    public const USER_TYPE_GUEST = 4;

    /** areas of the API entry points (Magento\Framework\App\Area) */
    private const API_AREAS = ['webapi_rest', 'webapi_soap', 'graphql'];

    /** storefront form posts that save the customer (lower case full action names) */
    private const FORM_ACTIONS = ['customer_account_createpost', 'customer_account_editpost'];

    /** forms of the storefront account pages: an admin-only attribute is never printed there */
    private const ACCOUNT_FORMS = ['customer_account_create', 'customer_account_edit'];

    /**
     * @param array<string, mixed> $rules the validate rules of the attribute
     */
    public function isAdminOnly(array $rules): bool
    {
        return !empty($rules[ValidateRulesBuilder::ADMIN_ONLY_KEY]);
    }

    /**
     * Whether a customer save in this context is one the customer (or a visitor) sends, so the values of admin-only attributes
     * must not change.
     *
     * @param string|null $area the area code, null when none is set (the command line, a setup script)
     * @param int|null $userType the user type of the API call (UserContextInterface), null when unknown
     */
    public function appliesTo(?string $area, ?int $userType, string $fullAction, string $httpMethod): bool
    {
        if ($area === 'frontend') {
            return strtoupper($httpMethod) === 'POST' && in_array(strtolower($fullAction), self::FORM_ACTIONS, true);
        }
        if ($area !== null && in_array($area, self::API_AREAS, true)) {
            return $userType === self::USER_TYPE_CUSTOMER || $userType === self::USER_TYPE_GUEST;
        }
        return false;
    }

    /**
     * Required and admin-only cannot go together in a form the customer fills in: the field is not printed, so the customer could never
     * satisfy the requirement.
     *
     * @param string[] $forms the forms the attribute is in
     */
    public function conflictsWithRequired(bool $adminOnly, bool $required, array $forms): bool
    {
        return $adminOnly && $required && array_intersect(self::ACCOUNT_FORMS, $forms) !== [];
    }
}
