/**
 * SEMS Admin — admin_settings.js
 * Handles: Feather Icons, Live Date, Dark Mode,
 *          Sidebar, Search, Tab Switching,
 *          Avatar Preview, Password Visibility,
 *          Password Strength, Password Match
 *
 * No data bridge required — this file has no PHP dependencies.
 */

// ═══════════════════════════════════════════════════════════════
// FEATHER ICONS
// ═══════════════════════════════════════════════════════════════

feather.replace({ 'stroke-width': 2 });

// Re-run after a short delay to catch any icons rendered late
setTimeout(function () {
    feather.replace({ 'stroke-width': 2 });
}, 100);

// ═══════════════════════════════════════════════════════════════
// LIVE DATE
// ═══════════════════════════════════════════════════════════════

(function () {
    var el   = document.getElementById('current-date');
    var opts = { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' };
    if (el) el.textContent = new Date().toLocaleDateString('en-PH', opts);
})();

// ═══════════════════════════════════════════════════════════════
// DARK MODE
// ═══════════════════════════════════════════════════════════════

function applyDark(on) {
    document.documentElement.classList.toggle('dark', on);
    localStorage.setItem('sems-theme', on ? 'dark' : 'light');

    var icon  = document.getElementById('theme-icon');
    var label = document.getElementById('theme-label');
    if (icon) {
        icon.classList.toggle('fa-moon', !on);
        icon.classList.toggle('fa-sun',   on);
        if (on) {
            icon.className = 'fas fa-sun w-5 text-center text-amber-500';
        } else {
            icon.className = 'fas fa-moon w-5 text-center';
        }
    }
    if (label) label.textContent = on ? 'Light Mode' : 'Dark Mode';
}

function toggleTheme() {
    applyDark(!document.documentElement.classList.contains('dark'));
}

// Restore saved theme on load
(function () {
    var theme = localStorage.getItem('sems-theme') || 'light';
    var icon  = document.getElementById('theme-icon');
    var label = document.getElementById('theme-label');
    if (theme === 'dark') {
        document.documentElement.classList.add('dark');
        if (icon)  icon.className    = 'fas fa-sun w-5 text-center text-amber-500';
        if (label) label.textContent = 'Light Mode';
    }
})();

// ═══════════════════════════════════════════════════════════════
// SIDEBAR (MOBILE)
// ═══════════════════════════════════════════════════════════════

function openSidebar() {
    var sb = document.getElementById('sidebar');
    sb.classList.remove('-translate-x-full');
    sb.classList.add('translate-x-0');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function (e) {
    var sb     = document.getElementById('sidebar');
    var toggle = document.querySelector('[onclick="openSidebar()"]');
    if (
        window.innerWidth < 1024 &&
        sb &&
        !sb.contains(e.target) &&
        (!toggle || !toggle.contains(e.target))
    ) {
        sb.classList.add('-translate-x-full');
        sb.classList.remove('translate-x-0');
    }
});

// ═══════════════════════════════════════════════════════════════
// SEARCH
// ═══════════════════════════════════════════════════════════════

function handleSearch() {
    var query = document.getElementById('searchInput').value.toLowerCase();
    console.log('Search query:', query);
}

// ═══════════════════════════════════════════════════════════════
// TAB SWITCHING
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.card-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            // Deactivate all tabs
            document.querySelectorAll('.card-tab').forEach(function (t) {
                t.classList.remove('active', 'text-blue-600', 'dark:text-blue-400', 'border-blue-600', 'dark:border-blue-400');
                t.classList.add('text-slate-500', 'dark:text-slate-400', 'border-transparent');
                t.setAttribute('aria-selected', 'false');
            });
            // Hide all panels
            document.querySelectorAll('.tab-panel').forEach(function (p) {
                p.classList.remove('active');
            });

            // Activate clicked tab
            tab.classList.add('active', 'text-blue-600', 'dark:text-blue-400', 'border-blue-600', 'dark:border-blue-400');
            tab.classList.remove('text-slate-500', 'dark:text-slate-400', 'border-transparent');
            tab.setAttribute('aria-selected', 'true');

            // Show corresponding panel
            var panel = document.getElementById('tab-' + tab.dataset.tab);
            if (panel) panel.classList.add('active');
        });
    });
});

// ═══════════════════════════════════════════════════════════════
// AVATAR PREVIEW + AUTO-SUBMIT
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    var photoInput = document.getElementById('photoInput');
    var avatarPrev = document.getElementById('avatarPreview');
    var avatarInit = document.getElementById('avatarInitials');

    if (photoInput) {
        photoInput.addEventListener('change', function () {
            if (!this.files || !this.files[0]) return;

            var reader = new FileReader();
            reader.onload = function (ev) {
                if (avatarInit) avatarInit.classList.add('hidden');
                if (avatarPrev) {
                    avatarPrev.src = ev.target.result;
                    avatarPrev.classList.remove('hidden');
                    avatarPrev.style.display = 'block';
                }
            };
            reader.readAsDataURL(this.files[0]);

            document.getElementById('photoForm').submit();
        });
    }
});

// ═══════════════════════════════════════════════════════════════
// PASSWORD VISIBILITY TOGGLE
// ═══════════════════════════════════════════════════════════════

function togglePwVisibility(inputId, btn) {
    var input    = document.getElementById(inputId);
    var showIcon = btn.querySelector('.eye-show');
    var hideIcon = btn.querySelector('.eye-hide');

    if (input.type === 'password') {
        input.type = 'text';
        if (showIcon) showIcon.classList.add('hidden');
        if (hideIcon) hideIcon.classList.remove('hidden');
    } else {
        input.type = 'password';
        if (showIcon) showIcon.classList.remove('hidden');
        if (hideIcon) hideIcon.classList.add('hidden');
    }
}

// ═══════════════════════════════════════════════════════════════
// PASSWORD STRENGTH METER
// ═══════════════════════════════════════════════════════════════

function checkPwStrength(pw) {
    var bar   = document.getElementById('strengthBar');
    var label = document.getElementById('strengthLabel');
    if (!bar || !label) return;

    var score = 0;
    if (pw.length >= 8)          score++;
    if (/[A-Z]/.test(pw))        score++;
    if (/[0-9]/.test(pw))        score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    var levels = [
        { w: '0%',   bg: '#e5e7eb', text: 'Enter a new password' },
        { w: '25%',  bg: '#ef4444', text: 'Weak'   },
        { w: '50%',  bg: '#f59e0b', text: 'Fair'   },
        { w: '75%',  bg: '#3b82f6', text: 'Good'   },
        { w: '100%', bg: '#22c55e', text: 'Strong' },
    ];

    var lvl = pw.length === 0 ? levels[0] : levels[Math.min(score, 4)];
    bar.style.width      = lvl.w;
    bar.style.background = lvl.bg;
    label.textContent    = lvl.text;
    label.style.color    = lvl.bg;
}

// ═══════════════════════════════════════════════════════════════
// PASSWORD MATCH INDICATOR
// ═══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function () {
    var newPwInput   = document.getElementById('new_password');
    var confirmInput = document.getElementById('confirm_password');
    var matchLabel   = document.getElementById('matchLabel');

    function checkMatch() {
        if (!matchLabel || !confirmInput || !newPwInput) return;
        if (!confirmInput.value) { matchLabel.textContent = ''; return; }

        if (confirmInput.value === newPwInput.value) {
            matchLabel.textContent = '✓ Passwords match';
            matchLabel.style.color = '#22c55e';
        } else {
            matchLabel.textContent = '✗ Passwords do not match';
            matchLabel.style.color = '#ef4444';
        }
    }

    if (confirmInput) confirmInput.addEventListener('input', checkMatch);
    if (newPwInput)   newPwInput.addEventListener('input',   checkMatch);
});