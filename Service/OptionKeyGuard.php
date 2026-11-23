<?php
/**
 * Copyright (c) Yuriy Boyko <info@yuriy-boyko.com>
 * Licensed under the Open Software License version 3.0
 */
declare(strict_types=1);

namespace MageOS\CustomerCustomAttributes\Service;

/**
 * The option keys of a posted attribute form that point at options of another attribute. The EAV resource updates and deletes
 * options by option id only, so an id that does not belong to the attribute being saved would change someone else's option.
 * New options have keys such as "option_0"; every numeric key must be an option id of this attribute.
 *
 * Pure PHP, no Magento imports.
 */
class OptionKeyGuard
{
    /**
     * @param array<string, mixed> $options the posted "option" array: value, order and delete, each keyed by option id or new key
     * @param array<int|string> $ownIds the option ids of the attribute (none for a new attribute)
     * @return string[] the foreign ids, empty when every key is allowed
     */
    public static function foreignIds(array $options, array $ownIds): array
    {
        $own = array_map('strval', $ownIds);
        $foreign = [];
        foreach (['value', 'order', 'delete'] as $part) {
            foreach (array_keys(is_array($options[$part] ?? null) ? $options[$part] : []) as $key) {
                $key = (string) $key;
                if (ctype_digit($key) && !in_array($key, $own, true)) {
                    $foreign[$key] = $key;
                }
            }
        }
        return array_values($foreign);
    }
}
