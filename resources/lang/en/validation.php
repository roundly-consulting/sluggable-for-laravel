<?php

declare(strict_types=1);

return [
    'unique' => 'The :attribute has already been taken.',
    'unique_locale' => 'The :attribute (:locale) has already been taken.',
    'format' => 'The :attribute must be a valid slug (lowercase letters, numbers and separators).',
    'too_long' => 'The :attribute may not be longer than :max characters.',
    'reserved' => 'The :attribute is reserved and cannot be used.',
    'locked' => 'The slug [:attribute] on [:model] is locked and cannot be changed; wrap the change in Slugs::unlocked() to override.',
];
