<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Query;

/**
 * What a screen asks for: cards, the filter panel or both.
 */
enum Need: string
{
    /** Cards and panel in one go, as a page without scripts needs. */
    case Everything = 'all';

    /** Cards only: the panel comes with a second request or is not needed. */
    case Results = 'results';

    /** Panel only: the cards are already on the screen. */
    case Panel = 'panel';

    /** Whether to count the value palette, the expensive part. */
    public function wantsPanel(): bool
    {
        return $this !== self::Results;
    }

    /** Whether documents are needed, not just aggregates. */
    public function wantsDocuments(): bool
    {
        return $this !== self::Panel;
    }
}
