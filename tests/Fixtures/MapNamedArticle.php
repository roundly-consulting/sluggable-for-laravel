<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

/** A STRING slug whose `name` source is a locale map (advertisements' Placement shape). */
class MapNamedArticle extends Article
{
    protected $casts = ['name' => 'array'];
}
