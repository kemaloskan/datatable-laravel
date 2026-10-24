<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\EditOrdersTable;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;

final class LivewireEditTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, DataTableServiceProvider::class];
    }

    public function test_editable_cells_render_an_edit_button_and_an_editor_for_the_type(): void
    {
        $html = preg_replace('/<!--.*?-->/s', '', Livewire::test(EditOrdersTable::class)->html());
        self::assertStringContainsString('aria-label="Edit Customer: Ayşe Yılmaz"', $html);
        self::assertStringNotContainsString('Edit Note:', $html);
        self::assertMatchesRegularExpression('/<input class="adt-edit-control" x-ref="control" data-initial="120.50" value="120.50" aria-label="Total"/', $html);
        self::assertStringContainsString('inputmode="decimal"', $html);
        self::assertMatchesRegularExpression('/<option value="Ankara"\s*>Ankara<\/option>/', $html);
        self::assertStringContainsString("this.\$wire.updateCell('1', 'customer', value)", $html); // Js::from escapes quotes itself
    }

    public function test_a_valid_edit_is_saved_and_shown(): void
    {
        $table = Livewire::test(EditOrdersTable::class);
        $table->call('updateCell', '1', 'customer', 'Ayşe Y.');
        self::assertSame('Ayşe Y.', Order::query()->find(1)->customer);
        self::assertStringContainsString('Ayşe Y.', $table->html());
        $table->call('updateCell', '1', 'total', '1300,75'); // a decimal comma
        self::assertSame('1300.75', Order::query()->find(1)->total);
        $table->call('updateCell', '1', 'active', '0');
        self::assertFalse(Order::query()->find(1)->active);
        $table->call('updateCell', '2', 'city', 'Ankara');
        self::assertSame('Ankara', Order::query()->find(2)->city);
    }

    public function test_invalid_values_show_the_error_and_change_nothing(): void
    {
        $table = Livewire::test(EditOrdersTable::class);
        self::assertFalse($table->instance()->updateCell('1', 'customer', ''));
        $table->call('updateCell', '1', 'total', 'çok');
        $html = $table->html();
        self::assertStringContainsString('role="alert">The Total field must be a number.</span>', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        $table->call('updateCell', '2', 'city', 'Paris');
        self::assertSame('120.50', Order::query()->find(1)->total);
        self::assertNotSame('Paris', Order::query()->find(2)->city);
        $table->call('cancelEdit', '1', 'total');
        self::assertStringNotContainsString('must be a number', $table->html());
    }

    public function test_only_editable_columns_of_rows_in_the_query_can_change(): void
    {
        $table = Livewire::test(EditOrdersTable::class);
        self::assertFalse($table->instance()->updateCell('1', 'note', 'x'));     // not editable
        self::assertFalse($table->instance()->updateCell('1', 'secret', 'x'));   // not a column
        self::assertFalse($table->instance()->updateCell('4', 'customer', 'x')); // outside query()
        self::assertSame('do-not-send', Order::query()->find(1)->secret);
        self::assertNotSame('x', Order::query()->find(4)?->customer);
    }

    public function test_the_model_policy_must_allow_update(): void
    {
        Gate::policy(Order::class, DenyUpdates::class);
        $this->expectException(AuthorizationException::class);
        Livewire::test(EditOrdersTable::class)->instance()->updateCell('1', 'customer', 'Nope');
    }

    public function test_edit_rules_combine_type_options_and_extra_rules(): void
    {
        self::assertSame(['required', 'string', 'max:40'], Column::make('a')->editable(['required', 'max:40'])->editRules());
        self::assertSame(['nullable', 'numeric', 'min:0'], Column::make('a')->type('decimal')->editable('min:0')->editRules());
        self::assertSame(['nullable', 'date_format:Y-m-d'], Column::make('a')->type('date')->editable()->editRules());
        self::assertFalse(Column::make('a')->isEditable());
    }
}

final class DenyUpdates
{
    public function update(mixed $user, Order $order): bool
    {
        return false;
    }

    public function test_a_column_of_another_table_is_never_written_to_the_row(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('it is not a column of [orders]');
        try {
            Livewire::test(\Acunsoft\DataTable\Laravel\Tests\Fixtures\JoinedEditOrdersTable::class)->call('updateCell', '1', 'name', 'X');
        } finally {
            self::assertNull(Order::query()->find(1)->getAttribute('name'));
        }
    }
}
