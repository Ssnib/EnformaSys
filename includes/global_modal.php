<?php
declare(strict_types=1);

/**
 * EnformaSys GlobalModal Helper
 *
 * Provides standardized portal-ready backdrop and panel wrappers that break out
 * of any parent stacking context via #modal-root in app_layout.js, enforce
 * viewport-wide z-[9999] overlays, and lock background body scroll while open.
 */

if (!function_exists('global_modal_overlay_classes')) {
    function global_modal_overlay_classes(string $extra = ''): string
    {
        $base = 'fixed inset-0 z-[99999] !z-[99999] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 sm:p-6';
        return trim($base . ($extra !== '' ? ' ' . $extra : ''));
    }
}

if (!function_exists('global_modal_panel_classes')) {
    function global_modal_panel_classes(string $maxWidth = 'max-w-2xl', string $extra = ''): string
    {
        $base = 'max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-2xl w-full ' . trim($maxWidth);
        return trim($base . ($extra !== '' ? ' ' . $extra : ''));
    }
}

if (!function_exists('global_modal_start')) {
    /**
     * Opens a reusable GlobalModal portal wrapper.
     *
     * @param string $id Modal DOM id
     * @param array{
     *   overlayClass?: string,
     *   panelClass?: string,
     *   maxWidth?: string,
     *   hidden?: bool,
     *   ariaLabelledBy?: string,
     *   onBackdropClick?: string
     * } $options
     */
    function global_modal_start(string $id, array $options = []): void
    {
        $isHidden = $options['hidden'] ?? true;
        $overlayExtra = trim(($isHidden ? 'hidden ' : '') . ($options['overlayClass'] ?? ''));
        $maxWidth = (string)($options['maxWidth'] ?? 'max-w-2xl');
        $panelClass = global_modal_panel_classes($maxWidth, (string)($options['panelClass'] ?? ''));
        $ariaLabelledBy = trim((string)($options['ariaLabelledBy'] ?? ''));
        $onBackdrop = trim((string)($options['onBackdropClick'] ?? ''));
        ?>
<div id="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
     data-global-modal="1"
     class="<?php echo htmlspecialchars(global_modal_overlay_classes($overlayExtra), ENT_QUOTES, 'UTF-8'); ?>"
     <?php if ($onBackdrop !== '') { ?>onclick="<?php echo htmlspecialchars($onBackdrop, ENT_QUOTES, 'UTF-8'); ?>"<?php } ?>
     role="dialog"
     aria-modal="true"
     <?php if ($ariaLabelledBy !== '') { ?>aria-labelledby="<?php echo htmlspecialchars($ariaLabelledBy, ENT_QUOTES, 'UTF-8'); ?>"<?php } ?>
     <?php if ($isHidden) { ?>aria-hidden="true"<?php } ?>>
    <div class="<?php echo htmlspecialchars($panelClass, ENT_QUOTES, 'UTF-8'); ?>" onclick="event.stopPropagation()">
<?php
    }
}

if (!function_exists('global_modal_end')) {
    function global_modal_end(): void
    {
        ?>
    </div>
</div>
<?php
    }
}
