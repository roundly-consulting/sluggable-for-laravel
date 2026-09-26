<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

/**
 * Shops/posts/advertisements style: getAttributeValue() resolves locale-map keys to the CURRENT
 * locale string. Sluggable must read the raw maps anyway.
 */
class AccessorPage extends LocalizedPage
{
    public function getAttributeValue($key): mixed
    {
        if (in_array($key, ['name', 'slug'], true)) {
            $map = parent::getAttributeValue($key);

            return is_array($map) ? ($map[app()->getLocale()] ?? null) : $map;
        }

        return parent::getAttributeValue($key);
    }
}
