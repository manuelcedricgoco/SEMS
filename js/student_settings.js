// student_settings.js

lucide.createIcons();

// ── Dark mode ──────────────────────────────────────────────────
const html = document.documentElement;

function applyDark(on) {
    html.classList.toggle('dark', on);
    const sunD = document.getElementById('sunD');
    const moonD = document.getElementById('moonD');
    const sunM = document.getElementById('sunIconM');
    const moonM = document.getElementById('moonIconM');
    if (sunD) sunD.style.display = on ? 'block' : 'none';
    if (moonD) moonD.style.display = on ? 'none' : 'block';
    if (sunM) sunM.style.display = on ? 'block' : 'none';
    if (moonM) moonM.style.display = on ? 'none' : 'block';
}

const stored = localStorage.getItem('sems-dark');
const sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
applyDark(stored !== null ? stored === 'true' : sysDark);

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

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

// ── Profile image preview ──────────────────────────────────────
const profileInput = document.getElementById('profile-input');
if (profileInput) {
    profileInput.addEventListener('change', function (e) {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (e) {
            const preview = document.getElementById('avatarPreview');
            if (preview && preview.tagName === 'IMG') {
                preview.src = e.target.result;
            } else if (preview) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:50%;';
                preview.replaceWith(img);
                img.id = 'avatarPreview';
            }
        };
        reader.readAsDataURL(file);
    });
}

// ── Password visibility toggle ─────────────────────────────────
const SVG_EYE_OPEN = '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
const SVG_EYE_CLOSED = '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/>';

function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById('icon-' + fieldId);
    if (!field || !icon) return;
    const isHidden = field.type === 'password';
    field.type = isHidden ? 'text' : 'password';
    icon.innerHTML = isHidden ? SVG_EYE_CLOSED : SVG_EYE_OPEN;
}

// ── Password strength + requirements ───────────────────────────
const newPw = document.getElementById('new_password');
const confPw = document.getElementById('confirm_password');
const curPw = document.getElementById('current_password');

if (newPw && confPw) {
    const strengthBar = document.getElementById('strength-bar');
    const strengthText = document.getElementById('strength-text');
    const matchText = document.getElementById('match-text');
    const submitBtn = document.getElementById('submitBtn');

    const ICON_CIRCLE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
    const ICON_CHECK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.1 9 11.1"/></svg>';

    const requirements = {
        length: { regex: /.{8,}/, el: document.getElementById('req-length') },
        uppercase: { regex: /[A-Z]/, el: document.getElementById('req-uppercase') },
        lowercase: { regex: /[a-z]/, el: document.getElementById('req-lowercase') },
        number: { regex: /[0-9]/, el: document.getElementById('req-number') },
        special: { regex: /[!@#$%^&*(),.?":{}|<>]/, el: document.getElementById('req-special') },
    };

    function updateRequirements(password) {
        let met = 0;
        for (const req of Object.values(requirements)) {
            if (!req.el) continue;
            const ok = req.regex.test(password);
            const icon = req.el.querySelector('.req-icon');
            if (ok) { req.el.classList.add('met'); if (icon) icon.innerHTML = ICON_CHECK; met++; }
            else { req.el.classList.remove('met'); if (icon) icon.innerHTML = ICON_CIRCLE; }
        }
        return met;
    }

    function checkStrength(pw) {
        let s = 0;
        if (pw.length >= 8) s++;
        if (pw.length >= 12) s++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
        if (/[0-9]/.test(pw)) s++;
        if (/[!@#$%^&*(),.?":{}|<>]/.test(pw)) s++;
        return s;
    }

    function updateMeter(pw) {
        updateRequirements(pw);
        if (!pw.length) {
            strengthBar.style.cssText = 'width:0%;background:transparent;';
            strengthText.textContent = 'Enter a password to see strength';
            strengthText.style.color = 'var(--ink3)';
            return;
        }
        const s = checkStrength(pw);
        if (s <= 2) {
            strengthBar.style.cssText = 'width:33%;background:#B04030;';
            strengthText.textContent = 'Weak — add more variety';
            strengthText.style.color = 'var(--rose)';
        } else if (s <= 4) {
            strengthBar.style.cssText = 'width:66%;background:#C07820;';
            strengthText.textContent = 'Medium — good, but could be stronger';
            strengthText.style.color = 'var(--amber)';
        } else {
            strengthBar.style.cssText = 'width:100%;background:var(--green);';
            strengthText.textContent = 'Strong — excellent!';
            strengthText.style.color = 'var(--green)';
        }
        checkAll();
    }

    function checkMatch() {
        const pw = newPw.value, cpw = confPw.value;
        if (cpw.length > 0) {
            matchText.style.display = 'block';
            if (pw === cpw) {
                matchText.textContent = '✓ Passwords match';
                matchText.style.color = 'var(--green)';
                return true;
            } else {
                matchText.textContent = '✗ Passwords do not match';
                matchText.style.color = 'var(--rose)';
                return false;
            }
        }
        matchText.style.display = 'none';
        return false;
    }

    function checkAll() {
        const pw = newPw.value;
        const cpw = confPw.value;
        const cur = curPw ? curPw.value : '';
        const allMet = Object.values(requirements).every(r => r.regex.test(pw));
        submitBtn.disabled = !(allMet && pw === cpw && pw.length > 0 && cur.length > 0);
    }

    newPw.addEventListener('input', function () { updateMeter(this.value); if (confPw.value.length > 0) checkMatch(); checkAll(); });
    confPw.addEventListener('input', function () { checkMatch(); checkAll(); });
    if (curPw) curPw.addEventListener('input', checkAll);
}

// ── Scroll reveal ──────────────────────────────────────────────
const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
        if (e.isIntersecting) { e.target.style.animationPlayState = 'running'; io.unobserve(e.target); }
    });
}, { threshold: 0.05 });

document.querySelectorAll('.anim-up').forEach(el => {
    el.style.animationPlayState = 'paused';
    io.observe(el);
});