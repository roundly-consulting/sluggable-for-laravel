<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

final class InvalidLocaleException extends SluggableException
{
    public static function malformed(string $locale): self
    {
        // The value is echoed only after being length-capped: it is attacker-controlled input.
        return new self(sprintf('The locale [%s] is not a valid locale identifier.', mb_substr($locale, 0, 40)));
    }
}
