/* ══════════════════════════════
   SEMS Landing Page — landing.js
══════════════════════════════ */

/* ── Navbar scroll ── */
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
    if (window.scrollY > 50) {
        navbar.classList.add('glass-effect', 'shadow-lg');
    } else {
        navbar.classList.remove('glass-effect', 'shadow-lg');
    }
});

/* ── Mobile menu ── */
const mobileMenuBtn = document.getElementById('mobile-menu-btn');
const mobileMenu    = document.getElementById('mobile-menu');
mobileMenuBtn.addEventListener('click', () => mobileMenu.classList.toggle('hidden'));
mobileMenu.querySelectorAll('a').forEach(l =>
    l.addEventListener('click', () => mobileMenu.classList.add('hidden'))
);

/* ── Scroll reveal ── */
const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('active'); });
}, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
document.querySelectorAll('.scroll-reveal').forEach(el => observer.observe(el));

/* ── Smooth scroll ── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', function (e) {
        e.preventDefault();
        const t = document.querySelector(this.getAttribute('href'));
        if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

/* ── Contact form ── */
document.getElementById('contact-form').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    btn.innerHTML = '<i class="fas fa-check mr-2"></i> Message Sent!';
    btn.style.background = '#10b981';
    setTimeout(() => {
        btn.innerHTML = 'Send Message';
        btn.style.background = '';
        this.reset();
    }, 3000);
});

/* ── Parallax floating shapes ── */
document.addEventListener('mousemove', (e) => {
    const shapes = document.querySelectorAll('.animate-float, .animate-float-delayed');
    const x = e.clientX / window.innerWidth;
    const y = e.clientY / window.innerHeight;
    shapes.forEach((shape, i) => {
        const speed = (i + 1) * 10;
        shape.style.transform = `translate(${(0.5 - x) * speed}px, ${(0.5 - y) * speed}px)`;
    });
});

/* ══════════════════════════════
   DEMO MODAL SLIDESHOW
══════════════════════════════ */
const SLIDE_DURATION = 8000;
const modal       = document.getElementById('demo-modal');
const openBtn     = document.getElementById('open-demo');
const closeBtn    = document.getElementById('close-modal');
const muteBtn     = document.getElementById('mute-btn');
const slides      = document.querySelectorAll('.demo-slide');
const counter     = document.getElementById('slide-counter');
const pbContainer = document.getElementById('progress-bars');
const total       = slides.length;
let current       = 0;
let autoTimer     = null;
let isMuted       = false;
let regAnimDone   = false;

/* ── Voice Narration ── */
const NARRATION = [
    "Welcome to SEMS — the Student Event Management System. Manage campus events, registrations, and attendance all in one platform.",
    "This is the Event Registration form. Notice the required field validation. Watch as a student fills in their details and submits the form successfully.",
    "Each registered student receives a unique QR code for instant attendance check-in. Scanning takes less than one second and is fully tamper-proof.",
    "The Analytics Dashboard gives organizers and admins a real-time overview of registrations, attendance rates, and monthly trends.",
    "SEMS supports three powerful roles: Students, Organizers, and Administrators — each with their own tailored set of tools and permissions."
];

function speak(text) {
    if (isMuted || !window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    const utter    = new SpeechSynthesisUtterance(text);
    utter.rate     = 0.92;
    utter.pitch    = 1.05;
    utter.volume   = 1;
    const voices   = window.speechSynthesis.getVoices();
    const preferred = voices.find(v => v.lang.startsWith('en') && v.name.toLowerCase().includes('natural'))
                   || voices.find(v => v.lang === 'en-US')
                   || voices[0];
    if (preferred) utter.voice = preferred;
    window.speechSynthesis.speak(utter);
}

muteBtn.addEventListener('click', () => {
    isMuted = !isMuted;
    muteBtn.innerHTML = isMuted
        ? '<i class="fas fa-volume-mute text-sm"></i>'
        : '<i class="fas fa-volume-up text-sm"></i>';
    muteBtn.title = isMuted ? 'Unmute narration' : 'Mute narration';
    if (isMuted) window.speechSynthesis.cancel();
});

/* ── Registration Animation (Slide 2) ── */
function resetRegForm() {
    regAnimDone = false;

    const badge = document.getElementById('event-badge');
    if (badge) {
        badge.textContent  = 'OPEN';
        badge.className    = 'ml-2 flex-shrink-0 px-2.5 py-0.5 rounded-full text-xs font-bold border border-green-400 text-green-600 bg-green-50';
    }

    const regBtn = document.getElementById('reg-open-btn');
    if (regBtn) {
        regBtn.innerHTML  = '<i class="fas fa-check-circle"></i> Register';
        regBtn.className  = 'flex-1 py-2.5 rounded-xl bg-green-500 hover:bg-green-600 text-white font-bold text-sm flex items-center justify-center gap-2 transition-all shadow-md shadow-green-200';
    }

    ['reg-confirm-dialog', 'reg-success-overlay'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.classList.add('hidden'); el.style.display = ''; }
    });

    const cap = document.getElementById('reg-caption');
    if (cap) {
        cap.textContent = 'Students browse open events and register with a single tap.';
        cap.className   = 'text-gray-400 text-xs text-center mt-1 max-w-sm';
    }
}

function runRegAnimation() {
    if (regAnimDone) return;
    regAnimDone = true;

    const caption    = document.getElementById('reg-caption');
    const confirmDlg = document.getElementById('reg-confirm-dialog');
    const dialogBox  = document.getElementById('reg-dialog-box');
    const successOvl = document.getElementById('reg-success-overlay');
    const regBtn     = document.getElementById('reg-open-btn');

    // Step 1 – simulate tap on Register button
    setTimeout(() => {
        if (regBtn) regBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening…';
        if (caption) caption.textContent = 'Tapping Register…';
    }, 1000);

    // Step 2 – show confirmation dialog
    setTimeout(() => {
        if (regBtn) regBtn.innerHTML = '<i class="fas fa-check-circle"></i> Register';
        confirmDlg.classList.remove('hidden');
        confirmDlg.style.display = 'flex';
        requestAnimationFrame(() => requestAnimationFrame(() => {
            dialogBox.style.transform = 'scale(1)';
        }));
        if (caption) caption.textContent = 'A confirmation dialog appears — Continue?';
    }, 2000);

    // Step 3 – simulate "Yes, Register" click
    setTimeout(() => {
        const yesBtn = document.getElementById('reg-confirm-btn');
        if (yesBtn) { yesBtn.textContent = 'Registering…'; yesBtn.disabled = true; }
        if (caption) caption.textContent = 'Confirming registration…';
    }, 3600);

    // Step 4 – show success overlay
    setTimeout(() => {
        confirmDlg.classList.add('hidden');
        successOvl.classList.remove('hidden');
        successOvl.style.display = 'flex';

        const badge = document.getElementById('event-badge');
        if (badge) {
            badge.textContent = 'JOINED';
            badge.className   = 'ml-2 flex-shrink-0 px-2.5 py-0.5 rounded-full text-xs font-bold border border-blue-400 text-blue-600 bg-blue-50';
        }
        if (caption) {
            caption.textContent = '🎉 Registered! QR code sent to your email.';
            caption.className   = 'text-green-400 text-xs text-center mt-1 max-w-sm font-semibold';
        }
    }, 4700);

    // Cancel button inside dialog
    const cancelBtn = document.getElementById('reg-cancel-btn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            confirmDlg.classList.add('hidden');
            if (caption) caption.textContent = 'Students browse open events and register with a single tap.';
        });
    }
}

/* ── Progress Bars ── */
function buildProgressBars() {
    pbContainer.innerHTML = '';
    for (let i = 0; i < total; i++) {
        const wrap = document.createElement('div');
        wrap.className = 'flex-1 h-1 rounded-full bg-white/30 overflow-hidden';
        const bar = document.createElement('div');
        bar.className  = 'h-full bg-white rounded-full';
        bar.style.width = i < current ? '100%' : '0%';
        wrap.appendChild(bar);
        pbContainer.appendChild(wrap);
    }
}

function animateCurrentBar() {
    const bars = pbContainer.querySelectorAll('.h-full');
    if (!bars[current]) return;
    const bar = bars[current];
    bar.style.transition = 'none';
    bar.style.width = '0%';
    requestAnimationFrame(() => requestAnimationFrame(() => {
        bar.style.transition = `width ${SLIDE_DURATION}ms linear`;
        bar.style.width = '100%';
    }));
}

function goTo(idx) {
    slides[current].classList.remove('active');
    current = (idx + total) % total;
    slides[current].classList.add('active');
    counter.textContent = `${current + 1} / ${total}`;
    buildProgressBars();
    animateCurrentBar();
    speak(NARRATION[current]);
    if (current === 1) setTimeout(runRegAnimation, 800);
    clearTimeout(autoTimer);
    autoTimer = setTimeout(() => goTo(current + 1), SLIDE_DURATION);
}

/* ── Open / Close Modal ── */
function openModal() {
    current = 0;
    slides.forEach(s => s.classList.remove('active'));
    slides[0].classList.add('active');
    resetRegForm();
    buildProgressBars();
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    clearTimeout(autoTimer);
    if (window.speechSynthesis) window.speechSynthesis.getVoices();
    setTimeout(() => {
        animateCurrentBar();
        speak(NARRATION[0]);
        autoTimer = setTimeout(() => goTo(1), SLIDE_DURATION);
    }, 200);
}

function closeModal() {
    modal.classList.remove('open');
    document.body.style.overflow = '';
    clearTimeout(autoTimer);
    window.speechSynthesis && window.speechSynthesis.cancel();
}

openBtn.addEventListener('click', openModal);
closeBtn.addEventListener('click', closeModal);
modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

document.addEventListener('keydown', (e) => {
    if (!modal.classList.contains('open')) return;
    if (e.key === 'Escape')     closeModal();
    if (e.key === 'ArrowRight') goTo(current + 1);
    if (e.key === 'ArrowLeft')  goTo(current - 1);
});

document.getElementById('next-slide').addEventListener('click', () => goTo(current + 1));
document.getElementById('prev-slide').addEventListener('click', () => goTo(current - 1));