<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Text;

/**
 * Text typed with the latin keyboard layout left on, read as the cyrillic it was meant to be.
 */
final class Layout
{
    private const KEYS = [
        'q' => 'й', 'w' => 'ц', 'e' => 'у', 'r' => 'к', 't' => 'е', 'y' => 'н',
        'u' => 'г', 'i' => 'ш', 'o' => 'щ', 'p' => 'з', '[' => 'х', ']' => 'ъ',
        'a' => 'ф', 's' => 'ы', 'd' => 'в', 'f' => 'а', 'g' => 'п', 'h' => 'р',
        'j' => 'о', 'k' => 'л', 'l' => 'д', ';' => 'ж', "'" => 'э',
        'z' => 'я', 'x' => 'ч', 'c' => 'с', 'v' => 'м', 'b' => 'и', 'n' => 'т',
        'm' => 'ь', ',' => 'б', '.' => 'ю', '`' => 'ё',
    ];

    /**
     * @return string|null the text in lowercase cyrillic, null when a character is not a key of the layout
     */
    public static function toCyrillic(string $text): ?string
    {
        if (preg_match('/^[a-z\s\[\];\',.`]+$/i', $text) !== 1) {
            return null;
        }

        return strtr(mb_strtolower($text), self::KEYS);
    }
}
