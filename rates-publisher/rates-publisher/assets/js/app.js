(function () {
    'use strict';

    // ---------------------------------------------------------- mobile nav
    const toggle = document.querySelector('.nav-toggle');
    const nav = document.getElementById('primary-nav');
    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            const open = nav.classList.toggle('is-open');
            toggle.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        window.addEventListener('resize', function () {
            if (window.innerWidth > 860) {
                nav.classList.remove('is-open');
                toggle.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

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
        ['dragleave', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                showName();
                if (uploadForm) uploadForm.requestSubmit();
            }
        });
    }

    // ---------------------------------------------------------- tabs
    document.querySelectorAll('.tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.tab').forEach(function (t) {
                const active = t === tab;
                t.classList.toggle('is-active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            document.querySelectorAll('.tab-panel').forEach(function (panel) {
                const active = panel.id === 'tab-' + tab.dataset.tab;
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
            });
        });
    });

    // ---------------------------------------------------------- copy
    const htmlOutput = document.getElementById('html-output');
    const preview = document.getElementById('alert-preview');

    function setStatus(button, text, ok) {
        const status = button.parentElement.querySelector('.copy-status');
        if (!status) return;
        status.textContent = text;
        status.classList.toggle('is-error', !ok);
        clearTimeout(status._timer);
        status._timer = setTimeout(function () { status.textContent = ''; }, 3000);
    }

    // Fallback for http:// on a LAN IP, where navigator.clipboard is unavailable
    function legacyCopy(node) {
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(node);
        selection.removeAllRanges();
        selection.addRange(range);
        const ok = document.execCommand('copy');
        selection.removeAllRanges();
        return ok;
    }

    async function copyHtmlSource() {
        const text = htmlOutput.value;
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        htmlOutput.hidden = false;
        htmlOutput.select();
        return document.execCommand('copy');
    }

    async function copyRenderedTable() {
        const html = htmlOutput.value;
        if (navigator.clipboard && window.isSecureContext && window.ClipboardItem) {
            await navigator.clipboard.write([
                new ClipboardItem({
                    'text/html': new Blob([html], { type: 'text/html' }),
                    'text/plain': new Blob([preview.innerText], { type: 'text/plain' })
                })
            ]);
            return true;
        }
        return legacyCopy(preview);
    }

    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', async function () {
            try {
                const ok = button.dataset.copy === 'html'
                    ? await copyHtmlSource()
                    : await copyRenderedTable();
                setStatus(button, ok ? 'Copied ✓' : 'Copy failed – select and press Ctrl+C', ok);
            } catch (err) {
                setStatus(button, 'Copy failed – select and press Ctrl+C', false);
            }
        });
    });

    // ---------------------------------------------------------- grid helpers
    document.querySelectorAll('.rates-grid input').forEach(function (input) {
        input.addEventListener('input', function () {
            const v = input.value.trim().toUpperCase();
            input.classList.toggle('is-missing', v === '' || v === 'N/A');
        });
    });
})();
