<?php
declare(strict_types=1);

// Errors go to the PHP error log, never to the page
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$config = require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/helpers.php';

// Over post_max_size PHP drops the whole body (and may already have printed a
// warning, so no session cookie can be sent): report it via a fixed notice code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    redirectTo('index.php?notice=too-large');
}

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

require __DIR__ . '/includes/SpreadsheetReader.php';
require __DIR__ . '/includes/RateParser.php';
require __DIR__ . '/includes/TableRenderer.php';
require __DIR__ . '/includes/actions.php';

sendSecurityHeaders();

$parser   = new RateParser($config);
$renderer = new TableRenderer($config);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Drop a job saved by an older version of the tool (different shape)
if (isset($_SESSION['job']) && !isset($_SESSION['job']['max_col'])) {
    unset($_SESSION['job']);
}

/** Store a one-time message and redirect back (Post/Redirect/Get). */
function redirectWith(string $type, string $message): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    redirectTo('index.php');
}

// ======================================================================
// POST handling
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        redirectWith('error', 'Your session expired. Please try again.');
    }

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'upload':
                $_SESSION['job'] = handleUpload($_FILES['rates_file'] ?? null, $parser, $config);
                redirectWith('success', 'File read successfully. Check the values below, then copy the table.');

            case 'update':
                if (!isset($_SESSION['job'])) {
                    redirectWith('error', 'Nothing to update – your session expired. Upload the file again.');
                }
                [$_SESSION['job'], $message] = handleUpdate($_SESSION['job'], $_POST, $parser, $config);
                redirectWith('success', $message);

            case 'reset':
                unset($_SESSION['job']);
                redirectWith('info', 'Cleared. Upload a new file.');
        }
    } catch (RuntimeException $ex) {
        redirectWith('error', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('Rates Publisher: ' . $ex);
        redirectWith('error', 'Something went wrong reading the file. Check that it opens in Excel, then try again.');
    }

    redirectWith('error', 'Unknown action.');
}

// ======================================================================
// View
// ======================================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if (!$flash && ($_GET['notice'] ?? '') === 'too-large') {
    $flash = ['type' => 'error', 'message' => uploadErrorMessage(UPLOAD_ERR_INI_SIZE)];
}

$job        = $_SESSION['job'] ?? null;
$outputHtml = null;
$review     = ['warnings' => [], 'column_options' => []];

if ($job) {
    $outputHtml = $renderer->render($job['date'], $job['value_date'], $job['rates']);
    $review     = buildReviewView($job, $config);
}

$pageTitle = 'Convert rates';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/view.php';
require __DIR__ . '/includes/footer.php';
