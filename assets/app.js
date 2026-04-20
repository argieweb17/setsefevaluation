import './stimulus_bootstrap.js';
/*
 * Quamc SET - SEF Evaluation — main JS entry point
 * Loaded via importmap (ES module = executes exactly once).
 */
import './styles/app.css';

const SIDEBAR_COLLAPSE_STORAGE_KEY = 'quamc.sidebarCollapsed';

function setSidebarWidthVars(isCollapsed) {
    const width = isCollapsed ? '88px' : '260px';
    document.documentElement.style.setProperty('--sidebar-current-width', width);
    document.documentElement.style.setProperty('--sidebar-width', width);
}

function readStoredSidebarCollapse() {
    try {
        return window.localStorage.getItem(SIDEBAR_COLLAPSE_STORAGE_KEY) === '1';
    } catch (error) {
        return false;
    }
}

function writeStoredSidebarCollapse(isCollapsed) {
    try {
        window.localStorage.setItem(SIDEBAR_COLLAPSE_STORAGE_KEY, isCollapsed ? '1' : '0');
    } catch (error) {
        // Ignore storage write failures and keep the in-memory state only.
    }
}

function syncSidebarDesktopToggle() {
    const toggle = document.getElementById('sidebarCollapseToggle');
    const icon = document.getElementById('sidebarCollapseToggleIcon');
    const isCollapsed = document.documentElement.classList.contains('sidebar-collapsed');
    if (!toggle) return;

    const label = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
    toggle.setAttribute('aria-label', label);
    toggle.setAttribute('title', label);
    toggle.setAttribute('aria-pressed', String(isCollapsed));

    if (!icon) return;
    icon.classList.toggle('bi-chevron-bar-left', !isCollapsed);
    icon.classList.toggle('bi-chevron-bar-right', isCollapsed);
}

function applySidebarCollapse(isCollapsed) {
    document.documentElement.classList.toggle('sidebar-collapsed', isCollapsed);
    setSidebarWidthVars(isCollapsed);
    writeStoredSidebarCollapse(isCollapsed);
    closeSidebar();
    syncSidebarDesktopToggle();
}

function initializeSidebarCollapse() {
    const isCollapsed = readStoredSidebarCollapse();
    document.documentElement.classList.toggle('sidebar-collapsed', isCollapsed);
    setSidebarWidthVars(isCollapsed);
    syncSidebarDesktopToggle();
}

/* ═══════════════════════════════════════════════════════════════
   SIDEBAR HELPERS
   ═══════════════════════════════════════════════════════════════ */
function closeSidebar() {
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    if (sb) sb.classList.remove('show');
    if (bd) bd.classList.remove('show');
}

function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    if (sb) sb.classList.toggle('show');
    if (bd) bd.classList.toggle('show');
}

/* Toggle sidebar dropdown sub-menu */
window.toggleSidebarDropdown = function(btn) {
    const dropdown = btn.closest('.sidebar-dropdown');
    if (!dropdown) return;
    const menu = dropdown.querySelector('.sidebar-dropdown-menu');
    if (!menu) return;
    const isOpen = dropdown.classList.contains('open');
    if (isOpen) {
        menu.style.display = 'none';
        dropdown.classList.remove('open');
    } else {
        menu.style.display = '';
        dropdown.classList.add('open');
    }
};

/* Update which sidebar link gets the .active highlight */
function updateActiveLink() {
    const currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        const href = link.getAttribute('href');
        if (!href) return;
        const isActive = href === currentPath
            || (href !== '/' && currentPath.startsWith(href));
        link.classList.toggle('active', isActive);
    });
}

function parseCountValue(rawValue) {
    const numeric = String(rawValue || '').replace(/[^\d-]/g, '');
    const parsed = Number.parseInt(numeric, 10);
    return Number.isNaN(parsed) ? 0 : parsed;
}

function formatCountValue(value) {
    return Math.max(0, Math.floor(value)).toLocaleString('en-US');
}

function finalizeStatCounters() {
    document.querySelectorAll('.fes-stat-number').forEach((el) => {
        const target = parseCountValue(el.dataset.countTarget || el.textContent);
        el.dataset.countTarget = String(target);
        el.textContent = formatCountValue(target);
    });
}

function animateStatCounters() {
    const counters = document.querySelectorAll('.fes-stat-number');
    if (!counters.length) return;

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) {
        finalizeStatCounters();
        return;
    }

    counters.forEach((el, index) => {
        const target = parseCountValue(el.dataset.countTarget || el.textContent);
        el.dataset.countTarget = String(target);

        if (target <= 0) {
            el.textContent = '0';
            return;
        }

        const duration = 900;
        const delay = Math.min(index * 80, 320);
        let startTime;

        el.textContent = '0';

        const step = (timestamp) => {
            if (startTime === undefined) startTime = timestamp;
            const elapsed = timestamp - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(target * eased);
            el.textContent = formatCountValue(current);

            if (progress < 1) {
                window.requestAnimationFrame(step);
            } else {
                el.textContent = formatCountValue(target);
            }
        };

        window.setTimeout(() => window.requestAnimationFrame(step), delay);
    });
}

function syncHomeHeroPanel() {
    const heroPanels = document.querySelectorAll('[data-hero-view]');
    if (!heroPanels.length) return;

    const params = new URLSearchParams(window.location.search);
    const requestedView = params.get('view');
    const allowedViews = new Set(['home', 'about', 'faq', 'contact']);
    const activeView = window.location.hash === '#about'
        ? 'about'
        : (allowedViews.has(String(requestedView)) ? String(requestedView) : 'home');
    heroPanels.forEach((panel) => {
        panel.hidden = panel.dataset.heroView !== activeView;
    });
}

window.addEventListener('hashchange', syncHomeHeroPanel);
window.addEventListener('popstate', syncHomeHeroPanel);

/* ═══════════════════════════════════════════════════════════════
   EVENT DELEGATION (click) — survives every Turbo body swap
   ═══════════════════════════════════════════════════════════════ */
document.addEventListener('click', (e) => {
    const heroNav = e.target.closest('.fes-nav-link[data-fes-hero-nav]');
    if (heroNav) {
        const targetUrl = new URL(heroNav.href, window.location.origin);
        if (targetUrl.pathname === window.location.pathname) {
            e.preventDefault();
            const targetView = heroNav.dataset.fesHeroNav;
            const nextUrl = targetView === 'about'
                ? `${window.location.pathname}${window.location.search}#about`
                : `${window.location.pathname}${window.location.search}`;
            window.history.pushState({}, '', nextUrl);
            syncHomeHeroPanel();
            return;
        }
    }

    /* Sidebar toggle button */
    if (e.target.closest('#sidebarToggle')) {
        toggleSidebar();
        return;
    }
    if (e.target.closest('#sidebarCollapseToggle')) {
        applySidebarCollapse(!document.documentElement.classList.contains('sidebar-collapsed'));
        return;
    }
    /* Backdrop click → close */
    if (e.target.id === 'sidebarBackdrop') {
        closeSidebar();
        return;
    }
    /* Sidebar nav-link → close sidebar immediately (Turbo handles navigation) */
    if (e.target.closest('.sidebar .nav-link') && !e.target.closest('.sidebar-dropdown-toggle')) {
        closeSidebar();
    }
    /* Guest navbar-collapse → close after link click on mobile */
    if (e.target.closest('#navbarNav a')) {
        const navCollapse = document.getElementById('navbarNav');
        if (navCollapse && navCollapse.classList.contains('show')) {
            const bsCollapse = bootstrap.Collapse.getInstance(navCollapse);
            if (bsCollapse) bsCollapse.hide();
        }
    }
});

/* ═══════════════════════════════════════════════════════════════
   TURBO LIFECYCLE HOOKS
   ═══════════════════════════════════════════════════════════════ */

/*
 * turbo:before-cache — runs RIGHT BEFORE Turbo snapshots the page.
 * Clean up transient UI state so the cached copy is pristine.
 * This prevents the "open sidebar / stale content" snapshot bug.
 */
document.addEventListener('turbo:before-cache', () => {
    closeSidebar();
    /* Collapse any open Bootstrap navbar (guest view) */
    document.querySelectorAll('.navbar-collapse.show').forEach(el => {
        el.classList.remove('show');
    });
    /* Dispose and clean up Bootstrap modals */
    document.querySelectorAll('.modal').forEach(el => {
        const inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.dispose();
        el.classList.remove('show');
        el.removeAttribute('style');
        el.removeAttribute('aria-modal');
        el.removeAttribute('role');
        el.setAttribute('aria-hidden', 'true');
    });
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    /* Strip auth-animate so cached snapshot won't replay entrance animations */
    document.querySelectorAll('.auth-animate').forEach(el => el.classList.remove('auth-animate'));
    /* Strip sb-animate so subject page animations don't replay from cache */
    document.querySelectorAll('.sb-animate').forEach(el => el.classList.remove('sb-animate'));
    /* Close account dropdown menus before caching */
    document.querySelectorAll('.fes-account-dropdown[open]').forEach(el => el.removeAttribute('open'));
    /* Ensure stat counters are cached with final values */
    finalizeStatCounters();
    /* Reset main-content opacity so cached snapshot is clean */
    const mc = document.querySelector('.main-content');
    if (mc) { mc.style.opacity = '0'; mc.style.animation = 'none'; }
});

/*
 * turbo:before-visit — runs when the user clicks a Turbo-enabled link.
 * Fade out content for smooth transition.
 */
document.addEventListener('turbo:before-visit', () => {
    closeSidebar();
    const mc = document.querySelector('.main-content');
    if (mc) {
        
        mc.style.opacity = '0';
    }
});

/*
 * turbo:load — runs after EVERY successful page render
 *   (initial load + every Turbo visit + back/forward cache restore).
 * This is the single hook that guarantees fresh state.
 */
document.addEventListener('turbo:load', () => {
    initializeSidebarCollapse();

    /* 1. Update sidebar active highlighting */
    updateActiveLink();

    /* 2. Ensure sidebar is closed (safety net for cached snapshots) */
    closeSidebar();

    /* 3. Fade in main content smoothly */
    const mc = document.querySelector('.main-content');
    if (mc) {
        mc.style.transition = '';
        mc.style.animation = '';
        mc.style.opacity = '';
    }

    /* 4. Add auth-animate to trigger entrance animations on fresh render */
    document.querySelectorAll('.auth-page').forEach(el => el.classList.add('auth-animate'));

    /* 5. Re-initialise any Bootstrap tooltips / popovers on new DOM */
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        if (typeof bootstrap !== 'undefined') new bootstrap.Tooltip(el);
    });
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
        if (typeof bootstrap !== 'undefined') new bootstrap.Popover(el);
    });

    /* 6. Move modals to <body> so they escape .main-content stacking context */
    document.querySelectorAll('.main-content .modal').forEach(el => {
        document.body.appendChild(el);
    });

    /* 7. Animate welcome page stat counters */
    animateStatCounters();

    /* 8. Toggle home/about content inside hero area based on URL hash */
    syncHomeHeroPanel();

});

initializeSidebarCollapse();

/*
 * turbo:before-render — runs just before Turbo swaps in the new <body>.
 * Dispose Bootstrap components attached to the OLD body so they don't leak.
 */
document.addEventListener('turbo:before-render', () => {
    /* Dispose Bootstrap modals on old body */
    document.querySelectorAll('.modal').forEach(el => {
        const inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.dispose();
    });
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        const tip = bootstrap.Tooltip.getInstance(el);
        if (tip) tip.dispose();
    });
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => {
        const pop = bootstrap.Popover.getInstance(el);
        if (pop) pop.dispose();
    });
});
