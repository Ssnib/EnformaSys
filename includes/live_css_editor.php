<?php
/**
 * EnformaSys Built-In Live CSS Editor & Theme Customizer
 * Non-blocking floating drawer for real-time CSS preview and safe server-side saving.
 */
if (defined('ENFORMASYS_LIVE_CSS_EDITOR_RENDERED')) {
    return;
}

$__liveCssRole = (string)($_SESSION['role'] ?? '');
$__liveCssIsAdmin = in_array($__liveCssRole, ['Admin', 'Dean', 'Rector'], true);
$__liveCssQueryActive = isset($_GET['live_css']) && (string)$_GET['live_css'] === '1' && isset($_SESSION['user']);

if (!$__liveCssIsAdmin && !$__liveCssQueryActive) {
    return;
}
define('ENFORMASYS_LIVE_CSS_EDITOR_RENDERED', true);

require_once __DIR__ . '/url.php';
$__liveCssFile = __DIR__ . '/../css/custom_live.css';
$__liveCssContent = is_file($__liveCssFile) ? (string)@file_get_contents($__liveCssFile) : '';
$__liveCssAjaxUrl = capstone_url('modules/ajax.php');
$__liveCssHrefBase = capstone_url('css/custom_live.css');
$__liveCssCsrf = (string)($_SESSION['csrf_token'] ?? '');
?>
<style>
#enformasysLiveCssBtn {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 9990;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    height: 2.5rem;
    padding: 0 0.95rem;
    border-radius: 9999px;
    border: 1px solid var(--border-hairline, rgba(92, 58, 49, 0.14));
    background: rgba(255, 255, 255, 0.90);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    color: var(--brand-primary, #5c3a31);
    font-family: var(--font-heading, 'Plus Jakarta Sans', 'Inter', sans-serif);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75), 0 10px 24px -6px rgba(45, 27, 22, 0.18);
    cursor: pointer;
    transition: transform 180ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 180ms ease, background-color 180ms ease, border-color 180ms ease;
}
#enformasysLiveCssBtn:hover {
    transform: translateY(-2px);
    border-color: var(--brand-primary, #5c3a31);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75), 0 14px 28px -6px rgba(45, 27, 22, 0.24);
}
#enformasysLiveCssBtn:active {
    transform: scale(0.96) !important;
}
html[data-theme="dark"] #enformasysLiveCssBtn {
    background: rgba(27, 24, 23, 0.90);
    color: #f5f0ec;
    border-color: rgba(245, 240, 236, 0.14);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08), 0 12px 28px -6px rgba(0, 0, 0, 0.60);
}
html[data-theme="dark"] #enformasysLiveCssBtn i {
    color: var(--brand-accent, #e0a943);
}
#enformasysLiveCssBtn .live-css-dot {
    width: 7px;
    height: 7px;
    border-radius: 9999px;
    background: #10b981;
    display: none;
}
#enformasysLiveCssBtn.has-unsaved .live-css-dot {
    display: inline-block;
    background: #f59e0b;
}

#enformasysLiveCssPanel {
    position: fixed;
    right: 1.25rem;
    bottom: 4.35rem;
    z-index: 9991;
    width: min(460px, calc(100vw - 2rem));
    max-height: min(76vh, 620px);
    display: flex;
    flex-direction: column;
    border-radius: 16px;
    border: 1px solid var(--border-hairline, rgba(92, 58, 49, 0.14));
    background: rgba(255, 255, 255, 0.96);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    color: var(--text-primary, #3f2822);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75), 0 24px 54px -12px rgba(45, 27, 22, 0.26);
    overflow: hidden;
    opacity: 0;
    transform: translateY(10px) scale(0.97);
    pointer-events: none;
    visibility: hidden;
    transition: opacity 180ms ease, transform 200ms cubic-bezier(0.22, 1, 0.36, 1), visibility 180ms ease;
}
#enformasysLiveCssPanel.is-open {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
    visibility: visible;
}
html[data-theme="dark"] #enformasysLiveCssPanel {
    background: rgba(27, 24, 23, 0.96);
    border-color: rgba(245, 240, 236, 0.12);
    color: #f5f0ec;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06), 0 28px 60px -12px rgba(0, 0, 0, 0.72);
}

.live-css-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    background: var(--surface-subtle, #fcfaf8);
    border-bottom: 1px solid var(--border-hairline, rgba(92, 58, 49, 0.10));
}
html[data-theme="dark"] .live-css-header {
    background: #231f1d;
    border-bottom-color: rgba(245, 240, 236, 0.09);
}
.live-css-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-family: var(--font-heading, 'Plus Jakarta Sans', 'Inter', sans-serif);
    font-size: 0.84rem;
    font-weight: 800;
    color: var(--text-primary, #3f2822);
    margin: 0;
}
html[data-theme="dark"] .live-css-title {
    color: #f5f0ec;
}
.live-css-status {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.15rem 0.55rem;
    border-radius: 9999px;
    font-size: 0.66rem;
    font-weight: 700;
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
    border: 1px solid rgba(16, 185, 129, 0.25);
}
html[data-theme="dark"] .live-css-status {
    background: rgba(16, 185, 129, 0.16);
    color: #6ee7b7;
    border-color: rgba(16, 185, 129, 0.30);
}
.live-css-status.is-dirty {
    background: rgba(245, 158, 11, 0.14);
    color: #b45309;
    border-color: rgba(245, 158, 11, 0.28);
}
html[data-theme="dark"] .live-css-status.is-dirty {
    color: #fcd34d;
}
.live-css-close {
    width: 1.85rem;
    height: 1.85rem;
    border-radius: 0.6rem;
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-secondary, #7b6158);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background-color 150ms ease, color 150ms ease;
}
.live-css-close:hover {
    background: rgba(92, 58, 49, 0.08);
    color: var(--text-primary, #3f2822);
}
html[data-theme="dark"] .live-css-close {
    color: #b8aea7;
}
html[data-theme="dark"] .live-css-close:hover {
    background: rgba(245, 240, 236, 0.08);
    color: #f5f0ec;
}

.live-css-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding: 0.5rem 1rem;
    border-bottom: 1px solid var(--border-hairline, rgba(92, 58, 49, 0.08));
    font-size: 0.7rem;
    color: var(--text-secondary, #7b6158);
}
html[data-theme="dark"] .live-css-toolbar {
    border-bottom-color: rgba(245, 240, 236, 0.08);
    color: #b8aea7;
}
.live-css-snippets {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
}
.live-css-chip {
    padding: 0.2rem 0.55rem;
    border-radius: 9999px;
    border: 1px solid var(--border-hairline, rgba(92, 58, 49, 0.14));
    background: var(--surface-subtle, #fcfaf8);
    color: var(--text-primary, #3f2822);
    font-size: 0.68rem;
    font-weight: 700;
    cursor: pointer;
    transition: background-color 140ms ease, border-color 140ms ease;
}
.live-css-chip:hover {
    border-color: var(--brand-primary, #5c3a31);
}
html[data-theme="dark"] .live-css-chip {
    background: #231f1d;
    border-color: rgba(245, 240, 236, 0.12);
    color: #f5f0ec;
}

.live-css-body {
    padding: 0.75rem 1rem;
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
}
#enformasysLiveCssInput {
    width: 100%;
    min-height: 240px;
    max-height: 44vh;
    resize: vertical;
    padding: 0.75rem 0.85rem;
    border-radius: 12px;
    border: 1px solid var(--border-hairline, rgba(92, 58, 49, 0.16));
    background: #fcfaf8;
    color: #2d1b16;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 12.5px;
    line-height: 1.55;
    tab-size: 2;
    outline: none;
    box-sizing: border-box;
    transition: border-color 160ms ease, box-shadow 160ms ease;
}
#enformasysLiveCssInput:focus {
    border-color: var(--brand-primary, #5c3a31);
    box-shadow: var(--focus-ring, 0 0 0 3px rgba(92, 58, 49, 0.14));
}
html[data-theme="dark"] #enformasysLiveCssInput {
    background: #141110 !important;
    color: #f5f0ec !important;
    border-color: rgba(245, 240, 236, 0.13) !important;
}

.live-css-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.65rem;
    flex-wrap: wrap;
    padding: 0.75rem 1rem;
    background: var(--surface-subtle, #fcfaf8);
    border-top: 1px solid var(--border-hairline, rgba(92, 58, 49, 0.10));
}
html[data-theme="dark"] .live-css-footer {
    background: #231f1d;
    border-top-color: rgba(245, 240, 236, 0.09);
}
.live-css-hint {
    font-size: 0.69rem;
    color: var(--text-secondary, #7b6158);
    font-weight: 600;
}
html[data-theme="dark"] .live-css-hint {
    color: #b8aea7;
}
.live-css-actions {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.live-css-btn-reset {
    padding: 0.45rem 0.8rem;
    border-radius: 10px;
    border: 1px solid var(--border-hairline, rgba(92, 58, 49, 0.18));
    background: #ffffff;
    color: var(--text-primary, #3f2822);
    font-size: 0.74rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: background-color 150ms ease, border-color 150ms ease;
}
.live-css-btn-reset:hover {
    background: #f8f5f2;
    border-color: rgba(92, 58, 49, 0.32);
}
html[data-theme="dark"] .live-css-btn-reset {
    background: #1b1817;
    color: #f5f0ec;
    border-color: rgba(245, 240, 236, 0.14);
}
html[data-theme="dark"] .live-css-btn-reset:hover {
    background: #2c2623;
}
.live-css-btn-save {
    padding: 0.45rem 0.95rem;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #674238 0%, #5c3a31 55%, #462a23 100%);
    color: #ffffff;
    font-size: 0.74rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    box-shadow: 0 4px 12px -2px rgba(92, 58, 49, 0.28);
    transition: transform 150ms ease, box-shadow 150ms ease, opacity 150ms ease;
}
.live-css-btn-save:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px -4px rgba(70, 42, 35, 0.34);
}
html[data-theme="dark"] .live-css-btn-save {
    background: linear-gradient(135deg, #c98a6b 0%, #b57556 100%);
    color: #181210;
}
@media print {
    #enformasysLiveCssBtn,
    #enformasysLiveCssPanel {
        display: none !important;
    }
}
</style>

<!-- Discreet Floating Live CSS Trigger -->
<button id="enformasysLiveCssBtn"
        type="button"
        class="print:hidden"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="enformasysLiveCssPanel"
        title="Open Live CSS Editor &amp; Theme Customizer">
    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
    <span>Live CSS</span>
    <span class="live-css-dot" aria-hidden="true"></span>
</button>

<!-- Non-Blocking Floating Live CSS Editor Drawer -->
<aside id="enformasysLiveCssPanel"
       class="print:hidden"
       role="dialog"
       aria-label="Live CSS Editor &amp; Theme Customizer"
       aria-hidden="true"
       data-ajax-url="<?php echo htmlspecialchars($__liveCssAjaxUrl, ENT_QUOTES, 'UTF-8'); ?>"
       data-css-href="<?php echo htmlspecialchars($__liveCssHrefBase, ENT_QUOTES, 'UTF-8'); ?>"
       data-csrf="<?php echo htmlspecialchars($__liveCssCsrf, ENT_QUOTES, 'UTF-8'); ?>"
       data-can-save="<?php echo $__liveCssIsAdmin ? '1' : '0'; ?>">
    <div class="live-css-header">
        <div class="live-css-title">
            <i class="fa-solid fa-palette" style="color: var(--brand-primary, #5c3a31);" aria-hidden="true"></i>
            <span>Live CSS Customizer</span>
            <span id="enformasysLiveCssStatus" class="live-css-status">Live Preview</span>
        </div>
        <button id="enformasysLiveCssCloseBtn" type="button" class="live-css-close" aria-label="Close Live CSS Editor" title="Close (Esc)">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </div>

    <div class="live-css-toolbar">
        <span>Target: <code>css/custom_live.css</code></span>
        <div class="live-css-snippets">
            <button type="button" class="live-css-chip" data-css-snippet=":root {&#10;  --brand-primary: #5c3a31;&#10;  --brand-accent: #b8860b;&#10;}">+ Tokens</button>
            <button type="button" class="live-css-chip" data-css-snippet="html[data-theme=&quot;dark&quot;] {&#10;  --bg-canvas: #12100f;&#10;  --surface-card: #1b1817;&#10;}">+ Dark</button>
        </div>
    </div>

    <div class="live-css-body">
        <label for="enformasysLiveCssInput" class="sr-only">Custom CSS rules</label>
        <textarea id="enformasysLiveCssInput"
                  spellcheck="false"
                  autocapitalize="off"
                  autocomplete="off"
                  autocorrect="off"
                  placeholder="/* Type or paste custom CSS here for instant real-time preview... */"><?php echo htmlspecialchars($__liveCssContent, ENT_QUOTES, 'UTF-8'); ?></textarea>
    </div>

    <div class="live-css-footer">
        <span id="enformasysLiveCssHint" class="live-css-hint">
            <?php echo $__liveCssIsAdmin ? 'Ctrl+S to save globally' : 'Preview only (Admin required to save)'; ?>
        </span>
        <div class="live-css-actions">
            <button id="enformasysLiveCssResetBtn" type="button" class="live-css-btn-reset" title="Reset and clear custom live CSS">
                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                <span>Reset</span>
            </button>
            <?php if ($__liveCssIsAdmin): ?>
            <button id="enformasysLiveCssSaveBtn" type="button" class="live-css-btn-save" title="Save CSS to css/custom_live.css">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                <span>Save Live CSS</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</aside>

<script>
(function () {
    if (window.__enformasysLiveCssInit) return;
    window.__enformasysLiveCssInit = true;

    var toggleBtn = document.getElementById('enformasysLiveCssBtn');
    var panel = document.getElementById('enformasysLiveCssPanel');
    var closeBtn = document.getElementById('enformasysLiveCssCloseBtn');
    var input = document.getElementById('enformasysLiveCssInput');
    var saveBtn = document.getElementById('enformasysLiveCssSaveBtn');
    var resetBtn = document.getElementById('enformasysLiveCssResetBtn');
    var statusBadge = document.getElementById('enformasysLiveCssStatus');
    var hintEl = document.getElementById('enformasysLiveCssHint');

    if (!toggleBtn || !panel || !input) return;

    var ajaxUrl = panel.getAttribute('data-ajax-url') || '';
    var cssHrefBase = panel.getAttribute('data-css-href') || '';
    var canSave = panel.getAttribute('data-can-save') === '1';
    var savedSnapshot = input.value;

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) return meta.content;
        return panel.getAttribute('data-csrf') || '';
    }

    function notify(msg, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(msg, type || 'info', 3200);
        } else if (hintEl) {
            hintEl.textContent = msg;
        }
    }

    function ensurePreviewStyle() {
        var styleEl = document.getElementById('enformasys-live-css-preview');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'enformasys-live-css-preview';
            document.head.appendChild(styleEl);
        }
        return styleEl;
    }

    function applyLivePreview(cssText) {
        var safeCss = String(cssText || '').replace(/<\s*\/\s*style/gi, '/* blocked */');
        ensurePreviewStyle().textContent = safeCss;
    }

    function refreshLiveLink(mtime) {
        var link = document.getElementById('enformasys-live-css-link');
        if (link && cssHrefBase) {
            link.href = cssHrefBase + '?v=' + String(mtime || Date.now());
        }
    }

    function setDirtyState(dirty) {
        if (dirty) {
            toggleBtn.classList.add('has-unsaved');
            if (statusBadge) {
                statusBadge.textContent = 'Unsaved Preview';
                statusBadge.classList.add('is-dirty');
            }
        } else {
            toggleBtn.classList.remove('has-unsaved');
            if (statusBadge) {
                statusBadge.textContent = 'Saved';
                statusBadge.classList.remove('is-dirty');
            }
        }
    }

    function openPanel() {
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        toggleBtn.setAttribute('aria-expanded', 'true');
        setTimeout(function () { input.focus(); }, 60);
    }

    function closePanel() {
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        toggleBtn.setAttribute('aria-expanded', 'false');
    }

    toggleBtn.addEventListener('click', function () {
        if (panel.classList.contains('is-open')) closePanel();
        else openPanel();
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closePanel);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('is-open')) {
            closePanel();
        }
    });

    // Instant Real-Time Preview on every keystroke / paste
    input.addEventListener('input', function () {
        applyLivePreview(input.value);
        setDirtyState(input.value !== savedSnapshot);
    });

    // Comfortable editor ergonomics: Tab inserts 2 spaces; Ctrl+S / Cmd+S saves
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var start = input.selectionStart;
            var end = input.selectionEnd;
            input.value = input.value.substring(0, start) + '  ' + input.value.substring(end);
            input.selectionStart = input.selectionEnd = start + 2;
            applyLivePreview(input.value);
            setDirtyState(input.value !== savedSnapshot);
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            if (canSave && saveBtn) saveBtn.click();
        }
    });

    // Quick snippet chips
    panel.querySelectorAll('[data-css-snippet]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var snip = chip.getAttribute('data-css-snippet') || '';
            var sep = input.value.trim() === '' ? '' : '\n\n';
            input.value = input.value.replace(/\s+$/, '') + sep + snip + '\n';
            applyLivePreview(input.value);
            setDirtyState(input.value !== savedSnapshot);
            input.focus();
            input.scrollTop = input.scrollHeight;
        });
    });

    async function persistLiveCss(actionName, payloadCss, btnEl, busyLabel) {
        if (!ajaxUrl) return;
        var origHtml = btnEl ? btnEl.innerHTML : '';
        if (btnEl) {
            btnEl.disabled = true;
            btnEl.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i><span>' + busyLabel + '</span>';
        }
        try {
            var fd = new FormData();
            fd.append('action', actionName);
            fd.append('csrf_token', getCsrfToken());
            if (payloadCss !== null) fd.append('css', payloadCss);

            var res = await fetch(ajaxUrl + '?action=' + encodeURIComponent(actionName), {
                method: 'POST',
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': getCsrfToken()
                }
            });
            var data = await res.json();
            if (!res.ok || !data || !data.ok) {
                throw new Error((data && data.error) ? data.error : 'Failed to update Live CSS.');
            }
            if (actionName === 'reset_live_css') {
                var resetText = typeof data.css === 'string' ? data.css : '';
                input.value = resetText;
                savedSnapshot = resetText;
                var prev = document.getElementById('enformasys-live-css-preview');
                if (prev) prev.textContent = '';
            } else {
                savedSnapshot = input.value;
                var prev2 = document.getElementById('enformasys-live-css-preview');
                if (prev2) prev2.textContent = '';
            }
            refreshLiveLink(data.mtime || Date.now());
            setDirtyState(false);
            notify(data.message || 'Live CSS updated.', 'success');
        } catch (err) {
            notify(err && err.message ? err.message : 'Unable to save Live CSS.', 'error');
        } finally {
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = origHtml;
            }
        }
    }

    if (saveBtn && canSave) {
        saveBtn.addEventListener('click', function () {
            persistLiveCss('save_live_css', input.value, saveBtn, 'Saving...');
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (canSave) {
                persistLiveCss('reset_live_css', null, resetBtn, 'Resetting...');
            } else {
                input.value = savedSnapshot;
                var prev = document.getElementById('enformasys-live-css-preview');
                if (prev) prev.textContent = '';
                setDirtyState(false);
                notify('Live CSS preview cleared.', 'info');
            }
        });
    }

    // Auto-open if ?live_css=1 was explicitly requested in the URL
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('live_css') === '1') {
            openPanel();
        }
    } catch (_) {}
})();
</script>
