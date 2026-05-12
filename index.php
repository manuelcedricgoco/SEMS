<?php
// ============================================================
// LANDING PAGE — SEMS
// Ito ang pangunahing public-facing page ng sistema.
// Dito makikita ng mga bisita ang features, how it works,
// user roles, contact form, at demo modal ng SEMS.
// Hindi kailangan ng login para ma-access ang page na ito.
// ============================================================

// --- Simulan ang session (para malaman kung may naka-login na
//     at magamit sa ibang bahagi ng system kung kinakailangan)
Session_start();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <!-- ============================================================
         META TAGS AT PAGE SETUP
         Basic HTML head: charset, viewport, title, at favicon.
         ============================================================ -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEMS - Student Event Management System</title>

    <!-- Tailwind CSS CDN — utility-first CSS framework -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Favicon ng landing page -->
    <link rel="icon" href="/assets/landing-page-icon-indigo.svg">

    <link rel="stylesheet" href="/CSS/index.css">

    <!-- Font Awesome — ginagamit para sa lahat ng icons sa page -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Google Fonts: Inter (body) + Syne (display/headings) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Syne:wght@700;800&display=swap" rel="stylesheet">

    <!-- ============================================================
         TAILWIND CONFIG
         Dito dini-define ang custom colors, fonts, animations,
         at keyframes na gagamitin sa buong landing page.
         ============================================================ -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans:    ['Inter', 'sans-serif'],
                        display: ['Syne', 'sans-serif'],
                    },
                    colors: {
                        primary:   '#6366f1', // Indigo — pangunahing kulay
                        secondary: '#8b5cf6', // Purple — pangalawang kulay
                        accent:    '#10b981', // Green — para sa success states
                        dark:      '#1e293b', // Dark slate — para sa text at dark bg
                    },
                    animation: {
                        // Floating animation para sa hero blobs at cards
                        'float':          'float 6s ease-in-out infinite',
                        'float-delayed':  'float 6s ease-in-out 3s infinite',

                        // Slide animations para sa hero section content
                        'slide-up':       'slideUp 0.8s ease-out forwards',
                        'slide-down':     'slideDown 0.8s ease-out forwards',
                        'fade-in':        'fadeIn 1s ease-out forwards',

                        // Pulse at bounce para sa visual accents
                        'pulse-slow':     'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'bounce-slow':    'bounce 3s infinite',

                        // Marquee animations para sa infinity loop strips
                        'marquee':         'marquee 30s linear infinite',
                        'marquee-reverse': 'marqueeReverse 30s linear infinite',
                        'marquee-fast':    'marquee 18s linear infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%':      { transform: 'translateY(-20px)' },
                        },
                        slideUp: {
                            '0%':   { opacity: '0', transform: 'translateY(30px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        slideDown: {
                            '0%':   { opacity: '0', transform: 'translateY(-30px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        fadeIn: {
                            '0%':   { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        // Para sa marquee strips — gumagalaw pakaliwa
                        marquee: {
                            '0%':   { transform: 'translateX(0%)' },
                            '100%': { transform: 'translateX(-50%)' },
                        },
                        // Para sa reverse marquee — gumagalaw pakanan
                        marqueeReverse: {
                            '0%':   { transform: 'translateX(-50%)' },
                            '100%': { transform: 'translateX(0%)' },
                        },
                    }
                }
            }
        }
    </script>
</head>
<body class="font-sans antialiased text-gray-800 bg-gray-50 overflow-x-hidden">


<!-- ============================================================
     DEMO MODAL
     Isang full-screen overlay modal na nagpapakita ng 5-slide
     interactive tour ng SEMS. Bubukas kapag nag-click ang user
     sa "Watch Demo" button sa hero section.
     - May progress bars para sa bawat slide
     - May previous/next navigation buttons
     - May mute toggle para sa voice narration (ginagamit ng JS)
     ============================================================ -->
<div id="demo-modal"
     class="fixed inset-0 z-[999] flex items-center justify-center p-4"
     style="background:rgba(15,15,30,0.85); backdrop-filter:blur(8px);">

    <div class="modal-box relative w-full max-w-4xl bg-white rounded-3xl overflow-hidden shadow-2xl">

        <!-- Modal Header: title at close button -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-primary to-secondary">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center">
                    <i class="fas fa-play text-white text-sm"></i>
                </div>
                <span class="font-bold text-white text-lg">SEMS — System Overview</span>
            </div>
            <!-- X button para isara ang modal -->
            <button id="close-modal"
                    class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/40 text-white flex items-center justify-center transition-all">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Mute button para i-toggle ang voice narration ng demo -->
        <div class="absolute top-4 right-16">
            <button id="mute-btn" title="Toggle voice narration"
                    class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/40 text-white flex items-center justify-center transition-all">
                <i class="fas fa-volume-up text-sm"></i>
            </button>
        </div>

        <!-- Slide Container: lahat ng 5 slides ay nandito.
             Isa lang ang may .active class sa isang pagkakataon (ginagamit ng JS). -->
        <div id="demo-slides" class="relative bg-gray-950 min-h-[420px] flex items-center justify-center overflow-hidden">


            <!-- ── SLIDE 1: Welcome Screen ──
                 Pangkalahatang introduction sa SEMS system.
                 Nagpapakita ng feature tags at floating icon. -->
            <div class="demo-slide active w-full h-full flex-col items-center justify-center text-center px-10 py-14 gap-4"
                 style="background:linear-gradient(135deg,#1e1b4b 0%,#312e81 100%);">
                <div class="w-20 h-20 rounded-2xl bg-white/10 flex items-center justify-center text-4xl mb-4 mx-auto animate-float">
                    <i class="fas fa-calendar-check text-indigo-300"></i>
                </div>
                <h2 class="text-3xl font-bold text-white font-display">Welcome to SEMS</h2>
                <p class="text-indigo-200 text-lg max-w-lg mx-auto">Student Event Management System — the all-in-one platform for campus event organising, registration, and attendance.</p>
                <div class="mt-6 flex flex-wrap gap-3 justify-center">
                    <span class="px-4 py-1.5 rounded-full bg-white/10 text-indigo-200 text-sm border border-white/10"><i class="fas fa-users mr-2"></i>Multi-role</span>
                    <span class="px-4 py-1.5 rounded-full bg-white/10 text-indigo-200 text-sm border border-white/10"><i class="fas fa-qrcode mr-2"></i>QR Check-in</span>
                    <span class="px-4 py-1.5 rounded-full bg-white/10 text-indigo-200 text-sm border border-white/10"><i class="fas fa-chart-line mr-2"></i>Analytics</span>
                </div>
            </div>


            <!-- ── SLIDE 2: Event Registration Flow ──
                 Nagpapakita ng animated event card na may confirmation dialog.
                 Ang JS ang nag-trigger ng sequence: Register → Confirm → Success overlay. -->
            <div class="demo-slide w-full h-full flex-col items-center justify-center px-6 py-8 gap-3"
                 style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);">
                <p class="text-blue-300 text-xs font-semibold uppercase tracking-widest mb-1">Event Registration Flow</p>

                <!-- Ang event card na ipinapakita sa estudyante -->
                <div id="reg-card" class="w-full max-w-xs bg-white rounded-2xl shadow-2xl overflow-hidden mx-auto border border-gray-100">
                    <div class="p-5">

                        <!-- Event title at OPEN badge -->
                        <div class="flex items-start justify-between mb-4">
                            <h3 class="font-bold text-gray-900 text-base leading-tight">Annual Tech Summit 2026</h3>
                            <span id="event-badge" class="ml-2 flex-shrink-0 px-2.5 py-0.5 rounded-full text-xs font-bold border border-green-400 text-green-600 bg-green-50">OPEN</span>
                        </div>

                        <!-- Event meta: petsa, venue, organizer, at type -->
                        <div class="space-y-2.5 mb-5">
                            <div class="flex items-start gap-3">
                                <span class="w-7 h-7 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <i class="fas fa-calendar-alt text-blue-500 text-xs"></i>
                                </span>
                                <div>
                                    <div class="text-sm font-semibold text-gray-800">May 15, 2026</div>
                                    <div class="text-xs text-gray-400">09:00 AM – 05:00 PM</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="w-7 h-7 rounded-lg bg-teal-50 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-map-marker-alt text-teal-500 text-xs"></i>
                                </span>
                                <span class="text-sm text-gray-700">Main Auditorium</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="w-7 h-7 rounded-lg bg-purple-50 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-user-circle text-purple-400 text-xs"></i>
                                </span>
                                <span class="text-sm text-gray-700">Supreme Student Government</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="w-7 h-7 rounded-lg bg-yellow-50 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-tag text-yellow-400 text-xs"></i>
                                </span>
                                <span class="text-sm text-gray-700">General Assembly</span>
                            </div>
                        </div>

                        <!-- Register button at info button -->
                        <div class="flex gap-2">
                            <button id="reg-open-btn"
                                    class="flex-1 py-2.5 rounded-xl bg-green-500 hover:bg-green-600 text-white font-bold text-sm flex items-center justify-center gap-2 transition-all shadow-md shadow-green-200">
                                <i class="fas fa-check-circle"></i> Register
                            </button>
                            <button class="w-10 h-10 rounded-xl border border-gray-200 flex items-center justify-center text-gray-400 hover:bg-gray-50 transition-all flex-shrink-0">
                                <i class="fas fa-info-circle text-sm"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Confirmation Dialog — nakatago sa simula, lalabas kapag nag-click ang Register.
                     Ang JS ang mag-toggle ng display at mag-animate ng scale. -->
                <div id="reg-confirm-dialog"
                     class="hidden absolute inset-0 flex items-center justify-center z-10"
                     style="background:rgba(15,23,42,0.6); backdrop-filter:blur(4px);">
                    <div id="reg-dialog-box"
                         class="bg-white rounded-3xl shadow-2xl p-7 w-72 text-center transform scale-90 transition-transform duration-300">
                        <div class="w-14 h-14 rounded-2xl bg-green-100 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-calendar-plus text-green-600 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-2">Confirm Registration</h3>
                        <p class="text-gray-500 text-sm mb-6">You are about to register for <strong class="text-gray-800">Annual Tech Summit 2026</strong>. Continue?</p>
                        <div class="flex gap-3">
                            <button id="reg-cancel-btn"
                                    class="flex-1 py-2.5 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold text-sm transition-all">
                                Cancel
                            </button>
                            <button id="reg-confirm-btn"
                                    class="flex-1 py-2.5 rounded-xl bg-green-500 hover:bg-green-600 text-white font-bold text-sm transition-all shadow-md shadow-green-200">
                                Yes, Register
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Success Overlay — ipinapakita pagkatapos mag-confirm ng registration.
                     Nagpapakita ng "Slot Reserved" at "QR Sent" badges. -->
                <div id="reg-success-overlay"
                     class="hidden absolute inset-0 flex items-center justify-center z-20"
                     style="background:rgba(15,23,42,0.7); backdrop-filter:blur(4px);">
                    <div class="bg-white rounded-3xl shadow-2xl p-8 w-72 text-center">
                        <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-check text-green-500 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Registration Confirmed!</h3>
                        <p class="text-gray-500 text-sm mb-4">You have successfully joined<br><strong>Annual Tech Summit 2026</strong></p>
                        <div class="flex gap-2 flex-wrap justify-center mb-3">
                            <span class="px-3 py-1 rounded-full bg-green-50 text-green-600 text-xs font-semibold border border-green-200"><i class="fas fa-check-circle mr-1"></i>Slot Reserved</span>
                            <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-600 text-xs font-semibold border border-blue-200"><i class="fas fa-qrcode mr-1"></i>QR Sent</span>
                        </div>
                        <p class="text-xs text-gray-400">A confirmation &amp; QR code has been sent to your email.</p>
                    </div>
                </div>

                <p id="reg-caption" class="text-gray-400 text-xs text-center mt-1 max-w-sm">Students browse open events and register with a single tap.</p>
            </div>


            <!-- ── SLIDE 3: QR Attendance ──
                 Nagpapakita ng sample QR code card at mga benepisyo ng QR system:
                 instant scan, tamper-proof, at offline support. -->
            <div class="demo-slide w-full h-full flex-col items-center justify-center px-10 py-10 gap-4"
                 style="background:linear-gradient(135deg,#064e3b 0%,#065f46 100%);">
                <div class="flex flex-col md:flex-row gap-8 items-center justify-center w-full max-w-2xl mx-auto">

                    <!-- QR Code visual mock — SVG-based, hindi real QR pero realistic ang hitsura -->
                    <div class="bg-white rounded-2xl p-5 shadow-2xl flex flex-col items-center gap-3 flex-shrink-0">
                        <div class="text-xs font-bold text-gray-500 uppercase tracking-widest">Your QR Code</div>
                        <svg width="120" height="120" viewBox="0 0 120 120" fill="none">
                            <rect width="120" height="120" fill="white"/>
                            <!-- Tatlong finder patterns (sulok-sulok) ng QR code -->
                            <rect x="10" y="10" width="30" height="30" rx="3" fill="#1e293b"/>
                            <rect x="80" y="10" width="30" height="30" rx="3" fill="#1e293b"/>
                            <rect x="10" y="80" width="30" height="30" rx="3" fill="#1e293b"/>
                            <rect x="16" y="16" width="18" height="18" rx="2" fill="white"/>
                            <rect x="86" y="16" width="18" height="18" rx="2" fill="white"/>
                            <rect x="16" y="86" width="18" height="18" rx="2" fill="white"/>
                            <rect x="22" y="22" width="6" height="6" fill="#1e293b"/>
                            <rect x="92" y="22" width="6" height="6" fill="#1e293b"/>
                            <rect x="22" y="92" width="6" height="6" fill="#1e293b"/>
                            <!-- Data cells ng QR code (mock pattern) -->
                            <rect x="50" y="10" width="6" height="6" fill="#1e293b"/>
                            <rect x="62" y="10" width="6" height="6" fill="#1e293b"/>
                            <rect x="56" y="18" width="6" height="6" fill="#1e293b"/>
                            <rect x="50" y="26" width="6" height="6" fill="#1e293b"/>
                            <rect x="68" y="26" width="6" height="6" fill="#1e293b"/>
                            <rect x="50" y="50" width="6" height="6" fill="#1e293b"/>
                            <rect x="62" y="56" width="6" height="6" fill="#1e293b"/>
                            <rect x="74" y="50" width="6" height="6" fill="#1e293b"/>
                            <rect x="56" y="74" width="6" height="6" fill="#1e293b"/>
                            <rect x="68" y="68" width="6" height="6" fill="#1e293b"/>
                            <rect x="80" y="74" width="6" height="6" fill="#1e293b"/>
                            <rect x="50" y="80" width="6" height="6" fill="#1e293b"/>
                            <rect x="74" y="86" width="6" height="6" fill="#1e293b"/>
                            <rect x="56" y="92" width="6" height="6" fill="#1e293b"/>
                            <rect x="68" y="98" width="6" height="6" fill="#1e293b"/>
                            <rect x="80" y="92" width="6" height="6" fill="#1e293b"/>
                            <rect x="104" y="50" width="6" height="6" fill="#1e293b"/>
                            <rect x="98" y="62" width="6" height="6" fill="#1e293b"/>
                            <rect x="104" y="74" width="6" height="6" fill="#1e293b"/>
                        </svg>
                        <div class="text-xs text-gray-400">Juan dela Cruz · 2024-00142</div>
                        <div class="flex items-center gap-1.5 text-xs font-semibold text-green-600 bg-green-50 px-3 py-1 rounded-full">
                            <i class="fas fa-check-circle"></i> Ready to Scan
                        </div>
                    </div>

                    <!-- Key benefits ng QR attendance system -->
                    <div class="space-y-4 text-white">
                        <h3 class="text-2xl font-bold font-display">QR Attendance</h3>
                        <p class="text-green-200">Each student gets a unique QR code. Organizers scan it at the door for instant attendance marking.</p>
                        <div class="bg-white/10 border border-white/20 rounded-xl p-4 space-y-2">
                            <div class="flex items-center gap-3 text-green-300"><i class="fas fa-bolt w-5"></i><span class="text-sm">Instant verification — under 1 second</span></div>
                            <div class="flex items-center gap-3 text-green-300"><i class="fas fa-lock w-5"></i><span class="text-sm">Tamper-proof one-time codes</span></div>
                            <div class="flex items-center gap-3 text-green-300"><i class="fas fa-wifi-slash w-5"></i><span class="text-sm">Works offline too</span></div>
                        </div>
                    </div>
                </div>
            </div>


            <!-- ── SLIDE 4: Analytics Dashboard ──
                 Nagpapakita ng mock analytics: stat cards at bar chart.
                 Ang bar heights ay galing sa PHP array at naka-render inline. -->
            <div class="demo-slide w-full h-full flex-col items-center justify-center px-6 py-10 gap-4"
                 style="background:linear-gradient(135deg,#1e1b4b 0%,#4c1d95 100%);">
                <h3 class="text-white font-bold text-2xl font-display text-center">Analytics Dashboard</h3>
                <div class="w-full max-w-xl bg-white/10 backdrop-blur rounded-2xl border border-white/20 p-5 space-y-4 mx-auto">

                    <!-- Tatlong summary stat cards -->
                    <div class="grid grid-cols-3 gap-3">
                        <div class="bg-white/10 rounded-xl p-3 text-center">
                            <div class="text-2xl font-bold text-white">248</div>
                            <div class="text-xs text-purple-200 mt-0.5">Registrations</div>
                        </div>
                        <div class="bg-white/10 rounded-xl p-3 text-center">
                            <div class="text-2xl font-bold text-white">89%</div>
                            <div class="text-xs text-purple-200 mt-0.5">Attendance</div>
                        </div>
                        <div class="bg-white/10 rounded-xl p-3 text-center">
                            <div class="text-2xl font-bold text-white">4.8★</div>
                            <div class="text-xs text-purple-200 mt-0.5">Feedback</div>
                        </div>
                    </div>

                    <!-- Bar chart — ang heights ay dynamically rendered ng PHP.
                         Bawat bar ay kumakatawan sa isang buwan ng taon. -->
                    <div>
                        <div class="text-xs font-semibold text-purple-200 mb-3 uppercase tracking-wider">Monthly Event Attendance</div>
                        <div class="flex items-end gap-2 h-24">
                            <?php
                            // Mock data para sa bar chart: Jan-Dec attendance percentages
                            $bars   = [60, 75, 45, 90, 70, 85, 95, 60, 80, 88, 72, 92];
                            $months = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
                            foreach ($bars as $i => $h): ?>
                                <div class="flex flex-col items-center gap-1 flex-1">
                                    <div class="w-full rounded-t-md"
                                         style="height:<?= $h ?>%; background:linear-gradient(to top,#6366f1,#8b5cf6); opacity:0.85;">
                                    </div>
                                    <span class="text-[9px] text-purple-300"><?= $months[$i] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <p class="text-purple-200 text-sm text-center max-w-md">Real-time analytics let administrators and organizers track performance at a glance.</p>
            </div>


            <!-- ── SLIDE 5: Role Management ──
                 Nagpapakita ng tatlong user roles: Student, Organizer, Admin.
                 May CTA button para mag-register sa sistema. -->
            <div class="demo-slide w-full h-full flex-col items-center justify-center px-10 py-10 gap-4"
                 style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);">
                <h3 class="text-white font-bold text-2xl font-display text-center mb-2">Three Powerful Roles</h3>
                <div class="grid grid-cols-3 gap-4 w-full max-w-2xl mx-auto">

                    <!-- Role Card: Student -->
                    <div class="bg-blue-500/20 border border-blue-400/30 rounded-2xl p-4 text-center">
                        <div class="w-12 h-12 mx-auto rounded-xl bg-blue-500/30 flex items-center justify-center text-2xl mb-3">
                            <i class="fas fa-user-graduate text-blue-300"></i>
                        </div>
                        <div class="text-white font-bold text-sm mb-2">Student</div>
                        <ul class="text-blue-200 text-xs space-y-1 text-left">
                            <li>• Browse events</li>
                            <li>• Register &amp; QR check-in</li>
                            <li>• Submit feedback</li>
                        </ul>
                    </div>

                    <!-- Role Card: Organizer -->
                    <div class="bg-purple-500/20 border border-purple-400/30 rounded-2xl p-4 text-center">
                        <div class="w-12 h-12 mx-auto rounded-xl bg-purple-500/30 flex items-center justify-center text-2xl mb-3">
                            <i class="fas fa-users-cog text-purple-300"></i>
                        </div>
                        <div class="text-white font-bold text-sm mb-2">Organizer</div>
                        <ul class="text-purple-200 text-xs space-y-1 text-left">
                            <li>• Create events</li>
                            <li>• Manage attendees</li>
                            <li>• View reports</li>
                        </ul>
                    </div>

                    <!-- Role Card: Admin -->
                    <div class="bg-gray-500/20 border border-gray-400/30 rounded-2xl p-4 text-center">
                        <div class="w-12 h-12 mx-auto rounded-xl bg-gray-500/30 flex items-center justify-center text-2xl mb-3">
                            <i class="fas fa-user-shield text-gray-300"></i>
                        </div>
                        <div class="text-white font-bold text-sm mb-2">Admin</div>
                        <ul class="text-gray-300 text-xs space-y-1 text-left">
                            <li>• Full system access</li>
                            <li>• Approve events</li>
                            <li>• Manage users</li>
                        </ul>
                    </div>
                </div>

                <!-- CTA sa loob ng demo modal — para agad mag-register -->
                <a href="/includes/auth.php?mode=register"
                   class="mt-4 px-8 py-3 rounded-full bg-gradient-to-r from-primary to-secondary text-white font-semibold shadow-xl hover:shadow-primary/40 transition-all hover:-translate-y-0.5 text-sm">
                    Get Started Now <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>


            <!-- Slide Navigation Controls (nasa ibaba ng lahat ng slides)
                 - Progress bars: isa para sa bawat slide, nagfe-fill habang lumalabas ang slide
                 - Prev/Next buttons: para manual na mag-navigate
                 - Slide counter: e.g., "2 / 5" -->
            <div class="absolute bottom-0 left-0 right-0 flex flex-col gap-0 z-20">
                <!-- Progress bars — ini-inject ng JavaScript batay sa slide count -->
                <div id="progress-bars" class="flex gap-1.5 px-6 pb-3 pt-2">
                    <!-- Dynamically generated by JS -->
                </div>
                <!-- Previous/Next navigation row -->
                <div class="flex items-center justify-between px-6 pb-4 bg-gradient-to-t from-black/50 pt-4">
                    <button id="prev-slide"
                            class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/40 text-white flex items-center justify-center transition-all text-sm">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <div id="slide-counter" class="text-white/70 text-sm font-medium">1 / 5</div>
                    <button id="next-slide"
                            class="w-9 h-9 rounded-full bg-white/20 hover:bg-white/40 text-white flex items-center justify-center transition-all text-sm">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div><!-- /demo-slides -->

        <!-- Modal Footer: slide count at "Start for Free" CTA button -->
        <div class="flex items-center justify-between px-6 py-3 bg-gray-50 border-t border-gray-100">
            <div class="text-xs text-gray-400">SEMS System Overview • 5 slides</div>
            <a href="/includes/auth.php?mode=register"
               class="px-4 py-1.5 rounded-full bg-gradient-to-r from-primary to-secondary text-white text-xs font-semibold shadow hover:shadow-primary/40 transition-all">
                Start for Free
            </a>
        </div>
    </div>
</div><!-- /demo-modal -->


<!-- ============================================================
     NAVIGATION BAR
     Fixed sa tuktok ng screen. Naglalaman ng:
     - Brand logo at pangalan
     - Desktop nav links (Features, How it Works, Roles, Contact)
     - Sign In at Sign Up buttons
     - Mobile hamburger menu (toggle via JS)
     Nagdadagdag ng glass blur effect kapag nag-scroll pababa (via JS).
     ============================================================ -->
<nav id="navbar" class="fixed w-full z-50 transition-all duration-300 top-0">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-20">

            <!-- Brand Logo at Pangalan -->
            <div class="flex-shrink-0 flex items-center gap-3 cursor-pointer group">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary to-secondary flex items-center justify-center text-white font-bold text-xl shadow-lg group-hover:shadow-primary/50 transition-all duration-300 group-hover:scale-110">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <span class="font-display font-bold text-2xl text-dark group-hover:text-primary transition-colors">SEMS</span>
            </div>

            <!-- Desktop Navigation Links (nakatago sa mobile) -->
            <div class="hidden md:flex items-center space-x-8">
                <a href="#features"     class="nav-link text-gray-700 hover:text-primary font-medium transition-colors">Features</a>
                <a href="#how-it-works" class="nav-link text-gray-700 hover:text-primary font-medium transition-colors">How it Works</a>
                <a href="#roles"        class="nav-link text-gray-700 hover:text-primary font-medium transition-colors">Roles</a>
                <a href="#contact"      class="nav-link text-gray-700 hover:text-primary font-medium transition-colors">Contact</a>
            </div>

            <!-- Desktop Auth Buttons -->
            <div class="hidden md:flex items-center space-x-4">
                <a href="/includes/auth.php?mode=login"
                   class="text-gray-700 hover:text-primary font-medium transition-all hover:scale-105">Sign In</a>
                <a href="/includes/auth.php?mode=register"
                   class="btn-shine px-6 py-2.5 rounded-full bg-gradient-to-r from-primary to-secondary text-white font-semibold shadow-lg hover:shadow-xl hover:shadow-primary/30 transition-all duration-300 hover:-translate-y-0.5">
                    Sign Up
                </a>
            </div>

            <!-- Mobile Hamburger Button — nagbubukas ng mobile menu dropdown -->
            <div class="md:hidden flex items-center">
                <button id="mobile-menu-btn" class="text-gray-700 hover:text-primary focus:outline-none p-2">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Dropdown Menu (nakatago by default, toggle ng JS) -->
    <div id="mobile-menu" class="md:hidden hidden glass-effect border-t border-gray-200">
        <div class="px-4 pt-2 pb-6 space-y-2">
            <a href="#features"      class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-primary/10 hover:text-primary transition-all">Features</a>
            <a href="#how-it-works"  class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-primary/10 hover:text-primary transition-all">How it Works</a>
            <a href="#roles"         class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-primary/10 hover:text-primary transition-all">Roles</a>
            <a href="/includes/auth.php?mode=login"    class="block px-3 py-2 rounded-lg text-gray-700 hover:bg-primary/10 hover:text-primary transition-all">Sign In</a>
            <a href="/includes/auth.php?mode=register" class="block px-3 py-2 rounded-lg bg-primary text-white text-center font-semibold hover:bg-primary/90 transition-all">Sign Up</a>
        </div>
    </div>
</nav><!-- /navbar -->


<!-- ============================================================
     HERO SECTION
     Pangunahing welcome area ng landing page. Naglalaman ng:
     - Animated background blobs (purple, indigo, pink)
     - Left column: tagline, description, CTA buttons, at quick stats
     - Right column: mock dashboard preview card na may floating badges
     - "Scroll to explore" bounce indicator sa ibaba
     ============================================================ -->
<section class="relative min-h-screen flex items-center justify-center overflow-hidden pt-20">

    <!-- Gradient background ng hero -->
    <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50"></div>
    <div class="absolute inset-0"
         style="background-image: radial-gradient(circle at 20% 50%, rgba(99,102,241,0.1) 0%, transparent 50%), radial-gradient(circle at 80% 80%, rgba(139,92,246,0.1) 0%, transparent 50%);">
    </div>

    <!-- Animated blobs: mga malulutong na kulay na gumagalaw para sa visual depth -->
    <div class="absolute top-20 left-10 w-72 h-72 bg-purple-300 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-float"></div>
    <div class="absolute top-40 right-10 w-72 h-72 bg-indigo-300 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-float-delayed"></div>
    <div class="absolute -bottom-8 left-20 w-72 h-72 bg-pink-300 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-float"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
        <div class="grid lg:grid-cols-2 gap-12 items-center">

            <!-- LEFT COLUMN: Headline, description, at CTA buttons -->
            <div class="text-center lg:text-left animate-slide-up">

                <!-- "New" badge — nagpapakita ng pinakabagong feature -->
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/80 border border-indigo-100 shadow-sm mb-6">
                    <span class="w-2 h-2 rounded-full bg-accent animate-pulse"></span>
                    <span class="text-sm font-medium text-gray-600">New: QR Attendance System</span>
                </div>

                <!-- Main headline ng landing page -->
                <h1 class="font-display text-5xl lg:text-7xl font-bold leading-tight mb-6">
                    Manage Events <br>
                    <span class="gradient-text">Smarter</span>
                </h1>

                <!-- Subheadline / description -->
                <p class="text-xl text-gray-600 mb-8 max-w-2xl mx-auto lg:mx-0 leading-relaxed">
                    The complete event management platform for students, organizers, and administrators.
                    Streamline registrations, track attendance, and create unforgettable experiences.
                </p>

                <!-- CTA Buttons: "Get Started Free" at "Watch Demo" -->
                <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                    <!-- Primary CTA: didiretso sa registration page -->
                    <a href="/includes/auth.php?mode=register"
                       class="btn-shine px-8 py-4 rounded-full bg-gradient-to-r from-primary to-secondary text-white font-semibold text-lg shadow-xl hover:shadow-2xl hover:shadow-primary/30 transition-all duration-300 hover:-translate-y-1 flex items-center justify-center gap-2">
                        Get Started Free <i class="fas fa-arrow-right"></i>
                    </a>

                    <!-- Secondary CTA: magbubukas ng demo modal (pinupukaw ng pulse-ring animation) -->
                    <button id="open-demo"
                            class="relative pulse-ring px-8 py-4 rounded-full bg-white text-gray-700 font-semibold text-lg border-2 border-gray-200 hover:border-primary hover:text-primary transition-all duration-300 hover:-translate-y-1 flex items-center justify-center gap-2 group">
                        <i class="fas fa-play-circle text-primary group-hover:scale-110 transition-transform text-xl"></i>
                        Watch Demo
                    </button>
                </div>

                <!-- Quick stats: 3 key metrics para sa credibility -->
                <div class="mt-12 grid grid-cols-3 gap-8 max-w-md mx-auto lg:mx-0">
                    <div class="text-center lg:text-left">
                        <div class="text-3xl font-bold text-dark">500+</div>
                        <div class="text-sm text-gray-500">Events Managed</div>
                    </div>
                    <div class="text-center lg:text-left">
                        <div class="text-3xl font-bold text-dark">10k+</div>
                        <div class="text-sm text-gray-500">Active Users</div>
                    </div>
                    <div class="text-center lg:text-left">
                        <div class="text-3xl font-bold text-dark">98%</div>
                        <div class="text-sm text-gray-500">Satisfaction</div>
                    </div>
                </div>
            </div><!-- /left column -->


            <!-- RIGHT COLUMN: Mock Dashboard Preview
                 Visual representation ng student dashboard.
                 May floating badges para sa "Attendance Marked" at "+24 joined" -->
            <div class="relative lg:h-auto animate-slide-down">
                <div class="relative bg-white rounded-3xl shadow-2xl p-6 border border-gray-100">
                    <div class="bg-gray-50 rounded-2xl p-4 space-y-4">

                        <!-- Mock user header row -->
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary to-secondary"></div>
                                <div>
                                    <div class="h-3 w-32 bg-gray-200 rounded"></div>
                                    <div class="h-2 w-20 bg-gray-200 rounded mt-1"></div>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <div class="w-8 h-8 rounded-lg bg-white shadow flex items-center justify-center">
                                    <i class="fas fa-bell text-gray-400 text-xs"></i>
                                </div>
                                <div class="w-8 h-8 rounded-lg bg-white shadow flex items-center justify-center">
                                    <i class="fas fa-cog text-gray-400 text-xs"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Mock stat cards (2x2 grid) -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                                <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center mb-2">
                                    <i class="fas fa-calendar text-primary text-sm"></i>
                                </div>
                                <div class="text-2xl font-bold text-dark">12</div>
                                <div class="text-xs text-gray-500">Total Events</div>
                            </div>
                            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                                <div class="w-8 h-8 rounded-lg bg-green-100 flex items-center justify-center mb-2">
                                    <i class="fas fa-users text-accent text-sm"></i>
                                </div>
                                <div class="text-2xl font-bold text-dark">248</div>
                                <div class="text-xs text-gray-500">Registrations</div>
                            </div>
                            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                                <div class="w-8 h-8 rounded-lg bg-purple-100 flex items-center justify-center mb-2">
                                    <i class="fas fa-clock text-secondary text-sm"></i>
                                </div>
                                <div class="text-2xl font-bold text-dark">4</div>
                                <div class="text-xs text-gray-500">Upcoming</div>
                            </div>
                            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                                <div class="w-8 h-8 rounded-lg bg-pink-100 flex items-center justify-center mb-2">
                                    <i class="fas fa-chart-line text-pink-500 text-sm"></i>
                                </div>
                                <div class="text-2xl font-bold text-dark">89%</div>
                                <div class="text-xs text-gray-500">Attendance</div>
                            </div>
                        </div>

                        <!-- Mock line chart (SVG path — para sa visual depth lang) -->
                        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                            <div class="flex justify-between items-center mb-3">
                                <div class="h-3 w-24 bg-gray-200 rounded"></div>
                                <div class="h-3 w-16 bg-gray-200 rounded"></div>
                            </div>
                            <div class="h-24 bg-gradient-to-t from-indigo-50 to-transparent rounded-lg relative overflow-hidden">
                                <svg class="absolute bottom-0 w-full" viewBox="0 0 400 100" preserveAspectRatio="none">
                                    <path d="M0,50 Q100,10 200,50 T400,30 L400,100 L0,100 Z" fill="rgba(99,102,241,0.2)"/>
                                    <path d="M0,50 Q100,10 200,50 T400,30" fill="none" stroke="#6366f1" stroke-width="2"/>
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Badge 1: "Attendance Marked" — nasa ibaba-kaliwa ng card -->
                    <div class="absolute -bottom-6 -left-6 bg-white rounded-2xl shadow-xl p-4 border border-gray-100 animate-float">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                                <i class="fas fa-check text-accent text-xl"></i>
                            </div>
                            <div>
                                <div class="font-semibold text-dark">Attendance</div>
                                <div class="text-sm text-gray-500">Marked Successfully</div>
                            </div>
                        </div>
                    </div>

                    <!-- Floating Badge 2: "+24 joined" — nasa itaas-kanan ng card -->
                    <div class="absolute -top-6 -right-6 bg-white rounded-2xl shadow-xl p-4 border border-gray-100 animate-float-delayed">
                        <div class="flex items-center gap-2">
                            <div class="flex -space-x-2">
                                <div class="w-8 h-8 rounded-full bg-blue-500 border-2 border-white"></div>
                                <div class="w-8 h-8 rounded-full bg-purple-500 border-2 border-white"></div>
                                <div class="w-8 h-8 rounded-full bg-pink-500 border-2 border-white"></div>
                            </div>
                            <div class="text-sm font-semibold text-dark">+24 joined</div>
                        </div>
                    </div>
                </div>
            </div><!-- /right column -->

        </div>
    </div>

    <!-- Scroll indicator — nagbo-bounce para himukin ang user na mag-scroll pababa -->
    <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce-slow">
        <a href="#marquee-strip" class="flex flex-col items-center text-gray-400 hover:text-primary transition-colors">
            <span class="text-sm mb-2">Scroll to explore</span>
            <i class="fas fa-chevron-down text-xl"></i>
        </a>
    </div>
</section><!-- /hero -->


<!-- ============================================================
     MARQUEE STRIP 1 — FEATURES (Forward, Light Background)
     Infinity loop ng mga feature tags na gumagalaw pakaliwa.
     Ang content ay naka-duplicate ng 4x para seamless ang loop.
     ============================================================ -->
<div id="marquee-strip" class="py-6 bg-white border-y border-gray-100 overflow-hidden">
    <div class="marquee-wrapper">
        <div class="marquee-track marquee-track--forward flex items-center gap-10 text-sm font-semibold text-gray-500">
            <?php
            // Listahan ng features na ipapakita sa marquee
            $items = [
                ['icon' => 'fa-qrcode',       'label' => 'QR Attendance'],
                ['icon' => 'fa-calendar-alt',  'label' => 'Event Management'],
                ['icon' => 'fa-chart-pie',     'label' => 'Analytics'],
                ['icon' => 'fa-users',         'label' => 'Multi-Role Access'],
                ['icon' => 'fa-bell',          'label' => 'Smart Notifications'],
                ['icon' => 'fa-shield-alt',    'label' => 'Role Management'],
                ['icon' => 'fa-mobile-alt',    'label' => 'Mobile Friendly'],
                ['icon' => 'fa-comments',      'label' => 'Feedback System'],
                ['icon' => 'fa-file-export',   'label' => 'Export Reports'],
                ['icon' => 'fa-lock',          'label' => 'Secure Access'],
            ];

            // I-duplicate ng 4x para walang makitang "gap" sa dulo ng animation loop
            $all = array_merge($items, $items, $items, $items);
            foreach ($all as $it): ?>
                <span class="flex items-center gap-2.5 whitespace-nowrap px-2 select-none">
                    <span class="w-7 h-7 rounded-lg bg-indigo-50 flex items-center justify-center text-primary text-xs flex-shrink-0">
                        <i class="fas <?= $it['icon'] ?>"></i>
                    </span>
                    <?= $it['label'] ?>
                </span>
                <!-- Separator between items -->
                <span class="text-indigo-200 select-none">✦</span>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<!-- ============================================================
     MARQUEE STRIP 2 — TESTIMONIALS (Reverse, Light Indigo Background)
     Infinity loop ng mga testimonials na gumagalaw pakanan (reverse).
     Nagbibigay ng social proof mula sa iba't ibang departments.
     ============================================================ -->
<div class="py-4 bg-indigo-50 overflow-hidden border-b border-indigo-100">
    <div class="marquee-wrapper">
        <div class="marquee-track marquee-track--reverse flex items-center gap-12 text-sm text-indigo-700">
            <?php
            // Testimonials mula sa iba't ibang departments at organizations
            $quotes = [
                '"Attendance is so much faster now!" — CS Dept',
                '"Our events run smoothly end-to-end." — Student Council',
                '"Best event system we\'ve ever used." — Engineering Club',
                '"The QR check-in is a game-changer." — Math Society',
                '"Incredible analytics dashboard." — Admin Office',
                '"Students love the easy registration." — Events Team',
                '"Simple, fast, and reliable." — Faculty Organizer',
            ];

            // I-duplicate para seamless ang reverse loop
            $all = array_merge($quotes, $quotes, $quotes, $quotes);
            foreach ($all as $q): ?>
                <span class="whitespace-nowrap select-none italic px-3"><?= $q ?></span>
                <span class="text-indigo-300 select-none">•</span>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<!-- ============================================================
     FEATURES SECTION
     Nagpapakita ng 6 pangunahing features ng SEMS sa isang
     3-column grid. Bawat card ay may icon, title, at description.
     Ang scroll-reveal class ay ina-animate ng JS kapag visible na sa viewport.
     ============================================================ -->
<section id="features" class="py-24 bg-white relative overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Section header -->
        <div class="text-center mb-16 scroll-reveal">
            <span class="inline-block px-4 py-1 rounded-full bg-indigo-50 text-primary font-semibold text-sm mb-4 border border-indigo-100">Features</span>
            <h2 class="font-display text-4xl lg:text-5xl font-bold text-dark mb-4">
                Everything you need to <span class="gradient-text">succeed</span>
            </h2>
            <p class="text-xl text-gray-600 max-w-2xl mx-auto">Powerful tools designed for modern event management across all user roles.</p>
        </div>

        <!-- Feature Cards Grid -->
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">

            <!-- Feature 1: QR Code Attendance -->
            <div class="feature-card group p-8 rounded-3xl bg-gray-50 border border-gray-100 card-hover scroll-reveal">
                <div class="feature-icon w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white text-2xl mb-6 shadow-lg shadow-blue-500/30">
                    <i class="fas fa-qrcode"></i>
                </div>
                <h3 class="text-xl font-bold text-dark mb-3 group-hover:text-primary transition-colors">QR Code Attendance</h3>
                <p class="text-gray-600 leading-relaxed">Instant check-ins with secure QR codes. Generate unique codes for each student and track attendance in real-time.</p>
            </div>

            <!-- Feature 2: Analytics Dashboard -->
            <div class="feature-card group p-8 rounded-3xl bg-gray-50 border border-gray-100 card-hover scroll-reveal" style="transition-delay:100ms">
                <div class="feature-icon w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center text-white text-2xl mb-6 shadow-lg shadow-purple-500/30">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <h3 class="text-xl font-bold text-dark mb-3 group-hover:text-secondary transition-colors">Analytics Dashboard</h3>
                <p class="text-gray-600 leading-relaxed">Comprehensive insights into event performance, attendance rates, and student engagement metrics.</p>
            </div>

            <!-- Feature 3: Mobile Friendly -->
            <div class="feature-card group p-8 rounded-3xl bg-gray-50 border border-gray-100 card-hover scroll-reveal" style="transition-delay:200ms">
                <div class="feature-icon w-14 h-14 rounded-2xl bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center text-white text-2xl mb-6 shadow-lg shadow-green-500/30">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <h3 class="text-xl font-bold text-dark mb-3 group-hover:text-accent transition-colors">Mobile Friendly</h3>
                <p class="text-gray-600 leading-relaxed">Access from any device. Responsive design ensures perfect experience on phones, tablets, and desktops.</p>
            </div>

            <!-- Feature 4: Feedback System -->
            <div class="feature-card group p-8 rounded-3xl bg-gray-50 border border-gray-100 card-hover scroll-reveal">
                <div class="feature-icon w-14 h-14 rounded-2xl bg-gradient-to-br from-pink-500 to-pink-600 flex items-center justify-center text-white text-2xl mb-6 shadow-lg shadow-pink-500/30">
                    <i class="fas fa-comments"></i>
                </div>
                <h3 class="text-xl font-bold text-dark mb-3 group-hover:text-pink-500 transition-colors">Feedback System</h3>
                <p class="text-gray-600 leading-relaxed">Collect and analyze feedback from participants. Improve future events with detailed survey tools.</p>
            </div>

            <!-- Feature 5: Role Management -->
            <div class="feature-card group p-8 rounded-3xl bg-gray-50 border border-gray-100 card-hover scroll-reveal" style="transition-delay:100ms">
                <div class="feature-icon w-14 h-14 rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 flex items-center justify-center text-white text-2xl mb-6 shadow-lg shadow-orange-500/30">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3 class="text-xl font-bold text-dark mb-3 group-hover:text-orange-500 transition-colors">Role Management</h3>
                <p class="text-gray-600 leading-relaxed">Secure access control for Students, Organizers, and Admins with customizable permissions.</p>
            </div>

            <!-- Feature 6: Smart Notifications -->
            <div class="feature-card group p-8 rounded-3xl bg-gray-50 border border-gray-100 card-hover scroll-reveal" style="transition-delay:200ms">
                <div class="feature-icon w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-600 flex items-center justify-center text-white text-2xl mb-6 shadow-lg shadow-indigo-500/30">
                    <i class="fas fa-bell"></i>
                </div>
                <h3 class="text-xl font-bold text-dark mb-3 group-hover:text-indigo-500 transition-colors">Smart Notifications</h3>
                <p class="text-gray-600 leading-relaxed">Automated reminders for upcoming events, registration confirmations, and attendance alerts.</p>
            </div>

        </div>
    </div>
</section><!-- /features -->


<!-- ============================================================
     MARQUEE STRIP 3 — STATS TICKER (Fast, Dark Background)
     Infinity loop ng key statistics. Gumagalaw mabilis pakaliwa
     sa dark slate background. Nagbibigay ng quick credibility metrics.
     ============================================================ -->
<div class="py-5 bg-dark overflow-hidden">
    <div class="marquee-wrapper dark-fade">
        <div class="marquee-track marquee-track--fast flex items-center gap-16 text-sm font-bold text-white">
            <?php
            // Key statistics ng SEMS para sa social proof
            $stats = [
                ['num' => '500+',    'label' => 'EVENTS MANAGED'],
                ['num' => '10,000+', 'label' => 'ACTIVE STUDENTS'],
                ['num' => '98%',     'label' => 'SATISFACTION RATE'],
                ['num' => '<1s',     'label' => 'QR SCAN SPEED'],
                ['num' => '3',       'label' => 'USER ROLES'],
                ['num' => '24/7',    'label' => 'ALWAYS ONLINE'],
                ['num' => '100%',    'label' => 'MOBILE READY'],
            ];

            // I-duplicate para seamless ang loop
            $all = array_merge($stats, $stats, $stats, $stats);
            foreach ($all as $s): ?>
                <span class="flex items-center gap-3 whitespace-nowrap select-none">
                    <span class="text-2xl font-display text-indigo-400"><?= $s['num'] ?></span>
                    <span class="text-gray-400 tracking-widest text-xs"><?= $s['label'] ?></span>
                </span>
                <!-- Separator sa pagitan ng stats -->
                <span class="text-indigo-700 select-none text-xl">◆</span>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<!-- ============================================================
     HOW IT WORKS SECTION
     Ipinapakita ang 3-step process para sa pag-gamit ng SEMS.
     May horizontal connector line sa desktop view.
     ============================================================ -->
<section id="how-it-works" class="py-24 bg-gray-50 relative">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Section header -->
        <div class="text-center mb-16 scroll-reveal">
            <span class="inline-block px-4 py-1 rounded-full bg-green-50 text-accent font-semibold text-sm mb-4 border border-green-100">Process</span>
            <h2 class="font-display text-4xl lg:text-5xl font-bold text-dark mb-4">
                How it <span class="gradient-text">Works</span>
            </h2>
            <p class="text-xl text-gray-600 max-w-2xl mx-auto">Get started in minutes with our simple three-step process.</p>
        </div>

        <!-- Steps Grid — may horizontal line connector sa gitna (desktop only) -->
        <div class="grid md:grid-cols-3 gap-8 relative">

            <!-- Connector line sa pagitan ng steps (nakatago sa mobile) -->
            <div class="hidden md:block absolute top-1/2 left-0 w-full h-1 bg-gradient-to-r from-primary via-secondary to-accent transform -translate-y-1/2 z-0 opacity-20"></div>

            <!-- Step 1: Create Account -->
            <div class="relative z-10 scroll-reveal">
                <div class="bg-white rounded-3xl p-8 shadow-xl border border-gray-100 text-center card-hover">
                    <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-primary to-secondary flex items-center justify-center text-white text-3xl font-bold mb-6 shadow-lg shadow-primary/30">
                        1
                    </div>
                    <h3 class="text-xl font-bold text-dark mb-3">Create Account</h3>
                    <p class="text-gray-600">Sign up as a Student, Organizer, or Admin. Verify your email and complete your profile setup.</p>
                </div>
            </div>

            <!-- Step 2: Manage Events -->
            <div class="relative z-10 scroll-reveal" style="transition-delay:150ms">
                <div class="bg-white rounded-3xl p-8 shadow-xl border border-gray-100 text-center card-hover">
                    <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-secondary to-purple-600 flex items-center justify-center text-white text-3xl font-bold mb-6 shadow-lg shadow-secondary/30">
                        2
                    </div>
                    <h3 class="text-xl font-bold text-dark mb-3">Manage Events</h3>
                    <p class="text-gray-600">Create events, set capacities, configure registration forms, and publish to the student community.</p>
                </div>
            </div>

            <!-- Step 3: Track & Analyze -->
            <div class="relative z-10 scroll-reveal" style="transition-delay:300ms">
                <div class="bg-white rounded-3xl p-8 shadow-xl border border-gray-100 text-center card-hover">
                    <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-accent to-green-600 flex items-center justify-center text-white text-3xl font-bold mb-6 shadow-lg shadow-accent/30">
                        3
                    </div>
                    <h3 class="text-xl font-bold text-dark mb-3">Track &amp; Analyze</h3>
                    <p class="text-gray-600">Monitor registrations, check attendance with QR codes, and analyze event success with detailed reports.</p>
                </div>
            </div>

        </div>
    </div>
</section><!-- /how-it-works -->


<!-- ============================================================
     USER ROLES SECTION
     Ipinapakita ang tatlong user roles ng SEMS: Student, Organizer,
     at Administrator. Bawat isa ay may gradient card at feature checklist.
     ============================================================ -->
<section id="roles" class="py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Section header -->
        <div class="text-center mb-16 scroll-reveal">
            <span class="inline-block px-4 py-1 rounded-full bg-purple-50 text-secondary font-semibold text-sm mb-4 border border-purple-100">User Roles</span>
            <h2 class="font-display text-4xl lg:text-5xl font-bold text-dark mb-4">
                Designed for every <span class="gradient-text">User</span>
            </h2>
            <p class="text-xl text-gray-600 max-w-2xl mx-auto">Tailored experiences for Students, Event Organizers, and Administrators.</p>
        </div>

        <!-- Role Cards -->
        <div class="grid lg:grid-cols-3 gap-8">

            <!-- Role: Student (Blue gradient) -->
            <div class="group relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-500 to-blue-600 p-8 text-white card-hover scroll-reveal">
                <div class="relative">
                    <div class="w-16 h-16 rounded-2xl bg-white/20 flex items-center justify-center text-3xl mb-6 backdrop-blur-sm">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Students</h3>
                    <ul class="space-y-3 text-blue-50">
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-blue-200"></i><span>Browse upcoming events</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-blue-200"></i><span>Easy registration process</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-blue-200"></i><span>QR Code check-in</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-blue-200"></i><span>Track attendance history</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-blue-200"></i><span>Submit feedback</span></li>
                    </ul>
                </div>
            </div>

            <!-- Role: Organizer (Purple gradient) -->
            <div class="group relative overflow-hidden rounded-3xl bg-gradient-to-br from-purple-500 to-purple-600 p-8 text-white card-hover scroll-reveal" style="transition-delay:100ms">
                <div class="relative">
                    <div class="w-16 h-16 rounded-2xl bg-white/20 flex items-center justify-center text-3xl mb-6 backdrop-blur-sm">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Organizers</h3>
                    <ul class="space-y-3 text-purple-50">
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-purple-200"></i><span>Create &amp; manage events</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-purple-200"></i><span>Track registrations</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-purple-200"></i><span>QR Code generation</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-purple-200"></i><span>Real-time attendance</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-purple-200"></i><span>Analytics &amp; reports</span></li>
                    </ul>
                </div>
            </div>

            <!-- Role: Administrator (Dark gradient) -->
            <div class="group relative overflow-hidden rounded-3xl bg-gradient-to-br from-dark to-gray-800 p-8 text-white card-hover scroll-reveal" style="transition-delay:200ms">
                <div class="relative">
                    <div class="w-16 h-16 rounded-2xl bg-white/10 flex items-center justify-center text-3xl mb-6 backdrop-blur-sm">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Administrators</h3>
                    <ul class="space-y-3 text-gray-300">
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-gray-400"></i><span>System oversight</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-gray-400"></i><span>User management</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-gray-400"></i><span>Event approvals</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-gray-400"></i><span>Department control</span></li>
                        <li class="flex items-center gap-3"><i class="fas fa-check-circle text-gray-400"></i><span>Platform analytics</span></li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
</section><!-- /roles -->


<!-- ============================================================
     CALL TO ACTION (CTA) SECTION
     Full-width gradient section na nag-aanyaya sa users na mag-register.
     May dalawang buttons: "Get Started Now" at "Sign In".
     ============================================================ -->
<section class="py-24 relative overflow-hidden">
    <!-- Gradient background ng CTA -->
    <div class="absolute inset-0 bg-gradient-to-r from-primary via-secondary to-accent"></div>

    <div class="relative max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <h2 class="font-display text-4xl lg:text-6xl font-bold text-white mb-6 scroll-reveal">
            Ready to transform your<br>event management?
        </h2>
        <p class="text-xl text-white/90 mb-10 max-w-2xl mx-auto scroll-reveal">
            Join thousands of students and organizers already using SEMS to create amazing experiences.
        </p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center scroll-reveal">
            <!-- Primary CTA: Mag-register -->
            <a href="/includes/auth.php?mode=register"
               class="px-10 py-4 rounded-full bg-white text-primary font-bold text-lg shadow-xl hover:shadow-2xl transition-all duration-300 hover:-translate-y-1 hover:scale-105">
                Get Started Now
            </a>
            <!-- Secondary CTA: Mag-login kung may account na -->
            <a href="/includes/auth.php?mode=login"
               class="px-10 py-4 rounded-full bg-white/10 text-white font-bold text-lg border-2 border-white/30 hover:bg-white/20 transition-all duration-300 backdrop-blur-sm">
                Sign In
            </a>
        </div>
    </div>
</section><!-- /cta -->


<!-- ============================================================
     CONTACT SECTION
     Naglalaman ng contact info (email, phone, address) sa kaliwa
     at isang contact form sa kanan.
     ============================================================ -->
<section id="contact" class="py-24 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid lg:grid-cols-2 gap-16">

            <!-- Left: Contact Information -->
            <div class="scroll-reveal">
                <span class="inline-block px-4 py-1 rounded-full bg-pink-50 text-pink-600 font-semibold text-sm mb-4 border border-pink-100">Contact Us</span>
                <h2 class="font-display text-4xl font-bold text-dark mb-4">Get in <span class="gradient-text">touch</span></h2>
                <p class="text-gray-600 mb-8">Have questions? We'd love to hear from you. Send us a message and we'll respond as soon as possible.</p>

                <!-- Contact details: Email, Phone, Address -->
                <div class="space-y-6">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-primary/10 flex items-center justify-center text-primary">
                            <i class="fas fa-envelope text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-dark">Email</div>
                            <div class="text-gray-600">manuelcedicgoco64@gmail.com</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-secondary/10 flex items-center justify-center text-secondary">
                            <i class="fas fa-phone text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-dark">Phone</div>
                            <div class="text-gray-600">09359133528</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-accent/10 flex items-center justify-center text-accent">
                            <i class="fas fa-map-marker-alt text-xl"></i>
                        </div>
                        <div>
                            <div class="font-semibold text-dark">Address</div>
                            <div class="text-gray-600">Barangay Tayaman, Mamburao, Occidental Mindoro</div>
                        </div>
                    </div>
                </div>
            </div><!-- /contact info -->

            <!-- Right: Contact Form
                 Hindi gumagalaw ang form sa PHP (no backend handling dito).
                 Ang submission ay hawak ng index.js via fetch/AJAX o simpleng alert. -->
            <div class="bg-white rounded-3xl p-8 shadow-xl border border-gray-100 scroll-reveal">
                <form id="contact-form" class="space-y-6">
                    <!-- First Name at Last Name (side by side) -->
                    <div class="grid sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                            <input type="text"
                                   class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all"
                                   placeholder="John">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                            <input type="text"
                                   class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all"
                                   placeholder="Doe">
                        </div>
                    </div>

                    <!-- Email field -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                        <input type="email"
                               class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all"
                               placeholder="john@example.com">
                    </div>

                    <!-- Message textarea -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Message</label>
                        <textarea rows="4"
                                  class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all resize-none"
                                  placeholder="How can we help you?"></textarea>
                    </div>

                    <!-- Submit button -->
                    <button type="submit"
                            class="w-full py-4 rounded-xl bg-gradient-to-r from-primary to-secondary text-white font-semibold shadow-lg hover:shadow-xl transition-all duration-300 hover:-translate-y-0.5 btn-shine">
                        Send Message
                    </button>
                </form>
            </div><!-- /contact form -->

        </div>
    </div>
</section><!-- /contact -->


<!-- ============================================================
     FOOTER
     Naglalaman ng brand info, quick links, legal links,
     copyright notice, at social media icon buttons.
     ============================================================ -->
<footer class="bg-dark text-white py-12 border-t border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Footer Grid: Brand (colspan 2), Quick Links, Legal -->
        <div class="grid md:grid-cols-4 gap-8 mb-8">

            <!-- Brand Description -->
            <div class="col-span-2">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary to-secondary flex items-center justify-center text-white font-bold">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <span class="font-display font-bold text-2xl">SEMS</span>
                </div>
                <p class="text-gray-400 max-w-sm">School Event Management System — The complete solution for managing campus events, registrations, and attendance.</p>
            </div>

            <!-- Quick Links -->
            <div>
                <h4 class="font-semibold mb-4">Quick Links</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="#features"     class="hover:text-white transition-colors">Features</a></li>
                    <li><a href="#how-it-works"  class="hover:text-white transition-colors">How it Works</a></li>
                    <li><a href="#roles"         class="hover:text-white transition-colors">User Roles</a></li>
                    <li><a href="/includes/auth.php"       class="hover:text-white transition-colors">Login</a></li>
                </ul>
            </div>

            <!-- Legal Links (placeholder hrefs) -->
            <div>
                <h4 class="font-semibold mb-4">Legal</h4>
                <ul class="space-y-2 text-gray-400">
                    <li><a href="#" class="hover:text-white transition-colors">Privacy Policy</a></li>
                    <li><a href="#" class="hover:text-white transition-colors">Terms of Service</a></li>
                    <li><a href="#" class="hover:text-white transition-colors">Cookie Policy</a></li>
                </ul>
            </div>

        </div>

        <!-- Footer Bottom: Copyright at Social Media Icons -->
        <div class="border-t border-gray-800 pt-8 flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-gray-500 text-sm">© 2026 SEMS. All rights reserved.</p>

            <!-- Social media icon buttons -->
            <div class="flex gap-4">
                <a href="#" class="w-10 h-10 rounded-full bg-gray-800 flex items-center justify-center text-gray-400 hover:bg-primary hover:text-white transition-all">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="#" class="w-10 h-10 rounded-full bg-gray-800 flex items-center justify-center text-gray-400 hover:bg-primary hover:text-white transition-all">
                    <i class="fab fa-twitter"></i>
                </a>
                <a href="#" class="w-10 h-10 rounded-full bg-gray-800 flex items-center justify-center text-gray-400 hover:bg-primary hover:text-white transition-all">
                    <i class="fab fa-instagram"></i>
                </a>
                <a href="#" class="w-10 h-10 rounded-full bg-gray-800 flex items-center justify-center text-gray-400 hover:bg-primary hover:text-white transition-all">
                    <i class="fab fa-linkedin-in"></i>
                </a>
            </div>
        </div>

    </div>
</footer>


<!-- ============================================================
     EXTERNAL JAVASCRIPT
     index.js ang naglalaman ng:
     - Navbar scroll effect (nagdadagdag ng glass blur at shadow)
     - Mobile menu toggle (hamburger open/close)
     - Demo modal open/close (open-demo at close-modal buttons)
     - Demo slide navigation (prev/next, progress bars, auto-advance)
     - Mute button toggle para sa voice narration
     - Slide 2 animation sequence (Register → Confirm → Success)
     - Scroll reveal (IntersectionObserver para sa .scroll-reveal elements)
     - Contact form submission handler
     ============================================================ -->
<script src="/js/index.js"></script>

</body>
</html>