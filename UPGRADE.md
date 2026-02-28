# Upgrade guide

## From timurturdyev/simple-search 0.1 to timurturdyev/ariadne 0.2

The package is renamed. Besides the names, 0.2 brings the screen cache fix for Laravel 13 and one flag that is off by default, so an application that changes only the names behaves as before. A constraint on `^0.1` of the old name never picks this up: the old name stays at 0.1, the new one starts at 0.2.

### Package

Swap the requirement without running anything yet, so the application never boots half renamed:

```bash
composer remove timurturdyev/simple-search --no-update
composer require timurturdyev/ariadne:^0.2 --no-update
```

The old name may also come through another package: `composer why timurturdyev/simple-search` lists who requires it. Then `composer remove` only warns that the package is not required in your `composer.json`, and the update would install ariadne next to simple-search 0.1. That makes two sets of classes, and an object of one never passes where the other is expected. Raise such a package to its release on ariadne the same way, `composer require vendor/package:<version> --no-update`, and add its name to the update below.

Take the steps below, then install:

```bash
composer update timurturdyev/simple-search timurturdyev/ariadne
```

### Namespace

`TimurTurdyev\SimpleSearch\` becomes `TimurTurdyev\Ariadne\` in every `use` line, docblock and class string, and `SimpleSearchServiceProvider` becomes `AriadneServiceProvider`. Seeders, Blade views, a `src` folder and root files name the classes too, so rewrite the whole repository rather than a list of folders. `git grep` reads tracked and new files and skips `vendor` and everything else ignored; the lock file is left to Composer:

```bash
git grep -lzIE --untracked 'TimurTurdyev\\+SimpleSearch|SimpleSearchServiceProvider' -- ':(exclude)composer.lock' \
    | xargs -0 perl -pi -e 's/TimurTurdyev(\\+)SimpleSearch/TimurTurdyev$1Ariadne/g; s/SimpleSearchServiceProvider/AriadneServiceProvider/g'
```

The pattern takes single and doubled backslashes alike. Then look for what is left, config keys such as `config('simple-search.url.sorts')` above all, which become `config('ariadne.url.sorts')`:

```bash
git grep -nIiE --untracked 'simple[-_]?search' -- ':(exclude)composer.lock'
```

### Provider

Laravel discovers the provider by itself. If you register it by hand, in `bootstrap/providers.php` or `config/app.php`, the class is now `TimurTurdyev\Ariadne\AriadneServiceProvider`, and the rewrite above has renamed it already. A `dont-discover` entry under `extra.laravel` in your `composer.json` holds the package name, not the class: change `timurturdyev/simple-search` there to `timurturdyev/ariadne`, or Laravel discovers the provider again, on top of your own registration. The check above lists that entry.

### Config

The published file is renamed, its keys stay as they are:

```bash
git mv config/simple-search.php config/ariadne.php
```

The package reads `config/ariadne.php` under the key `ariadne` and nothing else. A forgotten old file would be ignored, and the package would quietly run on its defaults, so in the console (artisan, a deploy, the `package:discover` script of Composer) the provider stops with a `RuntimeException` while `config/simple-search.php` exists and `config/ariadne.php` does not. Once the new file is there, the old one is not looked at; delete it.

The publish tag is `ariadne-config`:

```bash
php artisan vendor:publish --tag=ariadne-config
```

0.2 adds one key, `screen.palette_on_price`. A config published from 0.1 does not have it, so the flag stays off.

### Screen cache

The keys move from `simple-search:screen:v1:` to `ariadne:screen:v2:`, and a screen is stored as a `serialize()` string read back with the classes of the package alone. Old entries are never read again and expire within ten minutes; every screen is built anew once, so expect one cold start after the deploy. With a cache, the attributes, payload and extra of a `Hit` must hold arrays and scalars only: an object of any other class comes back as `__PHP_Incomplete_Class`.

### Manticore rebuilds

`SwapCopies` and `AttachShadow` take their locks as `ariadne.rebuild.<name>` instead of `simple-search.rebuild.<name>`, so a rebuild on 0.2 does not see the lock of one still running on 0.1. Do not deploy in the middle of a rebuild, or pass `lockPrefix: 'simple-search.rebuild.'` until the old one is over. Pointers and tables of the publications stay as they are.

### Live tests

The live Manticore tests of the package read `ARIADNE_MANTICORE` instead of `SIMPLE_SEARCH_MANTICORE` and create tables named `ariadne_it_*` instead of `simple_search_it_*`. Leftovers with the old prefix on the test instance are not dropped any more; drop them by hand.

### Behaviour

`QueryPlan::paletteKey()` follows the planned intents. A results-only query with a selection plans no palette, and there the key is now `main` instead of `palette`; screens with a panel get the same key as before.
