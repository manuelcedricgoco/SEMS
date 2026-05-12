/**
 * SEMS Admin — admin_user_manage.js
 * Handles: Dark Mode, Sidebar, Filter Tabs, Live Search,
 *          Table Render, Pagination, View/Edit/Delete Modals,
 *          Archive / Restore / Permanent Delete,
 *          Active ↔ Archived View Toggle, AJAX Sync, Toast
 *
 * Requires SEMS_USER_DATA global before this script loads.
 */

// ═══════════════════════════════════════════════════════════════
// STATE
// ═══════════════════════════════════════════════════════════════

var userData            = (typeof SEMS_USER_DATA !== 'undefined') ? SEMS_USER_DATA.users    : [];
var deptsData           = (typeof SEMS_USER_DATA !== 'undefined') ? SEMS_USER_DATA.depts    : [];
var orgsData            = (typeof SEMS_USER_DATA !== 'undefined') ? SEMS_USER_DATA.orgs     : [];
var clubsData           = (typeof SEMS_USER_DATA !== 'undefined') ? SEMS_USER_DATA.clubs    : [];
var archivedData        = (typeof SEMS_USER_DATA !== 'undefined') ? SEMS_USER_DATA.archived : [];

var currentRoleFilter   = 'all';
var currentEditUserId   = null;
var currentDeleteUserId = null;
var currentPermDeleteId = null;
var currentPage         = 1;
var ITEMS_PER_PAGE      = 10;

// ═══════════════════════════════════════════════════════════════
// STYLE MAPS
// ═══════════════════════════════════════════════════════════════

var roleStyles = {
    student:   'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400',
    organizer: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-400',
    admin:     'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400',
};
var avatarBgs = ['#3b82f6', '#6366f1', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444'];

var inputCls = 'w-full px-4 py-2.5 text-sm rounded-xl bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-400 text-slate-700 dark:text-slate-200 transition-all duration-200';

// ═══════════════════════════════════════════════════════════════
// INJECT BASE CSS
// ═══════════════════════════════════════════════════════════════

(function injectBaseCSS() {
    var style = document.createElement('style');
    style.id  = 'sems-user-mgmt-css';
    style.textContent = `
        #active-section.hidden,
        #archived-section.hidden {
            display: none !important;
        }
        .user-row {
            transition: opacity .25s ease, transform .25s ease;
        }
        .user-row.removing {
            opacity: 0;
            transform: translateX(-16px);
            pointer-events: none;
        }
        .archived-badge-live {
            min-width: 1.25rem;
            height: 1.25rem;
            padding: 0 .3rem;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fcd34d;
            color: #78350f;
            transition: background .2s;
        }
        .dark .archived-badge-live {
            background: #d97706;
            color: #fffbeb;
        }
    `;
    document.head.appendChild(style);
})();

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════

function toggleTheme() {
    var isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sems-theme', isDark ? 'dark' : 'light');
    _applyThemeUI(isDark);
}

function _applyThemeUI(isDark) {
    var icon  = document.getElementById('theme-icon');
    var label = document.getElementById('theme-label');
    if (!icon || !label) return;
    if (isDark) {
        icon.className    = 'fas fa-sun w-5 text-center text-amber-500';
        label.textContent = 'Light Mode';
    } else {
        icon.className    = 'fas fa-moon w-5 text-center';
        label.textContent = 'Dark Mode';
    }
}

window.addEventListener('DOMContentLoaded', function () {
    var saved = localStorage.getItem('sems-theme') || 'light';
    _applyThemeUI(saved === 'dark');
});

// ═══════════════════════════════════════════════════════════════
// SIDEBAR
// ═══════════════════════════════════════════════════════════════

function openSidebar() {
    document.getElementById('sidebar').classList.remove('-translate-x-full');
    var ov = document.getElementById('overlay');
    ov.classList.remove('pointer-events-none', 'opacity-0');
    ov.classList.add('pointer-events-auto', 'opacity-100');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    document.getElementById('sidebar').classList.add('-translate-x-full');
    var ov = document.getElementById('overlay');
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
// VIEW TOGGLE — Active ↔ Archived
// Uses inline style to beat any injected CSS specificity.
// ═══════════════════════════════════════════════════════════════

function showActiveView() {
    var activeEl   = document.getElementById('active-section');
    var archivedEl = document.getElementById('archived-section');
    if (!activeEl || !archivedEl) return;

    activeEl.style.display   = 'block';
    archivedEl.style.display = 'none';
    activeEl.classList.remove('hidden');
    archivedEl.classList.add('hidden');

    _setViewToggleUI('active');
}

function showArchivedView() {
    var activeEl   = document.getElementById('active-section');
    var archivedEl = document.getElementById('archived-section');
    if (!activeEl || !archivedEl) return;

    activeEl.style.display   = 'none';
    archivedEl.style.display = 'block';
    activeEl.classList.add('hidden');
    archivedEl.classList.remove('hidden');

    _setViewToggleUI('archived');
    renderArchiveTable();
}

function _setViewToggleUI(view) {
    var activeBtn   = document.getElementById('view-active-btn');
    var archivedBtn = document.getElementById('view-archived-btn');
    if (!activeBtn || !archivedBtn) return;

    if (view === 'active') {
        activeBtn.classList.add('bg-primary-500', 'text-white');
        activeBtn.classList.remove('text-slate-600', 'dark:text-slate-400',
                                   'hover:bg-gray-50', 'dark:hover:bg-slate-700');
        archivedBtn.classList.remove('bg-primary-500', 'text-white');
        archivedBtn.classList.add('text-slate-600', 'dark:text-slate-400',
                                  'hover:bg-gray-50', 'dark:hover:bg-slate-700');
    } else {
        archivedBtn.classList.add('bg-primary-500', 'text-white');
        archivedBtn.classList.remove('text-slate-600', 'dark:text-slate-400',
                                     'hover:bg-gray-50', 'dark:hover:bg-slate-700');
        activeBtn.classList.remove('bg-primary-500', 'text-white');
        activeBtn.classList.add('text-slate-600', 'dark:text-slate-400',
                                'hover:bg-gray-50', 'dark:hover:bg-slate-700');
    }
}

// ═══════════════════════════════════════════════════════════════
// ARCHIVED BADGE — live count on the toggle button
// ═══════════════════════════════════════════════════════════════

function syncArchivedBadge() {
    var btn = document.getElementById('view-archived-btn');
    if (!btn) return;

    var badge = btn.querySelector('.archived-badge-live');
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'archived-badge-live ml-1.5';
        btn.appendChild(badge);
    }
    badge.textContent = archivedData.length;
    badge.style.display = archivedData.length > 0 ? 'inline-flex' : 'none';
}

// ═══════════════════════════════════════════════════════════════
// SYNC ALL UI — keeps every panel in sync after any AJAX action
// ═══════════════════════════════════════════════════════════════

function syncAllUI() {
    applyFilters();
    updateStatCards();
    syncArchivedBadge();

    // Refresh archived table only when the archived view is currently visible
    var archivedEl = document.getElementById('archived-section');
    if (archivedEl && archivedEl.style.display !== 'none') {
        renderArchiveTable();
    }
}

// ═══════════════════════════════════════════════════════════════
// STAT CARDS — animate-update counts without page reload
// ═══════════════════════════════════════════════════════════════

function updateStatCards() {
    var counts = {
        total:     userData.length,
        student:   userData.filter(function (u) { return u.role === 'student';   }).length,
        organizer: userData.filter(function (u) { return u.role === 'organizer'; }).length,
        archived:  archivedData.length,
    };

    ['total', 'student', 'organizer', 'archived'].forEach(function (key) {
        var el = document.getElementById('stat-' + key);
        if (!el) return;
        el.style.transition = 'transform .15s ease, opacity .15s ease';
        el.style.transform  = 'scale(1.25)';
        el.style.opacity    = '0.4';
        el.textContent      = counts[key];
        setTimeout(function () {
            el.style.transform = 'scale(1)';
            el.style.opacity   = '1';
        }, 150);
    });
}

// ═══════════════════════════════════════════════════════════════
// FILTER TABS
// ═══════════════════════════════════════════════════════════════

function setRoleFilter(role) {
    currentRoleFilter = role;

    document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.className = 'tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-primary-400 hover:text-primary-500 transition-all duration-200';
    });

    var activeTab = document.getElementById('tab-' + role);
    if (activeTab) {
        activeTab.className = 'tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-primary-500 text-white shadow-sm shadow-primary-500/30 transition-all duration-200';
    }

    showActiveView();
    applyFilters();
}

// ═══════════════════════════════════════════════════════════════
// SEARCH + FILTER
// ═══════════════════════════════════════════════════════════════

function applyFilters(resetPage) {
    if (resetPage === undefined) resetPage = true;
    if (resetPage) currentPage = 1;

    var searchEl = document.getElementById('userSearch');
    var q = searchEl ? searchEl.value.toLowerCase() : '';

    var filtered = userData.filter(function (u) {
        var matchRole   = currentRoleFilter === 'all' || u.role === currentRoleFilter;
        var matchSearch = !q
            || (u.full_name || '').toLowerCase().includes(q)
            || (u.email     || '').toLowerCase().includes(q);
        return matchRole && matchSearch;
    });

    renderUsers(filtered);
}

// ═══════════════════════════════════════════════════════════════
// ARCHIVE SEARCH — live filter inside archived view
// ═══════════════════════════════════════════════════════════════

function filterArchived() {
    var searchEl = document.getElementById('archiveSearch');
    var q = searchEl ? searchEl.value.toLowerCase().trim() : '';
    var filtered = q
        ? archivedData.filter(function (u) {
            return (u.full_name || '').toLowerCase().includes(q)
                || (u.email    || '').toLowerCase().includes(q);
          })
        : archivedData;
    renderArchiveTable(filtered);
}

// ═══════════════════════════════════════════════════════════════
// TABLE RENDER — active users
// ═══════════════════════════════════════════════════════════════

function renderUsers(data) {
    data = data || [];
    var tbody               = document.getElementById('userTableBody');
    var emptyState          = document.getElementById('empty-state');
    var resultNum           = document.getElementById('result-num');
    var paginationContainer = document.getElementById('paginationContainer');

    if (resultNum) resultNum.textContent = data.length;

    if (data.length === 0) {
        tbody.innerHTML = '';
        emptyState.classList.remove('hidden');
        if (paginationContainer) paginationContainer.classList.add('hidden');
        return;
    }
    emptyState.classList.add('hidden');

    var totalPages = Math.ceil(data.length / ITEMS_PER_PAGE);
    if (currentPage > totalPages) currentPage = totalPages || 1;

    var start    = (currentPage - 1) * ITEMS_PER_PAGE;
    var pageData = data.slice(start, start + ITEMS_PER_PAGE);

    tbody.innerHTML = pageData.map(function (user, idx) {
        var name     = user.full_name || 'No Name';
        var initials = name.split(' ').map(function (n) { return n[0] || ''; }).join('').substring(0, 2).toUpperCase();
        var bg       = avatarBgs[user.user_id % avatarBgs.length];
        var pill     = roleStyles[user.role] || 'bg-gray-100 text-gray-600';

        var avatarHtml = user.profile_image
            ? '<img src="data:image/jpeg;base64,' + user.profile_image + '" alt="' + _esc(name) + '" class="w-8 h-8 rounded-lg object-cover border border-gray-200 dark:border-slate-600 shrink-0">'
            : '<div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-xs font-bold shrink-0" style="background:' + bg + '">' + initials + '</div>';

        return `
        <tr id="user-row-${user.user_id}" class="user-row hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors" style="animation-delay:${idx * 0.025}s">
            <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                    ${avatarHtml}
                    <span class="font-medium text-slate-900 dark:text-white">${_esc(name)}</span>
                </div>
            </td>
            <td class="px-6 py-4 text-slate-600 dark:text-slate-400 text-sm">${_esc(user.email || 'N/A')}</td>
            <td class="px-6 py-4">
                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold capitalize ${pill}">
                    ${user.role}
                </span>
            </td>
            <td class="px-6 py-4 text-slate-600 dark:text-slate-400 text-sm">${_esc(user.dept_name || 'N/A')}</td>
            <td class="px-6 py-4 text-slate-500 dark:text-slate-400 text-sm whitespace-nowrap">${_esc(user.joined || '')}</td>
            <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                    <button onclick="viewUserDetails(${user.user_id})" title="View Details"
                            class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-slate-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10 transition-all duration-200">
                        <i class="far fa-eye text-xs"></i>
                    </button>
                    <button onclick="editUser(${user.user_id})" title="Edit User"
                            class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-slate-400 hover:text-violet-500 hover:bg-violet-50 dark:hover:bg-violet-500/10 transition-all duration-200">
                        <i class="fas fa-pencil-alt text-xs"></i>
                    </button>
                    <button onclick="deleteUser(${user.user_id})" title="Archive User"
                            class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 text-slate-400 hover:text-amber-500 hover:bg-amber-50 dark:hover:bg-amber-500/10 transition-all duration-200">
                        <i class="fas fa-archive text-xs"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    renderPaginationControls(data.length, totalPages);
}

// ═══════════════════════════════════════════════════════════════
// PAGINATION
// ═══════════════════════════════════════════════════════════════

function renderPaginationControls(totalItems, totalPages) {
    var container = document.getElementById('paginationContainer');
    if (!container) return;

    if (totalItems <= ITEMS_PER_PAGE) {
        container.classList.add('hidden');
        container.innerHTML = '';
        return;
    }
    container.classList.remove('hidden');

    var from = (currentPage - 1) * ITEMS_PER_PAGE + 1;
    var to   = Math.min(currentPage * ITEMS_PER_PAGE, totalItems);

    var html = `
        <div class="flex flex-col sm:flex-row items-center justify-between px-6 py-4 border-t border-gray-100 dark:border-slate-700 gap-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Showing <span class="font-semibold text-slate-700 dark:text-slate-300">${from}</span>
                to <span class="font-semibold text-slate-700 dark:text-slate-300">${to}</span>
                of <span class="font-semibold text-slate-700 dark:text-slate-300">${totalItems}</span> users
            </p>
            <div class="flex items-center gap-2">`;

    html += `<button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}
        class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-all duration-200">
        <i class="fas fa-chevron-left text-xs"></i>
    </button>`;

    for (var i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
            var isActive = i === currentPage;
            html += `<button onclick="changePage(${i})"
                class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-semibold transition-all duration-200 ${isActive
                    ? 'bg-primary-500 text-white shadow-sm shadow-primary-500/30'
                    : 'border border-gray-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700'}">
                ${i}
            </button>`;
        } else if (i === currentPage - 2 || i === currentPage + 2) {
            html += '<span class="text-slate-400 text-xs px-1">...</span>';
        }
    }

    html += `<button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}
        class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 dark:border-slate-600 text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed transition-all duration-200">
        <i class="fas fa-chevron-right text-xs"></i>
    </button>`;

    html += '</div></div>';
    container.innerHTML = html;
}

function changePage(page) {
    if (page < 1) return;
    currentPage = page;
    applyFilters(false);
}

// ═══════════════════════════════════════════════════════════════
// ARCHIVED TABLE RENDER
// ═══════════════════════════════════════════════════════════════

function renderArchiveTable(data) {
    data = data || archivedData;

    var tbody    = document.getElementById('archiveTableBody');
    var empty    = document.getElementById('archiveEmpty');
    var countEl  = document.getElementById('archive-result-num');
    if (!tbody) return;

    if (countEl) countEl.textContent = data.length;

    if (data.length === 0) {
        tbody.innerHTML = '';
        if (empty) empty.classList.remove('hidden');
        return;
    }
    if (empty) empty.classList.add('hidden');

    tbody.innerHTML = data.map(function (user) {
        var name     = user.full_name || 'No Name';
        var initials = name.split(' ').map(function (n) { return n[0] || ''; }).join('').substring(0, 2).toUpperCase();
        var bg       = avatarBgs[user.user_id % avatarBgs.length];
        var pill     = roleStyles[user.role] || 'bg-gray-100 text-gray-600';

        var avatarHtml = user.profile_image
            ? '<img src="data:image/jpeg;base64,' + user.profile_image + '" alt="' + _esc(name) + '" class="w-8 h-8 rounded-lg object-cover border border-gray-200 dark:border-slate-600 shrink-0 opacity-60">'
            : '<div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-xs font-bold shrink-0 opacity-60" style="background:' + bg + '">' + initials + '</div>';

        return `
        <tr id="archive-row-${user.user_id}" class="hover:bg-amber-50/50 dark:hover:bg-amber-500/5 transition-colors opacity-80">
            <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                    ${avatarHtml}
                    <div>
                        <p class="font-medium text-slate-700 dark:text-slate-300 line-through decoration-slate-400/50 text-sm">${_esc(name)}</p>
                        <p class="text-xs text-slate-400 mt-0.5">${_esc(user.email || 'N/A')}</p>
                    </div>
                </div>
            </td>
            <td class="px-6 py-4">
                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold capitalize opacity-60 ${pill}">
                    ${user.role}
                </span>
            </td>
            <td class="px-6 py-4 text-slate-500 dark:text-slate-400 text-sm whitespace-nowrap">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 font-semibold text-xs">
                    <i class="fas fa-calendar-times text-[10px]"></i>
                    ${_esc(user.archived_on || '—')}
                </span>
            </td>
            <td class="px-6 py-4 text-slate-500 dark:text-slate-400 text-sm">${_esc(user.deleted_by_email || 'Admin')}</td>
            <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                    <button onclick="restoreUser(${user.user_id})"
                            title="Restore User"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400
                                   hover:bg-emerald-100 dark:hover:bg-emerald-500/20
                                   border border-emerald-200 dark:border-emerald-500/30 transition-all duration-200">
                        <i class="fas fa-undo"></i> Restore
                    </button>
                    <button onclick="openPermDeleteModal(${user.user_id})"
                            title="Delete Forever"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold
                                   bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400
                                   hover:bg-rose-100 dark:hover:bg-rose-500/20
                                   border border-rose-200 dark:border-rose-500/30 transition-all duration-200">
                        <i class="fas fa-trash-alt"></i> Delete
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ═══════════════════════════════════════════════════════════════
// VIEW USER MODAL
// ═══════════════════════════════════════════════════════════════

function viewUserDetails(userId) {
    var user = userData.find(function (u) { return u.user_id == userId; });
    if (!user) return;

    var name     = user.full_name || 'No Name';
    var initials = name.split(' ').map(function (n) { return n[0] || ''; }).join('').substring(0, 2).toUpperCase();
    var bg       = avatarBgs[user.user_id % avatarBgs.length];
    var pill     = roleStyles[user.role] || 'bg-gray-100 text-gray-600';

    var avatarHtml = user.profile_image
        ? '<img src="data:image/jpeg;base64,' + user.profile_image + '" alt="' + _esc(name) + '" class="w-14 h-14 rounded-xl object-cover border border-gray-200 dark:border-slate-600 shrink-0">'
        : '<div class="w-14 h-14 rounded-xl flex items-center justify-center text-white text-lg font-bold shrink-0" style="background:' + bg + '">' + initials + '</div>';

    var membershipLabel, membershipInfo, membershipIcon;
    if (user.org_name) {
        membershipLabel = 'Organization'; membershipInfo = user.org_name; membershipIcon = 'fa-building';
    } else if (user.club_name) {
        membershipLabel = 'Club'; membershipInfo = user.club_name; membershipIcon = 'fa-users';
    } else {
        membershipLabel = 'Membership'; membershipInfo = 'Not assigned'; membershipIcon = 'fa-minus-circle';
    }

    document.getElementById('viewModalBody').innerHTML = `
        <div class="flex items-center gap-4 p-4 rounded-xl bg-gray-50 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700">
            ${avatarHtml}
            <div>
                <p class="font-bold text-slate-900 dark:text-white text-base">${_esc(name)}</p>
                <p class="text-xs text-slate-400 mt-0.5">${_esc(user.email || 'N/A')}</p>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="p-4 rounded-xl bg-gray-50 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1"><i class="fas ${membershipIcon} mr-1"></i>${membershipLabel}</p>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">${_esc(membershipInfo)}</p>
            </div>
            <div class="p-4 rounded-xl bg-gray-50 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Role</p>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold capitalize ${pill}">${user.role}</span>
            </div>
        </div>
        <div class="p-4 rounded-xl bg-gray-50 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700">
            <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1"><i class="fas fa-envelope mr-1"></i>Email</p>
            <p class="font-semibold text-slate-900 dark:text-white text-sm">${_esc(user.email || 'N/A')}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="p-4 rounded-xl bg-gray-50 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1"><i class="fas fa-building mr-1"></i>Department</p>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">${_esc(user.dept_name || 'N/A')}</p>
            </div>
            <div class="p-4 rounded-xl bg-gray-50 dark:bg-slate-700/30 border border-gray-100 dark:border-slate-700">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1"><i class="fas fa-calendar mr-1"></i>Joined</p>
                <p class="font-semibold text-slate-900 dark:text-white text-sm">${_esc(user.joined || 'N/A')}</p>
            </div>
        </div>
    `;
    document.getElementById('viewModal').classList.remove('hidden');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.add('hidden');
}

// ═══════════════════════════════════════════════════════════════
// EDIT USER MODAL
// ═══════════════════════════════════════════════════════════════

window.updateExtraFields = function (userOrgId, userClubId) {
    userOrgId  = userOrgId  || 0;
    userClubId = userClubId || 0;

    var role      = document.getElementById('editRole').value;
    var container = document.getElementById('extraFieldsContainer');

    if (role === 'organizer') {
        var opts = orgsData.map(function (o) {
            return '<option value="' + o.org_id + '" ' + (o.org_id == userOrgId ? 'selected' : '') + '>' + _esc(o.org_name) + '</option>';
        }).join('');
        container.innerHTML = `
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Organization</label>
                <select id="editOrg" class="${inputCls}">
                    <option value="0">No Organization</option>${opts}
                </select>
            </div>`;
    } else if (role === 'student') {
        var opts = clubsData.map(function (c) {
            return '<option value="' + c.club_id + '" ' + (c.club_id == userClubId ? 'selected' : '') + '>' + _esc(c.club_name) + '</option>';
        }).join('');
        container.innerHTML = `
            <div>
                <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Club</label>
                <select id="editClub" class="${inputCls}">
                    <option value="0">No Club</option>${opts}
                </select>
            </div>`;
    } else {
        container.innerHTML = '';
    }
};

function editUser(userId) {
    var user = userData.find(function (u) { return u.user_id == userId; });
    if (!user) return;

    currentEditUserId = userId;

    var name     = user.full_name || 'No Name';
    var initials = name.split(' ').map(function (n) { return n[0] || ''; }).join('').substring(0, 2).toUpperCase();
    var bg       = avatarBgs[user.user_id % avatarBgs.length];

    var avatarHtml = user.profile_image
        ? '<img src="data:image/jpeg;base64,' + user.profile_image + '" alt="' + _esc(name) + '" class="w-10 h-10 rounded-xl object-cover border border-gray-200 dark:border-slate-600 shrink-0">'
        : '<div class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm font-bold shrink-0" style="background:' + bg + '">' + initials + '</div>';

    var deptOpts = deptsData.map(function (d) {
        return '<option value="' + d.dept_id + '" ' + (d.dept_id == user.dept_id ? 'selected' : '') + '>' + _esc(d.dept_name) + '</option>';
    }).join('');

    document.getElementById('editModalBody').innerHTML = `
        <div class="flex items-center gap-3 p-4 rounded-xl bg-violet-50 dark:bg-violet-500/10 border border-violet-100 dark:border-violet-500/20">
            ${avatarHtml}
            <div>
                <p class="text-[10px] font-semibold text-violet-500 uppercase tracking-wider">Editing</p>
                <p class="font-bold text-slate-900 dark:text-white text-sm">${_esc(name)}</p>
            </div>
        </div>
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Role</label>
            <select id="editRole" onchange="updateExtraFields(${user.org_id || 0}, ${user.club_id || 0})" class="${inputCls}">
                <option value="student"   ${user.role === 'student'   ? 'selected' : ''}>Student</option>
                <option value="organizer" ${user.role === 'organizer' ? 'selected' : ''}>Organizer</option>
                <option value="admin"     ${user.role === 'admin'     ? 'selected' : ''}>Admin</option>
            </select>
        </div>
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Department</label>
            <select id="editDept" class="${inputCls}">
                <option value="0">No Department</option>${deptOpts}
            </select>
        </div>
        <div id="extraFieldsContainer"></div>
        <div>
            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">Email</label>
            <input type="email" id="editEmail" value="${_esc(user.email || '')}" class="${inputCls}"/>
            <p class="text-xs text-slate-400 mt-1">You can modify the email address</p>
        </div>
    `;

    updateExtraFields(user.org_id, user.club_id);
    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
    currentEditUserId = null;
}

async function saveUserEdit() {
    if (!currentEditUserId) return;

    var btn = document.getElementById('saveEditBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i>Saving...';

    var role    = document.getElementById('editRole').value;
    var deptId  = parseInt(document.getElementById('editDept').value) || 0;
    var email   = document.getElementById('editEmail').value.trim();
    var payload = { action: 'edit_role', userId: currentEditUserId, role: role, deptId: deptId, email: email };

    if (role === 'organizer') {
        var orgSel = document.getElementById('editOrg');
        payload.orgId = orgSel ? (parseInt(orgSel.value) || 0) : 0;
    } else if (role === 'student') {
        var clubSel = document.getElementById('editClub');
        payload.clubId = clubSel ? (parseInt(clubSel.value) || 0) : 0;
    }

    try {
        var res  = await fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify(payload),
        });
        var data = await res.json();

        if (data.success) {
            var editedId = currentEditUserId;
            closeEditModal();

            var user = userData.find(function (u) { return u.user_id == editedId; });
            if (user) {
                user.role    = role;
                user.dept_id = deptId;
                user.email   = email;

                var dept = deptsData.find(function (d) { return d.dept_id == deptId; });
                user.dept_name = dept ? dept.dept_name : null;

                if (role === 'organizer') {
                    user.org_id  = payload.orgId;
                    user.club_id = null;
                    var org = orgsData.find(function (o) { return o.org_id == payload.orgId; });
                    user.org_name  = org ? org.org_name : null;
                    user.club_name = null;
                } else if (role === 'student') {
                    user.club_id = payload.clubId;
                    user.org_id  = null;
                    var club = clubsData.find(function (c) { return c.club_id == payload.clubId; });
                    user.club_name = club ? club.club_name : null;
                    user.org_name  = null;
                } else {
                    user.club_id = null; user.org_id = null;
                    user.org_name = null; user.club_name = null;
                }
            }
            syncAllUI();
            showToast('User updated successfully!', 'success');
        } else {
            showToast(data.error || data.message || 'Update failed', 'error');
        }
    } catch (err) {
        showToast('Request failed: ' + err.message, 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = 'Save Changes';
    }
}

// ═══════════════════════════════════════════════════════════════
// ARCHIVE (SOFT-DELETE) MODAL
// ═══════════════════════════════════════════════════════════════

function deleteUser(userId) {
    currentDeleteUserId = userId;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    currentDeleteUserId = null;
}

async function confirmDelete() {
    if (!currentDeleteUserId) return;

    var idSnap = currentDeleteUserId;
    var btn    = document.getElementById('confirmDeleteBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i>Archiving...';

    try {
        var res  = await fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ action: 'delete', userId: idSnap }),
        });
        var data = await res.json();

        if (data.success) {
            closeDeleteModal();

            // Move user into archivedData immediately (no page refresh)
            var user = userData.find(function (u) { return u.user_id == idSnap; });
            if (user) {
                var now    = new Date();
                var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                user.archived_on      = months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();
                user.deleted_by_email = 'You';
                archivedData.unshift(user);
            }

            // Animate row out, then remove from userData
            var row = document.getElementById('user-row-' + idSnap);
            if (row) {
                row.classList.add('removing');
                setTimeout(function () {
                    userData = userData.filter(function (u) { return u.user_id != idSnap; });
                    syncAllUI();
                }, 280);
            } else {
                userData = userData.filter(function (u) { return u.user_id != idSnap; });
                syncAllUI();
            }
            showToast('User archived. Restore from the Archived view.', 'success');
        } else {
            showToast(data.error || data.message || 'Archive failed', 'error');
        }
    } catch (err) {
        showToast('Request failed', 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-archive"></i> Archive User';
    }
}

// ═══════════════════════════════════════════════════════════════
// RESTORE USER (AJAX)
// ═══════════════════════════════════════════════════════════════

async function restoreUser(userId) {
    var row = document.getElementById('archive-row-' + userId);
    try {
        var res  = await fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ action: 'restore', userId: userId }),
        });
        var data = await res.json();

        if (data.success) {
            var user = archivedData.find(function (u) { return u.user_id == userId; });
            if (user) {
                archivedData = archivedData.filter(function (u) { return u.user_id != userId; });
                delete user.archived_on;
                delete user.deleted_by_email;
                user.deleted_at = null;
                userData.push(user);
            }

            if (row) {
                row.style.transition = 'all .28s ease';
                row.style.opacity    = '0';
                row.style.transform  = 'translateX(16px)';
                setTimeout(function () { syncAllUI(); }, 280);
            } else {
                syncAllUI();
            }
            showToast('User restored successfully!', 'success');
        } else {
            showToast(data.error || 'Restore failed', 'error');
        }
    } catch (err) {
        showToast('Request failed: ' + err.message, 'error');
    }
}

// ═══════════════════════════════════════════════════════════════
// PERMANENT DELETE MODAL
// ═══════════════════════════════════════════════════════════════

function openPermDeleteModal(userId) {
    currentPermDeleteId = userId;
    document.getElementById('permDeleteModal').classList.remove('hidden');
}

function closePermDeleteModal() {
    document.getElementById('permDeleteModal').classList.add('hidden');
    currentPermDeleteId = null;
}

async function confirmPermDelete() {
    if (!currentPermDeleteId) return;

    var idSnap = currentPermDeleteId;
    var btn    = document.getElementById('confirmPermDeleteBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1.5"></i>Deleting...';

    try {
        var res  = await fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ action: 'permanent_delete', userId: idSnap }),
        });
        var data = await res.json();

        if (data.success) {
            closePermDeleteModal();

            var row = document.getElementById('archive-row-' + idSnap);
            if (row) {
                row.style.transition = 'all .28s ease';
                row.style.opacity    = '0';
                row.style.transform  = 'translateX(-16px)';
                setTimeout(function () {
                    archivedData = archivedData.filter(function (u) { return u.user_id != idSnap; });
                    syncAllUI();
                }, 280);
            } else {
                archivedData = archivedData.filter(function (u) { return u.user_id != idSnap; });
                syncAllUI();
            }
            showToast('User permanently deleted.', 'error');
        } else {
            showToast(data.error || 'Delete failed', 'error');
        }
    } catch (err) {
        showToast('Request failed: ' + err.message, 'error');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-trash-alt"></i> Delete Forever';
    }
}

// ═══════════════════════════════════════════════════════════════
// TOAST
// ═══════════════════════════════════════════════════════════════

function showToast(message, type) {
    type = type || 'success';
    var colors = {
        success: 'bg-emerald-500 shadow-emerald-500/30',
        error:   'bg-rose-500 shadow-rose-500/30',
        info:    'bg-primary-500 shadow-primary-500/30',
    };
    var icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };

    var toast = document.createElement('div');
    toast.className = 'fixed top-4 right-4 z-[999] ' + (colors[type] || colors.info) + ' text-white px-4 py-3 rounded-xl shadow-lg flex items-center gap-2.5 text-sm font-medium translate-x-full transition-transform duration-300';
    toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i><span>' + _esc(message) + '</span>';
    document.body.appendChild(toast);

    setTimeout(function () { toast.classList.remove('translate-x-full'); }, 50);
    setTimeout(function () {
        toast.classList.add('translate-x-full');
        setTimeout(function () { toast.remove(); }, 300);
    }, 3200);
}

// ═══════════════════════════════════════════════════════════════
// HELPER — HTML escape
// ═══════════════════════════════════════════════════════════════

function _esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ═══════════════════════════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ═══════════════════════════════════════════════════════════════

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeViewModal();
        closeEditModal();
        closeDeleteModal();
        closePermDeleteModal();
    }
});

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    // Default: show active view
    showActiveView();
    applyFilters();
    syncArchivedBadge();
});