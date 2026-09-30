/**
 * app.js — dropzone, output tabs, copy buttons and grid hints for index.php.
 * Loaded with "defer", so the DOM is ready when this runs.
 */
(function () {
    'use strict';

    /** How long the "Copied ✓" message stays visible. */
    const STATUS_CLEAR_MS = 3000;

    // ---------------------------------------------------------- dropzone
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('rates_file');
    const fileLabel = document.getElementById('dropzone-file');
    const uploadForm = document.getElementById('upload-form');

    if (dropzone && fileInput) {
        const showName = function () {
            if (fileInput.files.length) {
                fileLabel.textContent = fileInput.files[0].name;
                dropzone.classList.add('has-file');
            }
        };
        fileInput.addEventListener('change', showName);

        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });
        dropzone.addEventListener('dragleave', function () {
            dropzone.classList.remove('is-dragging');
        });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropzone.classList.remove('is-dragging');
            if (e.dataTransfer && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                showName();
                if (uploadForm) uploadForm.requestSubmit();
            }
        });
    }

    // Prevent double submits while the file is uploading
    if (uploadForm) {
        const readButton = uploadForm.querySelector('button[type="submit"]:not([form])');
        const readLabel = readButton ? readButton.textContent : '';
        uploadForm.addEventListener('submit', function () {
            if (readButton) {
                readButton.disabled = true;
                readButton.textContent = 'Reading…';
            }
        });
        // Re-enable when the page is restored from the back/forward cache
        window.addEventListener('pageshow', function (e) {
            if (e.persisted && readButton) {
                readButton.disabled = false;
                readButton.textContent = readLabel;
            }
        });
    }

    // ---------------------------------------------------------- tabs
    // WAI-ARIA tabs: click or Left/Right/Home/End to switch
    const tabs = Array.from(document.querySelectorAll('[role="tab"]'));

    function activateTab(tab, moveFocus) {
        tabs.forEach(function (t) {
            const active = t === tab;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
            t.tabIndex = active ? 0 : -1;
            document.getElementById(t.getAttribute('aria-controls')).hidden = !active;
        });
        if (moveFocus) tab.focus();
    }

    tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { activateTab(tab, false); });
        tab.addEventListener('keydown', function (e) {
            const targets = {
                ArrowRight: tabs[(i + 1) % tabs.length],
                ArrowLeft: tabs[(i - 1 + tabs.length) % tabs.length],
                Home: tabs[0],
                End: tabs[tabs.length - 1]
            };
            if (targets[e.key]) {
                e.preventDefault();
                activateTab(targets[e.key], true);
            }
        });
    });

    // ---------------------------------------------------------- copy
    const htmlOutput = document.getElementById('html-output');
    const preview = document.getElementById('alert-preview');
    const statusTimers = new WeakMap();

    function setStatus(button, text, ok) {
        const status = button.parentElement.querySelector('.copy-status');
        if (!status) return;
        status.textContent = text;
        status.classList.toggle('is-error', !ok);
        clearTimeout(statusTimers.get(status));
        statusTimers.set(status, setTimeout(function () {
            status.textContent = '';
            status.classList.remove('is-error');
        }, STATUS_CLEAR_MS));
    }

    /** execCommand fallback, e.g. for http:// on a LAN IP where navigator.clipboard is unavailable. */
    function legacyCopy(node) {
        const selection = window.getSelection();
        if (node instanceof HTMLTextAreaElement) {
            node.select();
        } else {
            const range = document.createRange();
            range.selectNodeContents(node);
            selection.removeAllRanges();
            selection.addRange(range);
        }
        const ok = document.execCommand('copy');
        selection.removeAllRanges();
        return ok;
    }

    const canUseClipboard = function () {
        return navigator.clipboard && window.isSecureContext;
    };

    async function copyHtmlSource() {
        if (canUseClipboard()) {
            try {
                await navigator.clipboard.writeText(htmlOutput.value);
                return true;
            } catch (err) { /* permission denied etc. — fall back below */ }
        }
        return legacyCopy(htmlOutput);
    }

    async function copyRenderedTable() {
        if (canUseClipboard() && window.ClipboardItem) {
            try {
                await navigator.clipboard.write([
                    new ClipboardItem({
                        'text/html': new Blob([htmlOutput.value], { type: 'text/html' }),
                        'text/plain': new Blob([preview.innerText], { type: 'text/plain' })
                    })
                ]);
                return true;
            } catch (err) { /* e.g. text/html not supported — fall back below */ }
        }
        return legacyCopy(preview);
    }

    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', async function () {
            let ok = false;
            try {
                ok = button.dataset.copy === 'html' ? await copyHtmlSource() : await copyRenderedTable();
            } catch (err) {
                ok = false;
            }
            setStatus(button, ok ? 'Copied ✓' : 'Copy failed – select and press Ctrl+C', ok);
        });
    });

    // ---------------------------------------------------------- grid hints
    const reviewForm = document.querySelector('.review-form');
    const missing = reviewForm ? (reviewForm.dataset.missing || '').toUpperCase() : '';

    document.querySelectorAll('.rates-grid input').forEach(function (input) {
        input.addEventListener('input', function () {
            const v = input.value.trim().toUpperCase();
            input.classList.toggle('is-missing', v === '' || v === missing);
        });
    });
})();
