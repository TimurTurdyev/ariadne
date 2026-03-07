<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;
use TimurTurdyev\Ariadne\Catalog\Attributes\Fields;
use TimurTurdyev\Ariadne\Catalog\Attributes\OneCardPer;
use TimurTurdyev\Ariadne\Catalog\Attributes\Price;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Url\QueryStringCodec;
use TimurTurdyev\Ariadne\Url\UrlFormat;
use TimurTurdyev\Ariadne\Url\UrlStyle;

/**
 * A catalog definition compiled once per process and checked as a whole; immutable, so safe to share.
 *
 * Compiling never touches the database: a build server without one can still run it.
 */
final readonly class CatalogSpec
{
    public const DRIVERS = ['database'];

    /** Every key of a catalog entry with its default; a published entry that lacks a key keeps the default. */
    private const DEFAULTS = [
        'definition' => null,
        'driver' => 'database',
        'per_page' => 24,
        'keep_zeros' => true,
        'min_values' => 2,
        'min_coverage' => 0.0,
        'max_groups' => null,
        'filtered_bounds' => false,
        'style' => 'comma',
        'carry' => [],
        'cache' => null,
        'slug_language' => null,
    ];

    private const NAME = '/^[a-z][a-z0-9_]{0,23}$/';

    private const COLUMN = '/^[a-z_][a-z0-9_]*$/i';

    /** PostgreSQL cuts identifiers at 63 bytes; the longest name is the index of a generation. */
    private const MAX_IDENTIFIER = 63;

    /**
     * @param  CatalogDefinition<Model>  $definition
     * @param  array<string, array{kind: 'column'|'belongs-to'|'belongs-to-many', column: string, pivot?: string, related?: string, foreign?: string}>  $fields
     * @param  list<Facet>  $facets  declared and dynamic, in panel order
     * @param  array<string, (Closure(mixed): mixed)|null>  $relations  what a rebuild eager loads: the with() of every facet
     * @param  list<Sort>  $sorts
     * @param  list<string>  $carry  foreign parameters panel links keep
     * @param  list<string>  $databaseOnly  what keeps the catalog off the manticore driver
     */
    public function __construct(
        public string $name,
        public CatalogDefinition $definition,
        public Model $model,
        public ?string $connection,
        public string $table,
        public string $key,
        public ?string $priceColumn,
        public int $priceUnit,
        public ?string $cardColumn,
        public array $fields,
        public array $facets,
        public array $relations,
        public array $sorts,
        public string $defaultSort,
        public UrlFormat $format,
        public QueryStringCodec $codec,
        public int $perPage,
        public bool $keepZeros,
        public int $minValues,
        public float $minCoverage,
        public ?int $maxGroups,
        public bool $filteredBounds,
        public array $carry,
        public ?string $cache,
        public string $slugLanguage,
        public string $prefix,
        public array $databaseOnly,
    ) {}

    /**
     * @param  mixed  $entry  a definition class or an array with "definition" and the keys of DEFAULTS
     * @param  string  $prefix  prefix of the facet tables
     * @param  string  $locale  slug language when the entry names none
     *
     * @throws InvalidCatalog
     */
    public static function compile(string $name, mixed $entry, string $prefix, string $locale, Container $app): self
    {
        $problems = [];
        $options = is_string($entry) ? ['definition' => $entry] : (is_array($entry) ? $entry : null);

        if ($options === null) {
            throw new InvalidCatalog($name, ["config ariadne.catalogs.{$name} must be a definition class or an array with \"definition\""]);
        }

        self::unknownKeys("ariadne.catalogs.{$name}", $options, array_keys(self::DEFAULTS), $problems);
        $options = array_replace(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));

        if (preg_match(self::NAME, $name) !== 1) {
            $problems[] = "catalog key \"{$name}\" names its tables: use a letter, then up to 23 lowercase letters, digits and _";
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,19}$/', $prefix) !== 1) {
            $problems[] = "ariadne.tables.prefix \"{$prefix}\" must be a letter, then up to 19 lowercase letters, digits and _";
        } elseif (strlen($prefix.'rows_'.$name.'_'.str_repeat('9', 10).'_value') > self::MAX_IDENTIFIER) {
            $problems[] = "table names of catalog \"{$name}\" with prefix \"{$prefix}\" exceed ".self::MAX_IDENTIFIER.' characters: shorten the key or the prefix';
        }

        $class = $options['definition'];

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, CatalogDefinition::class)) {
            throw new InvalidCatalog($name, [...$problems, '"definition" must name a class implementing '.CatalogDefinition::class]);
        }

        /** @var CatalogDefinition<Model> $definition */
        $definition = $app->make($class);
        $documents = $definition->documents();
        $model = $documents->getModel();

        if ($documents->getQuery()->columns !== null) {
            $problems[] = 'documents() must not select columns: cards are made from its rows';
        }

        if (($documents->getQuery()->joins ?? []) !== []) {
            $problems[] = 'documents() must not join tables: the columns of a card would collide';
        }

        if ($model->getKeyType() !== 'int') {
            $problems[] = class_basename($model).' has a '.$model->getKeyType().' key: catalog documents need integer keys';
        }

        $driver = $options['driver'];

        if ($driver === 'manticore') {
            $problems[] = 'driver "manticore" comes with 0.4; use "database"';
        } elseif (! is_string($driver) || ! in_array($driver, self::DRIVERS, true)) {
            $problems[] = 'driver must be "database", '.var_export($driver, true).' given';
        }

        $reflection = new ReflectionClass($class);
        $price = self::attribute($reflection, Price::class);
        $card = self::attribute($reflection, OneCardPer::class);
        $databaseOnly = [];

        if ($price !== null && preg_match(self::COLUMN, $price->column) !== 1) {
            $problems[] = "#[Price] column \"{$price->column}\" is not a column name";
        }

        if ($price !== null && $price->unit < 1) {
            $problems[] = "#[Price] unit must be a positive integer, {$price->unit} given";
        }

        if ($card !== null && preg_match(self::COLUMN, $card->column) !== 1) {
            $problems[] = "#[OneCardPer] column \"{$card->column}\" is not a column name";
        }

        $style = UrlStyle::tryFrom(is_string($options['style']) ? $options['style'] : '');

        if ($style === null) {
            $problems[] = 'style must be "comma" or "brackets", '.var_export($options['style'], true).' given';
            $style = UrlStyle::Comma;
        }

        [$sorts, $default] = self::sorts($definition->sorts(), $problems, $databaseOnly);
        $format = new UrlFormat(
            $style,
            max(1, $price->unit ?? 1),
            array_map(static fn (Sort $sort): string => $sort->slug, $sorts),
        );
        $codec = new QueryStringCodec($format);
        $carry = self::strings($options['carry'], 'carry', $problems);
        [$facets, $relations] = self::facets($definition->facets(), $model, $codec, $carry, $problems);
        $fields = self::fields(self::attribute($reflection, Fields::class)->names ?? [], $model, $problems);

        $perPage = self::int($options['per_page'], 'per_page', 1, 500, $problems) ?? 24;
        $minValues = self::int($options['min_values'], 'min_values', 1, 1000, $problems) ?? 2;
        $maxGroups = $options['max_groups'] === null ? null : self::int($options['max_groups'], 'max_groups', 1, 1000, $problems);
        $minCoverage = $options['min_coverage'];

        if (! is_int($minCoverage) && ! is_float($minCoverage) || $minCoverage < 0 || $minCoverage > 1) {
            $problems[] = 'min_coverage must be a share from 0 to 1, '.var_export($minCoverage, true).' given';
            $minCoverage = 0.0;
        }

        foreach (['keep_zeros', 'filtered_bounds'] as $flag) {
            if (! is_bool($options[$flag])) {
                $problems[] = "{$flag} must be true or false, ".var_export($options[$flag], true).' given';
            }
        }

        $cache = $options['cache'];

        if ($cache !== null && ! is_string($cache)) {
            $problems[] = 'cache must name a cache store or be null';
        }

        $language = $options['slug_language'] ?? $locale;

        if (! is_string($language) || $language === '') {
            $problems[] = 'slug_language must name a language, such as "ru"';
            $language = 'en';
        }

        if ($problems !== []) {
            throw new InvalidCatalog($name, $problems);
        }

        return new self(
            name: $name,
            definition: $definition,
            model: $model,
            connection: $model->getConnectionName(),
            table: $model->getTable(),
            key: $model->getKeyName(),
            priceColumn: $price?->column,
            priceUnit: max(1, $price->unit ?? 1),
            cardColumn: $card?->column,
            fields: $fields,
            facets: $facets,
            relations: $relations,
            sorts: $sorts,
            defaultSort: $default,
            format: $format,
            codec: $codec,
            perPage: $perPage,
            keepZeros: $options['keep_zeros'] === true,
            minValues: $minValues,
            minCoverage: (float) $minCoverage,
            maxGroups: $maxGroups,
            filteredBounds: $options['filtered_bounds'] === true,
            carry: $carry,
            cache: is_string($cache) ? $cache : null,
            slugLanguage: $language,
            prefix: $prefix,
            databaseOnly: $databaseOnly,
        );
    }

    /**
     * The declared facet of this slug; dynamic groups are not here, they live in the data.
     */
    public function declared(string $slug): ?Facet
    {
        foreach ($this->facets as $facet) {
            if ($facet->isDeclared() && $facet->slug === $slug) {
                return $facet;
            }
        }

        return null;
    }

    public function dynamic(string $name): ?Facet
    {
        foreach ($this->facets as $facet) {
            if ($facet->isDynamic() && $facet->slug === $name) {
                return $facet;
            }
        }

        return null;
    }

    public function sort(string $slug): ?Sort
    {
        foreach ($this->sorts as $sort) {
            if ($sort->slug === $slug) {
                return $sort;
            }
        }

        return null;
    }

    public function hasRelevance(): bool
    {
        foreach ($this->sorts as $sort) {
            if ($sort->relevance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a group slug from data cannot be a panel group, or null when it can: for slug generators and admin forms.
     */
    public function slugProblem(string $slug): ?string
    {
        try {
            $valid = FacetId::fromString($slug)->value === $slug;
        } catch (InvalidArgumentException) {
            $valid = false;
        }

        if (! $valid) {
            return 'not a slug: use lowercase letters, digits and single dashes, up to '.FacetId::MAX_LENGTH;
        }

        if ($this->declared($slug) !== null) {
            return 'taken by a facet the definition declares';
        }

        if (ctype_digit($slug)) {
            return 'the address cannot read it: PHP turns an all-digit parameter name into an integer key';
        }

        $param = $this->codec->param(FacetId::fromString($slug));

        if ($this->codec->isReserved($slug)) {
            return "the address cannot read it: the parameter \"{$param}\" belongs to the codec";
        }

        if (in_array($param, $this->carry, true)) {
            return "the parameter \"{$param}\" is carried as a foreign one";
        }

        return null;
    }

    /**
     * @param  list<mixed>  $declared
     * @param  list<string>  $problems
     * @param  list<string>  $databaseOnly
     * @return array{0: list<Sort>, 1: string}
     */
    private static function sorts(array $declared, array &$problems, array &$databaseOnly): array
    {
        $sorts = [];
        $seen = [];
        $default = '';

        foreach ($declared as $at => $sort) {
            if (! $sort instanceof Sort) {
                $problems[] = "sorts()[{$at}] must be a Sort, ".get_debug_type($sort).' given';

                continue;
            }

            if (preg_match('/^[a-z0-9]+([_-][a-z0-9]+)*$/', $sort->slug) !== 1) {
                $problems[] = "sort \"{$sort->slug}\" is not a slug: use lowercase letters, digits, - and _";
            }

            if (isset($seen[$sort->slug])) {
                $problems[] = "sort \"{$sort->slug}\" is declared twice";
            }

            $seen[$sort->slug] = true;

            if (trim($sort->label) === '') {
                $problems[] = "sort \"{$sort->slug}\" has no label";
            }

            $sorts[] = $sort;

            if ($sort->relevance) {
                continue;
            }

            if ($sort->slug === Sort::RELEVANCE) {
                $problems[] = 'sort "relevance" is reserved for Sort::relevance()';
            }

            if (trim($sort->orderBy) === '') {
                $problems[] = "sort \"{$sort->slug}\" has no ORDER BY";
            } elseif ($sort->isRaw()) {
                $databaseOnly[] = "sort \"{$sort->slug}\" is raw SQL";
            }

            $default = $default === '' ? $sort->slug : $default;
        }

        return [$sorts, $default];
    }

    /**
     * @param  list<mixed>  $declared
     * @param  list<string>  $carry
     * @param  list<string>  $problems
     * @return array{0: list<Facet>, 1: array<string, (Closure(mixed): mixed)|null>}
     */
    private static function facets(array $declared, Model $model, QueryStringCodec $codec, array $carry, array &$problems): array
    {
        $facets = [];
        $slugs = [];
        $names = [];
        $relations = [];
        $constrainedBy = [];

        foreach ($declared as $at => $facet) {
            if (! $facet instanceof Facet) {
                $problems[] = "facets()[{$at}] must be a Facet, ".get_debug_type($facet).' given';

                continue;
            }

            if ($facet->isNamed()) {
                $problems[] = "facet \"{$facet->slug}\": Facet::named() belongs in describedBy() of a dynamic facet";

                continue;
            }

            if ($facet->position !== null) {
                $problems[] = "facet \"{$facet->slug}\": at() places a named group of a dynamic facet; declared facets keep their order";
            }

            if ($facet->isDynamic()) {
                if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $facet->slug) !== 1) {
                    $problems[] = "dynamic facet \"{$facet->slug}\" needs a short lowercase name";
                } elseif (isset($names[$facet->slug])) {
                    $problems[] = "dynamic facet \"{$facet->slug}\" is declared twice";
                }

                if ($facet->widget !== Facet::LIST || ! $facet->multiple) {
                    $problems[] = "dynamic facet \"{$facet->slug}\": single() and swatches() are set per group with Facet::named() in describedBy()";
                }

                $names[$facet->slug] = true;
            } else {
                self::declaredFacet($facet, $codec, $carry, isset($slugs[$facet->slug]), $problems);
                $slugs[$facet->slug] = true;

                if ($facet->described !== null) {
                    $problems[] = "facet \"{$facet->slug}\": describedBy() belongs to a dynamic facet";
                }
            }

            foreach ($facet->relations as $path => $constraint) {
                $problem = self::relationProblem($model, $path);

                if ($problem !== null) {
                    $problems[] = "facet \"{$facet->slug}\": with(\"{$path}\") {$problem}";

                    continue;
                }

                $owner = $constrainedBy[$path] ?? null;

                if ($owner !== null && ($relations[$path] ?? null) !== $constraint) {
                    $problems[] = "relation \"{$path}\" is loaded differently by facets \"{$owner}\" and \"{$facet->slug}\": one eager load would override the other";
                }

                $constrainedBy[$path] ??= $facet->slug;
                $relations[$path] = $constraint;
            }

            $facets[] = $facet;
        }

        if ($facets === []) {
            $problems[] = 'facets() declares no facet';
        }

        return [$facets, $relations];
    }

    /**
     * @param  list<string>  $carry
     * @param  list<string>  $problems
     */
    private static function declaredFacet(Facet $facet, QueryStringCodec $codec, array $carry, bool $twice, array &$problems): void
    {
        try {
            $valid = FacetId::fromString($facet->slug)->value === $facet->slug;
        } catch (InvalidArgumentException) {
            $valid = false;
        }

        if (! $valid) {
            $problems[] = "facet \"{$facet->slug}\" is not a slug: use lowercase letters, digits and single dashes, up to ".FacetId::MAX_LENGTH;

            return;
        }

        $param = $codec->param(FacetId::fromString($facet->slug));

        if ($codec->isReserved($facet->slug)) {
            $problems[] = "facet \"{$facet->slug}\" cannot be read from the address: the parameter \"{$param}\" belongs to the codec";
        }

        if (in_array($param, $carry, true)) {
            $problems[] = "facet \"{$facet->slug}\" is also a carried parameter";
        }

        if ($twice) {
            $problems[] = "facet \"{$facet->slug}\" is declared twice";
        }

        if (trim($facet->title) === '') {
            $problems[] = "facet \"{$facet->slug}\" has no title";
        }
    }

    /**
     * Why a with() path is not a chain of relations of the model, or null when it is.
     */
    private static function relationProblem(Model $model, string $path): ?string
    {
        $current = $model;

        foreach (explode('.', $path) as $name) {
            $relation = self::relation($current, $name);

            if ($relation === null) {
                return "names \"{$name}\", which is not a relation of ".class_basename($current);
            }

            $current = $relation->getRelated();
        }

        return null;
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $problems
     * @return array<string, array{kind: 'column'|'belongs-to'|'belongs-to-many', column: string, pivot?: string, related?: string, foreign?: string}>
     */
    private static function fields(array $names, Model $model, array &$problems): array
    {
        $fields = [];

        foreach ($names as $name) {
            if ($name === 'id' || isset($fields[$name])) {
                $problems[] = "#[Fields] names \"{$name}\" twice or reserves id, which ranked() uses";

                continue;
            }

            if (preg_match(self::COLUMN, $name) !== 1) {
                $problems[] = "#[Fields] \"{$name}\" is not a column or relation name";

                continue;
            }

            $relation = self::relation($model, $name);

            if ($relation instanceof BelongsTo) {
                $fields[$name] = ['kind' => 'belongs-to', 'column' => $relation->getForeignKeyName()];
            } elseif ($relation instanceof BelongsToMany) {
                $fields[$name] = [
                    'kind' => 'belongs-to-many',
                    'column' => $model->getKeyName(),
                    'pivot' => $relation->getTable(),
                    'related' => $relation->getRelatedPivotKeyName(),
                    'foreign' => $relation->getForeignPivotKeyName(),
                ];
            } elseif ($relation !== null) {
                $problems[] = "#[Fields] \"{$name}\" is a ".class_basename($relation).' relation: only belongsTo and belongsToMany narrow a catalog';
            } else {
                $fields[$name] = ['kind' => 'column', 'column' => $name];
            }
        }

        return $fields;
    }

    /**
     * @return Relation<Model, Model, mixed>|null
     */
    private static function relation(Model $model, string $name): ?Relation
    {
        if (! method_exists($model, $name)) {
            return null;
        }

        try {
            $relation = $model->{$name}();
        } catch (Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation : null;
    }

    /**
     * @template T of object
     *
     * @param  ReflectionClass<object>  $class
     * @param  class-string<T>  $attribute
     * @return T|null
     */
    private static function attribute(ReflectionClass $class, string $attribute): ?object
    {
        $found = $class->getAttributes($attribute)[0] ?? null;

        return $found?->newInstance();
    }

    /**
     * @param  array<array-key, mixed>  $given
     * @param  list<string>  $known
     * @param  list<string>  $problems
     */
    private static function unknownKeys(string $path, array $given, array $known, array &$problems): void
    {
        foreach (array_keys($given) as $key) {
            if (in_array($key, $known, true)) {
                continue;
            }

            $near = null;

            foreach ($known as $candidate) {
                if (levenshtein((string) $key, $candidate) <= 2) {
                    $near = $candidate;
                }
            }

            $problems[] = "config {$path}: unknown key \"{$key}\"".($near === null ? '' : ", did you mean \"{$near}\"?");
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private static function int(mixed $value, string $key, int $min, int $max, array &$problems): ?int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $problems[] = "{$key} must be an integer from {$min} to {$max}, ".var_export($value, true).' given';

            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $problems
     * @return list<string>
     */
    private static function strings(mixed $value, string $key, array &$problems): array
    {
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, static fn (mixed $one): bool => ! is_string($one)) !== []) {
            $problems[] = "{$key} must be a list of parameter names";

            return [];
        }

        /** @var list<string> */
        return $value;
    }
}
