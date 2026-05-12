// student_dashboard.js

lucide.createIcons();

// ── Data from PHP bridge ───────────────────────────────────────
const ATTENDANCE_RATE = SEMS_DASHBOARD.attendanceRate;
const TOTAL_EVENTS    = SEMS_DASHBOARD.totalEvents;

// ── Theme ──────────────────────────────────────────────────────
const htmlEl = document.documentElement;

function applyTheme(isDark) {
  htmlEl.classList.toggle('dark', isDark);
  const sunEls  = [document.getElementById('sunIcon'),  document.getElementById('sunIconM')];
  const moonEls = [document.getElementById('moonIcon'), document.getElementById('moonIconM')];
  sunEls.forEach(el  => { if (el) el.style.display = isDark ? 'block' : 'none'; });
  moonEls.forEach(el => { if (el) el.style.display = isDark ? 'none'  : 'block'; });
}

(function initTheme() {
  const stored  = localStorage.getItem('sems-dark');
  const sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  applyTheme(stored !== null ? stored === 'true' : sysDark);
  const dt = document.getElementById('darkToggle');
  if (dt) dt.style.display = 'inline-flex';
})();

function toggleDark() {
  const isDark = htmlEl.classList.toggle('dark');
  localStorage.setItem('sems-dark', isDark);
  applyTheme(isDark);
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
  entries.forEach(entry => {
    if (entry.isIntersecting) { entry.target.classList.add('running'); io.unobserve(entry.target); }
  });
}, { threshold: 0.05 });
document.querySelectorAll('.anim').forEach(el => io.observe(el));

// ── Attendance donut ───────────────────────────────────────────
(function initDonut() {
  const arc = document.getElementById('progressArc');
  const pct = document.getElementById('donutPct');
  if (!arc) return;

  requestAnimationFrame(() => {
    setTimeout(() => {
      const circumference = 251.33;
      arc.style.strokeDashoffset = circumference * (1 - ATTENDANCE_RATE / 100);
    }, 600);
  });

  if (pct) {
    let current = 0;
    const target = ATTENDANCE_RATE, duration = 1200, start = performance.now();
    function tick(now) {
      const elapsed = now - start;
      current = Math.min(Math.round((elapsed / duration) * target), target);
      pct.textContent = current + '%';
      if (current < target) requestAnimationFrame(tick);
    }
    setTimeout(() => requestAnimationFrame(tick), 500);
  }
})();

// ── Filter: registered events ──────────────────────────────────
function filterEvents() {
  const query   = document.getElementById('eventSearch').value.toLowerCase().trim();
  const status  = document.getElementById('statusFilter').value;
  const type    = document.getElementById('typeFilter').value;
  const rows    = document.querySelectorAll('.event-row-item');
  const noMatch = document.getElementById('noMatchState');
  const countEl = document.getElementById('visibleCount');
  const clearBtn = document.getElementById('clearFilters');
  const badge   = document.getElementById('activeFilterBadge');

  let visible = 0;
  rows.forEach(row => {
    const textMatch   = !query  || row.dataset.title.includes(query) || row.dataset.venue.includes(query) || row.dataset.organizer.includes(query);
    const statusMatch = status  === 'all' || row.dataset.status === status;
    const typeMatch   = type    === 'all' || row.dataset.type   === type;
    const show        = textMatch && statusMatch && typeMatch;
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  if (noMatch)  noMatch.classList.toggle('visible', visible === 0);
  if (countEl)  countEl.textContent = visible;

  const activeCount = (query ? 1 : 0) + (status !== 'all' ? 1 : 0) + (type !== 'all' ? 1 : 0);
  if (activeCount > 0) {
    clearBtn.classList.add('visible');
    badge.textContent = activeCount + ' filter' + (activeCount > 1 ? 's' : '') + ' active';
    badge.classList.add('visible');
  } else {
    clearBtn.classList.remove('visible');
    badge.classList.remove('visible');
  }
  lucide.createIcons();
}

function clearFilters() {
  document.getElementById('eventSearch').value     = '';
  document.getElementById('statusFilter').value    = 'all';
  document.getElementById('typeFilter').value      = 'all';
  filterEvents();
}

// ── Filter: attendance history ─────────────────────────────────
function filterHistory() {
  const q           = (document.getElementById('historySearch')?.value ?? '').toLowerCase().trim();
  const tableRows   = document.querySelectorAll('.history-row-item');
  const mobileCards = document.querySelectorAll('.history-mobile-item');
  const noMatchRow  = document.getElementById('historyNoMatch');
  const termEl      = document.getElementById('historySearchTerm');
  const mobileNM    = document.getElementById('historyMobileNoMatch');

  let visible = 0;
  function matches(el) {
    return !q || el.dataset.title.includes(q) || el.dataset.venue.includes(q) || el.dataset.type.includes(q);
  }

  tableRows.forEach(row => {
    const show = matches(row);
    row.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  mobileCards.forEach(card => { card.style.display = matches(card) ? '' : 'none'; });

  if (noMatchRow) noMatchRow.style.display = visible === 0 ? 'table-row' : 'none';
  if (termEl)     termEl.textContent = q;
  if (mobileNM)   mobileNM.style.display = (visible === 0 && q) ? 'block' : 'none';
  lucide.createIcons();
}

function clearHistorySearch() {
  const el = document.getElementById('historySearch');
  if (el) { el.value = ''; filterHistory(); }
}