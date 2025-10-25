<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Manticore;

/**
 * The schema of one engine table as data. A reserved column name (order, limit) is declared in backticks.
 */
final readonly class Table
{
    /** Declared type => the name DESCRIBE gives it. */
    private const DESCRIBED = ['int' => 'uint', 'integer' => 'uint', 'multi' => 'mva', 'multi64' => 'mva64'];

    /**
     * @param  array<string, string>  $columns  name => engine type, in DDL order, without id
     * @param  array<string, string>  $settings  name => value
     * @param  list<string>  $mutable  settings ATTACH leaves on the target table; re-applied with ALTER
     * @param  string  $suffix  marks a companion: tables of one set are published together, each at name($base)
     */
    public function __construct(
        public array $columns,
        public array $settings = [],
        public array $mutable = [],
        public string $suffix = '',
    ) {}

    public function name(string $base): string
    {
        return $base.$this->suffix;
    }

    public function create(string $base, bool $ifNotExists = false): string
    {
        $columns = [];

        foreach ($this->columns as $name => $type) {
            $columns[] = "{$name} {$type}";
        }

        $settings = '';

        foreach ($this->settings as $key => $value) {
            $settings .= " {$key}=".Writer::literal($value);
        }

        return 'CREATE TABLE '.($ifNotExists ? 'IF NOT EXISTS ' : '').$this->name($base)
            .' ('.implode(', ', $columns).')'.$settings;
    }

    /**
     * @param  string|null  $createStatement  SHOW CREATE TABLE of the table: settings it already has are skipped
     * @return list<string>
     */
    public function alterMutable(string $base, ?string $createStatement = null): array
    {
        $printed = $createStatement === null ? [] : self::printed($createStatement);
        $statements = [];

        foreach ($this->mutable as $key) {
            if (! isset($this->settings[$key])) {
                continue;
            }

            if (! isset($printed[$key]) || ! self::same($this->settings[$key], $printed[$key])) {
                $statements[] = "ALTER TABLE {$this->name($base)} {$key}=".Writer::literal($this->settings[$key]);
            }
        }

        return $statements;
    }

    /**
     * @param  list<array<string, mixed>>  $described  rows of DESCRIBE <table>
     * @return list<string> differences, empty when the table matches
     */
    public function drift(array $described, string $createStatement): array
    {
        $actual = [];

        foreach ($described as $row) {
            $field = $row['Field'] ?? null;
            $type = $row['Type'] ?? null;

            if (is_string($field) && is_string($type)) {
                $actual[$field] = strtolower($type);
            }
        }

        $drift = [];
        $declared = [];

        foreach ($this->columns as $column => $type) {
            $name = trim($column, '`');
            $declared[] = $name;

            // "string attribute" is described as plain "string".
            $first = strtolower(explode(' ', $type, 2)[0]);
            $expected = self::DESCRIBED[$first] ?? $first;

            if (! isset($actual[$name])) {
                $drift[] = "missing column {$name} ({$expected})";
            } elseif ($actual[$name] !== $expected) {
                $drift[] = "column {$name}: {$actual[$name]}, expected {$expected}";
            }
        }

        foreach (array_diff(array_keys($actual), ['id', ...$declared]) as $name) {
            $drift[] = "unexpected column {$name}";
        }

        $printed = self::printed($createStatement);

        foreach ($this->settings as $key => $value) {
            if (! isset($printed[$key]) || ! self::same($value, $printed[$key])) {
                $drift[] = "setting {$key}: expected '{$value}'";
            }
        }

        foreach (array_diff_key($printed, $this->settings) as $key => $value) {
            if (! $this->forced($key, $value)) {
                $drift[] = "unexpected setting {$key}='{$value}'";
            }
        }

        return $drift;
    }

    /**
     * @return array<string, string> setting => value as printed, still escaped
     */
    private static function printed(string $createStatement): array
    {
        // Column definitions end at the first line that starts with ")".
        $end = strpos($createStatement, "\n)");
        $tail = $end === false ? $createStatement : substr($createStatement, $end + 2);

        preg_match_all("/(\\w+)='((?:[^'\\\\]++|\\\\.)*+)'/s", $tail, $matches, PREG_SET_ORDER);

        $settings = [];

        foreach ($matches as $match) {
            $settings[$match[1]] = $match[2];
        }

        return $settings;
    }

    private static function same(string $declared, string $printed): bool
    {
        if (substr(Writer::literal($declared), 1, -1) === $printed) {
            return true;
        }

        // The engine prints a size as bytes: rt_mem_limit='256M' comes back as 268435456.
        if (preg_match('/^(\d+)([kmg])$/i', $declared, $size) === 1 && ctype_digit($printed)) {
            $unit = match (strtolower($size[2])) {
                'k' => 1024,
                'm' => 1024 ** 2,
                default => 1024 ** 3,
            };

            return (int) $size[1] * $unit === (int) $printed;
        }

        return false;
    }

    // The engine adds index_exact_words='1' to morphology with prefixes or infixes.
    private function forced(string $key, string $value): bool
    {
        return $key === 'index_exact_words'
            && $value === '1'
            && ($this->settings['morphology'] ?? '') !== ''
            && ((int) ($this->settings['min_infix_len'] ?? 0) > 0 || (int) ($this->settings['min_prefix_len'] ?? 0) > 0);
    }
}
