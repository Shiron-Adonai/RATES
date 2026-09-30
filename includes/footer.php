<?php
declare(strict_types=1);

// Only for inclusion by index.php, never opened directly
if (!function_exists('e')) {
    http_response_code(404);
    exit;
}

/**
 * footer.php — closes <main>, <body> and <html>.
 */
?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <p>&copy; <?= date('Y') ?> <?= e($config['site_name']) ?>. Internal tool.</p>
        <p>Upload &rarr; review &rarr; copy into Alert Web Publisher</p>
    </div>
</footer>
</body>
</html>
