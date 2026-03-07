<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

/**
 * One row of the catalogs table: the live generation and what the last rebuild left. Times are unix seconds.
 *
 * @internal
 */
final readonly class State
{
    /**
     * @param  int  $generation  0 - nothing published yet
     * @param  list<array{facet: string, reason: string, documents: int}>  $problems  what the last rebuild refused
     */
    public function __construct(
        public int $generation = 0,
        public ?int $previous = null,
        public ?int $flippedAt = null,
        public ?int $building = null,
        public ?int $buildingUntil = null,
        public ?int $rebuildRequestedAt = null,
        public int $version = 0,
        public int $documents = 0,
        public int $rows = 0,
        public array $problems = [],
        public ?int $builtAt = null,
        public ?float $buildSeconds = null,
    ) {}

    public static function fromRow(?object $row): self
    {
        if ($row === null) {
            return new self;
        }

        $values = (array) $row;
        $problems = is_string($values['problems'] ?? null) ? json_decode($values['problems'], true) : null;

        return new self(
            generation: self::int($values['generation'] ?? null) ?? 0,
            previous: self::int($values['previous_generation'] ?? null),
            flippedAt: self::int($values['flipped_at'] ?? null),
            building: self::int($values['building_generation'] ?? null),
            buildingUntil: self::int($values['building_until'] ?? null),
            rebuildRequestedAt: self::int($values['rebuild_requested_at'] ?? null),
            version: self::int($values['version'] ?? null) ?? 0,
            documents: self::int($values['document_total'] ?? null) ?? 0,
            rows: self::int($values['row_total'] ?? null) ?? 0,
            problems: self::problems($problems),
            builtAt: self::int($values['built_at'] ?? null),
            buildSeconds: is_numeric($values['build_seconds'] ?? null) ? (float) $values['build_seconds'] : null,
        );
    }

    /**
     * Whether a rebuild holds the catalog: its flag is set and has not run out.
     */
    public function isBuilding(int $now): bool
    {
        return $this->building !== null && $this->buildingUntil !== null && $this->buildingUntil > $now;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<array{facet: string, reason: string, documents: int}>
     */
    private static function problems(mixed $decoded): array
    {
        $problems = [];

        foreach (is_array($decoded) ? $decoded : [] as $problem) {
            if (is_array($problem)) {
                $problems[] = [
                    'facet' => is_string($problem['facet'] ?? null) ? $problem['facet'] : '',
                    'reason' => is_string($problem['reason'] ?? null) ? $problem['reason'] : '',
                    'documents' => is_int($problem['documents'] ?? null) ? $problem['documents'] : 0,
                ];
            }
        }

        return $problems;
    }
}
