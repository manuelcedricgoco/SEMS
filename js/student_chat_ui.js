/* ============================================================
 *  student_chat_ui.js
 *  UI helpers for student_chat.php
 *
 *  Dark-mode strategy is intentionally aligned with
 *  student_dashboard.js:
 *    key   : 'sems-dark'
 *    values: 'true' | 'false'   (string, matching dashboard)
 *    init  : falls back to prefers-color-scheme if key absent
 *
 *  Load order in student_chat.php:
 *    1. (inline, <head>)  before-paint theme IIFE
 *    2. lucide            (via CDN in <head>)
 *    3. student_chat_ui.js  ← this file
 *    4. sems_chat.js      (main messaging engine)
 *    5. (inline, body)    lucide.createIcons()
 * ============================================================ */

'use strict';

// ──────────────────────────────────────────────────────────────
// SIDEBAR
// ──────────────────────────────────────────────────────────────
function openSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  if (sidebar) sidebar.style.transform = 'translateX(0)';
  if (overlay) overlay.classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  if (sidebar && window.innerWidth < 1024)
    sidebar.style.transform = 'translateX(-100%)';
  if (overlay) overlay.classList.remove('show');
  document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeSidebar();
});

// ──────────────────────────────────────────────────────────────
// DARK MODE — aligned with student_dashboard.js
// ──────────────────────────────────────────────────────────────
const _html = document.documentElement;

/**
 * applyTheme(isDark)
 * Toggles the .dark class and updates the single theme-toggle
 * icon in the chat topbar.
 * We only call lucide.createIcons() when the icon attribute
 * actually changes, not on every repaint.
 */
function applyTheme(isDark) {
  _html.classList.toggle('dark', isDark);

  const icon = document.getElementById('themeIcon');
  if (!icon) return;

  const next = isDark ? 'sun' : 'moon';
  if (icon.getAttribute('data-lucide') !== next) {
    icon.setAttribute('data-lucide', next);
    // Re-render only this icon for performance
    lucide.createIcons({ nameAttr: 'data-lucide', attrs: { 'stroke-width': 1.75 } });
  }
}

/**
 * toggleDark()
 * Called by the topbar button: onclick="toggleDark()"
 * Persists with the same key used by student_dashboard.js.
 */
function toggleDark() {
  const isDark = _html.classList.toggle('dark');
  localStorage.setItem('sems-dark', isDark);
  applyTheme(isDark);
}

// Sync the icon immediately once lucide is available.
// The .dark class itself was already applied by the before-paint
// IIFE in the <head> of student_chat.php.
(function syncThemeIcon() {
  const stored  = localStorage.getItem('sems-dark');
  const sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const isDark  = stored !== null ? stored === 'true' : sysDark;
  const icon    = document.getElementById('themeIcon');
  if (icon) icon.setAttribute('data-lucide', isDark ? 'sun' : 'moon');
  // lucide.createIcons() is called at the bottom of student_chat.php
  // after all scripts load, so no call needed here.
})();

// ──────────────────────────────────────────────────────────────
// THREAD PANEL HELPERS
// ──────────────────────────────────────────────────────────────

/**
 * showList()
 * On mobile, slide the contact-list panel back into view
 * (hides the thread view). Exposed globally so sems_chat.js
 * can call it after opening a thread.
 */
window.showList = function () {
  document.getElementById('listPanel')?.classList.remove('hide');
};

// ──────────────────────────────────────────────────────────────
// IMAGE LIGHTBOX
// ──────────────────────────────────────────────────────────────
function openLightbox(src) {
  const lb  = document.getElementById('imgLightbox');
  const img = document.getElementById('lbImg');
  if (!lb || !img) return;
  img.src = src;
  lb.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeLightbox() {
  const lb = document.getElementById('imgLightbox');
  if (!lb) return;
  lb.classList.remove('open');
  document.body.style.overflow = '';
}

// Close lightbox on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeLightbox();
}, { capture: false });