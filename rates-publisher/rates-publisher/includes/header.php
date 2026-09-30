<?php
/**
 * header.php
 * Set $pageTitle / $pageDescription before including. Uses relative links so the
 * app works from any folder (e.g. http://localhost/rates-publisher/).
 */

$config      = $config ?? require __DIR__ . '/config.php';
$siteName    = $siteName    ?? $config['site_name'];
$siteTagline = $siteTagline ?? $config['site_tagline'];
$baseUrl     = $baseUrl     ?? '';

$pageTitle       = $pageTitle       ?? '';
$pageDescription = $pageDescription ?? $siteTagline;

$navItems = $navItems ?? [
    'Convert rates' => 'index.php',
];

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'index.php');
if ($currentPage === '' || !str_contains($currentPage, '.')) {
    $currentPage = 'index.php';
}

$fullTitle = $pageTitle !== '' ? "$pageTitle | $siteName" : $siteName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($pageDescription) ?>">
    <title><?= e($fullTitle) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e($baseUrl) ?>assets/css/style.css">
</head>
<body>

<a class="skip-link" href="#main-content">Skip to content</a>

<header class="site-header">
    <div class="container header-inner">
        <a href="<?= e($baseUrl) ?>index.php" class="brand" aria-label="<?= e($siteName) ?> home">
            <span class="brand-mark"><?= e(strtoupper(substr($siteName, 0, 1))) ?></span>
            <span class="brand-name"><?= e($siteName) ?></span>
        </a>

        <button class="nav-toggle" aria-controls="primary-nav" aria-expanded="false" aria-label="Toggle navigation">
            <span></span><span></span><span></span>
        </button>

        <nav id="primary-nav" class="primary-nav" aria-label="Primary">
            <ul>
                <?php foreach ($navItems as $label => $href): ?>
                    <?php $isActive = basename($href) === $currentPage; ?>
                    <li>
                        <a href="<?= e($baseUrl . $href) ?>"
                           class="<?= $isActive ? 'active' : '' ?>"
                           <?= $isActive ? 'aria-current="page"' : '' ?>>
                            <?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>

<main id="main-content" class="site-main">
