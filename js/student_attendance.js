// student_attendance.js

lucide.createIcons();

// ── Data from PHP bridge ───────────────────────────────────────
const DATA = SEMS_ATTENDANCE.data;

// ── State ──────────────────────────────────────────────────────
let currentPage = 1;
let perPage     = 25;
let sortCol     = 'login';
let sortDir     = 'desc';
let filtered    = [...DATA];

// ── Dark mode ──────────────────────────────────────────────────
const htmlEl = document.documentElement;

function applyDark(on) {
    htmlEl.classList.toggle('dark', on);
    // sun = visible when dark (click to go light); moon = visible when light
    ['sunIconM', 'sunIconD'].forEach(id => {
        const e = document.getElementById(id);
        if (e) e.style.display = on ? 'block' : 'none';
    });
    ['moonIconM', 'moonIconD'].forEach(id => {
        const e = document.getElementById(id);
        if (e) e.style.display = on ? 'none' : 'block';
    });
}

// Read saved preference; fall back to system preference
const stored  = localStorage.getItem('sems-dark');
const sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
applyDark(stored !== null ? stored === 'true' : sysDark);

function toggleDark() {
    const on = !htmlEl.classList.contains('dark');
    localStorage.setItem('sems-dark', on);
    applyDark(on);
}

// ── Sidebar ────────────────────────────────────────────────────
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('overlay');
const menuBtn = document.getElementById('menuBtn');

function openSidebar() {
    sidebar.classList.add('is-open');
    overlay.classList.add('is-open');
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    sidebar.classList.remove('is-open');
    overlay.classList.remove('is-open');
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && sidebar.classList.contains('is-open')) closeSidebar();
});

// ── Animate on scroll ──────────────────────────────────────────
const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
        if (e.isIntersecting) { e.target.classList.add('running'); io.unobserve(e.target); }
    });
}, { threshold: 0.05 });
document.querySelectorAll('.anim').forEach(el => io.observe(el));

// ── Helpers ────────────────────────────────────────────────────
function fmtDate(str) {
    if (!str) return '—';
    const d = new Date(str.replace(' ', 'T'));
    return isNaN(d) ? '—' : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function fmtTime(str) {
    if (!str) return '—';
    const d = new Date(str.replace(' ', 'T'));
    return isNaN(d) ? '—' : d.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
}

function fmtDuration(a, b) {
    if (!a || !b) return '—';
    const diff = (new Date(b.replace(' ', 'T')) - new Date(a.replace(' ', 'T'))) / 1000;
    if (diff <= 0) return '—';
    const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60);
    return (h > 0 ? h + 'h ' : '') + m + 'm';
}

function getStatus(r) {
    if (r.login && r.logout) return 'full';
    if (r.login) return 'in_only';
    return 'no_scan';
}

function statusPill(r) {
    const s = getStatus(r);
    if (s === 'full')    return `<span class="pill pill-green"><i data-lucide="check-circle" style="width:9px;height:9px;"></i> Full Session</span>`;
    if (s === 'in_only') return `<span class="pill pill-amber"><i data-lucide="log-in" style="width:9px;height:9px;"></i> Check-In Only</span>`;
    return `<span class="pill pill-muted"><i data-lucide="minus-circle" style="width:9px;height:9px;"></i> No Scan</span>`;
}

// ── Filter & Sort ──────────────────────────────────────────────
function applyFilters() {
    const q    = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
    const type = document.getElementById('typeFilter')?.value || '';
    const stat = document.getElementById('statusFilter')?.value || '';
    const from = document.getElementById('dateFrom')?.value || '';
    const to   = document.getElementById('dateTo')?.value || '';

    filtered = DATA.filter(r => {
        if (q && ![r.title, r.venue, r.organizer, r.type].some(f => (f || '').toLowerCase().includes(q))) return false;
        if (type && r.type !== type) return false;
        if (stat && getStatus(r) !== stat) return false;
        if (from || to) {
            const d = new Date((r.login || r.start || '').replace(' ', 'T'));
            if (from && d < new Date(from)) return false;
            if (to   && d > new Date(to + 'T23:59:59')) return false;
        }
        return true;
    });

    filtered.sort((a, b) => {
        let av = a[sortCol] || '', bv = b[sortCol] || '';
        if (sortCol === 'login' || sortCol === 'logout') {
            av = av ? new Date(av.replace(' ', 'T')) : 0;
            bv = bv ? new Date(bv.replace(' ', 'T')) : 0;
        } else {
            av = (av || '').toLowerCase();
            bv = (bv || '').toLowerCase();
        }
        if (av < bv) return sortDir === 'asc' ? -1 : 1;
        if (av > bv) return sortDir === 'asc' ?  1 : -1;
        return 0;
    });

    currentPage = 1;

    const activeCount = [q, type, stat, from, to].filter(Boolean).length;
    const badge    = document.getElementById('activeFilterBadge');
    const clearBtn = document.getElementById('clearFilters');

    if (activeCount > 0) {
        badge.textContent   = activeCount + ' filter' + (activeCount > 1 ? 's' : '') + ' active';
        badge.style.display = 'inline-flex';
        clearBtn.classList.add('visible');
    } else {
        badge.style.display = 'none';
        clearBtn.classList.remove('visible');
    }

    renderTable();
}

// ── Render ─────────────────────────────────────────────────────
function renderTable() {
    const total = filtered.length;
    const pages = Math.max(1, Math.ceil(total / perPage));
    currentPage = Math.min(currentPage, pages);
    const start = (currentPage - 1) * perPage;
    const slice = filtered.slice(start, start + perPage);

    document.getElementById('resultsCount').textContent  = total;
    document.getElementById('resultsPlural').textContent = total === 1 ? '' : 's';
    document.getElementById('pageInfo').textContent      = currentPage + ' / ' + pages;
    document.getElementById('paginationInfo').textContent =
        `Showing ${total ? start + 1 : 0}–${Math.min(start + perPage, total)} of ${total} records`;

    // ── Desktop table ──────────────────────────────────────────
    const tbody = document.getElementById('tableBody');
    const noD   = document.getElementById('noResultsDesktop');
    if (tbody) {
        if (!slice.length) {
            tbody.innerHTML   = '';
            noD.style.display = '';
        } else {
            noD.style.display = 'none';
            tbody.innerHTML = slice.map(r => `
        <tr>
          <td class="col-event">
            <div style="font-weight:600;font-size:.84rem;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;">${r.title}</div>
            <div style="font-size:.7rem;color:var(--ink3);margin-top:.1rem;">${r.organizer}</div>
          </td>
          <td><span class="pill pill-accent">${r.type}</span></td>
          <td class="col-venue" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.8rem;" title="${r.venue}">${r.venue}</td>
          <td class="col-time">${r.login  ? `<span class="time-pill time-in"><i data-lucide="log-in" style="width:10px;height:10px;"></i> ${fmtTime(r.login)}</span><br><span style="font-size:.68rem;color:var(--ink3);">${fmtDate(r.login)}</span>` : '<span style="color:var(--ink3);">—</span>'}</td>
          <td class="col-time">${r.logout ? `<span class="time-pill time-out"><i data-lucide="log-out" style="width:10px;height:10px;"></i> ${fmtTime(r.logout)}</span><br><span style="font-size:.68rem;color:var(--ink3);">${fmtDate(r.logout)}</span>` : '<span style="color:var(--ink3);">—</span>'}</td>
          <td>${r.login && r.logout ? `<span class="time-pill time-dur"><i data-lucide="timer" style="width:10px;height:10px;"></i> ${fmtDuration(r.login, r.logout)}</span>` : '<span style="color:var(--ink3);">—</span>'}</td>
          <td>${statusPill(r)}</td>
        </tr>
      `).join('');
            lucide.createIcons();
        }
    }

    // ── Mobile cards ───────────────────────────────────────────
    const mobCont = document.getElementById('mobileCardContainer');
    const noM     = document.getElementById('noResultsMobile');
    if (mobCont) {
        if (!slice.length) {
            mobCont.innerHTML = '';
            noM.style.display = '';
        } else {
            noM.style.display = 'none';
            mobCont.innerHTML = slice.map(r => `
        <div class="mob-card">
          <div class="mob-card-header">
            <div class="mob-card-title">${r.title}</div>
            ${statusPill(r)}
          </div>
          <div class="mob-card-meta">
            <i data-lucide="map-pin" style="width:10px;height:10px;display:inline;vertical-align:middle;"></i>
            ${r.venue}
            &nbsp;·&nbsp;
            <span class="pill pill-accent" style="font-size:.6rem;padding:.12rem .4rem;">${r.type}</span>
          </div>
          <div class="mob-card-date">
            ${r.login ? `<i data-lucide="calendar" style="width:10px;height:10px;display:inline;vertical-align:middle;"></i> ${fmtDate(r.login)}` : (r.start ? `<i data-lucide="calendar" style="width:10px;height:10px;display:inline;vertical-align:middle;"></i> ${fmtDate(r.start)}` : '')}
          </div>
          <div class="mob-card-pills">
            ${r.login  ? `<span class="time-pill time-in"><i data-lucide="log-in" style="width:10px;height:10px;"></i> ${fmtTime(r.login)}</span>` : ''}
            ${r.logout ? `<span class="time-pill time-out"><i data-lucide="log-out" style="width:10px;height:10px;"></i> ${fmtTime(r.logout)}</span>` : ''}
            ${r.login && r.logout ? `<span class="time-pill time-dur"><i data-lucide="timer" style="width:10px;height:10px;"></i> ${fmtDuration(r.login, r.logout)}</span>` : ''}
          </div>
        </div>
      `).join('');
            lucide.createIcons();
        }
    }

    // ── Pagination ─────────────────────────────────────────────
    const ctrl = document.getElementById('paginationControls');
    if (ctrl) {
        const range = 2;
        let html = `<button class="pg-btn" onclick="goPage(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}><i data-lucide="chevron-left" style="width:12px;height:12px;"></i></button>`;
        for (let p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || Math.abs(p - currentPage) <= range) {
                html += `<button class="pg-btn ${p === currentPage ? 'active' : ''}" onclick="goPage(${p})">${p}</button>`;
            } else if (Math.abs(p - currentPage) === range + 1) {
                html += `<span style="padding:0 .25rem;color:var(--ink3);font-size:.8rem;">…</span>`;
            }
        }
        html += `<button class="pg-btn" onclick="goPage(${currentPage + 1})" ${currentPage >= pages ? 'disabled' : ''}><i data-lucide="chevron-right" style="width:12px;height:12px;"></i></button>`;
        ctrl.innerHTML = html;
        lucide.createIcons();
    }
}

function goPage(p) {
    currentPage = p;
    renderTable();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function clearAllFilters() {
    ['searchInput', 'typeFilter', 'statusFilter', 'dateFrom', 'dateTo'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    applyFilters();
}

// ── Event listeners ────────────────────────────────────────────
['searchInput', 'typeFilter', 'statusFilter', 'dateFrom', 'dateTo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener(id === 'searchInput' ? 'input' : 'change', applyFilters);
});

document.getElementById('clearFilters')?.addEventListener('click', clearAllFilters);
document.getElementById('perPageSelect')?.addEventListener('change', function () {
    perPage = parseInt(this.value);
    currentPage = 1;
    renderTable();
});

document.querySelectorAll('.sortable-col').forEach(th => {
    th.addEventListener('click', () => {
        const col = th.dataset.col;
        if (sortCol === col) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        else { sortCol = col; sortDir = 'asc'; }
        applyFilters();
    });
});

// ── Export CSV ─────────────────────────────────────────────────
document.getElementById('exportCsvBtn')?.addEventListener('click', () => {
    const headers = ['Event', 'Type', 'Venue', 'Organizer', 'Check-In Date', 'Check-In Time', 'Check-Out Date', 'Check-Out Time', 'Duration', 'Status'];
    const rows = filtered.map(r => [
        `"${(r.title     || '').replace(/"/g, '""')}"`,
        r.type     || '',
        r.venue    || '',
        `"${(r.organizer || '').replace(/"/g, '""')}"`,
        fmtDate(r.login),  fmtTime(r.login),
        fmtDate(r.logout), fmtTime(r.logout),
        fmtDuration(r.login, r.logout),
        getStatus(r)
    ].join(','));
    const csv = [headers.join(','), ...rows].join('\n');
    const a   = document.createElement('a');
    a.href     = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'attendance_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
});

// ── Init ───────────────────────────────────────────────────────
applyFilters();