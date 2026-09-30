<?php
declare(strict_types=1);

/**
 * actions.php — what the page does with each form. Each handler takes the
 * current state and returns the new state; index.php stores it in the session.
 *
 * User-facing problems are thrown as RuntimeException (message shown as-is).
 */

/**
 * Read an uploaded rates file into a new job.
 *
 * @param array $file  one entry of $_FILES
 * @return array  the job stored in $_SESSION['job']
 */
function handleUpload(mixed $file, RateParser $parser, array $config): array
{
    if (!is_array($file) || !is_int($file['error'] ?? null)) {
        throw new RuntimeException(uploadErrorMessage(UPLOAD_ERR_NO_FILE));
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException(uploadErrorMessage($file['error']));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException(uploadErrorMessage(-1));
    }
    if ($file['size'] > $config['max_upload_bytes']) {
        throw new RuntimeException(uploadErrorMessage(UPLOAD_ERR_FORM_SIZE));
    }

    $sheet        = $parser->pickSheet(SpreadsheetReader::read($file['tmp_name'], (string) $file['name']));
    $rows         = $sheet['rows'];
    $currencyRows = $sheet['currency_rows'];
    $columns      = $parser->detectColumns($rows, $currencyRows);
    $firstDataRow = min(array_column($currencyRows, 'row'));
    $dates        = $parser->detectDates($rows, $firstDataRow);

    $date = $dates['date'] ?? date('Y-m-d');
    $fileHasValueDate = $dates['value_date'] !== null;
    $valueDate = ($config['value_date_source'] === 'file' && $fileHasValueDate)
        ? $dates['value_date']
        : RateParser::addBusinessDays($date, (int) $config['value_date_offset_days']);

    // Keep only the currency rows: they are all the update step needs
    $currencyGrid = [];
    $maxCol = 0;
    foreach ($currencyRows as $pos) {
        $cells = $rows[$pos['row']] ?? [];
        $currencyGrid[$pos['row']] = $cells;
        $maxCol = max($maxCol, $cells ? max(array_keys($cells)) : 0);
    }
    // A headed column can be empty for every currency; it must still be selectable
    $maxCol = max([$maxCol, ...array_filter($columns['mapping'], 'is_int')]);

    return [
        'file_name'       => basename((string) $file['name']),
        'sheet_name'      => $sheet['name'],
        'rows'            => $currencyGrid,
        'max_col'         => $maxCol,
        'currency_rows'   => $currencyRows,
        'mapping'         => $columns['mapping'],
        'method'          => $columns['method'],
        'rates'           => $parser->extractRates($rows, $currencyRows, $columns['mapping']),
        'date'            => $date,
        'value_date'      => $valueDate,
        'dates_found'     => $dates['date'] !== null,
        'file_value_date' => $dates['value_date'],
        // value_date_source = 'file', but the file had no value date
        'value_date_fallback' => $config['value_date_source'] === 'file' && !$fileHasValueDate,
    ];
}

/**
 * Apply the review form: dates, then either new column mapping (re-read
 * values) or hand-edited values.
 *
 * @return array{0: array, 1: string}  [new job, success message]
 */
function handleUpdate(array $job, array $post, RateParser $parser, array $config): array
{
    foreach (['date', 'value_date'] as $key) {
        if (isValidYmd($post[$key] ?? null)) {
            $job[$key] = $post[$key];
        }
    }

    // Column mapping: '' = not in file; anything else must be a column that exists
    $newMapping = [];
    foreach (RateParser::FIELDS as $field) {
        $raw = $post['map'][$field] ?? '';
        $col = is_string($raw) && $raw !== ''
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $job['max_col']]])
            : false;
        $newMapping[$field] = $col === false ? null : $col;
    }

    if ($newMapping !== $job['mapping']) {
        $job['mapping'] = $newMapping;
        $job['method']  = 'manual';
        $job['rates']   = $parser->extractRates($job['rows'], $job['currency_rows'], $newMapping);
        return [$job, 'Columns updated and values re-read from the file.'];
    }

    // Otherwise take the (possibly hand-edited) values from the grid
    foreach ($config['currencies'] as $code => $decimals) {
        foreach (RateParser::FIELDS as $field) {
            $raw = $post['rates'][$code][$field] ?? '';
            $num = is_string($raw) ? RateParser::toNumber($raw) : null;
            $job['rates'][$code][$field] = $parser->formatRate($num, (int) $decimals);
        }
    }
    return [$job, 'Table updated.'];
}

/**
 * Warnings and column dropdown labels for the review step.
 *
 * @return array{warnings: string[], column_options: array<int,string>}
 */
function buildReviewView(array $job, array $config): array
{
    $warnings = [];

    $notFound = array_diff(array_keys($config['currencies']), array_keys($job['currency_rows']));
    if ($notFound) {
        $warnings[] = 'Not found in the file (shown as ' . $config['missing_value'] . '): ' . implode(', ', $notFound) . '.';
    }
    if (in_array(null, $job['mapping'], true)) {
        $warnings[] = 'Some columns could not be detected. Pick them in "Excel columns" below.';
    }
    if ($job['method'] === 'position') {
        $warnings[] = 'No BUY/SELL headings were found, so columns were assigned by position. Double-check they are in the right order.';
    }
    if (!$job['dates_found']) {
        $warnings[] = "No date was found in the file, so today's date was used.";
    }
    if ($job['value_date_fallback']) {
        $warnings[] = 'No value date was found in the file, so it was calculated from the date. Check it below.';
    }

    // Each option shows a sample value from the first currency row of the file
    $sampleCode = array_key_first($job['currency_rows']);
    $sampleRow  = $sampleCode !== null ? ($job['rows'][$job['currency_rows'][$sampleCode]['row']] ?? []) : [];
    $options = [];
    for ($c = 0; $c <= $job['max_col']; $c++) {
        $sample = $sampleRow[$c] ?? '';
        $options[$c] = 'Column ' . RateParser::columnLetter($c)
            . ($sample !== '' ? " ({$sampleCode}: {$sample})" : '');
    }

    return ['warnings' => $warnings, 'column_options' => $options];
}
