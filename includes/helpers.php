<?php
declare(strict_types=1);

/**
 * helpers.php — small functions shared by the page and its templates.
 */

/** Escape text for HTML (content and attributes). */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Relative asset URL with a cache-busting version from the file's modification time. */
function asset(string $path): string
{
    $mtime = @filemtime(__DIR__ . '/../' . $path);
    return $path . ($mtime ? '?v=' . $mtime : '');
}

/** 303 redirect; falls back to a meta refresh if output has already started. */
function redirectTo(string $url): never
{
    if (headers_sent()) {
        exit('<meta http-equiv="refresh" content="0;url=' . e($url) . '"><a href="' . e($url) . '">Continue</a>');
    }
    header('Location: ' . $url, true, 303);
    exit;
}

/** True if $value is a real calendar date in Y-m-d format (2026-02-31 is rejected). */
function isValidYmd(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

/** Message for a PHP upload error code. */
function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Please choose the rates Excel file first.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION =>
            "The server could not store the upload (error code {$code}). Ask the administrator to check PHP's upload settings.",
        default => "Upload failed (error code {$code}).",
    };
}

/** Security headers for every page response. */
function sendSecurityHeaders(): void
{
    // Inline styles are needed for the generated Alert table (style="" attributes)
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    // The page holds the day's rates: don't keep it in shared caches
    header('Cache-Control: no-store');
}
