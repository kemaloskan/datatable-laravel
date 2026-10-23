<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\OrdersTable;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LanguagesTest extends TestCase
{
    private const LANGUAGES = ['en', 'tr', 'de', 'fr', 'es'];

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    public function test_every_language_has_every_text(): void
    {
        $english = array_keys(require __DIR__.'/../lang/en/table.php');
        sort($english);
        foreach (self::LANGUAGES as $language) {
            $texts = require __DIR__."/../lang/{$language}/table.php";
            $keys = array_keys($texts);
            sort($keys);
            self::assertSame($english, $keys, "lang/{$language} must have the same keys as lang/en");
            foreach ($texts as $key => $text) {
                self::assertNotSame('', trim($text), "{$language}.{$key} is empty");
                // Plural forms must keep the same placeholders.
                $forms = explode('|', $text);
                preg_match_all('/:\w+/', $forms[0], $first);
                foreach ($forms as $form) {
                    preg_match_all('/:\w+/', $form, $found);
                    if ($found[0] !== [] || $first[0] !== []) {
                        self::assertEqualsCanonicalizing(array_diff($first[0], [':total']), array_diff($found[0], [':total']), "{$language}.{$key}");
                    }
                }
            }
        }
    }

    public function test_singular_plural_and_digit_grouping_follow_the_language(): void
    {
        $status = fn (int $total) => trans_choice('acun-datatable::table.page_status', $total, ['page' => 1, 'pages' => 1, 'total' => $total]);
        app()->setLocale('en');
        self::assertSame('Page 1 of 1 · 1 row', $status(1));
        self::assertSame('Page 1 of 1 · 5 rows', $status(5));
        app()->setLocale('fr');
        self::assertSame('Page 1 sur 1 · 0 ligne', $status(0)); // French: 0 is singular
        app()->setLocale('tr');
        self::assertSame('Sayfa 1 / 1 · 1 kayıt', $status(1));
        app()->setLocale('de');
        self::assertSame('2 Zeilen importiert.', trans_choice('acun-datatable::table.import_done', 2, ['count' => 2]));
        self::assertSame('1.600', \Acunsoft\DataTable\Laravel\Support\Format::count(1600));
        self::assertSame('1,600', \Acunsoft\DataTable\Laravel\Support\Format::count(1600, 'en'));
    }

    public function test_the_livewire_table_speaks_the_application_language(): void
    {
        Order::query()->whereKeyNot(1)->delete();
        foreach ([
            'en' => ['Page 1 of 1 · 1 row', 'Search'],
            'de' => ['Seite 1 von 1 · 1 Eintrag', 'Suchen'],
            'fr' => ['Page 1 sur 1 · 1 ligne', 'Rechercher'],
            'es' => ['Página 1 de 1 · 1 fila', 'Buscar'],
            'tr' => ['Sayfa 1 / 1 · 1 kayıt', 'Ara'],
        ] as $language => [$status, $search]) {
            app()->setLocale($language);
            Livewire::test(OrdersTable::class)->assertSee($status)->assertSee($search);
        }
    }
}
