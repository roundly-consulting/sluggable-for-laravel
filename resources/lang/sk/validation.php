<?php

declare(strict_types=1);

return [
    'unique' => 'Hodnota poľa :attribute je už obsadená.',
    'unique_locale' => 'Hodnota poľa :attribute (:locale) je už obsadená.',
    'format' => 'Pole :attribute musí obsahovať platný slug (malé písmená, číslice a oddeľovače).',
    'too_long' => 'Pole :attribute môže mať najviac :max znakov.',
    'reserved' => 'Hodnota poľa :attribute je rezervovaná a nemožno ju použiť.',
    'locked' => 'Slug [:attribute] v modeli [:model] je uzamknutý a nemožno ho zmeniť; ak ho chcete zmeniť, vykonajte zmenu v rámci Slugs::unlocked().',
];
