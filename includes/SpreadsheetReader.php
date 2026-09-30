<?php
declare(strict_types=1);

/**
 * SpreadsheetReader — dependency-free reader for .xlsx and .csv files.
 *
 * .xlsx needs PHP's "zip" extension (enabled by default in XAMPP;
 * if not, uncomment ";extension=zip" in php.ini and restart Apache).
 *
 * Yields each sheet as: sheetName => [ rowIndex => [ colIndex => value ] ]
 * Sheets are parsed lazily, one at a time, so callers can stop at the first
 * useful sheet. Column/row indexes are 0-based and keep their real positions
 * (gaps preserved). Empty cells are omitted.
 */
final class SpreadsheetReader
{
    /** Cells beyond these limits are ignored (the rates table is far smaller). */
    public const MAX_ROWS = 5000;
    public const MAX_COLS = 200;

    /** Decompression limits (zip-bomb protection). */
    private const MAX_ENTRY_BYTES = 20 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    private const NS_MAIN        = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_MAIN_STRICT = 'http://purl.oclc.org/ooxml/spreadsheetml/main';
    private const NS_REL         = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_REL_STRICT  = 'http://purl.oclc.org/ooxml/officeDocument/relationships';

    /** Built-in number formats that display a date. */
    private const BUILT_IN_DATE_FORMATS = [14, 15, 16, 17, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    /**
     * Entry point. Chooses the reader by the original file extension.
     *
     * @return iterable<string, array<int, array<int, string|float>>>
     * @throws RuntimeException for unsupported, corrupt or oversized files
     */
    public static function read(string $path, string $originalName): iterable
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($ext) {
            'xlsx', 'xlsm' => self::readXlsx($path),
            'csv'          => ['CSV' => self::readCsv($path)],
            'xls'          => throw new RuntimeException(
                'Old .xls format is not supported. Open the file in Excel and use "Save As → Excel Workbook (.xlsx)".'
            ),
            default        => throw new RuntimeException('Unsupported file type. Please upload an .xlsx or .csv file.'),
        };
    }

    // ------------------------------------------------------------------ CSV

    /** @return array<int, array<int, string>> */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open the CSV file.');
        }

        try {
            // Skip a UTF-8 BOM before parsing, so a quoted first field is still read as quoted
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $delimiter = self::detectCsvDelimiter($handle);

            $rows = [];
            for ($r = 0; $r < self::MAX_ROWS && ($data = fgetcsv($handle, 0, $delimiter, '"', '')) !== false; $r++) {
                $cells = [];
                foreach ($data as $c => $value) {
                    if ($c >= self::MAX_COLS) {
                        break;
                    }
                    $value = trim(self::toUtf8((string) $value));
                    if ($value !== '') {
                        $cells[$c] = $value;
                    }
                }
                if ($cells) {
                    $rows[$r] = $cells;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Looks at the first non-empty lines. Tab or ";" wins when present on most
     * lines (";" files use decimal commas, so counting commas would mislead).
     * Leaves the handle where it was.
     *
     * @param resource $handle
     */
    private static function detectCsvDelimiter($handle): string
    {
        $start = ftell($handle);
        $lines = [];
        while (count($lines) < 10 && ($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }
        fseek($handle, (int) $start);

        foreach (["\t", ';'] as $candidate) {
            $hits = count(array_filter($lines, static fn(string $l) => str_contains($l, $candidate)));
            if ($lines && $hits * 2 >= count($lines)) {
                return $candidate;
            }
        }
        return ',';
    }

    /** Excel's plain "CSV" export is Windows-1252, not UTF-8. */
    private static function toUtf8(string $value): string
    {
        if (preg_match('//u', $value)) {
            return $value;
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        return iconv('Windows-1252', 'UTF-8//IGNORE', $value) ?: $value;
    }

    // ----------------------------------------------------------------- XLSX

    /** @return Generator<string, array<int, array<int, string|float>>> */
    private static function readXlsx(string $path): Generator
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'PHP "zip" extension is not enabled. In XAMPP open php.ini, remove the ";" before "extension=zip" and restart Apache.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The file is not a valid .xlsx workbook.');
        }

        try {
            self::checkTotalSize($zip);

            $workbook      = self::loadWorkbook($zip);
            $sharedStrings = self::loadSharedStrings($zip, $workbook['shared_strings']);
            $dateStyles    = self::loadDateStyleIndexes($zip, $workbook['styles']);

            $found = false;
            foreach ($workbook['sheets'] as $name => $sheetPath) {
                $xml = self::readEntry($zip, $sheetPath);
                if ($xml === null) {
                    continue;
                }
                $found = true;
                yield $name => self::parseSheet($xml, $sharedStrings, $dateStyles, $workbook['date1904']);
            }

            if (!$found) {
                throw new RuntimeException('No worksheets were found in the workbook.');
            }
        } finally {
            $zip->close();
        }
    }

    /** Rejects archives whose declared uncompressed size is unreasonable. */
    private static function checkTotalSize(ZipArchive $zip): void
    {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        if ($total > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('The workbook is too large to read. Is this the right rates file?');
        }
    }

    /** Reads one zip entry (case-insensitive), refusing oversized entries. */
    private static function readEntry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name, ZipArchive::FL_NOCASE);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_ENTRY_BYTES) {
            throw new RuntimeException('The workbook is too large to read. Is this the right rates file?');
        }

        $data = $zip->getFromIndex($stat['index']);
        return $data === false ? null : $data;
    }

    /**
     * Parses XML without network access. Real OOXML is UTF-8 and never has a
     * DOCTYPE; NUL bytes mean UTF-16/32, where the DOCTYPE check could not see it.
     */
    private static function loadXml(string $xml): SimpleXMLElement
    {
        if (str_contains($xml, "\0") || stripos($xml, '<!DOCTYPE') !== false) {
            throw new RuntimeException('The workbook contains invalid XML.');
        }

        $prev = libxml_use_internal_errors(true);
        $el   = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($el === false) {
            throw new RuntimeException('The workbook contains invalid XML.');
        }
        return $el;
    }

    /** SpreadsheetML namespace of a part (Transitional or Strict). */
    private static function mainNs(SimpleXMLElement $doc): string
    {
        return in_array(self::NS_MAIN_STRICT, $doc->getDocNamespaces(), true) ? self::NS_MAIN_STRICT : self::NS_MAIN;
    }

    /**
     * Sheet list, 1904 flag and the shared-strings/styles part names, resolved
     * through the workbook relationships.
     *
     * @return array{sheets: array<string,string>, date1904: bool, shared_strings: ?string, styles: ?string}
     */
    private static function loadWorkbook(ZipArchive $zip): array
    {
        $wbXml   = self::readEntry($zip, 'xl/workbook.xml');
        $relsXml = self::readEntry($zip, 'xl/_rels/workbook.xml.rels');
        if ($wbXml === null || $relsXml === null) {
            throw new RuntimeException('The workbook structure could not be read.');
        }

        // Package relationships use the same namespace in Transitional and Strict
        $targets = [];
        $typeTargets = ['sharedStrings' => null, 'styles' => null];
        foreach (self::loadXml($relsXml)->Relationship as $rel) {
            $target = ltrim(self::attr($rel, 'Target'), '/');
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }
            $targets[self::attr($rel, 'Id')] = $target;

            $type = basename(self::attr($rel, 'Type'));
            if (array_key_exists($type, $typeTargets)) {
                $typeTargets[$type] = $target;
            }
        }

        $wb = self::loadXml($wbXml);
        $ns = self::mainNs($wb);
        $relNs = $ns === self::NS_MAIN_STRICT ? self::NS_REL_STRICT : self::NS_REL;
        $book = $wb->children($ns);

        $date1904 = in_array(strtolower(self::attr($book->workbookPr, 'date1904')), ['1', 'true'], true);

        $sheets = [];
        foreach ($book->sheets->sheet ?? [] as $sheet) {
            $rid = (string) $sheet->attributes($relNs)['id'];
            if (isset($targets[$rid])) {
                $sheets[self::attr($sheet, 'name')] = $targets[$rid];
            }
        }

        return [
            'sheets'         => $sheets,
            'date1904'       => $date1904,
            'shared_strings' => $typeTargets['sharedStrings'] ?? 'xl/sharedStrings.xml',
            'styles'         => $typeTargets['styles'] ?? 'xl/styles.xml',
        ];
    }

    /** @return string[] */
    private static function loadSharedStrings(ZipArchive $zip, string $part): array
    {
        $xml = self::readEntry($zip, $part);
        if ($xml === null) {
            return [];
        }

        $doc = self::loadXml($xml);
        $strings = [];
        foreach ($doc->children(self::mainNs($doc))->si as $si) {
            $strings[] = self::richText($si);
        }
        return $strings;
    }

    /** Text of a <si> or <is> element: plain <t> or rich-text runs <r><t>; phonetic <rPh> is skipped. */
    private static function richText(SimpleXMLElement $el): string
    {
        $text = (string) $el->t;
        foreach ($el->r as $run) {
            $text .= (string) $run->t;
        }
        return $text;
    }

    /** Style indexes (cellXfs) that use a date number format. @return array<int,bool> */
    private static function loadDateStyleIndexes(ZipArchive $zip, string $part): array
    {
        $xml = self::readEntry($zip, $part);
        if ($xml === null) {
            return [];
        }

        $doc = self::loadXml($xml);
        $styles = $doc->children(self::mainNs($doc));

        $dateFmtIds = array_flip(self::BUILT_IN_DATE_FORMATS);
        foreach ($styles->numFmts->numFmt ?? [] as $fmt) {
            // Drop quoted literals, [colours/locales], escaped chars (\d) and padding (_x, *x)
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\.|[_*]./s', '', strtolower(self::attr($fmt, 'formatCode')));
            if (preg_match('/[dy]/', (string) $code)) {
                $dateFmtIds[(int) self::attr($fmt, 'numFmtId')] = true;
            }
        }

        $indexes = [];
        $i = 0;
        foreach ($styles->cellXfs->xf ?? [] as $xf) {
            if (isset($dateFmtIds[(int) self::attr($xf, 'numFmtId')])) {
                $indexes[$i] = true;
            }
            $i++;
        }
        return $indexes;
    }

    /** @return array<int, array<int, string|float>> */
    private static function parseSheet(string $xml, array $sharedStrings, array $dateStyles, bool $date1904): array
    {
        $doc = self::loadXml($xml);
        $ws  = $doc->children(self::mainNs($doc));

        $rows     = [];
        $rowIndex = -1;
        foreach ($ws->sheetData->row ?? [] as $rowEl) {
            // "r" is optional in the spec: without it, rows follow on from the previous one
            $r        = self::attr($rowEl, 'r');
            $rowIndex = $r !== '' ? (int) $r - 1 : $rowIndex + 1;
            if ($rowIndex < 0 || $rowIndex >= self::MAX_ROWS) {
                continue;
            }

            $col = 0;
            foreach ($rowEl->c as $c) {
                $ref = self::attr($c, 'r');
                if ($ref !== '') {
                    if (!preg_match('/^([A-Z]{1,3})\d+$/', $ref, $m)) {
                        continue;
                    }
                    $col = self::columnIndex($m[1]);
                }

                $raw = isset($c->v) ? (string) $c->v : null;
                $value = match (self::attr($c, 't')) {
                    's'             => $raw !== null ? ($sharedStrings[(int) $raw] ?? null) : null,
                    'inlineStr'     => isset($c->is) ? self::richText($c->is) : null,
                    'str', 'e', 'b' => $raw,
                    default         => self::numericCell($raw, isset($dateStyles[(int) self::attr($c, 's')]), $date1904),
                };

                if (is_string($value)) {
                    $value = trim($value);
                }
                if ($value !== null && $value !== '' && $col < self::MAX_COLS) {
                    $rows[$rowIndex][$col] = $value;
                }
                $col++;
            }
        }

        ksort($rows);
        return $rows;
    }

    /** A number cell: float, 'Y-m-d' when date-formatted, or the raw text if not numeric. */
    private static function numericCell(?string $raw, bool $isDate, bool $date1904): string|float|null
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return $raw;
        }
        $number = (float) $raw;
        return $isDate ? (self::excelSerialToDate($number, $date1904) ?? $number) : $number;
    }

    /**
     * An unqualified attribute ('' if absent). $el['name'] would look in the
     * element's namespace after children($ns), where it is never found.
     */
    private static function attr(SimpleXMLElement $el, string $name): string
    {
        return (string) ($el->attributes()[$name] ?? '');
    }

    /** "A" => 0, "Z" => 25, "AA" => 26 … */
    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /** Returns 'Y-m-d' for an Excel date serial, or null if out of range. */
    private static function excelSerialToDate(float $serial, bool $date1904): ?string
    {
        $days = (int) floor($serial);
        if ($days < 1 || $days > 2958465) { // 0 is an empty date cell; 2958465 = 9999-12-31
            return null;
        }
        if ($date1904) {
            $base = '1904-01-01';
        } else {
            // 1900 system: serials 1–59 are before Excel's fictitious 29 Feb 1900
            $base = '1899-12-30';
            if ($days < 60) {
                $days++;
            }
        }
        return (new DateTimeImmutable($base))->modify("+{$days} days")->format('Y-m-d');
    }
}
