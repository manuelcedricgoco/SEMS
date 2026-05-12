/**
 * SEMS Admin — admin_aprovals.js
 * Handles: Dark Mode, Sidebar, Card Rendering, Modals, Fetch/Approve/Reject
 *
 * Requires SEMS_APPROVALS_DATA to be defined inline before this script loads:
 *   <script>
 *     const SEMS_APPROVALS_DATA = {
 *         pendingEvents: [...],   // JSON-encoded $pendingEvents array
 *     };
 *   </script>
 *
 * BUG FIX: confirm-btn was left disabled=true after a successful approval/rejection.
 * The success path never re-enabled it, so every subsequent modal open had a dead button.
 * Fixed in two places:
 *   1. openConfirmModal()  — force btn.disabled = false every time the modal opens.
 *   2. executeDecision()   — explicitly reset btn on the success path before re-render.
 */

// ═══════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════

let pendingData          = (typeof SEMS_APPROVALS_DATA !== 'undefined') ? SEMS_APPROVALS_DATA.pendingEvents : [];
let currentViewId        = null;
let currentConfirmId     = null;
let currentConfirmAction = null;

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════

function toggleTheme() {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sems-theme', isDark ? 'dark' : 'light');
    _applyThemeUI(isDark);
}

function _applyThemeUI(isDark) {
    const icon  = document.getElementById('theme-icon');
    const label = document.getElementById('theme-label');
    if (isDark) {
        icon.className    = 'fas fa-sun w-5 text-center text-amber-500';
        label.textContent = 'Light Mode';
    } else {
        icon.className    = 'fas fa-moon w-5 text-center';
        label.textContent = 'Dark Mode';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const saved = localStorage.getItem('sems-theme') || 'light';
    _applyThemeUI(saved === 'dark');
});

// ═══════════════════════════════════════════════════════════════
// SIDEBAR
// ═══════════════════════════════════════════════════════════════

function openSidebar() {
    document.getElementById('sidebar').classList.remove('-translate-x-full');
    const ov = document.getElementById('overlay');
    ov.classList.remove('pointer-events-none', 'opacity-0');
    ov.classList.add('pointer-events-auto', 'opacity-100');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    document.getElementById('sidebar').classList.add('-translate-x-full');
    const ov = document.getElementById('overlay');
    ov.classList.remove('pointer-events-auto', 'opacity-100');
    ov.classList.add('pointer-events-none', 'opacity-0');
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#sidebar a').forEach(function (el) {
        el.addEventListener('click', function () {
            if (window.innerWidth < 1024) closeSidebar();
        });
    });
});

// ═══════════════════════════════════════════════════════════════
// XSS ESCAPE HELPER
// ═══════════════════════════════════════════════════════════════

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ═══════════════════════════════════════════════════════════════
// CARD RENDERING
// ═══════════════════════════════════════════════════════════════

function render() {
    const container    = document.getElementById('approvals-container');
    const countLabel   = document.getElementById('count-label');
    const emptyState   = document.getElementById('empty-state');
    const sidebarBadge = document.getElementById('sidebar-badge');
    const count        = pendingData.length;

    countLabel.innerText = count === 0
        ? 'No events awaiting approval'
        : `${count} event${count !== 1 ? 's' : ''} awaiting your review`;

    if (sidebarBadge) {
        sidebarBadge.innerText     = count;
        sidebarBadge.style.display = count > 0 ? 'inline-block' : 'none';
    }

    if (count === 0) {
        container.classList.add('hidden');
        emptyState.classList.remove('hidden');
        return;
    }

    container.classList.remove('hidden');
    emptyState.classList.add('hidden');

    container.innerHTML = pendingData.map(function (e, index) {
        const title = escapeHtml(e.title);
        const org   = escapeHtml(e.org);
        const date  = escapeHtml(e.start_date);
        const time  = escapeHtml(e.start_time) + ' – ' + escapeHtml(e.end_time);

        return `
        <div id="card-${e.id}" class="approval-card animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 h-full" style="animation-delay: ${index * 0.08}s">

            <div class="flex items-start justify-between gap-3 mb-4">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-calendar-alt text-amber-500 dark:text-amber-400 text-lg"></i>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-base font-bold text-slate-900 dark:text-white truncate" title="${title}">${title}</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-1">
                            <i class="fas fa-building text-[10px]"></i>
                            <span class="truncate">${org}</span>
                        </p>
                    </div>
                </div>
                <span class="flex-shrink-0 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400">Pending</span>
            </div>

            <div class="flex items-center gap-4 mb-4 text-xs text-slate-500 dark:text-slate-400">
                <span class="flex items-center gap-1"><i class="fas fa-calendar text-[10px] text-blue-400"></i> ${date}</span>
                <span class="flex items-center gap-1"><i class="fas fa-clock text-[10px] text-purple-400"></i> ${time}</span>
            </div>

            <div class="grid grid-cols-3 gap-2 mt-auto">
                <button onclick="openViewModal(${e.id})"
                        class="btn-action px-3 py-2.5 bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 rounded-xl font-medium text-xs flex items-center justify-center gap-1.5 transition-colors">
                    <i class="fas fa-eye text-[10px]"></i> Details
                </button>
                <button onclick="openConfirmModal(${e.id}, 'approved')"
                        class="btn-action px-3 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl font-semibold text-xs flex items-center justify-center gap-1.5 shadow-lg shadow-emerald-500/30">
                    <i class="fas fa-check text-[10px]"></i> Approve
                </button>
                <button onclick="openConfirmModal(${e.id}, 'rejected')"
                        class="btn-action px-3 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl font-semibold text-xs flex items-center justify-center gap-1.5 shadow-lg shadow-rose-500/30">
                    <i class="fas fa-times text-[10px]"></i> Reject
                </button>
            </div>
        </div>
        `;
    }).join('');

    // Re-apply active search filter if any
    const q = document.getElementById('search-input')?.value || '';
    if (q) filterCards(q);
}

// ═══════════════════════════════════════════════════════════════
// SEARCH / FILTER
// ═══════════════════════════════════════════════════════════════

function filterCards(query) {
    const q = query.toLowerCase().trim();
    document.querySelectorAll('#approvals-container > div[id^="card-"]').forEach(function (card) {
        const title = card.querySelector('h3')?.textContent.toLowerCase() || '';
        const org   = card.querySelector('p.text-xs span')?.textContent.toLowerCase() || '';
        card.style.display = (!q || title.includes(q) || org.includes(q)) ? '' : 'none';
    });
}

// ═══════════════════════════════════════════════════════════════
// MODAL HELPERS
// ═══════════════════════════════════════════════════════════════

function showModal(id) {
    const el = document.getElementById(id);
    el.classList.remove('modal-hidden');
    el.classList.add('modal-visible');
    document.body.style.overflow = 'hidden';
}

function hideModal(id) {
    const el = document.getElementById(id);
    el.classList.remove('modal-visible');
    el.classList.add('modal-hidden');
    document.body.style.overflow = '';
}

// ═══════════════════════════════════════════════════════════════
// VIEW MODAL
// ═══════════════════════════════════════════════════════════════

function openViewModal(eventId) {
    const e = pendingData.find(function (x) { return x.id == eventId; });
    if (!e) return;
    currentViewId = eventId;

    document.getElementById('view-title').textContent       = e.title;
    document.getElementById('view-title').setAttribute('title', e.title);
    document.getElementById('view-org').textContent         = e.org;
    document.getElementById('view-start-date').textContent  = e.start_date;
    document.getElementById('view-end-date').textContent    = e.end_date;
    document.getElementById('view-time').textContent        = e.start_time + ' - ' + e.end_time;
    document.getElementById('view-venue').textContent       = e.venue || 'TBA';
    document.getElementById('view-description').textContent = e.description || 'No description provided.';
    document.getElementById('view-description-section').style.display =
        (e.description && e.description.trim().length) ? 'block' : 'none';

    const orgName = e.organizer_name || 'Unknown';
    document.getElementById('view-organizer-name').textContent     = orgName;
    document.getElementById('view-organizer-email').textContent    = e.organizer_email || '';
    document.getElementById('view-organizer-position').textContent = e.organizer_position || 'Organizer';

    const avatarEl = document.getElementById('view-organizer-avatar');
    if (e.organizer_image) {
        avatarEl.innerHTML  = `<img src="${escapeHtml(e.organizer_image)}" class="w-full h-full object-cover">`;
        avatarEl.className  = 'w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-lg flex-shrink-0 overflow-hidden';
    } else {
        avatarEl.textContent = orgName.charAt(0).toUpperCase();
        avatarEl.className   = 'w-12 h-12 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold text-lg flex-shrink-0';
    }

    const remarksSection = document.getElementById('view-remarks-section');
    const remarksText    = document.getElementById('view-remarks-text');
    if (e.existing_remarks && e.existing_remarks.trim().length) {
        remarksText.textContent = e.existing_remarks.trim();
        remarksSection.classList.remove('hidden');
    } else {
        remarksSection.classList.add('hidden');
    }

    showModal('view-modal');
}

function closeViewModal() {
    hideModal('view-modal');
    currentViewId = null;
}

// ═══════════════════════════════════════════════════════════════
// CONFIRM MODAL
// ═══════════════════════════════════════════════════════════════

function openConfirmModal(eventId, action) {
    const e = pendingData.find(function (x) { return x.id == eventId; });
    if (!e) return;
    currentConfirmId     = eventId;
    currentConfirmAction = action;

    const isApprove = action === 'approved';
    const iconBg    = document.getElementById('confirm-icon-bg');
    const icon      = document.getElementById('confirm-icon');
    const title     = document.getElementById('confirm-title');
    const subtitle  = document.getElementById('confirm-subtitle');
    const btn       = document.getElementById('confirm-btn');

    // ── FIX: Always reset the button state when the modal opens.
    // The success path in executeDecision() left btn.disabled = true,
    // which caused the button to be dead on every subsequent open.
    btn.disabled = false;

    document.getElementById('confirm-event-title').textContent = e.title;
    document.getElementById('confirm-remarks').value           = '';
    document.getElementById('remarks-char-count').textContent  = '0';
    clearRemarksError();

    if (isApprove) {
        iconBg.className     = 'w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-500/20 flex items-center justify-center mx-auto mb-4';
        icon.className       = 'fas fa-check text-2xl text-emerald-500 dark:text-emerald-400';
        title.textContent    = 'Approve Event?';
        subtitle.textContent = 'Please provide remarks for the organizer before approving.';
        btn.className        = 'flex-1 px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-semibold text-sm shadow-lg shadow-emerald-500/30 transition-colors flex items-center justify-center gap-2';
        btn.innerHTML        = '<i class="fas fa-check"></i><span>Confirm Approval</span>';
    } else {
        iconBg.className     = 'w-16 h-16 rounded-full bg-rose-100 dark:bg-rose-500/20 flex items-center justify-center mx-auto mb-4';
        icon.className       = 'fas fa-times text-2xl text-rose-500 dark:text-rose-400';
        title.textContent    = 'Reject Event?';
        subtitle.textContent = 'Please provide remarks explaining the rejection reason.';
        btn.className        = 'flex-1 px-4 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-semibold text-sm shadow-lg shadow-rose-500/30 transition-colors flex items-center justify-center gap-2';
        btn.innerHTML        = '<i class="fas fa-times"></i><span>Confirm Rejection</span>';
    }

    showModal('confirm-modal');
    setTimeout(function () { document.getElementById('confirm-remarks').focus(); }, 300);
}

function closeConfirmModal() {
    hideModal('confirm-modal');
    currentConfirmId     = null;
    currentConfirmAction = null;

    // ── FIX (safety net): also reset button here so even if the modal is
    // dismissed mid-request (e.g. Escape key while fetch is in-flight),
    // the button is never left permanently disabled.
    const btn = document.getElementById('confirm-btn');
    if (btn) {
        btn.disabled = false;
    }
}

// ═══════════════════════════════════════════════════════════════
// REMARKS VALIDATION
// ═══════════════════════════════════════════════════════════════

function showRemarksError() {
    const ta    = document.getElementById('confirm-remarks');
    const label = document.getElementById('remarks-required-label');
    ta.classList.add('remarks-error');
    label.classList.remove('hidden');
    ta.classList.remove('shake');
    void ta.offsetWidth; // force reflow to restart animation
    ta.classList.add('shake');
    ta.addEventListener('animationend', function () { ta.classList.remove('shake'); }, { once: true });
    ta.focus();
}

function clearRemarksError() {
    const ta    = document.getElementById('confirm-remarks');
    const label = document.getElementById('remarks-required-label');
    ta.classList.remove('remarks-error');
    label.classList.add('hidden');
    document.getElementById('remarks-char-count').textContent = ta.value.length;
}

// ═══════════════════════════════════════════════════════════════
// TRIGGER CONFIRM FROM VIEW MODAL
// ═══════════════════════════════════════════════════════════════

function confirmFromView(action) {
    const id = currentViewId;
    closeViewModal();
    if (id) setTimeout(function () { openConfirmModal(id, action); }, 50);
}

// ═══════════════════════════════════════════════════════════════
// STAT CARDS LIVE UPDATE
// ═══════════════════════════════════════════════════════════════

function updateStatCards(stats) {
    const mapping = {
        'stat-total':    stats.total,
        'stat-approved': stats.approved,
        'stat-rejected': stats.rejected,
        'stat-pending':  stats.pending,
    };
    Object.entries(mapping).forEach(function ([id, newValue]) {
        const el = document.getElementById(id);
        if (!el) return;
        if (parseInt(el.textContent) !== newValue) {
            el.textContent = newValue;
            el.classList.remove('stat-bump');
            void el.offsetWidth;
            el.classList.add('stat-bump');
            el.addEventListener('animationend', function () { el.classList.remove('stat-bump'); }, { once: true });
        }
    });
}

// ═══════════════════════════════════════════════════════════════
// EXECUTE APPROVE / REJECT (FETCH)
// ═══════════════════════════════════════════════════════════════

function executeDecision() {
    if (!currentConfirmId || !currentConfirmAction) return;

    const remarks = document.getElementById('confirm-remarks').value.trim();

    if (!remarks) {
        showRemarksError();
        return;
    }

    const resolvedId     = currentConfirmId;
    const resolvedAction = currentConfirmAction;
    const card           = document.getElementById(`card-${resolvedId}`);
    const btn            = document.getElementById('confirm-btn');

    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Processing...</span>';

    fetch(window.location.pathname, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            eventId: resolvedId,
            status:  resolvedAction,
            remarks: remarks,
        }),
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
        if (data.success) {
            // ── FIX: Reset the button BEFORE closing and re-rendering.
            // Without this, btn.disabled stays true. The next call to
            // openConfirmModal() would find a permanently-disabled button.
            btn.disabled  = false;
            btn.innerHTML = '<span>Confirm</span>';

            closeConfirmModal();

            if (card) card.classList.add('fade-out');
            if (data.stats) updateStatCards(data.stats);

            // Remove the processed card from local state and re-render
            setTimeout(function () {
                pendingData = pendingData.filter(function (x) { return x.id != resolvedId; });
                render();
            }, 400);
        } else {
            // On failure: re-enable so the admin can try again
            alert(data.message || 'Something went wrong. Please try again.');
            btn.disabled  = false;
            btn.innerHTML = '<span>Confirm</span>';
        }
    })
    .catch(function (err) {
        console.error('Network error:', err);
        alert('A network error occurred. Please try again.');
        // ── Also reset here in case of network failure
        btn.disabled  = false;
        btn.innerHTML = '<span>Confirm</span>';
        closeConfirmModal();
    });
}

// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        const viewModal    = document.getElementById('view-modal');
        const confirmModal = document.getElementById('confirm-modal');
        if (viewModal?.classList.contains('modal-visible'))         closeViewModal();
        else if (confirmModal?.classList.contains('modal-visible')) closeConfirmModal();
    }
    // Ctrl+Enter inside confirm modal = submit
    if (e.key === 'Enter' && e.ctrlKey) {
        const confirmModal = document.getElementById('confirm-modal');
        if (confirmModal?.classList.contains('modal-visible')) executeDecision();
    }
});

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', render);