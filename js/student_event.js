// student_event.js

lucide.createIcons();

let pendingEventId = null;

// ── Dark mode ──────────────────────────────────────────────────
const html = document.documentElement;

function applyDark(on) {
    html.classList.toggle('dark', on);
    ['sunD', 'sunIconM'].forEach(id => { const e = document.getElementById(id); if (e) e.style.display = on ? 'block' : 'none'; });
    ['moonD', 'moonIconM'].forEach(id => { const e = document.getElementById(id); if (e) e.style.display = on ? 'none' : 'block'; });
}

const stored = localStorage.getItem('sems-dark');
const sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
applyDark(stored !== null ? stored === 'true' : sysDark);

const dBtn = document.getElementById('desktopDarkBtn');
if (dBtn) dBtn.style.display = 'inline-flex';

function toggleDark() {
    const on = html.classList.toggle('dark');
    localStorage.setItem('sems-dark', on);
    applyDark(on);
}

// ── Sidebar ────────────────────────────────────────────────────
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('overlay');

function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
}

// ── Modal helpers ──────────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); lucide.createIcons(); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-wrap').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-wrap').forEach(m => m.classList.remove('open'));
});

// ── Details modal ──────────────────────────────────────────────
function openDetailsModal(btn) {
    const card   = btn.closest('.event-card');
    const status = card.dataset.status;
    document.getElementById('detailsTitle').textContent    = card.dataset.title;
    document.getElementById('detailsType').textContent     = card.dataset.type;
    document.getElementById('detailsDate').textContent     = card.dataset.date + ' at ' + card.dataset.time.split('–')[0].trim();
    document.getElementById('detailsEnd').textContent      = card.dataset.end;
    document.getElementById('detailsVenue').textContent    = 'Venue: ' + card.dataset.venue;
    document.getElementById('detailsOrganizer').textContent = card.dataset.organizer;
    const badge = document.getElementById('detailsBadge');
    badge.className  = 'ev-status ' + ({ OPEN:'open', JOINED:'joined', REQUIRED:'required', FULL:'full' }[status] || '');
    badge.textContent = status;
    openModal('detailsModal');
}

// ── Register modal ─────────────────────────────────────────────
function openRegisterModal(btn) {
    const card = btn.closest('.event-card');
    pendingEventId = card.dataset.id;
    document.getElementById('registerEventName').textContent = card.dataset.title;
    openModal('registerModal');
}

function submitRegister() {
    if (!pendingEventId) return;
    const btn = document.getElementById('confirmRegisterBtn');
    btn.innerHTML = '<i data-lucide="loader-2" style="width:14px;height:14px;display:inline;margin-right:.3rem;animation:spin .7s linear infinite;"></i>Registering…';
    btn.disabled  = true;
    lucide.createIcons();
    sessionStorage.setItem('lastRegisteredEvent', document.getElementById('registerEventName').textContent);
    document.getElementById('registerEventId').value = pendingEventId;
    document.getElementById('registerForm').submit();
}

// ── Toast ──────────────────────────────────────────────────────
function showToast(msg) {
    const t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

// ── URL param handling ─────────────────────────────────────────
const params = new URLSearchParams(window.location.search);
if (params.get('registered') === '1') {
    const last = sessionStorage.getItem('lastRegisteredEvent') || '';
    document.getElementById('successEventName').textContent = last;
    sessionStorage.removeItem('lastRegisteredEvent');
    openModal('successModal');
}
if (params.get('error') === 'duplicate') showToast('You are already registered for this event.');
if (params.get('error') === '1')         showToast('Something went wrong. Please try again.');
if (params.get('error') === 'full')      showToast('Sorry, this event is already full.');


/* ══════════════════════════════════════════════════════════════
   PANEL SWITCHING — Events vs Announcements
══════════════════════════════════════════════════════════════ */
const eventsSection = document.getElementById('eventsSection');
const annPanel      = document.getElementById('annPanel');
const sortSelect    = document.getElementById('sortSelect');
const noResults     = document.getElementById('noResults');

function showEventsPanel() {
    if (eventsSection) eventsSection.style.display = '';
    if (annPanel)      annPanel.classList.remove('visible');
    if (sortSelect)    sortSelect.style.display = '';
    if (noResults)     noResults.style.display  = '';
}

function showAnnPanel() {
    if (eventsSection) eventsSection.style.display = 'none';
    if (annPanel)      annPanel.classList.add('visible');
    if (sortSelect)    sortSelect.style.display = 'none'; // sort doesn't apply to announcements
    if (noResults)     noResults.style.display  = 'none';
    // Run announcement filter using whatever is already in the shared search input
    filterAnnouncements();
    lucide.createIcons();
}


/* ══════════════════════════════════════════════════════════════
   ANNOUNCEMENT SEARCH
   Reads from the shared #searchInput — no duplicate field needed.
══════════════════════════════════════════════════════════════ */
function filterAnnouncements() {
    const q       = (document.getElementById('searchInput')?.value || '').trim().toLowerCase();
    const items   = document.querySelectorAll('.ann-item');
    const noMatch = document.getElementById('annNoMatch');
    let visible   = 0;

    items.forEach(item => {
        const haystack = [item.dataset.title, item.dataset.body, item.dataset.source].join(' ');
        const show     = !q || haystack.includes(q);
        item.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    if (noMatch) noMatch.style.display = visible === 0 && q ? '' : 'none';
}


/* ══════════════════════════════════════════════════════════════
   EVENTS — SEARCH + FILTER + SORT + PAGINATION ENGINE
══════════════════════════════════════════════════════════════ */
const CARDS_PER_PAGE = 6;

let allCards     = [];
let activeFilter = 'ALL';
let searchQuery  = '';
let sortMode     = 'date-asc';
let currentPage  = 1;

function initEngine() {
    const grid = document.getElementById('eventsGrid');

    allCards = grid ? Array.from(grid.querySelectorAll('.event-card')) : [];

    // Shared search input — handles both events and announcements
    const searchInput = document.getElementById('searchInput');
    const clearBtn    = document.getElementById('clearSearch');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim().toLowerCase();
            clearBtn?.classList.toggle('visible', q.length > 0);

            if (activeFilter === 'ANNOUNCEMENTS') {
                // filterAnnouncements reads directly from #searchInput — no mirroring needed
                filterAnnouncements();
            } else {
                searchQuery = q;
                currentPage = 1;
                render();
            }
        });

        clearBtn?.addEventListener('click', () => {
            searchInput.value = '';
            clearBtn.classList.remove('visible');
            searchQuery = '';
            filterAnnouncements(); // clears ann results too if panel is visible
            currentPage = 1;
            render();
        });
    }

    // Filter pills
    document.querySelectorAll('.filter-pill').forEach(pill => {
        pill.addEventListener('click', () => {
            activeFilter = pill.dataset.filter;
            document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');

            if (activeFilter === 'ANNOUNCEMENTS') {
                showAnnPanel(); // filterAnnouncements is called inside showAnnPanel
            } else {
                showEventsPanel();
                currentPage = 1;
                render();
            }
        });
    });

    // Sort
    if (sortSelect) {
        sortSelect.addEventListener('change', () => {
            sortMode = sortSelect.value;
            currentPage = 1;
            render();
        });
    }

    render();
}

function getFilteredSorted() {
    let list = allCards.slice();

    if (activeFilter !== 'ALL' && activeFilter !== 'ANNOUNCEMENTS') {
        list = list.filter(c => c.dataset.status === activeFilter);
    }

    if (searchQuery) {
        list = list.filter(c => {
            const hay = [c.dataset.title, c.dataset.venue, c.dataset.organizer, c.dataset.type].join(' ').toLowerCase();
            return hay.includes(searchQuery);
        });
    }

    list.sort((a, b) => {
        switch (sortMode) {
            case 'date-asc':   return +a.dataset.startTs - +b.dataset.startTs;
            case 'date-desc':  return +b.dataset.startTs - +a.dataset.startTs;
            case 'title-asc':  return a.dataset.title.localeCompare(b.dataset.title);
            case 'title-desc': return b.dataset.title.localeCompare(a.dataset.title);
            default: return 0;
        }
    });

    return list;
}

function render() {
    if (activeFilter === 'ANNOUNCEMENTS') return; // announcements panel handles itself

    const filtered  = getFilteredSorted();
    const total     = filtered.length;
    const totalPgs  = Math.max(1, Math.ceil(total / CARDS_PER_PAGE));
    if (currentPage > totalPgs) currentPage = totalPgs;

    const start     = (currentPage - 1) * CARDS_PER_PAGE;
    const end       = start + CARDS_PER_PAGE;
    const pageCards = filtered.slice(start, end);

    allCards.forEach(c => c.style.display = 'none');
    pageCards.forEach(c => c.style.display = '');

    const grid = document.getElementById('eventsGrid');
    if (grid) pageCards.forEach(c => grid.appendChild(c));

    lucide.createIcons();

    const nr = document.getElementById('noResults');
    if (nr) nr.classList.toggle('visible', total === 0);

    buildPaginator(currentPage, totalPgs, total, start, end);
}

function buildPaginator(page, totalPgs, total, start, end) {
    const pag  = document.getElementById('paginator');
    const info = document.getElementById('pageInfo');
    if (!pag) return;

    if (totalPgs <= 1) {
        pag.innerHTML = '';
        if (info) info.textContent = total > 0 ? `Showing all ${total} event${total !== 1 ? 's' : ''}` : '';
        return;
    }

    let h = `<button class="page-btn" onclick="goPage(${page - 1})" ${page === 1 ? 'disabled' : ''} aria-label="Previous page">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  </button>`;

    paginationRange(page, totalPgs).forEach(p => {
        if (p === '…') {
            h += `<button class="page-btn ellipsis">…</button>`;
        } else {
            h += `<button class="page-btn${p === page ? ' active' : ''}" onclick="goPage(${p})" aria-label="Page ${p}" ${p === page ? 'aria-current="page"' : ''}>${p}</button>`;
        }
    });

    h += `<button class="page-btn" onclick="goPage(${page + 1})" ${page === totalPgs ? 'disabled' : ''} aria-label="Next page">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
  </button>`;

    pag.innerHTML = h;
    if (info) info.textContent = `Showing ${start + 1}–${Math.min(end, total)} of ${total} event${total !== 1 ? 's' : ''}`;
}

function paginationRange(current, total) {
    if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
    if (current <= 4)          return [1, 2, 3, 4, 5, '…', total];
    if (current >= total - 3)  return [1, '…', total - 4, total - 3, total - 2, total - 1, total];
    return [1, '…', current - 1, current, current + 1, '…', total];
}

function goPage(p) {
    const filtered = getFilteredSorted();
    const totalPgs = Math.max(1, Math.ceil(filtered.length / CARDS_PER_PAGE));
    if (p < 1 || p > totalPgs) return;
    currentPage = p;
    render();
    const grid = document.getElementById('eventsGrid');
    if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function clearAllFilters() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) searchInput.value = '';
    searchQuery  = '';
    activeFilter = 'ALL';
    currentPage  = 1;
    document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
    document.querySelector('.filter-pill[data-filter="ALL"]')?.classList.add('active');
    document.getElementById('clearSearch')?.classList.remove('visible');
    showEventsPanel();
    render();
}

// ── Init ───────────────────────────────────────────────────────
initEngine();