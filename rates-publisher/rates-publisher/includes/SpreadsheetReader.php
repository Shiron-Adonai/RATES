<?php
/**
 * SpreadsheetReader — dependency-free reader for .xlsx and .csv files.
 *
 * .xlsx needs PHP's "zip" extension (enabled by default in XAMPP;
 * if not, uncomment ";extension=zip" in php.ini and restart Apache).
 *
 * Returns every sheet as: [ sheetName => [ rowIndex => [ colIndex => value ] ] ]
 * Column/row indexes are 0-based and keep their real positions (gaps preserved).
 */
class SpreadsheetReader
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @return array<string, array<int, array<int, string|float|null>>> */
    public static function read(string $path, string $originalName): array
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

    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open the CSV file.');
        }

        // Detect delimiter from the first line
        $firstLine = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = array_map(static fn($v) => $v === null ? null : trim((string) $v), $data);
        }
        fclose($handle);

        // Strip UTF-8 BOM
        if (isset($rows[0][0]) && is_string($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
        }

        return $rows;
    }

    // ----------------------------------------------------------------- XLSX

    private static function readXlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'PHP "zip" extension is not enabled. In XAMPP open php.ini, remove the ";" before "extension=zip" and restart Apache.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not a valid .xlsx workbook.');
        }

        try {
            $sharedStrings = self::loadSharedStrings($zip);
            $dateStyles    = self::loadDateStyleIndexes($zip);
            $sheets        = self::loadSheetList($zip);

            $result = [];
            foreach ($sheets as $name => $sheetPath) {
                $xml = $zip->getFromName($sheetPath);
                if ($xml === false) {
                    continue;
                }
                $result[$name] = self::parseSheet($xml, $sharedStrings, $dateStyles);
            }
        } finally {
            $zip->close();
        }

        if (!$result) {
            throw new RuntimeException('No worksheets were found in the workbook.');
        }

        return $result;
    }

    private static function loadXml(string $xml): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $el   = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($prev);

        if ($el === false) {
            throw new RuntimeException('The workbook contains invalid XML.');
        }
        return $el;
    }

    /** @return string[] */
    private static function loadSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = self::loadXml($xml);
        $doc->registerXPathNamespace('m', self::NS_MAIN);

        $strings = [];
        foreach ($doc->xpath('/m:sst/m:si') ?: [] as $si) {
            $si->registerXPathNamespace('m', self::NS_MAIN);
            // Plain <t> or rich text runs <r><t>; skip phonetic <rPh>
            $parts = $si->xpath('./m:t | ./m:r/m:t') ?: [];
            $strings[] = implode('', array_map('strval', $parts));
        }
        return $strings;
    }

    /** Style indexes (cellXfs) that use a date number format. @return array<int,bool> */
    private static function loadDateStyleIndexes(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }

        $doc = self::loadXml($xml);
        $doc->registerXPathNamespace('m', self::NS_MAIN);

        $builtInDates = [14, 15, 16, 17, 22, 27, 30, 36, 45, 46, 47, 50, 57];
        $customDates  = [];
        foreach ($doc->xpath('/m:styleSheet/m:numFmts/m:numFmt') ?: [] as $fmt) {
            $code = strtolower((string) $fmt['formatCode']);
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $code); // drop literals & colours
            if (preg_match('/[dy]/', $code) || preg_match('/m.*[dy]|[dy].*m/', $code)) {
                $customDates[] = (int) $fmt['numFmtId'];
            }
        }
        $dateFmtIds = array_flip(array_merge($builtInDates, $customDates));

        $indexes = [];
        foreach ($doc->xpath('/m:styleSheet/m:cellXfs/m:xf') ?: [] as $i => $xf) {
            if (isset($dateFmtIds[(int) $xf['numFmtId']])) {
                $indexes[$i] = true;
            }
        }
        return $indexes;
    }

    /** @return array<string,string> sheet name => zip path */
    private static function loadSheetList(ZipArchive $zip): array
    {
        $wbXml   = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wbXml === false || $relsXml === false) {
            throw new RuntimeException('The workbook structure could not be read.');
        }

        $rels = self::loadXml($relsXml);
        $rels->registerXPathNamespace('r', self::NS_PKG);
        $targets = [];
        foreach ($rels->xpath('/r:Relationships/r:Relationship') ?: [] as $rel) {
            $target = ltrim((string) $rel['Target'], '/');
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . $target;
            }
            $targets[(string) $rel['Id']] = $target;
        }

        $wb = self::loadXml($wbXml);
        $wb->registerXPathNamespace('m', self::NS_MAIN);

        $sheets = [];
        foreach ($wb->xpath('/m:workbook/m:sheets/m:sheet') ?: [] as $sheet) {
            $rid = (string) $sheet->attributes(self::NS_REL)['id'];
            if (isset($targets[$rid])) {
                $sheets[(string) $sheet['name']] = $targets[$rid];
            }
        }
        return $sheets;
    }

    private static function parseSheet(string $xml, array $sharedStrings, array $dateStyles): array
    {
        $doc = self::loadXml($xml);
        $doc->registerXPathNamespace('m', self::NS_MAIN);

        $rows = [];
        foreach ($doc->xpath('/m:worksheet/m:sheetData/m:row') ?: [] as $rowEl) {
            $rowIndex = ((int) $rowEl['r']) - 1;
            $rowEl->registerXPathNamespace('m', self::NS_MAIN);

            $col = 0;
            foreach ($rowEl->xpath('./m:c') ?: [] as $c) {
                $ref = (string) $c['r'];
                if ($ref !== '' && preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    $col      = self::columnIndex($m[1]);
                    $rowIndex = ((int) $m[2]) - 1;
                }

                $type  = (string) $c['t'];
                $style = (int) $c['s'];
                $c->registerXPathNamespace('m', self::NS_MAIN);

                $v = $c->xpath('./m:v');
                $raw = $v ? (string) $v[0] : null;

                $value = match ($type) {
                    's'         => $raw !== null ? ($sharedStrings[(int) $raw] ?? '') : null,
                    'inlineStr' => implode('', array_map('strval', $c->xpath('./m:is/m:t | ./m:is/m:r/m:t') ?: [])),
                    'str', 'e'  => $raw,
                    'b'         => $raw,
                    default     => $raw === null || $raw === '' ? null : (
                        isset($dateStyles[$style]) && is_numeric($raw)
                            ? self::excelSerialToDate((float) $raw)
                            : (is_numeric($raw) ? (float) $raw : $raw)
                    ),
                };

                if ($value !== null && $value !== '') {
                    $rows[$rowIndex][$col] = is_string($value) ? trim($value) : $value;
                }
                $col++;
            }
        }

        ksort($rows);
        return $rows;
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /** Returns 'Y-m-d' for an Excel date serial (1900 date system). */
    private static function excelSerialToDate(float $serial): string
    {
        $days = (int) floor($serial);
        $base = new DateTimeImmutable('1899-12-30');
        return $base->modify("+{$days} days")->format('Y-m-d');
    }
}
