<?php
declare(strict_types=1);

session_start();

$config = require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/SpreadsheetReader.php';
require __DIR__ . '/includes/RateParser.php';
require __DIR__ . '/includes/TableRenderer.php';

$parser   = new RateParser($config);
$renderer = new TableRenderer($config);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/** Store a one-time message and redirect back (Post/Redirect/Get). */
function redirectWith(string $type, string $message): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: index.php');
    exit;
}

// ======================================================================
// POST handling
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        redirectWith('error', 'Your session expired. Please try again.');
    }

    $action = $_POST['action'] ?? '';

    // ---------------------------------------------------------- upload
    if ($action === 'upload') {
        $file = $_FILES['rates_file'] ?? null;

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            redirectWith('error', 'Please choose the rates Excel file first.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            redirectWith('error', 'Upload failed (error code ' . (int) $file['error'] . ').');
        }
        if ($file['size'] > $config['max_upload_bytes']) {
            redirectWith('error', 'The file is too large.');
        }

        try {
            $sheets       = SpreadsheetReader::read($file['tmp_name'], $file['name']);
            $sheet        = $parser->pickSheet($sheets);
            $rows         = $sheet['rows'];
            $currencyRows = $parser->findCurrencyRows($rows);
            $columns      = $parser->detectColumns($rows, $currencyRows);
            $rates        = $parser->extractRates($rows, $currencyRows, $columns['mapping']);
            $dates        = $parser->detectDates($rows);
        } catch (Throwable $ex) {
            redirectWith('error', $ex->getMessage());
        }

        $date = $dates['date'] ?? date('Y-m-d');
        $computedValueDate = RateParser::addBusinessDays($date, (int) $config['value_date_offset_days']);
        $valueDate = ($config['value_date_source'] === 'file' && $dates['value_date'] !== null)
            ? $dates['value_date']
            : $computedValueDate;

        $_SESSION['job'] = [
            'file_name'     => basename($file['name']),
            'sheet_name'    => $sheet['name'],
            'rows'          => $rows,
            'currency_rows' => $currencyRows,
            'mapping'       => $columns['mapping'],
            'method'        => $columns['method'],
            'rates'         => $rates,
            'date'          => $date,
            'value_date'    => $valueDate,
            'dates_found'   => $dates['date'] !== null,
            'file_value_date' => $dates['value_date'],
        ];

        redirectWith('success', 'File read successfully. Check the values below, then copy the table.');
    }

    // ---------------------------------------------------------- update
    if ($action === 'update' && isset($_SESSION['job'])) {
        $job = &$_SESSION['job'];

        // Dates
        foreach (['date', 'value_date'] as $key) {
            $val = (string) ($_POST[$key] ?? '');
            if (DateTimeImmutable::createFromFormat('!Y-m-d', $val)) {
                $job[$key] = $val;
            }
        }

        // Column mapping
        $newMapping = [];
        foreach (RateParser::FIELDS as $field) {
            $raw = $_POST['map'][$field] ?? '';
            $newMapping[$field] = $raw === '' ? null : (int) $raw;
        }

        if ($newMapping !== $job['mapping']) {
            // Columns changed -> re-read values from the sheet
            $job['mapping'] = $newMapping;
            $job['method']  = 'manual';
            $job['rates']   = $parser->extractRates($job['rows'], $job['currency_rows'], $newMapping);
            unset($job);
            redirectWith('success', 'Columns updated and values re-read from the file.');
        }

        // Otherwise take the (possibly hand-edited) values from the grid
        $missing = $config['missing_value'];
        foreach ($config['currencies'] as $code => $decimals) {
            foreach (RateParser::FIELDS as $field) {
                $raw = trim((string) ($_POST['rates'][$code][$field] ?? ''));
                $num = RateParser::toNumber($raw);
                $job['rates'][$code][$field] = ($num === null || $num == 0.0)
                    ? $missing
                    : number_format($num, $decimals, '.', '');
            }
        }
        unset($job);
        redirectWith('success', 'Table updated.');
    }

    // ---------------------------------------------------------- reset
    if ($action === 'reset') {
        unset($_SESSION['job']);
        redirectWith('info', 'Cleared. Upload a new file.');
    }

    redirectWith('error', 'Unknown action.');
}

// ======================================================================
// View
// ======================================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$job = $_SESSION['job'] ?? null;

$outputHtml = null;
$warnings   = [];
$columnOptions = [];

if ($job) {
    $outputHtml = $renderer->render($job['date'], $job['value_date'], $job['rates']);

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

    // Build column dropdown options with a sample value (first currency row)
    $maxCol = 0;
    foreach ($job['rows'] as $cells) {
        $maxCol = max($maxCol, $cells ? max(array_keys($cells)) : 0);
    }
    $sampleCode = array_key_first($job['currency_rows']);
    $sampleRow  = $sampleCode !== null ? $job['rows'][$job['currency_rows'][$sampleCode]['row']] : [];
    for ($c = 0; $c <= $maxCol; $c++) {
        $sample = $sampleRow[$c] ?? '';
        $columnOptions[$c] = 'Column ' . RateParser::columnLetter($c)
            . ($sample !== '' && $sampleCode ? " ({$sampleCode}: {$sample})" : '');
    }
}

$pageTitle = 'Convert rates';
require __DIR__ . '/includes/header.php';
?>

<div class="container">

    <section class="page-intro">
        <h1>Daily exchange rates</h1>
        <p>Upload today's rates Excel file. The table is generated in the same format as the Alert page, ready to paste.</p>
    </section>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- ============================== Upload ============================== -->
    <section class="card">
        <div class="card-head">
            <h2><span class="step">1</span> Upload the rates file</h2>
            <?php if ($job): ?>
                <span class="muted">Current: <strong><?= e($job['file_name']) ?></strong> (sheet "<?= e($job['sheet_name']) ?>")</span>
            <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" class="upload-form" id="upload-form">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="upload">

            <label class="dropzone" id="dropzone">
                <input type="file" name="rates_file" id="rates_file" accept=".xlsx,.xlsm,.csv" required>
                <span class="dropzone-title">Drop the Excel file here or click to browse</span>
                <span class="dropzone-sub" id="dropzone-file">.xlsx or .csv, up to <?= (int) ($config['max_upload_bytes'] / 1024 / 1024) ?> MB</span>
            </label>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Read file</button>
                <?php if ($job): ?>
                    <button type="submit" form="reset-form" class="btn btn-ghost">Clear</button>
                <?php endif; ?>
            </div>
        </form>
        <form method="post" id="reset-form">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="reset">
        </form>
    </section>

    <?php if ($job): ?>

        <?php foreach ($warnings as $w): ?>
            <div class="alert alert-warning"><?= e($w) ?></div>
        <?php endforeach; ?>

        <!-- ============================== Review ============================== -->
        <section class="card">
            <div class="card-head">
                <h2><span class="step">2</span> Check the values</h2>
                <span class="muted">Edit anything that looks wrong, then press "Update table".</span>
            </div>

            <form method="post" class="review-form">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="update">

                <div class="field-row">
                    <label class="field">
                        <span>Date</span>
                        <input type="date" name="date" value="<?= e($job['date']) ?>" required>
                    </label>
                    <label class="field">
                        <span>Value date</span>
                        <input type="date" name="value_date" value="<?= e($job['value_date']) ?>" required>
                        <?php if (!empty($job['file_value_date']) && $job['file_value_date'] !== $job['value_date']): ?>
                            <small class="muted">Spot value date in the file: <?= e(TableRenderer::formatDate($job['file_value_date'])) ?></small>
                        <?php endif; ?>
                    </label>
                </div>

                <details class="mapping" <?= in_array(null, $job['mapping'], true) || $job['method'] === 'position' ? 'open' : '' ?>>
                    <summary>Excel columns
                        <span class="muted">(detected <?= $job['method'] === 'headers' ? 'from headings' : ($job['method'] === 'manual' ? 'manually' : 'by position') ?>)</span>
                    </summary>
                    <div class="mapping-grid">
                        <?php foreach (RateParser::FIELD_LABELS as $field => $label): ?>
                            <label class="field">
                                <span><?= e($label) ?></span>
                                <select name="map[<?= e($field) ?>]">
                                    <option value="">— not in file (N/A) —</option>
                                    <?php foreach ($columnOptions as $c => $optLabel): ?>
                                        <option value="<?= $c ?>" <?= $job['mapping'][$field] === $c ? 'selected' : '' ?>>
                                            <?= e($optLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="muted small">Changing a column re-reads the values from the file (hand edits below are replaced).</p>
                </details>

                <div class="table-scroll">
                    <table class="rates-grid">
                        <thead>
                        <tr>
                            <th rowspan="2">Currency</th>
                            <th colspan="2">Spot</th>
                            <th colspan="2">Bank notes</th>
                        </tr>
                        <tr>
                            <th>Bnk sells</th><th>Bnk buys</th>
                            <th>Bnk sells</th><th>Bnk buys</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($job['rates'] as $code => $values): ?>
                            <tr>
                                <th scope="row"><?= e($code) ?></th>
                                <?php foreach (RateParser::FIELDS as $field): ?>
                                    <?php $v = $values[$field]; ?>
                                    <td>
                                        <input type="text" inputmode="decimal"
                                               name="rates[<?= e($code) ?>][<?= e($field) ?>]"
                                               value="<?= e($v) ?>"
                                               class="<?= $v === $config['missing_value'] ? 'is-missing' : '' ?>"
                                               aria-label="<?= e($code . ' ' . RateParser::FIELD_LABELS[$field]) ?>">
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Update table</button>
                    <span class="muted small">Leave a box empty or type N/A for no rate.</span>
                </div>
            </form>
        </section>

        <!-- ============================== Output ============================== -->
        <section class="card">
            <div class="card-head">
                <h2><span class="step">3</span> Copy into Alert</h2>
            </div>

            <div class="tabs" role="tablist">
                <button type="button" class="tab is-active" data-tab="preview" role="tab" aria-selected="true">Preview</button>
                <button type="button" class="tab" data-tab="html" role="tab" aria-selected="false">HTML source</button>
            </div>

            <div class="tab-panel is-active" id="tab-preview" role="tabpanel">
                <p class="muted small">For Alert's normal (visual) editor: copy the table, then paste it into the page.</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" data-copy="table">Copy table</button>
                    <span class="copy-status" aria-live="polite"></span>
                </div>
                <div class="alert-preview" id="alert-preview"><?= $outputHtml ?></div>
            </div>

            <div class="tab-panel" id="tab-html" role="tabpanel" hidden>
                <p class="muted small">For Alert's Source / HTML view: select all the old table code and replace it with this.</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" data-copy="html">Copy HTML</button>
                    <span class="copy-status" aria-live="polite"></span>
                </div>
                <textarea id="html-output" class="code-output" rows="18" readonly spellcheck="false"><?= e($outputHtml) ?></textarea>
            </div>
        </section>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
