<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/sluggable-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=sluggable-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/sluggable-for-laravel/main/art/hero.png" alt="Sluggable for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/sluggable-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/sluggable-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/sluggable-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/sluggable-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/sluggable-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/sluggable-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=sluggable-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Sluggable for Laravel

Single- and multi-language slugs for Eloquent: plain string slug columns and JSON locale-map
columns, scoped per-locale uniqueness backed by engine-native unique indexes, locale-aware route
model binding, validation rules and an optional slug history with 301 redirects. With the
defaults, ordinary input slugs exactly like `Str::slug()`, so adopting it keeps the slugs you
already have.

## Installation

Requires PHP 8.4 and Laravel 12 or 13. Supported databases: PostgreSQL 12+ and MySQL 8.0.23+ /
MariaDB 10.3.3+ (SQLite 3.38+ for tests). SQL Server is not supported.

```bash
composer require roundly-consulting/sluggable-for-laravel
```

## Usage

Add the trait (zero config: `slug` is generated from `name`) and the column:

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;

final class Clinic extends Model implements Sluggable
{
    use HasSlug;

    protected $fillable = ['name', 'slug'];
}

// in the migration
$table->slug();          // string('slug', 255)
$table->uniqueSlug();    // its unique index
```

Every save gets a unique slug, and it stays put when the name changes, so links never break:

```php
use RoundlyConsulting\Sluggable\Facades\Slugs;

Clinic::create(['name' => 'Happy Paws'])->slug;      // "happy-paws"
Clinic::create(['name' => 'Happy Paws'])->slug;      // "happy-paws-2"

Route::get('/clinics/{clinic:slug}', ShowClinic::class);

Slugs::regenerate($clinic);                          // recompute from the new name and save
Slugs::slugify('Žltý kôň @ home', language: 'sk');   // "zlty-kon-at-home"
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/sluggable-for-laravel](https://roundly-consulting.com/open-source/docs/sluggable-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=sluggable-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=sluggable-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=sluggable-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
