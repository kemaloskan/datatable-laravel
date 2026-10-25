<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Jobs\RunTableImport;
use Acunsoft\DataTable\Laravel\Support\TableTasks;
use Acunsoft\DataTable\Laravel\Support\Text;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The back end of the import protocol `acun-import/1` (the import dialog of
 * `@acunsoft/datatable-import`), for one kind of record. Extend it, then
 * register it: `Route::acunImport('orders/import', OrdersImport::class)`.
 *
 * Everything the protocol asks of a back end happens here: the limits are
 * checked on every upload, the file is read safely (SheetReader), the mapping
 * is checked again on start, imports belong to their user and tenant,
 * uploads are idempotent, rows are validated by type (RowImporter) and saved
 * in chunks, at once or on your queue, with progress and a CSV error report.
 * You decide the columns, who may import, the limits, how rows are saved and
 * whether to queue. Nothing needs Redis or a queue: state lives in the app's
 * cache store, files on a disk.
 */
abstract class TableImport
{
    public const PROTOCOL = 'acun-import/1';

    private const MODES = ['insert', 'update', 'upsert'];

    private const FINAL = ['done', 'failed', 'cancelled', 'expired'];

    // ---- What you define -----------------------------------------------------

    /** @return list<Column> the columns a file can be mapped to (header text, type, database column) */
    abstract protected function columns(): array;

    /** Whether this request may import here; asked on every request of the protocol. */
    abstract public function authorize(Request $request): bool;

    /** @return list<string> column IDs that must be mapped and filled */
    protected function required(): array
    {
        return [];
    }

    /** The column whose value finds an existing record (insert or update); null: always insert. */
    protected function importKey(): ?string
    {
        return null;
    }

    /** @return list<string> the modes offered: `insert`, `update`, `upsert` */
    protected function modes(): array
    {
        return $this->importKey() === null ? ['insert'] : ['upsert', 'insert', 'update'];
    }

    /** The tenant an import belongs to (team, company …), read on upload; imports of another tenant are not found. */
    protected function tenant(Request $request): ?string
    {
        return null;
    }

    /** The auth guard who owns imports. Default: the default guard. */
    protected function guard(): ?string
    {
        return null;
    }

    /** Largest file, in kilobytes. Default 10 MB. */
    protected function maxKilobytes(): int
    {
        return 10_240;
    }

    /** Most data rows in a file. Default 100,000. */
    protected function maxRows(): int
    {
        return 100_000;
    }

    /** @return list<string> accepted file extensions (`xlsx`, `csv`, `txt`) */
    protected function accept(): array
    {
        return ['xlsx', 'csv'];
    }

    /** How long an import (its state, file and error report) is kept, in seconds. Default a day. */
    protected function keepSeconds(): int
    {
        return 86_400;
    }

    /** Rows saved per transaction. Default 1,000. */
    protected function chunkSize(): int
    {
        return 1_000;
    }

    /** The disk uploads and error reports are kept on (private). Default `local`. */
    protected function disk(): string
    {
        return 'local';
    }

    /** RowImporter options: `decimalSeparator` (`.` or `,`), `dateOrder` (`dmy`, `mdy`), `headers` (extra header names per column ID). */
    protected function readOptions(): array
    {
        return [];
    }

    /** An empty file with the right headers, offered for download. */
    protected function templateUrl(): ?string
    {
        return null;
    }

    /** At once (false, the default) or as a job on your queue (true), by the number of rows. */
    protected function shouldQueue(int $rows): bool
    {
        return false;
    }

    protected function queueConnection(): ?string
    {
        return null;
    }

    protected function queueName(): ?string
    {
        return null;
    }

    /**
     * Where the default `save()` puts rows: a query or relation scoped to the
     * tenant, e.g. `Team::find($context->tenant)->orders()` or
     * `Order::query()->where('team_id', $context->tenant)`.
     */
    protected function query(ImportContext $context): EloquentBuilder|Relation|QueryBuilder|null
    {
        return null;
    }

    /**
     * The database attributes of one valid row: each mapped column's database
     * column. Add what the file does not hold (a tenant ID, a search column).
     *
     * @param array<string, mixed> $values typed values by column ID
     * @return array<string, mixed>
     */
    protected function attributes(array $values, ImportContext $context): array
    {
        $attributes = [];
        foreach ($this->columns() as $column) {
            if (array_key_exists($column->id(), $values)) {
                $attributes[$column->databaseColumn()] = $values[$column->id()];
            }
        }

        return $attributes;
    }

    /**
     * Save one chunk of valid rows, inside a transaction. Report what happened
     * with `$context->inserted()`, `updated()`, `skipped()` and `reject()`.
     * The default inserts, updates or upserts by `importKey()` within `query()`.
     *
     * @param list<array{line: int, values: array<string, mixed>}> $rows
     */
    protected function save(array $rows, ImportContext $context): void
    {
        $query = $this->query($context) ?? throw new \LogicException(static::class.': override query() or save().');
        $key = $this->importKey();
        $keyColumn = $key === null ? null : ($this->column($key)?->databaseColumn() ?? $key);
        // The records the chunk refers to, in one query per 1,000 keys (not one per row).
        $existing = [];
        if ($keyColumn !== null) {
            $values = array_values(array_unique(array_filter(array_map(fn (array $row) => $row['values'][$key] ?? null, $rows), fn ($value) => $value !== null), SORT_REGULAR));
            foreach (array_chunk($values, 1_000) as $part) {
                foreach ((clone $query)->whereIn($keyColumn, $part)->get() as $record) {
                    $existing[(string) data_get($record, $keyColumn)] = $record;
                }
            }
        }
        foreach ($rows as $row) {
            $attributes = $this->attributes($row['values'], $context);
            $value = $key === null ? null : ($row['values'][$key] ?? null);
            $found = $value === null ? null : ($existing[(string) $value] ?? null);
            if ($found !== null) {
                if ($context->mode === 'insert') {
                    $context->reject($row['line'], __('acun-datatable::table.import_exists'), $key, (string) $value);
                } elseif ($found instanceof Model) {
                    $found->forceFill($attributes)->save();
                    $context->updated();
                } else {
                    (clone $query)->where($keyColumn, $value)->update($attributes);
                    $context->updated();
                }
            } elseif ($context->mode === 'update') {
                $context->skipped();
            } else {
                if ($query instanceof QueryBuilder) {
                    (clone $query)->insert($attributes);
                    $saved = (object) $attributes;
                } else {
                    $saved = $query instanceof Relation ? $query->make() : $query->getModel()->newInstance();
                    $saved->forceFill($attributes)->save();
                }
                if ($value !== null) {
                    $existing[(string) $value] = $saved; // the same key later in the file updates it
                }
                $context->inserted();
            }
        }
    }

    // ---- The protocol (called by the routes of Route::acunImport) -------------

    /** `GET {url}`: the limits and formats, as hints for the UI. */
    final public function capabilities(Request $request): JsonResponse
    {
        if (($refused = $this->refuse($request)) !== null) {
            return $refused;
        }

        return new JsonResponse(array_filter([
            'protocol' => self::PROTOCOL,
            'accept' => array_map(fn (string $extension) => '.'.$extension, $this->accept()),
            'maxBytes' => $this->maxKilobytes() * 1024,
            'maxRows' => $this->maxRows(),
            'modes' => $this->offeredModes(),
            'templateUrl' => $this->templateUrl(),
        ], fn ($value) => $value !== null));
    }

    /** `POST {url}`: check the file against the limits, keep it, read its header and first rows. */
    final public function upload(Request $request): JsonResponse
    {
        if (($refused = $this->refuse($request)) !== null) {
            return $refused;
        }
        $owner = $this->ownerOf($request);
        $idempotency = (string) $request->header('Idempotency-Key', '');
        $idempotencyKey = preg_match('/^[\x21-\x7e]{1,200}$/', $idempotency) === 1 ? 'acun-datatable:import-key:'.hash('sha256', $owner."\0".$idempotency) : null;
        if ($idempotencyKey !== null && is_string($known = Cache::get($idempotencyKey)) && ($state = $this->load($known, $owner)) !== null) {
            return new JsonResponse($this->view($state), 200);
        }

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return $this->problem(422, __('acun-datatable::table.import_no_file'), 'file');
        }
        $maxBytes = $this->maxKilobytes() * 1024;
        $tooLarge = __('acun-datatable::table.file_too_large', ['mb' => rtrim(rtrim(number_format($maxBytes / 1048576, 1, '.', ''), '0'), '.')]);
        if (! $file->isValid()) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? $this->problem(413, $tooLarge, 'file')
                : $this->problem(422, __('acun-datatable::table.file_unreadable'), 'file');
        }
        if ($file->getSize() > $maxBytes) {
            return $this->problem(413, $tooLarge, 'file');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, array_map('strtolower', $this->accept()), true) || ! $this->looksLike($file, $extension)) {
            return $this->problem(415, __('acun-datatable::table.file_type'), 'file');
        }
        try {
            $sheet = (new SheetReader(maxRows: $this->maxRows(), maxBytes: $maxBytes))->read((string) $file->getRealPath(), $extension);
        } catch (ImportException $error) {
            return $this->problem(422, $error->getMessage(), 'file');
        }
        if ($sheet->headers === []) {
            return $this->problem(422, __('acun-datatable::table.import_no_headers'), 'file');
        }

        $id = 'imp_'.Str::random(32);
        $path = $file->storeAs("acun-datatable/imports/{$id}", "source.{$extension}", ['disk' => $this->disk()]);
        $state = [
            'id' => $id, 'owner' => $owner, 'guard' => $this->guard(), 'user' => Auth::guard($this->guard())->id(),
            'tenant' => $this->tenant($request), 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 200),
            'extension' => $extension, 'path' => $path, 'headers' => array_values(array_map('strval', $sheet->headers)), 'rows' => count($sheet->rows),
            'mapping' => $this->suggest($sheet->headers),
            'preview' => array_map(fn (array $cells, int $index) => ['line' => $index + 2, 'cells' => array_map(fn ($cell) => self::text($cell), array_pad(array_slice($cells, 0, count($sheet->headers)), count($sheet->headers), null))], array_slice($sheet->rows, 0, 5), array_keys(array_slice($sheet->rows, 0, 5))),
            'status' => 'mapping', 'mode' => null, 'processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
            'errors' => [], 'errorsTruncated' => false, 'report' => null, 'message' => null,
            'expiresAt' => now()->addSeconds($this->keepSeconds())->toIso8601String(),
        ];
        $this->store($state);
        if ($idempotencyKey !== null) {
            Cache::put($idempotencyKey, $id, $this->keepSeconds());
        }

        return new JsonResponse($this->view($state), 201);
    }

    /** `POST {url}/{id}/start`: check the mapping and mode again, then import at once or queue it. */
    final public function start(Request $request, string $id): JsonResponse
    {
        if (($refused = $this->refuse($request)) !== null) {
            return $refused;
        }
        $state = $this->load($id, $this->ownerOf($request));
        if ($state === null) {
            return $this->problem(404, __('acun-datatable::table.import_not_found'));
        }
        if ($state['status'] !== 'mapping') {
            return $this->problem(409, __('acun-datatable::table.import_started'));
        }
        $problems = $this->checkStart($request->json()->all(), $state['headers']);
        if ($problems !== []) {
            return new JsonResponse(['message' => reset($problems), 'errors' => $problems], 422);
        }
        $offered = $this->offeredModes();
        $state['mapping'] = $request->json('mapping');
        $state['mode'] = $request->json('mode') ?? $offered[0];
        if ($this->shouldQueue($state['rows'])) {
            $state['status'] = 'queued';
            $this->store($state);
            $job = new RunTableImport(static::class, $id, $state['user'], $state['guard'], app()->getLocale());
            $pending = dispatch($job);
            if (($connection = $this->queueConnection()) !== null) {
                $pending->onConnection($connection);
            }
            if (($queue = $this->queueName()) !== null) {
                $pending->onQueue($queue);
            }
            unset($pending); // dispatched here

            return new JsonResponse($this->view($this->load($id, $state['owner']) ?? $state));
        }
        $state['status'] = 'running';
        $this->store($state);
        $this->process($id);

        return new JsonResponse($this->view($this->load($id, $state['owner']) ?? $state));
    }

    /** `GET {url}/{id}`: the import as it is now. */
    final public function state(Request $request, string $id): JsonResponse
    {
        if (($refused = $this->refuse($request)) !== null) {
            return $refused;
        }
        $state = $this->load($id, $this->ownerOf($request));

        return $state === null ? $this->problem(404, __('acun-datatable::table.import_not_found')) : new JsonResponse($this->view($state));
    }

    /** `DELETE {url}/{id}`: discard one waiting for its mapping, or stop a running one between chunks. */
    final public function cancel(Request $request, string $id): JsonResponse
    {
        if (($refused = $this->refuse($request)) !== null) {
            return $refused;
        }
        $state = $this->load($id, $this->ownerOf($request));
        if ($state === null) {
            return $this->problem(404, __('acun-datatable::table.import_not_found'));
        }
        if (in_array($state['status'], self::FINAL, true)) {
            return $this->problem(409, __('acun-datatable::table.import_finished'));
        }
        Cache::put($this->key($id).':cancel', true, $this->keepSeconds());
        if ($state['status'] === 'mapping') {
            Storage::disk($this->disk())->delete($state['path']);
        }
        $state['status'] = 'cancelled';
        $this->store($state);

        return new JsonResponse($this->view($state));
    }

    /** `GET {url}/{id}/errors.csv`: every refused row with its reason. */
    final public function errorReport(Request $request, string $id): Response
    {
        if (($refused = $this->refuse($request)) !== null) {
            return $refused;
        }
        $state = $this->load($id, $this->ownerOf($request));
        $disk = Storage::disk($this->disk());
        if ($state === null || $state['report'] === null || ! $disk->exists($state['report'])) {
            return $this->problem(404, __('acun-datatable::table.import_not_found'));
        }

        return new StreamedResponse(function () use ($disk, $state): void {
            $stream = $disk->readStream($state['report']);
            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="import-errors.csv"', 'Cache-Control' => 'no-store']);
    }

    /**
     * Read, validate and save an import that was started (at once, or in a
     * queued job). Its state is updated after every chunk; a cancel is noticed
     * between chunks.
     */
    final public function process(string $id): void
    {
        $state = Cache::get($this->key($id));
        if (! is_array($state) || ! in_array($state['status'], ['queued', 'running'], true)) {
            return;
        }
        $state['status'] = 'running';
        $this->store($state);
        $disk = Storage::disk($this->disk());
        $context = new ImportContext($id, (string) $state['mode'], (string) $state['owner'], $state['tenant'], $state['user']);
        try {
            $local = $this->localCopy($state['path']);
            $sheet = (new SheetReader(maxRows: $this->maxRows(), maxBytes: $this->maxKilobytes() * 1024))->read($local['path'], $state['extension']);
            ($local['cleanup'])();
            // The user's mapping as headers the RowImporter knows (column IDs); file columns left out get none.
            $headers = array_map(fn ($column) => is_string($column) ? $column : '', $state['mapping']);
            $options = $this->readOptions();
            $reader = new RowImporter(
                $this->columns(), [], $this->required(),
                (string) ($options['decimalSeparator'] ?? '.'), (string) ($options['dateOrder'] ?? 'dmy'), PHP_INT_MAX,
            );
            $reportPath = "acun-datatable/imports/{$id}/errors.csv";
            $titles = [];
            foreach ($this->columns() as $column) {
                $titles[$column->id()] = $column->headerText();
            }
            $size = max(1, $this->chunkSize());
            foreach (array_chunk($sheet->rows, $size) as $number => $rows) {
                if (Cache::get($this->key($id).':cancel') === true) {
                    break;
                }
                $offset = $number * $size;
                $result = $reader->import(new Sheet($headers, $rows));
                $valid = array_map(fn (array $row) => ['line' => $row['line'] + $offset, 'values' => $row['values']], $result->rows);
                $context->rejected = [];
                DB::transaction(fn () => $this->save($valid, $context));
                $problems = [
                    ...array_map(fn (array $error) => ['line' => $error['line'] + $offset] + array_filter(['column' => $error['column'], 'value' => $error['value']], fn ($v) => $v !== '') + ['code' => 'invalid', 'message' => $error['message']], $result->errors),
                    ...array_map(fn (array $error) => $error + ['code' => 'refused'], $context->rejected),
                ];
                $failedLines = count(array_unique(array_column($problems, 'line')));
                if ($problems !== []) {
                    if (! $disk->exists($reportPath)) {
                        $disk->put($reportPath, __('acun-datatable::table.import_error_report'));
                        $state['report'] = $reportPath;
                    }
                    $disk->append($reportPath, implode("\n", array_map(fn (array $error) => implode(',', array_map(self::csvCell(...), [
                        (string) $error['line'], isset($error['column']) ? ($titles[$error['column']] ?? $error['column']) : '', $error['value'] ?? '', $error['message'],
                    ])), $problems)));
                }
                $fresh = Cache::get($this->key($id));
                $state = (is_array($fresh) ? $fresh : $state) + ['report' => null];
                $state['report'] ??= $problems !== [] ? $reportPath : null;
                $state['processed'] = min($state['rows'], $offset + count($rows));
                [$state['inserted'], $state['updated'], $state['skipped']] = [$context->inserted, $context->updated, $context->skipped];
                $state['failed'] += $failedLines;
                $room = 100 - count($state['errors']);
                if ($room > 0) {
                    $state['errors'] = [...$state['errors'], ...array_slice($problems, 0, $room)];
                }
                $state['errorsTruncated'] = $state['errorsTruncated'] || count($problems) > max(0, $room);
                if ($state['status'] !== 'cancelled') {
                    $state['status'] = 'running';
                }
                $this->store($state);
            }
            $fresh = Cache::get($this->key($id));
            $state = is_array($fresh) ? $fresh : $state;
            $state['status'] = Cache::get($this->key($id).':cancel') === true ? 'cancelled' : 'done';
        } catch (Throwable $error) {
            report($error);
            $state['status'] = 'failed';
            $state['message'] = __('acun-datatable::table.import_failed');
        }
        $disk->delete($state['path']);
        $this->store($state);
    }

    // ---- Helpers -------------------------------------------------------------

    /** 403 when not authorized, 401 when nobody can own an import (no user, no session). */
    private function refuse(Request $request): ?JsonResponse
    {
        if (! $this->authorize($request)) {
            return $this->problem(403, __('acun-datatable::table.import_forbidden'));
        }
        if (TableTasks::owner($this->guard()) === null) {
            return $this->problem(401, __('acun-datatable::table.import_forbidden'));
        }

        return null;
    }

    private function ownerOf(Request $request): string
    {
        return (string) TableTasks::owner($this->guard()).'|'.($this->tenant($request) ?? '');
    }

    private function key(string $id): string
    {
        return 'acun-datatable:import:'.$id;
    }

    /** @return array<string, mixed>|null the import, only for its owner (user and tenant) */
    private function load(string $id, string $owner): ?array
    {
        if (preg_match('/^imp_[A-Za-z0-9]{32}$/', $id) !== 1) {
            return null;
        }
        $state = Cache::get($this->key($id));

        return is_array($state) && hash_equals((string) $state['owner'], $owner) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function store(array $state): void
    {
        Cache::put($this->key($state['id']), $state, $this->keepSeconds());
    }

    /** @return list<string> */
    private function offeredModes(): array
    {
        $modes = array_values(array_intersect($this->modes(), self::MODES));
        if ($modes === []) {
            throw new \LogicException(static::class.': modes() must offer insert, update or upsert.');
        }

        return $modes;
    }

    private function column(string $id): ?Column
    {
        foreach ($this->columns() as $column) {
            if ($column->id() === $id) {
                return $column;
            }
        }

        return null;
    }

    /**
     * The import as the protocol answers it.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function view(array $state): array
    {
        $answer = [
            'protocol' => self::PROTOCOL, 'id' => $state['id'], 'status' => $state['status'],
            'file' => ['name' => $state['name'], 'rows' => $state['rows']],
            'processed' => $state['processed'], 'total' => $state['rows'],
            'inserted' => $state['inserted'], 'updated' => $state['updated'], 'skipped' => $state['skipped'], 'failed' => $state['failed'],
            'errors' => $state['errors'], 'errorsTruncated' => $state['errorsTruncated'],
            'errorReport' => $state['report'] !== null && in_array($state['status'], self::FINAL, true) ? "{$state['id']}/errors.csv" : null,
            'cancellable' => ! in_array($state['status'], self::FINAL, true),
            'expiresAt' => $state['expiresAt'],
        ];
        if ($state['message'] !== null) {
            $answer['message'] = $state['message'];
        }
        if (in_array($state['status'], ['queued', 'running'], true)) {
            $answer['retryAfter'] = 1000;
        }
        if ($state['status'] === 'mapping') {
            $required = $this->required();
            $answer += [
                'headers' => $state['headers'],
                'columns' => array_map(fn (Column $column) => array_filter([
                    'id' => $column->id(), 'title' => $column->headerText(), 'type' => $column->valueType() ?? 'text',
                    'required' => in_array($column->id(), $required, true) ? true : null,
                ], fn ($value) => $value !== null), array_values($this->columns())),
                'mapping' => $state['mapping'],
                'preview' => $state['preview'],
                'modes' => $this->offeredModes(),
                'mode' => $this->offeredModes()[0],
            ];
        } elseif ($state['mode'] !== null) {
            $answer['mode'] = $state['mode'];
        }

        return $answer;
    }

    /**
     * The back end's suggestion: a header matches a column's header text, ID or
     * extra names (`readOptions()['headers']`), ignoring case, accents and ı/İ.
     *
     * @param list<string> $headers
     * @return list<string|null>
     */
    private function suggest(array $headers): array
    {
        $extra = (array) ($this->readOptions()['headers'] ?? []);
        $names = [];
        foreach ($this->columns() as $column) {
            foreach ([$column->headerText(), $column->id(), ...(array) ($extra[$column->id()] ?? [])] as $name) {
                $names[Text::fold(trim((string) $name))] ??= $column->id();
            }
        }
        $used = [];

        return array_map(function ($header) use ($names, &$used) {
            $id = $names[Text::fold(trim((string) $header))] ?? null;
            if ($id === null || isset($used[$id])) {
                return null;
            }
            $used[$id] = true;

            return $id;
        }, array_values($headers));
    }

    /**
     * The start check of the protocol (never trusting the UI): one entry per
     * header, known columns at most once, every required column, an offered mode.
     *
     * @param list<string> $headers
     * @return array<string, string>
     */
    private function checkStart(mixed $body, array $headers): array
    {
        $problems = [];
        $mapping = is_array($body) ? ($body['mapping'] ?? null) : null;
        if (! is_array($mapping) || ! array_is_list($mapping) || count($mapping) !== count($headers)) {
            $problems['mapping'] = __('acun-datatable::table.import_mapping_length', ['count' => count($headers)]);
        } else {
            $known = [];
            foreach ($this->columns() as $column) {
                $known[$column->id()] = $column->headerText();
            }
            $seen = [];
            foreach ($mapping as $entry) {
                if ($entry === null) {
                    continue;
                }
                if (! is_string($entry) || ! isset($known[$entry])) {
                    $problems['mapping'] = __('acun-datatable::table.import_unknown_column', ['column' => is_scalar($entry) ? (string) $entry : '?']);
                    break;
                }
                if (isset($seen[$entry])) {
                    $problems['mapping'] = __('acun-datatable::table.import_mapped_twice', ['column' => $known[$entry]]);
                    break;
                }
                $seen[$entry] = true;
            }
            $missing = array_values(array_filter($this->required(), fn (string $id) => ! isset($seen[$id])));
            if (! isset($problems['mapping']) && $missing !== []) {
                $problems['mapping'] = __('acun-datatable::table.import_required_columns', ['columns' => implode(', ', array_map(fn ($id) => $known[$id] ?? $id, $missing))]);
            }
        }
        $offered = $this->offeredModes();
        $mode = is_array($body) ? ($body['mode'] ?? null) : null;
        if ($mode === null && count($offered) > 1) {
            $problems['mode'] = __('acun-datatable::table.import_choose_mode', ['modes' => implode(', ', $offered)]);
        } elseif ($mode !== null && (! is_string($mode) || ! in_array($mode, $offered, true))) {
            $problems['mode'] = __('acun-datatable::table.import_mode', ['mode' => is_scalar($mode) ? (string) $mode : '?']);
        }

        return $problems;
    }

    /** The file's own signature: xlsx is a zip archive; CSV is text (no NUL bytes). */
    private function looksLike(UploadedFile $file, string $extension): bool
    {
        $handle = fopen((string) $file->getRealPath(), 'rb');
        if ($handle === false) {
            return false;
        }
        $start = (string) fread($handle, 8192);
        fclose($handle);

        return $extension === 'xlsx' ? str_starts_with($start, "PK\x03\x04") : ! str_contains($start, "\0");
    }

    /** @return array{path: string, cleanup: callable(): void} the stored file as a local path (copied from a remote disk) */
    private function localCopy(string $path): array
    {
        $disk = Storage::disk($this->disk());
        try {
            $local = $disk->path($path);
            if (is_file($local)) {
                return ['path' => $local, 'cleanup' => fn () => null];
            }
        } catch (Throwable) {
            // not a local disk
        }
        $temporary = tempnam(sys_get_temp_dir(), 'adt-import-');
        $target = fopen($temporary, 'wb');
        $source = $disk->readStream($path);
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        return ['path' => $temporary, 'cleanup' => fn () => @unlink($temporary)];
    }

    private function problem(int $status, string $message, ?string $field = null): JsonResponse
    {
        return new JsonResponse(['message' => $message] + ($field !== null ? ['errors' => [$field => [$message]]] : []), $status);
    }

    /** A cell of the preview as text. */
    private static function text(mixed $cell): ?string
    {
        return match (true) {
            $cell === null, $cell === '' => null,
            $cell instanceof DateTimeInterface => $cell->format($cell->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i'),
            is_bool($cell) => $cell ? 'true' : 'false',
            default => (string) $cell,
        };
    }

    /** A CSV cell of the error report, guarded against formulas. */
    private static function csvCell(string $value): string
    {
        if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            $value = "'".$value;
        }

        return preg_match('/[",\r\n]/', $value) === 1 ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
