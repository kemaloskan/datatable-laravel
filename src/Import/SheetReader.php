<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

use Carbon\CarbonImmutable;
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
        if (! is_file($path) || ! is_readable($path)) {
            throw new ImportException(__('acun-datatable::table.file_unreadable'));
        }
        if (filesize($path) > $this->maxBytes) {
            throw new ImportException(__('acun-datatable::table.file_too_large', ['mb' => intdiv($this->maxBytes, 1024 * 1024)]));
        }
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => $this->csv((string) file_get_contents($path)),
            'xlsx' => $this->xlsx($path, $sheet),
            default => throw new ImportException(__('acun-datatable::table.file_type')),
        };
    }

    public function csv(string $content): Sheet
    {
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1254');
        }
        $delimiter = $this->detect($content);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);
        $records = [];
        $line = 0;
        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($record === [null] || $record === ['']) {
                continue;
            }
            if (count($record) > $this->maxColumns) {
                throw new ImportException(__('acun-datatable::table.too_many_columns', ['max' => $this->maxColumns]));
            }
            $records[] = $record;
            if (count($records) > $this->maxRows + 1) {
                throw new ImportException(__('acun-datatable::table.too_many_rows', ['max' => $this->maxRows]));
            }
        }
        fclose($stream);

        return $this->sheet($records);
    }

    private function detect(string $content): string
    {
        $first = strtok($content, "\r\n") ?: '';
        $first = preg_replace('/"[^"]*"/', '', $first) ?? $first;
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);

        return reset($counts) === 0 ? ',' : (string) array_key_first($counts);
    }

    private function xlsx(string $path, string|int|null $wanted): Sheet
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

            $shared = [];
            preg_match_all('/<(?:\w+:)?si\b[^>]*?(?:\/>|>(.*?)<\/(?:\w+:)?si>)/s', $this->entry($zip, $byType('/sharedStrings', 'xl/sharedStrings.xml')) ?? '', $matches);
            foreach ($matches[1] as $inner) {
                $shared[] = self::runs($inner);
            }

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

            $sheetPath = $chosen['relation'] !== null && isset($relations[$chosen['relation']])
                ? $relations[$chosen['relation']]['target'] : 'xl/worksheets/sheet1.xml';
            $worksheet = $this->entry($zip, $sheetPath) ?? throw new ImportException(__('acun-datatable::table.file_damaged'));
        } finally {
            $zip->close();
        }

        $data = preg_match('/<(?:\w+:)?sheetData\b[^>]*?(?:\/>|>(.*?)<\/(?:\w+:)?sheetData>)/s', $worksheet, $match) === 1 ? ($match[1] ?? '') : '';
        $records = [];
        if (preg_match_all('/<(?:\w+:)?row\b[^>]*?(?:\/>|>(.*?)<\/(?:\w+:)?row>)/s', $data, $rows) === false) {
            throw new ImportException(__('acun-datatable::table.file_damaged'));
        }
        foreach ($rows[1] as $rowXml) {
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
            if (array_filter($cells, fn ($cell) => $cell !== null && $cell !== '') === []) {
                continue;
            }
            $records[] = $cells;
            if (count($records) > $this->maxRows + 1) {
                throw new ImportException(__('acun-datatable::table.too_many_rows', ['max' => $this->maxRows]));
            }
        }

        return $this->sheet($records);
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
        $headerRow = array_shift($records) ?? [];
        $headers = array_map(fn ($cell) => trim($cell instanceof \DateTimeInterface ? $cell->format('Y-m-d') : (string) $cell), $headerRow);
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }
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
