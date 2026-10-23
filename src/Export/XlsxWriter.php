<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Export;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Streams rows into an `.xlsx` file (Office Open XML) with ext-zip: the
 * worksheet is written to a temporary file row by row, so memory stays flat.
 * Same output as the browser package: typed numbers, decimals with their scale
 * (1–6 digits), Excel dates, booleans, a bold frozen header; text is always
 * an inline string, never a formula.
 */
final class XlsxWriter
{
    public const MAX_ROWS = 1_048_575;
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const HEAD = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n";

    /** @var resource */
    private $sheet;
    private string $sheetPath;
    private int $row = 0;
    /** @var list<int> */
    private array $widths = [];

    public function __construct(private readonly string $path, private readonly string $sheetName = 'Sheet1')
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Excel export needs the PHP zip extension (ext-zip).');
        }
        $this->sheetPath = tempnam(sys_get_temp_dir(), 'adt-sheet-') ?: throw new RuntimeException('Cannot create a temporary file.');
        $handle = fopen($this->sheetPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write a temporary file.');
        }
        $this->sheet = $handle;
    }

    /** @param list<string> $headers */
    public function header(array $headers): void
    {
        $this->widths = array_map(fn (string $header) => mb_strlen($header), $headers);
        $this->write(array_map(fn (string $header) => ['header', $header], $headers));
    }

    /**
     * @param list<array{0: string, 1: mixed}> $cells `[kind, value]` with kind
     *        `text`, `number`, `decimal`, `date`, `datetime`, `boolean` or `null`
     */
    public function row(array $cells): void
    {
        if ($this->row > self::MAX_ROWS) {
            throw new RuntimeException('An xlsx sheet holds at most '.self::MAX_ROWS.' data rows.');
        }
        $this->write($cells);
    }

    /** Finish the file at the path given to the constructor. */
    public function close(): void
    {
        fclose($this->sheet);
        $cols = '';
        foreach ($this->widths as $index => $width) {
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $index + 1, max(8, min($width, 60) + 2));
        }
        $zip = new ZipArchive();
        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the xlsx file.');
        }
        $zip->addFromString('[Content_Types].xml', self::HEAD.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', self::HEAD.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::REL.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', self::HEAD.'<workbook xmlns="'.self::MAIN.'" xmlns:r="'.self::REL.'">'
            .'<sheets><sheet name="'.self::xml(self::sheetName($this->sheetName)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::HEAD.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::REL.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.self::REL.'/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());

        // Wrap the streamed rows: head + rows + tail, without loading them.
        $worksheet = tempnam(sys_get_temp_dir(), 'adt-ws-') ?: throw new RuntimeException('Cannot create a temporary file.');
        $out = fopen($worksheet, 'wb');
        $in = fopen($this->sheetPath, 'rb');
        fwrite($out, self::HEAD.'<worksheet xmlns="'.self::MAIN.'" xmlns:r="'.self::REL.'">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'.($cols === '' ? '' : "<cols>{$cols}</cols>").'<sheetData>');
        stream_copy_to_stream($in, $out);
        fwrite($out, '</sheetData></worksheet>');
        fclose($in);
        fclose($out);
        $zip->addFile($worksheet, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($worksheet);
        @unlink($this->sheetPath);
    }

    /** @param list<array{0: string, 1: mixed}> $cells */
    private function write(array $cells): void
    {
        $this->row++;
        $xml = '<row r="'.$this->row.'">';
        foreach (array_values($cells) as $index => [$kind, $value]) {
            $xml .= $this->cell($kind, $value, self::column($index).$this->row, $index);
        }
        fwrite($this->sheet, $xml.'</row>');
    }

    private function cell(string $kind, mixed $value, string $ref, int $index): string
    {
        if ($value === null || $kind === 'null') {
            return '';
        }
        $note = function (string $text) use ($index): void {
            $this->widths[$index] = max($this->widths[$index] ?? 0, min(mb_strlen($text), 60));
        };
        switch ($kind) {
            case 'number':
                $note((string) $value);

                return '<c r="'.$ref.'"><v>'.$value.'</v></c>';
            case 'decimal':
                $text = (string) $value;
                $note($text);
                $scale = str_contains($text, '.') ? strlen(substr($text, strpos($text, '.') + 1)) : 0;
                $style = $scale >= 1 && $scale <= 6 ? ' s="'.(3 + $scale).'"' : '';

                return '<c r="'.$ref.'"'.$style.'><v>'.$text.'</v></c>';
            case 'boolean':
                return '<c r="'.$ref.'" t="b"><v>'.($value ? 1 : 0).'</v></c>';
            case 'date':
            case 'datetime':
                if ($value instanceof DateTimeInterface) {
                    $serial = self::serial($value, $kind === 'date');
                    if ($serial !== null) {
                        $note($kind === 'date' ? '0000-00-00' : '0000-00-00 00:00');

                        return '<c r="'.$ref.'" s="'.($kind === 'date' ? 2 : 3).'"><v>'.$serial.'</v></c>';
                    }
                    $value = $value->format($kind === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
                }
                // no break: unreadable dates are written as text
            default:
                $text = mb_substr((string) $value, 0, 32767);
                $note($text);
                $style = $kind === 'header' ? ' s="1"' : '';

                return '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.self::xml($text).'</t></is></c>';
        }
    }

    public static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    /** Excel serial number of the local date and time as shown (1900 system). */
    private static function serial(DateTimeInterface $date, bool $dayOnly): ?float
    {
        [$y, $m, $d, $h, $i, $s] = array_map('intval', explode(' ', $date->format('Y n j G i s')));
        $days = (gmmktime(0, 0, 0, $m, $d, $y) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400;
        if ($days < 61) {
            return null; // before 1900-03-01 Excel dates are unreliable
        }

        return $dayOnly ? (float) $days : $days + ($h * 3600 + $i * 60 + $s) / 86400;
    }

    private static function xml(string $text): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function sheetName(string $name): string
    {
        $clean = trim(mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name) ?? '', 0, 31));

        return $clean === '' || strtolower($clean) === 'history' ? 'Sheet1' : $clean;
    }

    private static function styles(): string
    {
        $formats = '';
        $xfs = '';
        for ($scale = 1; $scale <= 6; $scale++) {
            $formats .= '<numFmt numFmtId="'.(163 + $scale).'" formatCode="#,##0.'.str_repeat('0', $scale).'"/>';
            $xfs .= '<xf numFmtId="'.(163 + $scale).'" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        }

        return self::HEAD.'<styleSheet xmlns="'.self::MAIN.'"><numFmts count="6">'.$formats.'</numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="10"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="22" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .$xfs.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
