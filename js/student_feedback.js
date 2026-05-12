// student_feedback.js

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

// ── Event select: lock form if already submitted ───────────────
document.getElementById('eventSelect').addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    const alreadyDone = selected && selected.dataset.submitted === '1';
    const alert = document.getElementById('alreadySubmittedAlert');
    const submitBtn = document.getElementById('submitBtn');

    // Show/hide alert
    if (alert) {
        alert.style.display = alreadyDone ? 'block' : 'none';
        if (alreadyDone) lucide.createIcons();
    }

    // Disable/enable all rating selects, textareas, and submit button
    document.querySelectorAll('.rating-select').forEach(s => {
        s.disabled = alreadyDone;
        s.style.opacity = alreadyDone ? '.5' : '1';
        s.style.cursor  = alreadyDone ? 'not-allowed' : '';
    });
    document.querySelectorAll('.comment-textarea').forEach(t => {
        t.disabled = alreadyDone;
        t.style.opacity = alreadyDone ? '.5' : '1';
        t.style.cursor  = alreadyDone ? 'not-allowed' : '';
    });

    submitBtn.disabled = alreadyDone || !this.value;
});

// ── Toast ──────────────────────────────────────────────────────
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.className = 'toast ' + (type === 'success' ? 'toast-success' : 'toast-error');
    toast.innerHTML = `<i data-lucide="${type === 'success' ? 'check-circle' : 'x-circle'}" style="width:17px;height:17px;flex-shrink:0;"></i><span>${message}</span>`;
    lucide.createIcons();
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3500);
}

// ── Clear form ─────────────────────────────────────────────────
function clearForm() {
    document.getElementById('eventSelect').selectedIndex = 0;
    document.querySelectorAll('.rating-select').forEach(s => s.selectedIndex = 0);
    document.querySelectorAll('.comment-textarea').forEach(t => t.value = '');
    showToast('Form cleared', 'success');
}

// ── Submit feedback ────────────────────────────────────────────
function submitFeedback() {
    const eventId = document.getElementById('eventSelect').value;
    const submitBtn = document.getElementById('submitBtn');

    if (!eventId) {
        showToast('Please select an event', 'error');
        const sel = document.getElementById('eventSelect');
        sel.classList.add('anim-shake');
        setTimeout(() => sel.classList.remove('anim-shake'), 500);
        return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i data-lucide="loader-2" style="width:14px;height:14px;animation:spin .7s linear infinite;"></i> Submitting…';
    lucide.createIcons();

    const ratings = {};
    const comments = {};
    document.querySelectorAll('.rating-select').forEach(s => { if (s.value) ratings[s.dataset.cat] = s.value; });
    document.querySelectorAll('.comment-textarea').forEach(t => { comments[t.dataset.cat] = t.value; });

    const ratingsStr = Object.entries(ratings).map(([k, v]) => `ratings[${k}]=${encodeURIComponent(v)}`).join('&');
    const commentsStr = Object.entries(comments).map(([k, v]) => `comments[${k}]=${encodeURIComponent(v)}`).join('&');

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=submit_feedback&event_id=' + encodeURIComponent(eventId) + '&' + ratingsStr + '&' + commentsStr
    })
        .then(r => r.json())
        .then(res => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i data-lucide="send" style="width:14px;height:14px;"></i> Submit Feedback';
            lucide.createIcons();
            if (res.status === 'success') {
                showToast(res.msg, 'success');
                setTimeout(() => location.reload(), 2000);
            } else if (res.status === 'already_submitted') {
                showToast(res.msg, 'success');
            } else {
                showToast(res.msg || 'Error submitting feedback', 'error');
            }
        })
        .catch(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i data-lucide="send" style="width:14px;height:14px;"></i> Submit Feedback';
            lucide.createIcons();
            showToast('Error submitting feedback', 'error');
        });
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

// ── Spin keyframe (for submit loader) ──────────────────────────
const style = document.createElement('style');
style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(style);