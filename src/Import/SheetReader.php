<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

use Carbon\CarbonImmutable;
use Generator;
use ZipArchive;

/**
 * Read an uploaded CSV or xlsx file into a Sheet (first non-empty row =
 * headers), with limits suited to user uploads.
 *
 * - CSV: `,` `;` or tab detected; quotes and line breaks in fields; a UTF-8
 *   byte order mark is removed; files that are not valid UTF-8 are read as
 *   Windows-1254 (what Turkish Excel writes).
 * - xlsx: the first (or a named) worksheet; shared and inline strings, rich
 *   text, date formats, 1900/1904 dates, cached formula results (formulas are
 *   never evaluated). XML is scanned as text: no DTD, no entity expansion.
 * - Limits: file size, rows, columns, archive entries and the inflated size
 *   of each entry, counted while reading (zip-bomb safe).
 *
 * `read()` gives the whole Sheet; `stream()` gives the headers at once and the
 * rows in chunks as the file is read, so memory follows the chunk, not the
 * file (both read the same rows).
 */
final class SheetReader
{
    private const BUILTIN_DATES = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    public function __construct(
        private readonly int $maxRows = 10_000,
        private readonly int $maxColumns = 1_000,
        private readonly int $maxBytes = 20 * 1024 * 1024,
        private readonly int $maxEntryBytes = 50 * 1024 * 1024,
    ) {
    }

    /** @param string|null $extension `csv`, `txt` or `xlsx`; default from the path */
    public function read(string $path, ?string $extension = null, string|int|null $sheet = null): Sheet
    {
        return $this->sheet(iterator_to_array($this->records($path, $extension, $sheet), false));
    }

    /**
     * The headers at once, the rows as they are read (`chunks()`), with the
     * same rows, checks and limits as `read()`.
     *
     * @param string|null $extension `csv`, `txt` or `xlsx`; default from the path
     */
    public function stream(string $path, ?string $extension = null, string|int|null $sheet = null): SheetStream
    {
        $records = $this->records($path, $extension, $sheet);
        $headers = $this->headers($records->valid() ? $records->current() : []);
        $records->next();

        return new SheetStream($headers, $records);
    }

    public function csv(string $content): Sheet
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $this->sheet(iterator_to_array($this->limited($this->csvRecords($stream)), false));
    }

    /** @return Generator<int, list<mixed>> the non-empty records of a file, the header row first */
    private function records(string $path, ?string $extension, string|int|null $sheet): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ImportException(__('acun-datatable::table.file_unreadable'));
        }
        if (filesize($path) > $this->maxBytes) {
            throw new ImportException(__('acun-datatable::table.file_too_large', ['mb' => intdiv($this->maxBytes, 1024 * 1024)]));
        }
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));

        yield from $this->limited(match ($extension) {
            'csv', 'txt' => $this->csvRecords(fopen($path, 'rb')),
            'xlsx' => $this->xlsxRecords($path, $sheet),
            default => throw new ImportException(__('acun-datatable::table.file_type')),
        });
    }

    /**
     * @param Generator<int, list<mixed>> $records
     * @return Generator<int, list<mixed>> the records, refused past the row limit (the header row not counted)
     */
    private function limited(Generator $records): Generator
    {
        $count = 0;
        foreach ($records as $record) {
            if (++$count > $this->maxRows + 1) {
                throw new ImportException(__('acun-datatable::table.too_many_rows', ['max' => $this->maxRows]));
            }
            yield $record;
        }
    }

    /**
     * The records of a CSV stream (closed when done). A UTF-8 byte order mark
     * is skipped; a stream that is not valid UTF-8 is read as Windows-1254,
     * converted into a temporary stream (in memory up to 2 MB, then on disk).
     *
     * @param resource $handle
     * @return Generator<int, list<string|null>>
     */
    private function csvRecords($handle): Generator
    {
        try {
            if (fread($handle, 3) === "\u{FEFF}") {
                $stream = $handle;
            } elseif (self::isUtf8($handle)) {
                rewind($handle);
                $stream = $handle;
            } else {
                rewind($handle);
                $stream = fopen('php://temp/maxmemory:'.(2 * 1024 * 1024), 'w+b');
                while (($chunk = fread($handle, 1 << 20)) !== false && $chunk !== '') {
                    // Windows-1254 has one byte per character: pieces convert like the whole.
                    fwrite($stream, mb_convert_encoding($chunk, 'UTF-8', 'Windows-1254'));
                }
                fclose($handle);
                rewind($stream);
            }
            $start = (int) ftell($stream);
            $head = '';
            while (preg_match('/[^\r\n][\r\n]/', $head) !== 1 && ($chunk = fread($stream, 65536)) !== false && $chunk !== '') {
                $head .= $chunk;
            }
            $delimiter = $this->detect($head);
            fseek($stream, $start);
            while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if ($record === [null] || $record === ['']) {
                    continue;
                }
                if (count($record) > $this->maxColumns) {
                    throw new ImportException(__('acun-datatable::table.too_many_columns', ['max' => $this->maxColumns]));
                }
                yield $record;
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Whether a whole stream is valid UTF-8, checked in pieces cut between
     * characters (as one check of the whole would answer).
     *
     * @param resource $handle
     */
    private static function isUtf8($handle): bool
    {
        rewind($handle);
        $carry = '';
        while (($chunk = fread($handle, 1 << 20)) !== false && $chunk !== '') {
            $chunk = $carry.$chunk;
            $length = strlen($chunk);
            $cut = $length;
            for ($back = 1; $back <= min(3, $length); $back++) {
                $byte = ord($chunk[$length - $back]);
                if ($byte >= 0xC0) {
                    $cut = ($byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2)) > $back ? $length - $back : $length;
                    break;
                }
                if ($byte < 0x80) {
                    break;
                }
            }
            if (! mb_check_encoding(substr($chunk, 0, $cut), 'UTF-8')) {
                return false;
            }
            $carry = substr($chunk, $cut);
        }

        return $carry === '' || mb_check_encoding($carry, 'UTF-8');
    }

    private function detect(string $content): string
    {
        $first = strtok($content, "\r\n") ?: '';
        $first = preg_replace('/"[^"]*"/', '', $first) ?? $first;
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);

        return reset($counts) === 0 ? ',' : (string) array_key_first($counts);
    }

    /** @return Generator<int, list<mixed>> the non-empty rows of a worksheet, read as it inflates */
    private function xlsxRecords(string $path, string|int|null $wanted): Generator
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ImportException(__('acun-datatable::table.file_needs_zip'));
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportException(__('acun-datatable::table.file_not_xlsx'));
        }
        try {
            if ($zip->numFiles > 2_000) {
                throw new ImportException(__('acun-datatable::table.file_damaged'));
            }
            $workbook = $this->entry($zip, 'xl/workbook.xml') ?? throw new ImportException(__('acun-datatable::table.file_not_xlsx'));
            $date1904 = preg_match('/<(?:\w+:)?workbookPr\b[^>]*\bdate1904\s*=\s*["\'](?:1|true)["\']/', $workbook) === 1;
            preg_match_all('/<(?:\w+:)?sheet\b([^>]*?)\/?>/', $workbook, $matches);
            $sheets = array_map(function (string $source) {
                $attrs = self::attributes($source);
                $relation = null;
                foreach ($attrs as $key => $value) {
                    if ($key !== 'sheetId' && preg_match('/(^|:)id$/', $key) === 1) {
                        $relation = $value;
                    }
                }

                return ['name' => $attrs['name'] ?? '', 'relation' => $relation];
            }, $matches[1]);
            $wanted ??= 0;
            $chosen = is_int($wanted) ? ($sheets[$wanted] ?? null) : (collect($sheets)->firstWhere('name', $wanted));
            if ($chosen === null) {
                throw new ImportException(__('acun-datatable::table.sheet_not_found', ['name' => (string) $wanted]));
            }

            $relations = [];
            preg_match_all('/<(?:\w+:)?Relationship\b([^>]*?)\/?>/', $this->entry($zip, 'xl/_rels/workbook.xml.rels') ?? '', $matches);
            foreach ($matches[1] as $source) {
                $attrs = self::attributes($source);
                if (isset($attrs['Id'], $attrs['Target'])) {
                    $relations[$attrs['Id']] = ['target' => self::resolve($attrs['Target']), 'type' => $attrs['Type'] ?? ''];
                }
            }
            $byType = function (string $suffix, string $fallback) use ($relations): string {
                foreach ($relations as $relation) {
                    if (str_ends_with($relation['type'], $suffix)) {
                        return $relation['target'];
                    }
                }

                return $fallback;
            };

            $styles = $this->entry($zip, $byType('/styles', 'xl/styles.xml')) ?? '';
            $custom = [];
            preg_match_all('/<(?:\w+:)?numFmt\b([^>]*?)\/?>/', $styles, $matches);
            foreach ($matches[1] as $source) {
                $attrs = self::attributes($source);
                $custom[(int) ($attrs['numFmtId'] ?? -1)] = $attrs['formatCode'] ?? '';
            }
            $dateStyles = [];
            if (preg_match('/<(?:\w+:)?cellXfs\b[^>]*>(.*?)<\/(?:\w+:)?cellXfs>/s', $styles, $xfs) === 1) {
                preg_match_all('/<(?:\w+:)?xf\b([^>]*?)\/?>/', $xfs[1], $matches);
                foreach ($matches[1] as $index => $source) {
                    $id = (int) (self::attributes($source)['numFmtId'] ?? 0);
                    if (in_array($id, self::BUILTIN_DATES, true) || (isset($custom[$id]) && self::isDateFormat($custom[$id]))) {
                        $dateStyles[$index] = true;
                    }
                }
            }

            $shared = [];
            foreach (self::elements($this->pieces($zip, $byType('/sharedStrings', 'xl/sharedStrings.xml')), 'si') as $inner) {
                $shared[] = self::runs($inner ?? '');
            }

            $sheetPath = $chosen['relation'] !== null && isset($relations[$chosen['relation']])
                ? $relations[$chosen['relation']]['target'] : 'xl/worksheets/sheet1.xml';
            if ($zip->statName($sheetPath) === false) {
                throw new ImportException(__('acun-datatable::table.file_damaged'));
            }
            foreach (self::elements($this->pieces($zip, $sheetPath), 'row', 'sheetData') as $rowXml) {
                $cells = $this->rowCells($rowXml ?? '', $shared, $dateStyles, $date1904);
                if (array_filter($cells, fn ($cell) => $cell !== null && $cell !== '') !== []) {
                    yield $cells;
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The cells of one row (its inner XML), placed by their references.
     *
     * @param list<string> $shared
     * @param array<int, true> $dateStyles
     * @return list<mixed>
     */
    private function rowCells(string $rowXml, array $shared, array $dateStyles, bool $date1904): array
    {
        $cells = [];
        $next = 0;
        preg_match_all('/<(?:\w+:)?c\b([^>]*?)(?:\/>|>(.*?)<\/(?:\w+:)?c>)/s', $rowXml, $found, PREG_SET_ORDER);
        foreach ($found as $cellMatch) {
            $attrs = self::attributes($cellMatch[1]);
            $index = isset($attrs['r']) ? (self::columnIndex($attrs['r']) ?? $next) : $next;
            $next = $index + 1;
            if ($index >= $this->maxColumns) {
                throw new ImportException(__('acun-datatable::table.too_many_columns', ['max' => $this->maxColumns]));
            }
            $inner = $cellMatch[2] ?? '';
            $value = preg_match('/<(?:\w+:)?v\b[^>]*>(.*?)<\/(?:\w+:)?v>/s', $inner, $v) === 1 ? self::decode($v[1]) : null;
            $cell = match ($attrs['t'] ?? 'n') {
                's' => $value === null ? null : ($shared[(int) $value] ?? null),
                'inlineStr' => self::runs(preg_match('/<(?:\w+:)?is\b[^>]*>(.*?)<\/(?:\w+:)?is>/s', $inner, $is) === 1 ? $is[1] : ''),
                'str' => $value,
                'b' => $value === null ? null : ($value === '1' || $value === 'true'),
                'e' => null,
                'd' => $value === null ? null : rescue(fn () => CarbonImmutable::parse($value), null, false),
                default => $value === null || trim($value) === '' || ! is_numeric($value) ? ($value === '' ? null : $value)
                    : (isset($dateStyles[(int) ($attrs['s'] ?? 0)]) ? self::fromSerial((float) $value, $date1904) : 0 + $value),
            };
            while (count($cells) < $index) {
                $cells[] = null;
            }
            $cells[$index] = $cell;
        }

        return $cells;
    }

    /**
     * One archive entry in pieces as it inflates (none when it does not
     * exist), refusing entries that declare or inflate beyond the limit.
     *
     * @return Generator<int, string>
     */
    private function pieces(ZipArchive $zip, string $name): Generator
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return;
        }
        if (($stat['encryption_method'] ?? 0) !== 0) {
            throw new ImportException(__('acun-datatable::table.file_encrypted'));
        }
        $tooLarge = fn () => new ImportException(__('acun-datatable::table.file_too_large', ['mb' => intdiv($this->maxEntryBytes, 1024 * 1024)]));
        if ($stat['size'] > $this->maxEntryBytes) {
            throw $tooLarge();
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new ImportException(__('acun-datatable::table.file_damaged'));
        }
        try {
            $total = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    break;
                }
                // The declared size can lie (zip bombs): count what actually comes out.
                $total += strlen($chunk);
                if ($total > $this->maxEntryBytes) {
                    throw $tooLarge();
                }
                yield $chunk;
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * The inner XML of each `<name>` element of XML read in pieces (null for
     * an empty `<name/>`), matched as the whole-text regex matched them. With
     * a container, only the elements in its first occurrence count; one that
     * opens and never closes makes the file damaged.
     *
     * @param Generator<int, string> $pieces
     * @return Generator<int, string|null>
     */
    private static function elements(Generator $pieces, string $name, ?string $container = null): Generator
    {
        $begin = $container !== null ? '/<(?:\w+:)?'.$container.'\b[^>]*?(\/?)>/' : null;
        $next = '/<(?:\w+:)?'.$name.'\b'.($container !== null ? '|<\/(?:\w+:)?'.$container.'>' : '').'/';
        $close = '/<\/(?:\w+:)?('.$name.($container !== null ? '|'.$container : '').')>/';
        $buffer = '';
        $at = 0; // where to look next in the buffer
        $scanned = 0; // where the search for the end of an incomplete element goes on
        $inside = $container === null;
        $final = false;
        $match = function (string $pattern, int $offset, ?array &$found) use (&$buffer): bool {
            $result = preg_match($pattern, $buffer, $found, PREG_OFFSET_CAPTURE, $offset);
            if ($result === false) {
                throw new ImportException(__('acun-datatable::table.file_damaged'));
            }

            return $result === 1;
        };
        // Keep only a tag that may still be arriving.
        $keepTag = function () use (&$buffer, &$at): int {
            $tag = $at < strlen($buffer) ? strrpos($buffer, '<', $at) : false;

            return $tag === false ? strlen($buffer) : $tag;
        };
        for (;;) {
            if (! $inside && $begin !== null) {
                if ($match($begin, $at, $found)) {
                    if ($found[1][0] === '/') {
                        return; // <sheetData/>: no rows
                    }
                    $inside = true;
                    $at = $found[0][1] + strlen($found[0][0]);
                } else {
                    $at = $keepTag();
                }
            }
            while ($inside) {
                if (! $match($next, $at, $found)) {
                    $at = $keepTag();
                    $scanned = 0;
                    break;
                }
                if ($found[0][0][1] === '/') {
                    return; // the container closes
                }
                $start = $found[0][1];
                $tagEnd = strpos($buffer, '>', $start);
                if ($tagEnd === false) {
                    $at = $start;
                    break;
                }
                if ($buffer[$tagEnd - 1] === '/') {
                    yield null;
                    $at = $tagEnd + 1;
                    continue;
                }
                if (! $match($close, max($tagEnd + 1, $scanned), $end)) {
                    if ($final) {
                        $at = $start + 1; // never closed: not an element
                        continue;
                    }
                    $at = $start;
                    $scanned = max($tagEnd + 1, strlen($buffer) - 128); // its end can only be in what comes next
                    break;
                }
                if ($end[1][0] === $name) {
                    yield substr($buffer, $tagEnd + 1, $end[0][1] - $tagEnd - 1);
                    $at = $end[0][1] + strlen($end[0][0]);
                } else {
                    $at = $start + 1; // the container closes inside it: not an element; the container ends next
                }
                $scanned = 0;
            }
            if ($final) {
                if ($inside && $container !== null) {
                    throw new ImportException(__('acun-datatable::table.file_damaged'));
                }

                return;
            }
            if ($pieces->valid()) {
                $buffer = substr($buffer, $at).$pieces->current();
                $scanned = max(0, $scanned - $at);
                $at = 0;
                $pieces->next();
            } else {
                $final = true;
            }
        }
    }

    /** Read one archive entry, refusing entries that inflate beyond the limit. */
    private function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if (($stat['encryption_method'] ?? 0) !== 0) {
            throw new ImportException(__('acun-datatable::table.file_encrypted'));
        }
        if ($stat['size'] > $this->maxEntryBytes) {
            throw new ImportException(__('acun-datatable::table.file_too_large', ['mb' => intdiv($this->maxEntryBytes, 1024 * 1024)]));
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new ImportException(__('acun-datatable::table.file_damaged'));
        }
        $content = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $content .= $chunk;
            if (strlen($content) > $this->maxEntryBytes) {
                fclose($stream);
                throw new ImportException(__('acun-datatable::table.file_too_large', ['mb' => intdiv($this->maxEntryBytes, 1024 * 1024)]));
            }
        }
        fclose($stream);

        return $content;
    }

    /** @param list<list<mixed>> $records */
    private function sheet(array $records): Sheet
    {
        $headers = $this->headers(array_shift($records) ?? []);
        $width = count($headers);
        $rows = array_map(function (array $row) use ($width) {
            $out = [];
            for ($i = 0; $i < $width; $i++) {
                $out[] = $row[$i] ?? null;
            }

            return $out;
        }, $records);

        return new Sheet($headers, $rows);
    }

    /**
     * @param list<mixed> $headerRow
     * @return list<string> the header texts, without trailing empty ones
     */
    private function headers(array $headerRow): array
    {
        $headers = array_map(fn ($cell) => trim($cell instanceof \DateTimeInterface ? $cell->format('Y-m-d') : (string) $cell), $headerRow);
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        return $headers;
    }

    /** @return array<string, string> */
    private static function attributes(string $source): array
    {
        preg_match_all('/([\w:.-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $source, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $match) {
            $out[$match[1]] = self::decode(($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
        }

        return $out;
    }

    private static function decode(string $text): string
    {
        $text = preg_replace_callback('/&(?:#x([0-9a-fA-F]+)|#(\d+)|(lt|gt|amp|quot|apos));/', function (array $m) {
            if (($m[3] ?? '') !== '') {
                return ['lt' => '<', 'gt' => '>', 'amp' => '&', 'quot' => '"', 'apos' => "'"][$m[3]];
            }
            $code = ($m[1] ?? '') !== '' ? hexdec($m[1]) : (int) $m[2];

            return $code <= 0x10FFFF ? mb_chr((int) $code, 'UTF-8') : $m[0];
        }, $text) ?? $text;

        return preg_replace_callback('/_x([0-9a-fA-F]{4})_/', fn (array $m) => mb_chr((int) hexdec($m[1]), 'UTF-8'), $text) ?? $text;
    }

    private static function runs(string $xml): string
    {
        $visible = preg_replace('/<(?:\w+:)?rPh\b.*?<\/(?:\w+:)?rPh>/s', '', $xml) ?? $xml;
        preg_match_all('/<(?:\w+:)?t\b[^>]*?(?:\/>|>(.*?)<\/(?:\w+:)?t>)/s', $visible, $matches);

        return implode('', array_map(fn ($text) => self::decode($text ?? ''), $matches[1]));
    }

    private static function isDateFormat(string $code): bool
    {
        $bare = preg_replace(['/"[^"]*"/', '/\\\\./', '/\[(?!h\]|m\]|s\])[^\]]*\]/i'], '', $code) ?? $code;

        return preg_match('/[dmyhs]/i', $bare) === 1 && strcasecmp(trim($bare), 'general') !== 0;
    }

    private static function columnIndex(string $ref): ?int
    {
        if (preg_match('/^([A-Z]+)\d*$/i', $ref, $m) !== 1) {
            return null;
        }
        $index = 0;
        foreach (str_split(strtoupper($m[1])) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    private static function resolve(string $target): string
    {
        $parts = explode('/', str_starts_with($target, '/') ? substr($target, 1) : 'xl/'.$target);
        $out = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($out);
            } elseif ($part !== '.' && $part !== '') {
                $out[] = $part;
            }
        }

        return implode('/', $out);
    }

    /** A spreadsheet serial number as a local date-time in the app timezone. */
    private static function fromSerial(float $serial, bool $date1904): CarbonImmutable
    {
        $seconds = (int) round($serial * 86400);
        $epoch = $date1904 ? gmmktime(0, 0, 0, 1, 1, 1904) : gmmktime(0, 0, 0, 12, 30, 1899);
        $utc = $epoch + $seconds;

        return CarbonImmutable::create(
            (int) gmdate('Y', $utc), (int) gmdate('n', $utc), (int) gmdate('j', $utc),
            (int) gmdate('G', $utc), (int) gmdate('i', $utc), (int) gmdate('s', $utc),
            config('app.timezone') ?: 'UTC',
        );
    }
}
