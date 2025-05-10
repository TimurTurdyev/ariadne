<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Results;

/**
 * One result item without a domain: the document id plus what the adapter knows about it.
 */
final readonly class Hit
{
    /**
     * @param  array<string, mixed>  $attributes  the engine or database row
     * @param  array<string, mixed>  $payload  card data as stored
     * @param  array<string, mixed>  $extra  what the adapter added on top
     */
    public function __construct(
        public int $id,
        public array $attributes = [],
        public array $payload = [],
        public array $extra = [],
    ) {}

    /**
     * An attribute as an integer: PDO returns numbers as strings.
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->attributes[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $attributes  replaces the whole row
     */
    public function withAttributes(array $attributes): self
    {
        return new self($this->id, $attributes, $this->payload, $this->extra);
    }

    /**
     * @param  array<string, mixed>  $payload  replaces the whole payload
     */
    public function withPayload(array $payload): self
    {
        return new self($this->id, $this->attributes, $payload, $this->extra);
    }

    /**
     * Sets one key and keeps the rest.
     */
    public function withExtra(string $key, mixed $value): self
    {
        $extra = $this->extra;
        $extra[$key] = $value;

        return new self($this->id, $this->attributes, $this->payload, $extra);
    }
}
