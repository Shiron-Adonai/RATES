<?php
declare(strict_types=1);

/**
 * Minimal test runner (no Composer). Run from the project folder:
 *
 *     php tests/run.php
 *
 * Optional: put the real bank file at tests/fixtures/RTS300926.xlsx to also
 * check it against the expected values in docs/TECHNICAL.md §13.
 */

$config = require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/SpreadsheetReader.php';
require __DIR__ . '/../includes/RateParser.php';
require __DIR__ . '/../includes/TableRenderer.php';

$passed = 0;
$failed = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL  {$name}\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

function throws(string $name, callable $fn, string $messagePart): void
{
    try {
        $fn();
        check($name, "exception containing '{$messagePart}'", 'no exception');
    } catch (RuntimeException $ex) {
        check($name, true, str_contains($ex->getMessage(), $messagePart));
    }
}

/** Builds a minimal .xlsx from sheet XML bodies. @param array<string,string> $sheets name => <sheetData> inner XML */
function makeXlsx(array $sheets, array $sharedStrings = [], bool $date1904 = false, string $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'): string
{
    $path = tempnam(sys_get_temp_dir(), 'rp') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $rels = '';
    $list = '';
    $i = 0;
    foreach ($sheets as $name => $data) {
        $i++;
        $zip->addFromString("xl/worksheets/sheet{$i}.xml", "<?xml version=\"1.0\"?><worksheet xmlns=\"{$ns}\"><sheetData>{$data}</sheetData></worksheet>");
        $rels .= "<Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$i}.xml\"/>";
        $list .= "<sheet name=\"{$name}\" sheetId=\"{$i}\" r:id=\"rId{$i}\"/>";
    }
    $rels .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $rels .= '<Relationship Id="rIdT" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $relNs = str_contains($ns, 'purl.oclc.org') ? 'http://purl.oclc.org/ooxml/officeDocument/relationships' : 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $pr = $date1904 ? '<workbookPr date1904="1"/>' : '';
    $zip->addFromString('xl/workbook.xml', "<?xml version=\"1.0\"?><workbook xmlns=\"{$ns}\" xmlns:r=\"{$relNs}\">{$pr}<sheets>{$list}</sheets></workbook>");
    $zip->addFromString('xl/_rels/workbook.xml.rels', "<?xml version=\"1.0\"?><Relationships xmlns=\"http://schemas.openxmlformats.org/package/2006/relationships\">{$rels}</Relationships>");

    $si = implode('', array_map(static fn($s) => '<si><t>' . htmlspecialchars($s) . '</t></si>', $sharedStrings));
    $zip->addFromString('xl/sharedStrings.xml', "<?xml version=\"1.0\"?><sst xmlns=\"{$ns}\">{$si}</sst>");
    // Style 0 = general, style 1 = built-in date (14), style 2 = custom "dd/mm/yyyy", style 3 = number with escaped \d
    $zip->addFromString('xl/styles.xml', "<?xml version=\"1.0\"?><styleSheet xmlns=\"{$ns}\">"
        . '<numFmts><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="0.0000\ \d"/></numFmts>'
        . '<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="164"/><xf numFmtId="165"/></cellXfs></styleSheet>');
    $zip->close();
    return $path;
}

/** @return array<string,array> all sheets, fully read */
function readAll(string $path, string $name): array
{
    $out = [];
    foreach (SpreadsheetReader::read($path, $name) as $sheet => $rows) {
        $out[$sheet] = $rows;
    }
    return $out;
}

$parser = new RateParser($config);

// ---------------------------------------------------------------- toNumber
check('toNumber float',          1.5,     RateParser::toNumber(1.5));
check('toNumber plain',          1.1179,  RateParser::toNumber('1.1179'));
check('toNumber decimal comma',  1.1179,  RateParser::toNumber('1,1179'));
check('toNumber 1,234.56',       1234.56, RateParser::toNumber('1,234.56'));
check('toNumber 1.234,56',       1234.56, RateParser::toNumber('1.234,56'));
check('toNumber nbsp',           1234.5,  RateParser::toNumber("1\u{202F}234.5"));
check('toNumber text',           null,    RateParser::toNumber('N/A'));
check('toNumber empty',          null,    RateParser::toNumber(''));
check('toNumber infinite',       null,    RateParser::toNumber('1e999'));

// ------------------------------------------------------------------ toDate
check('toDate Y-m-d',            '2026-09-30', RateParser::toDate('2026-09-30'));
check('toDate d/m/Y in text',    '2026-10-01', RateParser::toDate('Value Date: 01/10/2026'));
check('toDate 2-digit year',     '2026-09-30', RateParser::toDate('30.09.26'));
check('toDate invalid day',      null,         RateParser::toDate('31/09/2026'));
check('toDate 3-digit year',     null,         RateParser::toDate('30/09/202'));
check('toDate mixed separators', null,         RateParser::toDate('30/09-2026'));
check('toDate non-string',       null,         RateParser::toDate(45000.0));

// --------------------------------------------------------- business days
check('business day Wed→Thu', '2026-10-01', RateParser::addBusinessDays('2026-09-30', 1));
check('business day Fri→Mon', '2026-10-05', RateParser::addBusinessDays('2026-10-02', 1));
check('business day Sat→Mon', '2026-10-05', RateParser::addBusinessDays('2026-10-03', 1));

// ------------------------------------------------------------ misc helpers
check('columnLetter A',   'A',  RateParser::columnLetter(0));
check('columnLetter Z',   'Z',  RateParser::columnLetter(25));
check('columnLetter AA',  'AA', RateParser::columnLetter(26));
check('columnLetter AZ',  'AZ', RateParser::columnLetter(51));
check('formatRate JPY',   '160.12', $parser->formatRate(160.1234, 2));
check('formatRate zero',  'N/A',    $parser->formatRate(0.0, 4));
check('formatRate neg',   'N/A',    $parser->formatRate(-1.0, 4));
check('isValidYmd ok',    true,  isValidYmd('2026-02-28'));
check('isValidYmd 31 Feb', false, isValidYmd('2026-02-31'));
check('isValidYmd array', false, isValidYmd(['2026-02-28']));
check('formatDate',       '30/09/2026', TableRenderer::formatDate('2026-09-30'));

// ------------------------------------------------------- column detection
$rows = [
    3 => [2 => 'Date:', 3 => '30/09/2026'],
    5 => [6 => 'SPOT', 8 => 'BANK NOTES'],
    6 => [6 => 'BNK SELLS', 7 => 'BNK BUYS', 8 => 'BNK SELLS', 9 => 'BNK BUYS'],
    7 => [3 => 'USD', 6 => 1.1179, 7 => 1.1519, 8 => 1.1049, 9 => 1.1649],
    8 => [3 => 'GBP', 6 => 0.8612, 7 => 0.8872, 8 => 0.8512, 9 => 0.8972],
];
$currencyRows = $parser->findCurrencyRows($rows);
check('findCurrencyRows', ['USD' => ['row' => 7, 'col' => 3], 'GBP' => ['row' => 8, 'col' => 3]], $currencyRows);
check('detectColumns headers', [
    'mapping' => ['spot_sell' => 6, 'spot_buy' => 7, 'note_sell' => 8, 'note_buy' => 9],
    'method'  => 'headers',
], $parser->detectColumns($rows, $currencyRows));

$noHeaders = [7 => $rows[7], 8 => $rows[8]];
check('detectColumns position', 'position', $parser->detectColumns($noHeaders, $currencyRows)['method']);
check('detectColumns position map', 6, $parser->detectColumns($noHeaders, $currencyRows)['mapping']['spot_sell']);

$rates = $parser->extractRates($rows, $currencyRows, $parser->detectColumns($rows, $currencyRows)['mapping']);
check('extractRates USD', '1.1179', $rates['USD']['spot_sell']);
check('extractRates missing', 'N/A', $rates['CHF']['spot_sell']);

check('leftmost code only', ['USD' => ['row' => 0, 'col' => 0]],
    $parser->findCurrencyRows([0 => [0 => 'USD'], 1 => [0 => 'USD', 1 => 'GBP']]));

// Dates: header area first, so a date in a table further down is ignored
$withLaterDates = $rows + [40 => [0 => 'Interest rates', 1 => 'Value date', 2 => '15/08/2026']];
check('detectDates header', ['date' => '2026-09-30', 'value_date' => null], $parser->detectDates($withLaterDates, 7));

check('detectDates value date only in header', ['date' => '2026-09-30', 'value_date' => '2026-10-02'],
    $parser->detectDates([0 => [0 => 'Value date', 1 => '02/10/2026'], 7 => [0 => 'USD'], 20 => [0 => 'Date', 1 => '30/09/2026']], 7));

// --------------------------------------------------------------------- CSV
$csv = tempnam(sys_get_temp_dir(), 'rp');
file_put_contents($csv, "\xEF\xBB\xBF\"Rates, 30 Sept\";x\nUSD;1,1179;1,1519\nGBP;0,8612;0,8872\n");
$csvRows = readAll($csv, 'rates.csv')['CSV'];
check('CSV BOM + quoted first field', 'Rates, 30 Sept', $csvRows[0][0]);
check('CSV semicolon delimiter',      '1,1179', $csvRows[1][1]);
check('CSV decimal comma parse',      1.1179, RateParser::toNumber($csvRows[1][1]));

file_put_contents($csv, "Code,Sell\nCHF,0.9\n\n\xC9t\xE9,1\n");
$csvRows = readAll($csv, 'rates.csv')['CSV'];
check('CSV comma delimiter',       '0.9', $csvRows[1][1]);
check('CSV blank line skipped',    false, isset($csvRows[2]));
check('CSV Windows-1252 → UTF-8',  'Été', $csvRows[3][0]);
unlink($csv);

// -------------------------------------------------------------------- XLSX
$xlsx = makeXlsx(
    [
        'RATES' => '<row r="4"><c r="C4" t="s"><v>0</v></c><c r="D4" s="1"><v>46295</v></c></row>'
            . '<row r="8"><c r="D8" t="s"><v>1</v></c><c r="G8"><v>1.1179</v></c><c r="H8" s="3"><v>1.1519</v></c></row>'
            . '<row><c t="inlineStr"><is><r><t>G</t></r><r><t>BP</t></r></is></c><c><v>0.8612</v></c></row>'
            . '<row r="12"><c r="ZZZZZZ12"><v>1</v></c><c r="HA12"><v>2</v></c></row>',
        'REF RATES' => '<row r="1"><c r="A1" t="inlineStr"><is><t>CHF</t></is></c></row>',
    ],
    ['Date:', 'USD']
);
$sheets = readAll($xlsx, 'RTS300926.xlsx');
$sheet  = $sheets['RATES'];
check('xlsx shared string',       'Date:', $sheet[3][2]);
check('xlsx date style → Y-m-d',  '2026-09-30', $sheet[3][3]);
check('xlsx number',              1.1179, $sheet[7][6]);
check('xlsx escaped \\d not date', 1.1519, $sheet[7][7]);
check('xlsx row without r',       'GBP', $sheet[8][0]);
check('xlsx rich inline string',  0.8612, $sheet[8][1]);
check('xlsx bad/huge refs ignored', false, isset($sheet[11]));
check('xlsx sheets in order', ['RATES', 'REF RATES'], array_keys($sheets));

$picked = $parser->pickSheet(SpreadsheetReader::read($xlsx, 'x.xlsx'));
check('pickSheet first with currencies', 'RATES', $picked['name']);
check('pickSheet returns currency rows', ['row' => 7, 'col' => 3], $picked['currency_rows']['USD']);
unlink($xlsx);

$xlsx = makeXlsx(['S' => '<row r="1"><c r="A1" s="1"><v>44834</v></c></row>'], [], true);
check('xlsx 1904 date system', '2026-10-01', readAll($xlsx, 'x.xlsx')['S'][0][0]);
unlink($xlsx);

$xlsx = makeXlsx(['S' => '<row r="1"><c r="A1" t="inlineStr"><is><t>USD</t></is></c></row>'], [], false, 'http://purl.oclc.org/ooxml/spreadsheetml/main');
check('xlsx Strict namespace', 'USD', readAll($xlsx, 'x.xlsx')['S'][0][0]);
unlink($xlsx);

$xlsx = makeXlsx(['S' => '<row r="1"><c r="A1" s="1"><v>0</v></c></row>']);
check('xlsx empty date cell (0) stays a number', 0.0, readAll($xlsx, 'x.xlsx')['S'][0][0]);
unlink($xlsx);

throws('xls rejected',  fn() => readAll(__FILE__, 'old.xls'), 'Old .xls');
throws('bad extension', fn() => readAll(__FILE__, 'x.pdf'), 'Unsupported');
throws('not a zip',     fn() => readAll(__FILE__, 'x.xlsx'), 'not a valid');

// Zip bomb: an entry that decompresses beyond the limit
$bomb = tempnam(sys_get_temp_dir(), 'rp') . '.xlsx';
$zip = new ZipArchive();
$zip->open($bomb, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('xl/workbook.xml', str_repeat(' ', 60 * 1024 * 1024));
$zip->close();
throws('zip bomb rejected', fn() => readAll($bomb, 'x.xlsx'), 'too large');
unlink($bomb);

// DOCTYPE (entity expansion) rejected
$xlsx = makeXlsx(['S' => '']);
$zip = new ZipArchive();
$zip->open($xlsx);
$zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa">]><worksheet/>');
$zip->close();
throws('DOCTYPE rejected', fn() => readAll($xlsx, 'x.xlsx'), 'invalid XML');
$zip->open($xlsx);
$zip->addFromString('xl/worksheets/sheet1.xml', "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY a "a">]><worksheet/>', 'UTF-16LE', 'UTF-8'));
$zip->close();
throws('UTF-16 DOCTYPE rejected', fn() => readAll($xlsx, 'x.xlsx'), 'invalid XML');
unlink($xlsx);

// --------------------------------------------------------------- renderer
$renderer = new TableRenderer($config);
$html = $renderer->render('2026-09-30', '2026-10-01', ['USD' => ['spot_sell' => '<b>', 'spot_buy' => '1', 'note_sell' => '1', 'note_buy' => '1']]);
check('render escapes cells', true, str_contains($html, '<p>&lt;b&gt;</p>'));
check('render dates', true, str_contains($html, 'Date: 30/09/2026') && str_contains($html, 'Value Date: 01/10/2026'));
try {
    new TableRenderer(['colors' => ['table_bg' => 'red" onmouseover="x']] + $config);
    check('bad colour rejected', 'exception', 'none');
} catch (InvalidArgumentException) {
    check('bad colour rejected', true, true);
}

// ------------------------------------------------------- real bank file
$real = __DIR__ . '/fixtures/RTS300926.xlsx';
if (is_file($real)) {
    $sheet = $parser->pickSheet(SpreadsheetReader::read($real, basename($real)));
    $cols  = $parser->detectColumns($sheet['rows'], $sheet['currency_rows']);
    $rates = $parser->extractRates($sheet['rows'], $sheet['currency_rows'], $cols['mapping']);
    check('real file: method', 'headers', $cols['method']);
    check('real file: 15 currencies found', 15, count($sheet['currency_rows']));
    echo "Real file USD: " . implode(' / ', $rates['USD']) . "  (compare with docs/TECHNICAL.md §13)\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
