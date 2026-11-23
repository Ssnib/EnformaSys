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
  };

  const close = () => {
    sidebar.classList.add('-translate-x-full');
    backdrop.classList.add('hidden');
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
            otherChevron.classList.remove('rotate-180', 'text-amber-400');
            otherChevron.classList.add('text-slate-400');
          }
        }
      });
      
      if (isExpanded) {
        btn.setAttribute('aria-expanded', 'false');
        if (menu) menu.classList.remove('open');
        if (chevron) {
          chevron.classList.remove('rotate-180', 'text-amber-400');
          chevron.classList.add('text-slate-400');
        }
      } else {
        btn.setAttribute('aria-expanded', 'true');
        if (menu) menu.classList.add('open');
        if (chevron) {
          chevron.classList.add('rotate-180', 'text-amber-400');
          chevron.classList.remove('text-slate-400');
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
 * @param {number} [duration=3000] - Duration in ms before auto-dismissal
 */
function showToast(message, type = 'success', duration = 3000) {
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



