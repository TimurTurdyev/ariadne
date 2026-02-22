<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Present;

use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Query\Selection;
use TimurTurdyev\Ariadne\Url\QueryStringCodec;

/**
 * What every panel address starts from: the path, the foreign parameters and the landing page, if any.
 */
final readonly class LinkBase
{
    /** Longest encoded foreign part of an address; a parameter that does not fit is left out. */
    public const MAX_FOREIGN_LENGTH = 1024;

    public string $path;

    /** @var array<string, string|list<string>> */
    public array $foreign;

    public ?string $landing;

    public Selection $pinned;

    /**
     * @param  array<string, string|list<string>>  $foreign  parameters the catalog does not know and carries as they are
     * @param  string|null  $landing  path of the landing page; ignored without a pinned choice
     * @param  Selection  $pinned  the selection the landing path carries instead of the query string
     */
    public function __construct(string $path, array $foreign = [], ?string $landing = null, Selection $pinned = new Selection)
    {
        $this->path = self::normalized($path);
        $this->foreign = $foreign;
        $this->landing = $landing === null || $pinned->isEmpty() ? null : self::normalized($landing);
        $this->pinned = $pinned;
    }

    /**
     * @param  array<int|string, mixed>  $params  parsed query string of the page
     */
    public static function of(string $path, array $params, QueryStringCodec $codec, FacetDictionary $dictionary): self
    {
        return new self($path, $codec->foreign($params, $dictionary));
    }

    /**
     * The same base without the given foreign parameters.
     */
    public function without(string ...$names): self
    {
        return new self($this->path, array_diff_key($this->foreign, array_flip($names)), $this->landing, $this->pinned);
    }

    /**
     * The same base with only the given foreign parameters.
     */
    public function only(string ...$names): self
    {
        return new self($this->path, array_intersect_key($this->foreign, array_flip($names)), $this->landing, $this->pinned);
    }

    /**
     * The base of a landing page: an address that keeps every pinned choice stays on its path.
     */
    public function landing(string $path, Selection $pinned): self
    {
        return new self($this->path, $this->foreign, $path, $pinned);
    }

    private static function normalized(string $path): string
    {
        // "//host/path" in an href is another host to a browser.
        return '/'.ltrim($path, '/\\');
    }
}
