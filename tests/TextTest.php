<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Support\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PlainTestCase;

/** Same vectors as packages/core/tests: the server must fold exactly like the browser. */
final class TextTest extends PlainTestCase
{
    /** @return array<string, array{string, string}> */
    public static function folds(): array
    {
        return [
            'İSTANBUL' => ['İSTANBUL', 'istanbul'],
            'ISPARTA' => ['ISPARTA', 'isparta'],
            'Iğdır' => ['Iğdır', 'igdir'],
            'Ayşe' => ['Ayşe', 'ayse'],
            'Çanakkale' => ['Çanakkale', 'canakkale'],
            'Üsküdar' => ['Üsküdar', 'uskudar'],
            'ızmit' => ['ızmit', 'izmit'],
            'Göreme' => ['Göreme', 'goreme'],
        ];
    }

    #[DataProvider('folds')]
    public function test_fold_matches_the_browser(string $input, string $expected): void
    {
        self::assertSame($expected, Text::fold($input));
    }

    public function test_search_terms_split_like_the_browser(): void
    {
        self::assertSame(['ali', 'new york', '2026'], Text::searchTerms('  ali  "new york"  2026 '));
        self::assertSame([], Text::searchTerms('"" "  "'));
        self::assertSame(['o"neil'], Text::searchTerms('o"neil'));
        self::assertSame(['new york'], Text::searchTerms('"new york'));
    }

    public function test_escape_like_wildcards(): void
    {
        self::assertSame('50!% !_x!!', Text::escapeLike('50% _x!'));
    }
}
