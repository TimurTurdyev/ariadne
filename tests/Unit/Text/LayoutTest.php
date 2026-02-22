<?php

declare(strict_types=1);

use TimurTurdyev\Ariadne\Text\Layout;

it('reads text typed in the latin layout as cyrillic', function (): void {
    expect(Layout::toCyrillic('rjv,bytpjy'))->toBe('комбинезон')
        // The comma key is a letter: without it the same word comes out one letter short.
        ->and(Layout::toCyrillic('rjvbytpjy'))->toBe('коминезон')
        ->and(Layout::toCyrillic('CKBG'))->toBe('слип')
        ->and(Layout::toCyrillic('ghbdtn vbh'))->toBe('привет мир')
        ->and(Layout::toCyrillic("[];',.`"))->toBe('хъжэбюё');
});

it('gives null for text with characters the layout does not have', function (): void {
    expect(Layout::toCyrillic('слип'))->toBeNull()
        ->and(Layout::toCyrillic('model 62'))->toBeNull()
        ->and(Layout::toCyrillic('a-b'))->toBeNull()
        ->and(Layout::toCyrillic(''))->toBeNull();
});
