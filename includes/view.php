<?php
declare(strict_types=1);

// Only for inclusion by index.php, never opened directly
if (!function_exists('e')) {
    http_response_code(404);
    exit;
}

/**
 * view.php — the page body (upload, review, copy). Included by index.php with
 * $config, $flash, $job, $review and $outputHtml set.
 */
$methodLabels = ['headers' => 'from headings', 'manual' => 'manually', 'position' => 'by position'];
?>
<div class="container">

    <section class="page-intro">
        <h1>Daily exchange rates</h1>
        <p>Upload today's rates Excel file. The table is generated in the same format as the Alert page, ready to paste.</p>
    </section>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($flash['message']) ?></div>
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

        <?php foreach ($review['warnings'] as $w): ?>
            <div class="alert alert-warning"><?= e($w) ?></div>
        <?php endforeach; ?>

        <!-- ============================== Review ============================== -->
        <section class="card">
            <div class="card-head">
                <h2><span class="step">2</span> Check the values</h2>
                <span class="muted">Edit anything that looks wrong, then press "Update table".</span>
            </div>

            <form method="post" class="review-form" data-missing="<?= e($config['missing_value']) ?>">
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
                        <span class="muted">(detected <?= e($methodLabels[$job['method']] ?? $job['method']) ?>)</span>
                    </summary>
                    <div class="mapping-grid">
                        <?php foreach (RateParser::FIELD_LABELS as $field => $label): ?>
                            <label class="field">
                                <span><?= e($label) ?></span>
                                <select name="map[<?= e($field) ?>]">
                                    <option value="">— not in file (N/A) —</option>
                                    <?php foreach ($review['column_options'] as $c => $optLabel): ?>
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
                    <table class="rates-grid" style="--color-table-head: <?= e($config['colors']['header_bg']) ?>">
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
                    <span class="muted small">Leave a box empty or type <?= e($config['missing_value']) ?> for no rate.</span>
                </div>
            </form>
        </section>

        <!-- ============================== Output ============================== -->
        <section class="card">
            <div class="card-head">
                <h2><span class="step">3</span> Copy into Alert</h2>
            </div>

            <div class="tabs" role="tablist" aria-label="Output format">
                <button type="button" class="tab is-active" id="tab-btn-preview" role="tab"
                        aria-selected="true" aria-controls="tab-preview">Preview</button>
                <button type="button" class="tab" id="tab-btn-html" role="tab"
                        aria-selected="false" aria-controls="tab-html" tabindex="-1">HTML source</button>
            </div>

            <div class="tab-panel" id="tab-preview" role="tabpanel" aria-labelledby="tab-btn-preview" tabindex="0">
                <p class="muted small">For Alert's normal (visual) editor: copy the table, then paste it into the page.</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" data-copy="table">Copy table</button>
                    <span class="copy-status" aria-live="polite"></span>
                </div>
                <div class="alert-preview" id="alert-preview"><?= $outputHtml ?></div>
            </div>

            <div class="tab-panel" id="tab-html" role="tabpanel" aria-labelledby="tab-btn-html" tabindex="0" hidden>
                <p class="muted small">For Alert's Source / HTML view: select the whole old block (from the first <code>&lt;p&gt;&lt;/p&gt;</code> to the end of the small empty table after the rates) and replace it with this.</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary" data-copy="html">Copy HTML</button>
                    <span class="copy-status" aria-live="polite"></span>
                </div>
                <textarea id="html-output" class="code-output" rows="18" readonly spellcheck="false" aria-label="Table HTML"><?= e($outputHtml) ?></textarea>
            </div>
        </section>

    <?php endif; ?>
</div>
