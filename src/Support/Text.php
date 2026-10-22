<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Support;

/**
 * Text helpers that match the browser packages exactly, so a server-mode table
 * finds the same rows as a local one.
 */
final class Text
{
    /** Without ext-intl: the letters people type without accents (Turkish first). */
    private const FALLBACK = [
        'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g', 'ç' => 'c', 'Ç' => 'c', 'ö' => 'o', 'Ö' => 'o',
        'ü' => 'u', 'Ü' => 'u', 'ı' => 'i', 'İ' => 'i', 'I' => 'i', 'â' => 'a', 'Â' => 'a', 'î' => 'i',
        'Î' => 'i', 'û' => 'u', 'Û' => 'u', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e',
        'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ñ' => 'n', 'ß' => 'ss',
    ];

    /**
     * Fold text for matching, like `foldText` in @acunsoft/datatable-core:
     * case- and accent-insensitive and Turkish-aware in every locale
     * (İ, I, ı and i all become i; ş→s, ğ→g, ç→c, ö→o, ü→u).
     */
    public static function fold(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            $text = preg_replace('/\p{M}/u', '', $decomposed === false ? $text : $decomposed) ?? $text;
        } else {
            $text = strtr($text, self::FALLBACK);
        }

        return str_replace('ı', 'i', mb_strtolower($text, 'UTF-8'));
    }

    /**
     * Split smart-search text like `splitSearchTerms` in the core: words are
     * separate terms, a quoted phrase is one term, empty terms are dropped.
     *
     * @return list<string>
     */
    public static function searchTerms(string $text): array
    {
        preg_match_all('/"([^"]*)"?|(\S+)/u', $text, $matches, PREG_SET_ORDER);
        $terms = [];
        foreach ($matches as $match) {
            $term = trim(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
            if ($term !== '' && $term !== '"') {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    /** Escape LIKE wildcards for `LIKE ? ESCAPE '!'`. */
    public static function escapeLike(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }
}
