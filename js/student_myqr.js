// student_myqr.js

lucide.createIcons();

// ── Data from PHP bridge ───────────────────────────────────────
const qrData = SEMS_QR.qrData;

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
    generateQR(qrData);
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

// ── QR Code ────────────────────────────────────────────────────
function generateQR(data) {
    const container = document.getElementById('qrCanvas');
    container.innerHTML = '';
    const isDark = html.classList.contains('dark');
    new QRCode(container, {
        text: data,
        width: 220,
        height: 220,
        correctLevel: QRCode.CorrectLevel.H,
        colorDark: isDark ? '#F0E8D4' : '#2A1A06',
        colorLight: isDark ? '#221A0C' : '#FFFFFF',
    });
}

generateQR(qrData);

new MutationObserver(() => generateQR(qrData))
    .observe(html, { attributes: true, attributeFilter: ['class'] });

// ── Download ───────────────────────────────────────────────────
function downloadQR() {
    const canvas = document.querySelector('#qrCanvas canvas');
    if (canvas) {
        const link = document.createElement('a');
        link.download = 'sems-qr-code.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    }
}

// ── Scroll reveal ──────────────────────────────────────────────
const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
        if (e.isIntersecting) {
            e.target.style.animationPlayState = 'running';
            io.unobserve(e.target);
        }
    });
}, { threshold: 0.05 });

document.querySelectorAll('.anim-up, .anim-card').forEach(el => {
    el.style.animationPlayState = 'paused';
    io.observe(el);
});