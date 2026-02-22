<?php

declare(strict_types=1);

use TimurTurdyev\Ariadne\Manticore\Table;

/**
 * One column of every type the comparison has to translate.
 */
function engine_table_typed(): Table
{
    return new Table(
        [
            'title' => 'text', 'code' => 'string attribute', 'model' => 'int', 'price' => 'bigint',
            'width' => 'float', 'sections' => 'multi', 'tags' => 'multi64', 'payload' => 'string',
        ],
        ['morphology' => 'lemmatize_ru_all, stem_en', 'min_infix_len' => '2'],
    );
}

/**
 * How DESCRIBE names the columns of engine_table_typed().
 *
 * @return array<string, string> field => type
 */
function engine_table_types(): array
{
    return [
        'id' => 'bigint', 'title' => 'text', 'code' => 'string', 'model' => 'uint', 'price' => 'bigint',
        'width' => 'float', 'sections' => 'mva', 'tags' => 'mva64', 'payload' => 'string',
    ];
}

/**
 * @param  array<string, string>  $types  field => type
 * @return list<array<string, mixed>> rows of DESCRIBE
 */
function engine_table_describe(array $types): array
{
    $rows = [];

    foreach ($types as $field => $type) {
        $rows[] = ['Field' => $field, 'Type' => $type, 'Properties' => $type === 'text' ? 'indexed stored' : ''];
    }

    return $rows;
}

/**
 * SHOW CREATE TABLE of engine_table_typed().
 */
function engine_table_created(): string
{
    return "CREATE TABLE catalog (\nid bigint,\ntitle text,\ncode string attribute,\nmodel integer,\nprice bigint,\n"
        ."width float,\nsections multi,\ntags multi64,\npayload string attribute\n"
        .") morphology='lemmatize_ru_all, stem_en' min_infix_len='2'";
}

/**
 * @param  array<string, string>  $types  field => type, as DESCRIBE would answer
 * @return list<string>
 */
function engine_table_drift(array $types, ?string $created = null): array
{
    return engine_table_typed()->drift(engine_table_describe($types), $created ?? engine_table_created());
}

it('creates a table with every column and setting in the given order', function (): void {
    $table = new Table(
        [
            'title' => 'text', 'code' => 'text', 'model' => 'int', 'brand' => 'int', 'sections' => 'multi',
            'price' => 'bigint', 'width' => 'float', 'payload' => 'string',
        ],
        [
            'morphology' => 'lemmatize_ru_all',
            'morphology_skip_fields' => 'code',
            'index_exact_words' => '1',
            'expand_keywords' => 'exact',
            'min_infix_len' => '2',
        ],
    );

    expect($table->create('catalog', ifNotExists: true))->toBe(
        'CREATE TABLE IF NOT EXISTS catalog (title text, code text, model int, brand int, sections multi,'
        .' price bigint, width float, payload string)'
        ." morphology='lemmatize_ru_all' morphology_skip_fields='code' index_exact_words='1'"
        ." expand_keywords='exact' min_infix_len='2'"
    );
});

it('creates a companion under the suffixed name with its own settings', function (): void {
    $columns = [
        'title' => 'text', 'extra' => 'text', 'code' => 'string attribute',
        'in_stock' => 'int', 'sales' => 'int', 'price' => 'int',
    ];
    $main = new Table($columns, ['morphology' => 'lemmatize_ru_all, stem_en', 'min_infix_len' => '2', 'index_exact_words' => '1']);
    $fuzzy = new Table($columns, ['min_infix_len' => '2'], suffix: '_fuzzy');

    expect($main->create('goods_b'))->toBe(
        'CREATE TABLE goods_b (title text, extra text, code string attribute, in_stock int, sales int, price int)'
        ." morphology='lemmatize_ru_all, stem_en' min_infix_len='2' index_exact_words='1'"
    )
        ->and($fuzzy->create('goods_b'))->toBe(
            'CREATE TABLE goods_b_fuzzy (title text, extra text, code string attribute, in_stock int, sales int, price int)'
            ." min_infix_len='2'"
        )
        ->and((new Table(['title' => 'text']))->create('plain'))->toBe('CREATE TABLE plain (title text)');
});

it('finds no drift in a table the engine describes the same way', function (): void {
    expect(engine_table_drift(engine_table_types()))->toBe([]);
});

it('reports a missing column with the type the engine would show', function (): void {
    expect(engine_table_drift(array_diff_key(engine_table_types(), ['model' => true])))
        ->toBe(['missing column model (uint)']);
});

it('reports a column of another type', function (): void {
    expect(engine_table_drift([...engine_table_types(), 'price' => 'uint']))
        ->toBe(['column price: uint, expected bigint']);
});

it('reports a column the schema does not have', function (): void {
    expect(engine_table_drift([...engine_table_types(), 'legacy' => 'uint']))
        ->toBe(['unexpected column legacy']);
});

it('reports a setting the table was created with another value of', function (): void {
    $created = str_replace("min_infix_len='2'", "min_infix_len='3'", engine_table_created());

    expect(engine_table_drift(engine_table_types(), $created))
        ->toBe(["setting min_infix_len: expected '2'"]);
});

it('escapes a setting value like a string literal and finds it escaped in the table', function (): void {
    $table = new Table(['title' => 'text'], ['regexp_filter' => '(\\d+)x(\\d+) => \\1 x \\2', 'blend_chars' => "'"], ['regexp_filter']);
    $escaped = "regexp_filter='(\\\\d+)x(\\\\d+) => \\\\1 x \\\\2' blend_chars='\\''";
    $created = "CREATE TABLE plain (\nid bigint,\ntitle text\n) {$escaped}";

    expect($table->create('plain'))->toBe("CREATE TABLE plain (title text) {$escaped}")
        ->and($table->alterMutable('plain'))->toBe(["ALTER TABLE plain regexp_filter='(\\\\d+)x(\\\\d+) => \\\\1 x \\\\2'"])
        ->and($table->drift(engine_table_describe(['id' => 'bigint', 'title' => 'text']), $created))->toBe([]);
});

it('reads a column in backticks and a type in any case or spelling', function (): void {
    $table = new Table(['`order`' => 'integer', 'title' => 'Text', 'price' => 'INT']);

    expect($table->create('plain'))->toBe('CREATE TABLE plain (`order` integer, title Text, price INT)')
        ->and($table->drift(
            engine_table_describe(['id' => 'bigint', 'order' => 'uint', 'title' => 'text', 'price' => 'uint']),
            "CREATE TABLE plain (\nid bigint,\norder integer,\ntitle text,\nprice integer\n)",
        ))->toBe([]);
});

it('compares a size setting in bytes', function (string $declared, array $drift): void {
    $table = new Table(['title' => 'text'], ['rt_mem_limit' => $declared]);

    expect($table->drift(engine_table_describe(['id' => 'bigint', 'title' => 'text']), "CREATE TABLE plain (\nid bigint,\ntitle text\n) rt_mem_limit='268435456'"))
        ->toBe($drift);
})->with([
    'the same size in megabytes' => ['256M', []],
    'the same size in bytes' => ['268435456', []],
    'another size' => ['128m', ["setting rt_mem_limit: expected '128m'"]],
]);

it('reports a setting the table has and the schema does not, except the one the engine adds itself', function (): void {
    $created = str_replace(
        "min_infix_len='2'",
        "min_infix_len='2' index_exact_words='1' expand_keywords='exact' regexp_filter='a => b'",
        engine_table_created(),
    );

    expect(engine_table_drift(engine_table_types(), $created))->toBe([
        "unexpected setting expand_keywords='exact'",
        "unexpected setting regexp_filter='a => b'",
    ]);
});
