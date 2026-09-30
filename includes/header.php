<?php
declare(strict_types=1);

// Only for inclusion by index.php, never opened directly
if (!function_exists('e')) {
    http_response_code(404);
    exit;
}

/**
 * header.php — <head>, site header, opens <main>.
 * Included by index.php with $config and $pageTitle set.
 */
$siteName  = $config['site_name'];
$fullTitle = $pageTitle !== '' ? "$pageTitle | $siteName" : $siteName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($config['site_tagline']) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($fullTitle) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</head>
<body>

<a class="skip-link" href="#main-content">Skip to content</a>

<header class="site-header">
    <div class="container header-inner">
        <a href="index.php" class="brand" aria-label="<?= e($siteName) ?> home">
            <span class="brand-mark" aria-hidden="true"><?= e(strtoupper(substr($siteName, 0, 1))) ?></span>
            <span class="brand-name"><?= e($siteName) ?></span>
        </a>
    </div>
</header>

<main id="main-content" class="site-main">
