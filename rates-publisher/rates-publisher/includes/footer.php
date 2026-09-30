<?php
/**
 * footer.php — closes <main>, <body> and <html>.
 */
$siteName = $siteName ?? 'Rates Publisher';
$baseUrl  = $baseUrl  ?? '';
?>
</main>

<footer class="site-footer site-footer--compact">
    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <p>&copy; <?= date('Y') ?> <?= e($siteName) ?>. Internal tool.</p>
            <p>Upload &rarr; review &rarr; copy into Alert Web Publisher</p>
        </div>
    </div>
</footer>

<script src="<?= e($baseUrl) ?>assets/js/app.js"></script>
</body>
</html>
