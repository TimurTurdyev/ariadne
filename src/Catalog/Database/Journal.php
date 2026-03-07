<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

/**
 * Marks of changed documents, written in the transaction of the edit and removed by exact id once applied.
 *
 * @internal
 */
final readonly class Journal
{
    public const BATCH = 500;

    public function __construct(private Tables $tables) {}

    /**
     * @param  list<int>  $documents
     */
    public function mark(string $catalog, array $documents, int $now): void
    {
        foreach (array_chunk($documents, self::BATCH) as $chunk) {
            $this->tables->table($this->tables->changes())->insert(array_map(
                static fn (int $document): array => ['catalog' => $catalog, 'document_id' => $document, 'created_at' => $now],
                $chunk,
            ));
        }
    }

    /**
     * Pending marks after an id, oldest first, from the write connection: a replica could miss the newest.
     *
     * @return list<array{id: int, document: int}>
     */
    public function pending(string $catalog, int $after = 0, int $limit = self::BATCH): array
    {
        $rows = $this->tables->table($this->tables->changes())
            ->useWritePdo()
            ->where('catalog', $catalog)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'document_id']);

        $marks = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $marks[] = ['id' => self::int($values['id'] ?? null), 'document' => self::int($values['document_id'] ?? null)];
        }

        return $marks;
    }

    /**
     * @param  list<int>  $ids
     */
    public function forget(array $ids): void
    {
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $this->tables->table($this->tables->changes())->whereIn('id', $chunk)->delete();
        }
    }

    /**
     * Count and the created_at of the oldest and newest pending mark.
     *
     * @return array{count: int, oldest: ?int, newest: ?int}
     */
    public function stats(string $catalog): array
    {
        $row = $this->tables->table($this->tables->changes())
            ->useWritePdo()
            ->where('catalog', $catalog)
            ->selectRaw('COUNT(*) AS n, MIN(created_at) AS oldest, MAX(created_at) AS newest')
            ->first();
        $values = (array) $row;

        return [
            'count' => self::int($values['n'] ?? null),
            'oldest' => is_numeric($values['oldest'] ?? null) ? (int) $values['oldest'] : null,
            'newest' => is_numeric($values['newest'] ?? null) ? (int) $values['newest'] : null,
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
