(() => {
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const shell = document.getElementById('appShell');
  const openBtn = document.getElementById('openSidebar');
  const closeBtn = document.getElementById('closeSidebar');
  const collapseBtn = document.getElementById('toggleSidebarCollapse');

  if (!sidebar || !backdrop || !shell) return;

  const textEls = Array.from(document.querySelectorAll('.sidebar-text'));

  const open = () => {
    sidebar.classList.remove('-translate-x-full');
    backdrop.classList.remove('hidden');
    document.body.classList.add('overflow-hidden', 'lg:overflow-auto');
    if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
    if (closeBtn) closeBtn.focus({ preventScroll: true });
  };

  const close = () => {
    sidebar.classList.add('-translate-x-full');
    backdrop.classList.add('hidden');
    document.body.classList.remove('overflow-hidden', 'lg:overflow-auto');
    if (openBtn) {
      openBtn.setAttribute('aria-expanded', 'false');
    }
  };

  const setCollapsed = (collapsed) => {
    if (collapsed) {
      sidebar.classList.add('lg:w-20');
      sidebar.classList.remove('lg:w-64');
      shell.classList.add('lg:pl-20');
      shell.classList.remove('lg:pl-64');
      textEls.forEach((el) => el.classList.add('hidden'));
      localStorage.setItem('capstone_sidebar_collapsed', '1');
    } else {
      sidebar.classList.add('lg:w-64');
      sidebar.classList.remove('lg:w-20');
      shell.classList.add('lg:pl-64');
      shell.classList.remove('lg:pl-20');
      textEls.forEach((el) => el.classList.remove('hidden'));
      localStorage.setItem('capstone_sidebar_collapsed', '0');
    }
  };

  const collapsedSaved = localStorage.getItem('capstone_sidebar_collapsed');
  if (collapsedSaved === '1' && collapseBtn) {
    setCollapsed(true);
  }

  if (openBtn) openBtn.addEventListener('click', open);
  if (closeBtn) closeBtn.addEventListener('click', close);
  backdrop.addEventListener('click', close);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !sidebar.classList.contains('-translate-x-full') && window.innerWidth < 1024) {
      close();
      if (openBtn) openBtn.focus({ preventScroll: true });
    }
  });

  // Mobile compact search bar toggle (< 1024px)
  const mobileSearchToggle = document.getElementById('mobileSearchToggle');
  const headerSearchWrap = document.getElementById('headerSearchWrap');
  const topbarSearchInput = document.getElementById('topbarSearch');
  if (mobileSearchToggle && headerSearchWrap) {
    mobileSearchToggle.addEventListener('click', () => {
      const isHidden = headerSearchWrap.classList.contains('hidden');
      if (isHidden) {
        headerSearchWrap.classList.remove('hidden');
        headerSearchWrap.classList.add('flex');
        mobileSearchToggle.setAttribute('aria-expanded', 'true');
        if (topbarSearchInput) topbarSearchInput.focus();
      } else {
        headerSearchWrap.classList.add('hidden');
        headerSearchWrap.classList.remove('flex');
        mobileSearchToggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  if (collapseBtn) {
    collapseBtn.addEventListener('click', () => {
      const isCollapsed = sidebar.classList.contains('lg:w-20');
      setCollapsed(!isCollapsed);
    });
  }

  // Sidebar collapsible accordion dropdown menus
  const dropdownToggles = document.querySelectorAll('.sidebar-dropdown-toggle');
  dropdownToggles.forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const isCollapsed = sidebar.classList.contains('lg:w-20');
      if (isCollapsed) {
        // Expand sidebar first if clicking a dropdown while collapsed
        setCollapsed(false);
      }
      
      const group = btn.closest('.sidebar-dropdown-group');
      const menu = group ? group.querySelector('.sidebar-dropdown-menu') : btn.nextElementSibling;
      const chevron = btn.querySelector('.fa-chevron-down');
      const isExpanded = btn.getAttribute('aria-expanded') === 'true';
      
      // Close other dropdowns for clean accordion behavior
      dropdownToggles.forEach((otherBtn) => {
        if (otherBtn !== btn) {
          otherBtn.setAttribute('aria-expanded', 'false');
          const otherGroup = otherBtn.closest('.sidebar-dropdown-group');
          const otherMenu = otherGroup ? otherGroup.querySelector('.sidebar-dropdown-menu') : otherBtn.nextElementSibling;
          const otherChevron = otherBtn.querySelector('.fa-chevron-down');
          if (otherMenu) otherMenu.classList.remove('open');
          if (otherChevron) {
            otherChevron.classList.remove('rotate-180', 'text-[#59443F]');
            otherChevron.classList.add('text-[#8B7168]');
          }
        }
      });
      
      if (isExpanded) {
        btn.setAttribute('aria-expanded', 'false');
        if (menu) menu.classList.remove('open');
        if (chevron) {
          chevron.classList.remove('rotate-180', 'text-[#59443F]');
          chevron.classList.add('text-[#8B7168]');
        }
      } else {
        btn.setAttribute('aria-expanded', 'true');
        if (menu) menu.classList.add('open');
        if (chevron) {
          chevron.classList.add('rotate-180', 'text-[#59443F]');
          chevron.classList.remove('text-[#8B7168]');
        }
      }
    });
  });

  // =========================================================================
  // Zero-Click Filter Auto-Submission
  // Eliminates need to click "Filter" or "Apply" buttons across the application
  // =========================================================================
  document.addEventListener('change', (e) => {
    const el = e.target;
    if (!el) return;
    const isFilterInput = el.matches(
      'form.capstone-filter-bar select, form.filter-form select, form[data-auto-filter="1"] select, select.auto-filter, form.capstone-filter-bar input[type="radio"], form.filter-form input[type="radio"], form[method="get"]:not([data-no-auto="1"]):not(.modal-form) select:not([data-no-auto="1"])'
    );
    if (isFilterInput) {
      const form = el.closest('form');
      if (form) {
        // Subtle indicator that the filter is updating
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
          submitBtn.style.opacity = '0.6';
          submitBtn.disabled = true;
        }
        form.submit();
      }
    }
  });

  // =========================================================================
  // Global Omnibar Spotlight Search (Ctrl+K or Cmd+K)
  // Fast 1-click navigation across all modules
  // =========================================================================
  const omnibarModal = document.getElementById('omnibarModal');
  const omnibarInput = document.getElementById('omnibarInput');
  const openOmnibarBtn = document.getElementById('openOmnibarBtn');
  const closeOmnibarBtn = document.getElementById('closeOmnibarBtn');
  const omnibarResults = document.getElementById('omnibarResults');

  let activeIndex = 0;

  const getVisibleItems = () => {
    if (!omnibarResults) return [];
    return Array.from(omnibarResults.querySelectorAll('.omnibar-item:not([style*="display: none"])'));
  };

  const updateActiveItem = () => {
    const items = getVisibleItems();
    items.forEach((item, idx) => {
      if (idx === activeIndex) {
        item.classList.add('active');
        item.scrollIntoView({ block: 'nearest' });
      } else {
        item.classList.remove('active');
      }
    });
  };

  const openOmnibar = () => {
    if (!omnibarModal || !omnibarInput) return;
    omnibarModal.classList.remove('hidden');
    omnibarInput.value = '';
    filterOmnibar('');
    activeIndex = 0;
    updateActiveItem();
    setTimeout(() => omnibarInput.focus(), 50);
  };

  const closeOmnibar = () => {
    if (!omnibarModal) return;
    omnibarModal.classList.add('hidden');
  };

  const filterOmnibar = (query) => {
    if (!omnibarResults) return;
    const q = query.trim().toLowerCase();
    const items = Array.from(omnibarResults.querySelectorAll('.omnibar-item'));
    items.forEach((item) => {
      const title = (item.getAttribute('data-title') || '').toLowerCase();
      const text = item.textContent.toLowerCase();
      if (!q || title.includes(q) || text.includes(q)) {
        item.style.display = 'flex';
      } else {
        item.style.display = 'none';
      }
    });
    activeIndex = 0;
    updateActiveItem();
  };

  if (openOmnibarBtn) openOmnibarBtn.addEventListener('click', openOmnibar);
  if (closeOmnibarBtn) closeOmnibarBtn.addEventListener('click', closeOmnibar);
  if (omnibarModal) {
    omnibarModal.addEventListener('click', (e) => {
      if (e.target === omnibarModal) closeOmnibar();
    });
  }

  if (omnibarInput) {
    omnibarInput.addEventListener('input', (e) => {
      filterOmnibar(e.target.value);
    });

    omnibarInput.addEventListener('keydown', (e) => {
      const items = getVisibleItems();
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (items.length > 0) {
          activeIndex = (activeIndex + 1) % items.length;
          updateActiveItem();
        }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (items.length > 0) {
          activeIndex = (activeIndex - 1 + items.length) % items.length;
          updateActiveItem();
        }
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (items[activeIndex]) {
          items[activeIndex].click();
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        closeOmnibar();
      }
    });
  }

  // Keyboard shortcut listeners
  window.addEventListener('keydown', (e) => {
    const isCtrlK = (e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k';
    if (isCtrlK) {
      e.preventDefault();
      if (omnibarModal && !omnibarModal.classList.contains('hidden')) {
        closeOmnibar();
      } else {
        openOmnibar();
      }
      return;
    }

    if (e.key === 'Escape') {
      if (omnibarModal && !omnibarModal.classList.contains('hidden')) {
        closeOmnibar();
      }
      return;
    }

    // '/' shortcut to focus search input if not in a form input
    if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
      const inPageSearch = document.querySelector('.search-container input, #searchBox, #quickSearch, .search-box, input[type="search"], input[id*="search" i], input[id*="Search"]');
      if (inPageSearch) {
        e.preventDefault();
        inPageSearch.focus();
        inPageSearch.select();
      }
    }
  });
})();

/**
 * EnformaSys Floating Toast Notification System
 * @param {string} message - Message text to display
 * @param {'success'|'error'|'info'} [type='success'] - Notification state
 * @param {number} [duration=3500] - Duration in ms before auto-dismissal
 */
function showToast(message, type = 'success', duration = 3500) {
  if (!message) return;

  const run = () => {
    if (!document.body) return;

    // Normalize type
    const normalizedType = String(type || 'success').toLowerCase();
    const validTypes = ['success', 'error', 'info'];
    const toastType = validTypes.includes(normalizedType) ? normalizedType : 'info';

    // Get or create container
    let container = document.getElementById('enformasys-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'enformasys-toast-container';
      container.setAttribute('aria-live', 'polite');
      container.setAttribute('aria-atomic', 'true');
      document.body.appendChild(container);
    }

    // Icon depending on state
    let iconClass = 'fa-solid fa-circle-check';
    if (toastType === 'error') {
      iconClass = 'fa-solid fa-triangle-exclamation';
    } else if (toastType === 'info') {
      iconClass = 'fa-solid fa-circle-info';
    }

    // Create toast bubble
    const toast = document.createElement('div');
    toast.className = `toast-bubble toast-bubble-${toastType} toast-entering`;
    toast.setAttribute('role', toastType === 'error' ? 'alert' : 'status');

    // Icon wrap
    const iconWrap = document.createElement('span');
    iconWrap.className = 'toast-icon-wrap';
    iconWrap.setAttribute('aria-hidden', 'true');
    const icon = document.createElement('i');
    icon.className = iconClass;
    iconWrap.appendChild(icon);

    // Message
    const msgEl = document.createElement('span');
    msgEl.className = 'toast-message';
    msgEl.textContent = message;

    // Close button
    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'toast-close-btn';
    closeBtn.setAttribute('aria-label', 'Close notification');
    closeBtn.innerHTML = '&times;';

    toast.appendChild(iconWrap);
    toast.appendChild(msgEl);
    toast.appendChild(closeBtn);

    container.appendChild(toast);

    let dismissTimeout = null;
    let isLeaving = false;

    const dismiss = () => {
      if (isLeaving) return;
      isLeaving = true;
      clearTimeout(dismissTimeout);
      toast.classList.remove('toast-entering');
      toast.classList.add('toast-leaving');
      toast.addEventListener('animationend', () => {
        toast.remove();
      }, { once: true });
      setTimeout(() => {
        if (toast.parentNode) toast.remove();
      }, 350);
    };

    closeBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      dismiss();
    });

    toast.addEventListener('click', () => dismiss());

    const startTimer = (ms) => {
      clearTimeout(dismissTimeout);
      if (ms > 0) {
        dismissTimeout = setTimeout(dismiss, ms);
      }
    };

    startTimer(duration);

    toast.addEventListener('mouseenter', () => clearTimeout(dismissTimeout));
    toast.addEventListener('mouseleave', () => startTimer(1500));
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
}

window.showToast = showToast;
window.showToast.success = (msg, duration) => showToast(msg, 'success', duration);
window.showToast.error = (msg, duration) => showToast(msg, 'error', duration);
window.showToast.info = (msg, duration) => showToast(msg, 'info', duration);

/**
 * EnformaSys GlobalModal Portal & Scroll-Lock Manager
 *
 * Breaks every modal overlay out of nested layout stacking contexts by
 * portaling them directly into document.body (equivalent to ReactDOM.createPortal(..., document.body)),
 * enforces viewport-wide z-[99999] layering above sidebar/header (z-40),
 * and locks document.body.style.overflow while any modal is open.
 */
(function initGlobalModalPortal() {
  const SKIP_IDS = new Set([
    'sidebarBackdrop',
    'mobileDrawerBackdrop',
    'enformasys-toast-container',
    'modal-root',
    'appShell',
    'sidebar'
  ]);

  const observedModals = new WeakSet();
  let attrObserver = null;

  function isOverlayCandidate(el) {
    if (!el || el.nodeType !== 1) return false;
    if (el.id && SKIP_IDS.has(el.id)) return false;
    if (el.hasAttribute('data-portal-skip')) return false;
    // Skip inner dialog panels wrapped inside an outer .modal or overlay backdrop
    if (el.classList.contains('modal-panel')) return false;
    if (el.parentElement && el.parentElement.closest('.modal, .modal-overlay, [data-global-modal]')) {
      return false;
    }

    if (el.hasAttribute('data-global-modal')) return true;
    if (el.classList.contains('modal') || el.classList.contains('modal-overlay')) return true;

    const cls = typeof el.className === 'string' ? el.className : '';
    const hasFixedInset = cls.includes('fixed') && cls.includes('inset-0');
    const inlinePos = (el.style && el.style.position === 'fixed' && (el.style.inset === '0px' || el.style.inset === '0'));
    const isDialog = el.getAttribute('role') === 'dialog' || el.getAttribute('aria-modal') === 'true' || (el.id && /Modal(Global)?$/i.test(el.id));

    return (hasFixedInset || inlinePos) && (isDialog || (el.id && /Modal/i.test(el.id)));
  }

  function isModalOpen(el) {
    if (!el) return false;
    if (el.classList.contains('hidden')) return false;
    if (el.style && el.style.display === 'none') return false;
    if (el.classList.contains('modal')) {
      return el.classList.contains('open');
    }
    if (el.classList.contains('opacity-0') && el.classList.contains('pointer-events-none')) {
      return false;
    }
    const cs = window.getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity || '1') === 0) {
      return false;
    }
    return true;
  }

  function syncBodyScrollLock() {
    if (!document.body) return;
    const modals = Array.from(document.body.querySelectorAll(':scope > [data-portaled="1"]'));
    const anyOpen = modals.some(isModalOpen);
    document.body.style.overflow = anyOpen ? 'hidden' : 'unset';
  }

  function watchModalAttributes(el) {
    if (!attrObserver || observedModals.has(el)) return;
    observedModals.add(el);
    attrObserver.observe(el, {
      attributes: true,
      attributeFilter: ['class', 'style', 'aria-hidden']
    });
  }

  function portalElement(el) {
    if (!document.body || !el) return;

    // Enforce viewport-spanning z-[99999] layer directly on document.body
    el.setAttribute('data-portaled', '1');
    el.style.setProperty('z-index', '99999', 'important');
    if (el.parentNode !== document.body) {
      document.body.appendChild(el);
    }
    watchModalAttributes(el);
  }

  function scanAndPortal(scope) {
    const container = scope || document;
    const candidates = container.querySelectorAll('[data-global-modal], .modal, .modal-overlay, [role="dialog"], [aria-modal="true"], [id*="Modal"]');
    candidates.forEach((el) => {
      if (isOverlayCandidate(el)) {
        portalElement(el);
      }
    });
    syncBodyScrollLock();
  }

  function boot() {
    if (!document.body) return;

    attrObserver = new MutationObserver(() => {
      syncBodyScrollLock();
    });

    scanAndPortal(document);

    // Watch #appShell for any dynamically inserted modals
    const shell = document.getElementById('appShell') || document.body;
    const domObserver = new MutationObserver((mutations) => {
      let foundNew = false;
      mutations.forEach((m) => {
        m.addedNodes.forEach((node) => {
          if (node.nodeType === 1 && !node.hasAttribute('data-portaled') && !SKIP_IDS.has(node.id)) {
            if (isOverlayCandidate(node)) {
              portalElement(node);
              foundNew = true;
            } else if (node.querySelectorAll) {
              scanAndPortal(node);
              foundNew = true;
            }
          }
        });
      });
      if (foundNew) syncBodyScrollLock();
    });
    domObserver.observe(shell, { childList: true, subtree: true });

    window.EnformaSysModalPortal = {
      portal: portalElement,
      scan: scanAndPortal,
      syncScrollLock: syncBodyScrollLock
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

/**
 * EnformaSys Global Accessible Confirmation Modal & Double-Submit Guard
 * Usage:
 *   const ok = await window.enformasysConfirm({
 *     title: 'Confirm Action',
 *     message: 'Are you sure?',
 *     confirmText: 'Confirm',
 *     cancelText: 'Cancel',
 *     variant: 'primary' | 'warning' | 'danger'
 *   });
 */
(function initEnformaSysConfirmSystem() {
  let modalRoot = null;
  let activeResolve = null;
  let lastFocusedEl = null;

  const VARIANT_STYLES = {
    primary: {
      iconBg: 'bg-[#F8EEEE] text-[#59443F] border-[#E8C7C5]',
      iconStyle: 'background:#F8EEEE;color:#59443F;border-color:#E8C7C5;',
      iconClass: 'fa-solid fa-circle-check',
      btnClass: 'enformasys-confirm-btn enformasys-confirm-btn-primary bg-[#59443F] hover:bg-[#463430] text-white focus:ring-[#D6A6A6]',
      defaultTitle: 'Confirm Changes',
      defaultConfirm: 'Confirm'
    },
    warning: {
      iconBg: 'bg-amber-50 text-amber-600 border-amber-200',
      iconStyle: 'background:#FFFBEB;color:#D97706;border-color:#FDE68A;',
      iconClass: 'fa-solid fa-triangle-exclamation',
      btnClass: 'enformasys-confirm-btn enformasys-confirm-btn-warning bg-amber-600 hover:bg-amber-700 text-white focus:ring-amber-300',
      defaultTitle: 'Please Confirm',
      defaultConfirm: 'Proceed'
    },
    danger: {
      iconBg: 'bg-rose-50 text-rose-600 border-rose-200',
      iconStyle: 'background:#FEF2F2;color:#DC2626;border-color:#FECACA;',
      iconClass: 'fa-solid fa-triangle-exclamation',
      btnClass: 'enformasys-confirm-btn enformasys-confirm-btn-danger bg-rose-600 hover:bg-rose-700 text-white focus:ring-rose-300',
      defaultTitle: 'Confirm Deletion',
      defaultConfirm: 'Delete'
    }
  };

  function ensureModal() {
    if (modalRoot && document.body.contains(modalRoot)) return modalRoot;
    modalRoot = document.getElementById('enformasysConfirmModal');
    if (modalRoot) return modalRoot;

    modalRoot = document.createElement('div');
    modalRoot.id = 'enformasysConfirmModal';
    modalRoot.className = 'enformasys-confirm-overlay hidden';
    modalRoot.setAttribute('role', 'alertdialog');
    modalRoot.setAttribute('aria-modal', 'true');
    modalRoot.setAttribute('aria-hidden', 'true');
    modalRoot.setAttribute('aria-labelledby', 'enformasysConfirmTitle');
    modalRoot.setAttribute('aria-describedby', 'enformasysConfirmMessage');
    modalRoot.setAttribute('data-portal-skip', '1');

    modalRoot.innerHTML = `
      <div class="enformasys-confirm-dialog p-5 sm:p-6">
        <div class="enformasys-confirm-body flex items-start gap-3.5">
          <div id="enformasysConfirmIconWrap" class="enformasys-confirm-icon w-11 h-11 rounded-2xl border flex items-center justify-center shrink-0 text-base shadow-2xs">
            <i id="enformasysConfirmIcon" class="fa-solid fa-circle-check" aria-hidden="true"></i>
          </div>
          <div class="flex-1 min-w-0">
            <h3 id="enformasysConfirmTitle" class="enformasys-confirm-title font-heading font-extrabold text-base text-[#59443F] leading-snug m-0"></h3>
            <p id="enformasysConfirmMessage" class="enformasys-confirm-message text-xs sm:text-sm text-[#8B7168] font-medium mt-1.5 leading-relaxed whitespace-pre-line m-0"></p>
          </div>
        </div>
        <div class="enformasys-confirm-actions mt-5 pt-4 border-t border-[#E8C7C5]/40 flex flex-col-reverse sm:flex-row sm:justify-end gap-2.5">
          <button type="button" id="enformasysConfirmCancelBtn" class="enformasys-confirm-btn enformasys-confirm-btn-cancel w-full sm:w-auto px-4 py-2 rounded-xl text-xs font-bold text-[#59443F] bg-[#F8EEEE] hover:bg-[#E8C7C5]/70 border border-[#E8C7C5] transition cursor-pointer">
            Cancel
          </button>
          <button type="button" id="enformasysConfirmOkBtn" class="enformasys-confirm-btn enformasys-confirm-btn-primary w-full sm:w-auto px-4 py-2 rounded-xl text-xs font-bold transition shadow-xs focus:outline-none focus:ring-2 cursor-pointer inline-flex items-center justify-center gap-2">
            Confirm
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(modalRoot);

    const cancelBtn = modalRoot.querySelector('#enformasysConfirmCancelBtn');
    const okBtn = modalRoot.querySelector('#enformasysConfirmOkBtn');

    cancelBtn.addEventListener('click', () => closeConfirm(false));
    okBtn.addEventListener('click', () => closeConfirm(true));
    modalRoot.addEventListener('click', (e) => {
      if (e.target === modalRoot) closeConfirm(false);
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && modalRoot && !modalRoot.classList.contains('hidden')) {
        e.preventDefault();
        closeConfirm(false);
      }
    });

    return modalRoot;
  }

  function closeConfirm(result) {
    if (!modalRoot || modalRoot.classList.contains('hidden')) return;
    modalRoot.classList.add('hidden');
    modalRoot.setAttribute('aria-hidden', 'true');
    if (window.EnformaSysModalPortal && typeof window.EnformaSysModalPortal.syncScrollLock === 'function') {
      window.EnformaSysModalPortal.syncScrollLock();
    } else {
      document.body.style.overflow = '';
    }

    const resolve = activeResolve;
    activeResolve = null;
    if (!result && lastFocusedEl && typeof lastFocusedEl.focus === 'function') {
      try { lastFocusedEl.focus(); } catch (_) {}
    }
    lastFocusedEl = null;
    if (typeof resolve === 'function') {
      resolve(Boolean(result));
    }
  }

  function inferVariant(message, explicitVariant) {
    if (explicitVariant && VARIANT_STYLES[explicitVariant]) return explicitVariant;
    const msg = String(message || '').toLowerCase();
    if (/sign out|log out|logout|deactivate/.test(msg)) return 'warning';
    if (/delete|remove|archive|reject|deny|drop|unenroll/.test(msg)) return 'danger';
    return 'primary';
  }

  function enformasysConfirm(options) {
    const opts = typeof options === 'string' ? { message: options } : (options || {});
    const variantKey = inferVariant(opts.message, opts.variant);
    const cfg = VARIANT_STYLES[variantKey] || VARIANT_STYLES.primary;

    if (!document.body) {
      return Promise.resolve(window.confirm(opts.message || 'Are you sure?'));
    }

    // Cancel any stale pending modal cleanly
    if (activeResolve) {
      closeConfirm(false);
    }

    const root = ensureModal();
    const iconWrap = root.querySelector('#enformasysConfirmIconWrap');
    const iconEl = root.querySelector('#enformasysConfirmIcon');
    const titleEl = root.querySelector('#enformasysConfirmTitle');
    const msgEl = root.querySelector('#enformasysConfirmMessage');
    const cancelBtn = root.querySelector('#enformasysConfirmCancelBtn');
    const okBtn = root.querySelector('#enformasysConfirmOkBtn');

    lastFocusedEl = document.activeElement;
    iconWrap.className = `enformasys-confirm-icon w-11 h-11 rounded-2xl border flex items-center justify-center shrink-0 text-base shadow-2xs ${cfg.iconBg}`;
    iconWrap.setAttribute('style', cfg.iconStyle);
    iconEl.className = opts.iconClass || (variantKey === 'warning' && /sign out|logout/i.test(String(opts.title || opts.message || '')) ? 'fa-solid fa-arrow-right-from-bracket' : cfg.iconClass);
    titleEl.textContent = opts.title || cfg.defaultTitle;
    msgEl.textContent = opts.message || 'Are you sure you want to proceed?';
    cancelBtn.textContent = opts.cancelText || 'Cancel';
    okBtn.textContent = opts.confirmText || cfg.defaultConfirm;
    okBtn.disabled = false;
    cancelBtn.disabled = false;
    okBtn.className = `w-full sm:w-auto px-4 py-2 rounded-xl text-xs font-bold transition shadow-xs focus:outline-none focus:ring-2 cursor-pointer inline-flex items-center justify-center gap-2 ${cfg.btnClass}`;

    root.classList.remove('hidden');
    root.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    setTimeout(() => {
      if (variantKey === 'danger' || variantKey === 'warning') {
        cancelBtn.focus();
      } else {
        okBtn.focus();
      }
    }, 20);

    return new Promise((resolve) => {
      activeResolve = resolve;
    });
  }

  function setButtonLoading(btn, label) {
    if (!btn || btn.dataset.enformasysLoading === '1') return;
    btn.dataset.enformasysLoading = '1';
    const text = label || btn.getAttribute('data-confirm-loading') || 'Saving...';
    if (btn.tagName === 'INPUT') {
      btn.value = text;
    } else {
      btn.innerHTML = `<span class="enformasys-btn-spinner"><i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i><span>${text}</span></span>`;
    }
    btn.disabled = true;
    btn.classList.add('opacity-75', 'cursor-wait');
  }

  function preserveSubmitterField(form, submitter) {
    if (!form || !submitter || !submitter.name) return;
    const existing = form.querySelector(`input[data-enformasys-submitter="1"]`);
    if (existing) existing.remove();
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = submitter.name;
    hidden.value = submitter.value || '1';
    hidden.setAttribute('data-enformasys-submitter', '1');
    form.appendChild(hidden);
  }

  function submitFormWithState(form, submitter, loadingText) {
    if (!form || form.dataset.enformasysSubmitting === '1') return;
    form.dataset.enformasysConfirmed = '1';
    form.dataset.enformasysSubmitting = '1';
    const activeBtn = submitter || form.querySelector('button[type="submit"]:not(:disabled), button:not([type]):not(:disabled), input[type="submit"]:not(:disabled)');
    if (activeBtn) {
      preserveSubmitterField(form, activeBtn);
      setButtonLoading(activeBtn, loadingText);
    }
    HTMLFormElement.prototype.submit.call(form);
  }

  function extractConfirmOptions(el, fallbackOpts) {
    const msg = el.getAttribute('data-confirm') || (fallbackOpts && fallbackOpts.message) || 'Are you sure you want to proceed?';
    return {
      message: msg,
      title: el.getAttribute('data-confirm-title') || (fallbackOpts && fallbackOpts.title) || undefined,
      confirmText: el.getAttribute('data-confirm-text') || (fallbackOpts && fallbackOpts.confirmText) || undefined,
      cancelText: el.getAttribute('data-confirm-cancel') || 'Cancel',
      variant: el.getAttribute('data-confirm-variant') || (fallbackOpts && fallbackOpts.variant) || undefined,
      loadingText: el.getAttribute('data-confirm-loading') || (fallbackOpts && fallbackOpts.loadingText) || 'Saving...'
    };
  }

  // Capture-phase click interception for logout links, data-confirm links, and data-confirm submit buttons
  document.addEventListener('click', async (e) => {
    const rawTarget = e.target;
    const target = rawTarget && rawTarget.nodeType === 3 ? rawTarget.parentElement : rawTarget;
    if (!target || !target.closest) return;

    // 1. Intercept Logout Links & Buttons globally (supports href="logout.php", "../logout.php", "/logout.php")
    const logoutEl = target.closest('a[href*="logout.php"]:not([data-no-confirm="1"]), button[data-href*="logout.php"]:not([data-no-confirm="1"]), [data-logout-trigger="1"]');
    if (logoutEl) {
      e.preventDefault();
      e.stopPropagation();
      const opts = logoutEl.hasAttribute('data-confirm')
        ? extractConfirmOptions(logoutEl)
        : {
            title: 'Sign Out',
            message: 'Sign out of EnformaSys? Any unsaved changes will be lost.',
            confirmText: 'Sign Out',
            variant: 'warning',
            loadingText: 'Signing out...'
          };
      const confirmed = await enformasysConfirm(opts);
      if (confirmed) {
        const dest = logoutEl.getAttribute('href') || logoutEl.getAttribute('data-href') || '/logout.php';
        window.location.href = dest;
      }
      return;
    }

    // 2. Intercept declarative data-confirm links
    const confirmLink = target.closest('a[data-confirm]');
    if (confirmLink) {
      e.preventDefault();
      e.stopPropagation();
      const opts = extractConfirmOptions(confirmLink);
      const confirmed = await enformasysConfirm(opts);
      if (confirmed) {
        const href = confirmLink.getAttribute('href');
        if (href) window.location.href = href;
      }
      return;
    }

    // 3. Intercept declarative data-confirm submit buttons
    const confirmBtn = target.closest('button[data-confirm]:not([type="button"]):not([type="reset"]), input[type="submit"][data-confirm]');
    if (confirmBtn && !confirmBtn.disabled) {
      const form = confirmBtn.form || confirmBtn.closest('form');
      if (!form) return;
      if (form.dataset.enformasysConfirmed === '1') return;

      e.preventDefault();
      e.stopPropagation();
      if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
        return;
      }
      const opts = extractConfirmOptions(confirmBtn);
      const confirmed = await enformasysConfirm(opts);
      if (confirmed) {
        submitFormWithState(form, confirmBtn, opts.loadingText);
      }
    }
  }, true);

  // Capture-phase form[data-confirm] interception
  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (!form.hasAttribute('data-confirm')) return;
    if (form.dataset.enformasysConfirmed === '1') return;

    e.preventDefault();
    e.stopImmediatePropagation();
    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
      return;
    }
    const opts = extractConfirmOptions(form);
    const submitter = e.submitter || form.querySelector('button[type="submit"]:not(:disabled), input[type="submit"]:not(:disabled)');
    const confirmed = await enformasysConfirm(opts);
    if (confirmed) {
      submitFormWithState(form, submitter, opts.loadingText);
    }
  }, true);

  // Bubble-phase double-submit guard for regular POST forms
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!form || form.tagName !== 'FORM' || e.defaultPrevented) return;
    if (String(form.method || '').toLowerCase() !== 'post') return;
    if (form.hasAttribute('data-no-loading') || form.target === '_blank') return;

    if (form.dataset.enformasysSubmitting === '1') {
      e.preventDefault();
      return;
    }
    form.dataset.enformasysSubmitting = '1';
    const submitter = e.submitter || form.querySelector('button[type="submit"]:not(:disabled), input[type="submit"]:not(:disabled)');
    if (submitter) {
      preserveSubmitterField(form, submitter);
      const loadingLabel = submitter.getAttribute('data-confirm-loading') || form.getAttribute('data-confirm-loading') || 'Saving...';
      setButtonLoading(submitter, loadingLabel);
    }
  }, false);

  window.enformasysConfirm = enformasysConfirm;
  window.enformasysSetButtonLoading = setButtonLoading;
})();



