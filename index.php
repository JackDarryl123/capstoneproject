<?php
require_once 'includes/session_helper.php';
start_user_session();

// Auto-redirect if already logged in (but not if showing a message or registration status)
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && !isset($_GET['register']) && !isset($_GET['logged_out']) && !isset($_SESSION['message'])) {
    switch ($_SESSION['role']) {
        case 'pgdh_pacco':
            header("Location: PACCO/admin_dashboard.php");
            break;
        case 'pgdh_gso':
            header("Location: GSO/admin_dashboard.php");
            break;
        case 'admin':
            header("Location: admin_dashboard.php");
            break;
        case 'staff':
            header("Location: staff/staff_dashboard.php");
            break;
        case 'supply':
            header("Location: supply/supply_dashboard.php");
            break;
        default:
            header("Location: users/user_dashboard.php");
            break;
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PEPO | System Login</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif'],
                    },
                    colors: {
                        pepo: {
                            dark: '#1e1e2d',
                            green: '#0ac347',
                            light: '#f3f6f9',
                            muted: '#a1a5b7',
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* NEW CODE: Animated Green Gradient */
        body {


            /* Gradient from Dark Sidebar Color (#1e1e2d) to Your Brand Green (#00cc44) */
            background: linear-gradient(-45deg, #103a13, #064e3b, #129112, #000000);
            background-size: 500% 500%;
            animation: gradientBG 20s ease infinite;
            height: 100vh;
            /* Ensures full height */
            margin: 0;
        }

        @keyframes gradientBG {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        /* Custom Input Styling */
        .custom-input {
            background-color: #f9f9f9;
            border: 1px solid #e1e3ea;
            transition: all 0.3s ease;
        }

        .custom-input:focus {
            background-color: #ffffff;
            border-color: #00cc44;
            box-shadow: 0 0 0 4px rgba(0, 204, 68, 0.1);
            outline: none;
        }

        /* Form switching animation */
        .hidden-form {
            display: none;
        }

        .fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body class="font-sans flex items-center justify-center min-h-screen p-4">

    <!-- load cotent -->
    <div id="global-loader"
        class="fixed inset-0 z-[9999] bg-black/60 backdrop-blur-sm hidden flex-col items-center justify-center transition-none">
        <div class="relative flex items-center justify-center scale-110">
            <div
                class="absolute w-20 h-20 border-4 border-green-500/30 rounded-full animate-[ping_0.8s_cubic-bezier(0,0,0.2,1)_infinite]">
            </div>
            <div
                class="w-16 h-16 border-4 border-transparent border-t-pepo-green border-r-green-400 rounded-full animate-[spin_0.5s_linear_infinite] shadow-[0_0_25px_rgba(74,222,128,0.6)]">
            </div>
            <div class="absolute inset-0 flex items-center justify-center">
                <svg class="w-6 h-6 text-pepo-green animate-pulse" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
            </div>
        </div>
        <div class="mt-5 text-white font-bold tracking-[0.3em] text-xs uppercase animate-pulse">
            Authenticating...
        </div>
    </div>


    <div
        class="bg-white rounded-[30px] shadow-2xl overflow-hidden flex flex-col lg:flex-row w-full max-w-5xl min-h-[600px] fade-in-up">


        <div
            class="w-full lg:w-5/12 bg-[#0a3922] flex flex-col items-center justify-center p-6 lg:p-10 relative overflow-hidden min-h-[250px]">

            <div
                class="absolute w-80 h-80 bg-white rounded-full mix-blend-overlay filter blur-3xl opacity-10 animate-pulse">
            </div>

            <div class="relative z-10 text-center">

                <img src="./rs/Pepo_Logo.png" alt="PEPO Logo"
                    class="w-28 lg:w-52 h-auto mx-auto mb-4 lg:mb-8 drop-shadow-2xl hover:scale-110 transition-transform duration-500 cursor-pointer">

                <h1
                    class="text-lg lg:text-2xl font-bold text-white tracking-wide drop-shadow-md font-sans leading-tight">
                    PROVINCIAL EQUIPMENT<br>POOL OFFICE
                </h1>

                <div
                    class="mt-3 lg:mt-6 h-1 lg:h-1.5 w-16 lg:w-24 bg-[#00cc44] mx-auto rounded-full shadow-[0_0_15px_rgba(0,204,68,0.6)]">
                </div>

                <p class="mt-3 lg:mt-6 text-green-50 text-xs lg:text-base font-medium px-4 italic opacity-90">
                    "Empowering Public Service for Every Mindoreño"
                </p>
            </div>

            <div class="absolute bottom-2 lg:bottom-6 text-[10px] lg:text-xs text-white/30 tracking-widest uppercase">
                &copy; 2026 PEPO Management System
            </div>
        </div>






        <div class="w-full lg:w-7/12 bg-white p-8 md:p-12 lg:p-16 flex flex-col justify-center relative">

            <?php
            if (isset($_SESSION['message']) && isset($_SESSION['msg_type'])) {
                $msg = $_SESSION['message'];
                $type = $_SESSION['msg_type'];

                // Determine styling based on type
                $alertStyle = ($type == 'danger' || strpos($msg, 'inactive') !== false)
                    ? 'bg-red-50 text-red-600 border-red-200'
                    : (($type == 'warning' || strpos($msg, 'pending') !== false)
                        ? 'bg-yellow-50 text-yellow-600 border-yellow-200'
                        : 'bg-green-50 text-green-600 border-green-200');

                $icon = ($type == 'danger') ? '🔒' : '⚠️';

                echo "<div class='mb-6 p-4 rounded-xl border $alertStyle flex items-start gap-3 shadow-sm animate-pulse'>";
                echo "<div class='text-xl'>$icon</div>";
                echo "<div class='flex-1'>";
                echo "<p class='font-semibold text-sm'>" . htmlspecialchars($msg) . "</p>";

                if (strpos($msg, 'pending') !== false || strpos($msg, 'inactive') !== false) {
                    echo "<a href='check_signup_status.php' class='text-xs font-bold underline mt-1 block hover:opacity-75'>Check Application Status &rarr;</a>";
                }
                echo "</div>";
                echo "<button onclick='this.parentElement.remove()' class='text-xl leading-none opacity-50 hover:opacity-100'>&times;</button>";
                echo "</div>";

                unset($_SESSION['message']);
                unset($_SESSION['msg_type']);
            }
            ?>

            <div id="loginForm" class="w-full max-w-md mx-auto">
                <div class="mb-8">
                    <h2 class="text-3xl font-bold text-pepo-dark">Welcome!</h2>
                    <p class="text-pepo-muted text-sm mt-1">Please enter your credentials to access the dashboard.</p>
                </div>

                <form method="POST" action="process.php" class="space-y-5">

                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                            </svg>
                        </div>
                        <input type="email" name="email" required
                            class="custom-input w-full pl-12 pr-4 py-4 rounded-xl text-sm font-medium text-gray-700 placeholder-gray-400"
                            placeholder="Email Address">
                    </div>

                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input type="password" name="password" required minlength="8"
                            class="custom-input w-full pl-12 pr-4 py-4 rounded-xl text-sm font-medium text-gray-700 placeholder-gray-400"
                            placeholder="Password">
                    </div>

                    <div class="flex items-center justify-between text-sm">
                        <label class="flex items-center text-gray-500 hover:text-pepo-dark cursor-pointer">
                            <input type="checkbox" name="remember"
                                class="w-4 h-4 text-pepo-green rounded border-gray-300 focus:ring-pepo-green accent-green-600 mr-2">
                            <span>Remember me</span>
                        </label>
                        <a href="forgot_password.php" class="font-semibold text-pepo-green hover:underline">Forgot
                            Password?</a>
                    </div>

                    <button type="submit" name="login"
                        class="w-full bg-pepo-green text-white font-bold py-4 rounded-xl shadow-lg shadow-green-200 transition-all duration-300 hover:bg-[#00b33c] hover:shadow-green-300 transform hover:-translate-y-1">
                        SIGN IN
                    </button>
                </form>

                <div class="mt-8 text-center space-y-2">
                    <p class="text-sm text-gray-500">Don't have an account?</p>
                    <button onclick="showRegister()" class="text-pepo-green font-bold hover:underline text-sm">Create an Account</button>
                    <div class="pt-2">
                        <button onclick="toggleUserGuide(true)" class="text-xs text-pepo-muted hover:text-pepo-green transition-colors flex items-center justify-center mx-auto gap-1">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Need help? View User Guide
                        </button>
                    </div>
                </div>
            </div>

            <div id="registerForm" class="hidden-form w-full max-w-md mx-auto">
                <div class="mb-6">
                    <h2 class="text-3xl font-bold text-pepo-dark">Get Started</h2>
                    <p class="text-pepo-muted text-sm mt-1">Create your official account.</p>
                </div>

                <form method="POST" action="process.php" class="space-y-4">

                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <input type="text" name="username" required
                            class="custom-input w-full pl-12 pr-4 py-3.5 rounded-xl text-sm text-gray-700 placeholder-gray-400"
                            placeholder="Username">
                    </div>

                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
                            </svg>
                        </div>
                        <input type="email" name="email" required
                            class="custom-input w-full pl-12 pr-4 py-3.5 rounded-xl text-sm text-gray-700 placeholder-gray-400"
                            placeholder="Email Address">
                    </div>

                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <input type="password" name="password" required minlength="8"
                            class="custom-input w-full pl-12 pr-4 py-3.5 rounded-xl text-sm text-gray-700 placeholder-gray-400"
                            placeholder="Password">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="relative">
                            <select name="designation" required
                                class="custom-input w-full px-4 py-3.5 rounded-xl text-sm text-gray-700 appearance-none bg-white cursor-pointer">
                                <option value="" disabled selected>Designation</option>
                                <option value="Mamburao">Mamburao</option>
                                <option value="Sablayan">Sablayan</option>
                                <option value="San Jose">San Jose</option>
                                <option value="Lubang">Lubang</option>
                            </select>
                            <div
                                class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none text-gray-500">
                                ▼</div>
                        </div>

                        <div class="relative">
                            <select name="role" required
                                class="custom-input w-full px-4 py-3.5 rounded-xl text-sm text-gray-700 appearance-none bg-white cursor-pointer">
                                <option value="" disabled selected>Role</option>
                                <option value="user">Property Custodian Officer</option>
                                <option value="staff">Maintenance staff</option>
                                <option value="supply">Supply Dept Admin</option>
                                <option value="admin">Maintenance Dept Admin</option>
                            </select>
                            <div
                                class="absolute inset-y-0 right-0 flex items-center px-3 pointer-events-none text-gray-500">
                                ▼</div>
                        </div>
                    </div>

                    <button type="submit" name="register"
                        class="w-full bg-pepo-dark text-white font-bold py-4 rounded-xl shadow-lg transition-all duration-300 hover:bg-[#2a2a3f] transform hover:-translate-y-1 mt-2">
                        CREATE ACCOUNT
                    </button>
                </form>

                <div class="mt-6 text-center space-y-2">
                    <p class="text-sm text-gray-500">Already registered?</p>
                    <button onclick="showLogin()" class="text-pepo-green font-bold hover:underline text-sm">Sign In here</button>
                    <div class="pt-2 border-t border-gray-50 mt-4">
                        <button onclick="toggleUserGuide(true)" class="text-xs text-pepo-muted hover:text-pepo-green transition-colors flex items-center justify-center mx-auto gap-1">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Need help? View User Guide
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- User Guide Modal -->
    <div id="user-guide-modal" class="fixed inset-0 z-[10000] hidden items-center justify-center p-4 md:p-6">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="toggleUserGuide(false)"></div>
        <div class="relative bg-white w-full max-w-4xl max-h-[90vh] rounded-[30px] shadow-2xl overflow-hidden flex flex-col animate-fade-in-up">
            <!-- Modal Header -->
            <div class="p-6 md:p-8 border-b border-gray-100 flex items-center justify-between bg-pepo-light">
                <div>
                    <h3 class="text-2xl font-bold text-pepo-dark">PEPO User Guide</h3>
                    <p class="text-pepo-muted text-sm mt-1">Everything you need to know about the system.</p>
                </div>
                <button onclick="toggleUserGuide(false)" class="p-2 hover:bg-gray-200 rounded-full transition-colors">
                    <svg class="w-6 h-6 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="flex flex-col md:flex-row flex-1 overflow-hidden">
                <!-- Sidebar Tabs -->
                <div class="w-full md:w-1/3 bg-gray-50 border-r border-gray-100 overflow-y-auto p-4 space-y-2">
                    <button onclick="switchGuideTab('overview')" class="guide-tab-btn active w-full text-left px-4 py-3 rounded-xl text-sm font-semibold transition-all flex items-center gap-3">
                        <span class="w-8 h-8 rounded-lg bg-pepo-green/10 text-pepo-green flex items-center justify-center">🏠</span>
                        System Overview
                    </button>
                    <button onclick="switchGuideTab('account')" class="guide-tab-btn w-full text-left px-4 py-3 rounded-xl text-sm font-semibold transition-all flex items-center gap-3">
                        <span class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-500 flex items-center justify-center">👤</span>
                        Account & Approval
                    </button>
                    <button onclick="switchGuideTab('roles')" class="guide-tab-btn w-full text-left px-4 py-3 rounded-xl text-sm font-semibold transition-all flex items-center gap-3">
                        <span class="w-8 h-8 rounded-lg bg-purple-500/10 text-purple-500 flex items-center justify-center">🛡️</span>
                        Role Permissions
                    </button>
                    <button onclick="switchGuideTab('features')" class="guide-tab-btn w-full text-left px-4 py-3 rounded-xl text-sm font-semibold transition-all flex items-center gap-3">
                        <span class="w-8 h-8 rounded-lg bg-orange-500/10 text-orange-500 flex items-center justify-center">⚡</span>
                        Core Features
                    </button>
                </div>

                <!-- Tab Content -->
                <div class="flex-1 overflow-y-auto p-6 md:p-8">
                    <!-- Overview Tab -->
                    <div id="guide-overview" class="guide-content space-y-6">
                        <div>
                            <h4 class="text-xl font-bold text-pepo-dark mb-4">Welcome to PEPO</h4>
                            <p class="text-gray-600 leading-relaxed">
                                The **Provincial Equipment Pool Office (PEPO)** Management System is a comprehensive platform designed to streamline equipment tracking, maintenance, and documentation for provincial operations in Occidental Mindoro.
                            </p>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 rounded-2xl bg-green-50 border border-green-100">
                                <p class="font-bold text-pepo-green mb-1">Efficiency</p>
                                <p class="text-xs text-gray-500">Automated workflows for requests and logs.</p>
                            </div>
                            <div class="p-4 rounded-2xl bg-blue-50 border border-blue-100">
                                <p class="font-bold text-blue-600 mb-1">Transparency</p>
                                <p class="text-xs text-gray-500">Real-time status tracking for all equipment.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Account Tab -->
                    <div id="guide-account" class="guide-content hidden space-y-6">
                        <h4 class="text-xl font-bold text-pepo-dark mb-4">Registration & Approval Process</h4>
                        <div class="space-y-4">
                            <div class="flex gap-4">
                                <div class="flex-none w-8 h-8 rounded-full bg-pepo-green text-white flex items-center justify-center font-bold">1</div>
                                <div>
                                    <p class="font-bold text-pepo-dark">Create Account</p>
                                    <p class="text-sm text-gray-500">Fill out the registration form with your official email and designation.</p>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="flex-none w-8 h-8 rounded-full bg-pepo-green text-white flex items-center justify-center font-bold">2</div>
                                <div>
                                    <p class="font-bold text-pepo-dark">Admin Verification</p>
                                    <p class="text-sm text-gray-500">Your account will be placed in 'Pending' status. An administrator must verify your identity.</p>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="flex-none w-8 h-8 rounded-full bg-pepo-green text-white flex items-center justify-center font-bold">3</div>
                                <div>
                                    <p class="font-bold text-pepo-dark">Account Activation</p>
                                    <p class="text-sm text-gray-500">Once approved, you will receive an email notification or you can check your status via the login page.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Roles Tab -->
                    <div id="guide-roles" class="guide-content hidden space-y-4">
                        <h4 class="text-xl font-bold text-pepo-dark mb-4">System Roles</h4>
                        <div class="space-y-3">
                            <div class="p-4 rounded-xl border border-gray-100 hover:border-pepo-green transition-colors">
                                <p class="font-bold text-pepo-dark text-sm">Property Custodian Officer</p>
                                <p class="text-xs text-gray-500 mt-1">Submit equipment requests, view documents, and track appointment status.</p>
                            </div>
                            <div class="p-4 rounded-xl border border-gray-100 hover:border-pepo-green transition-colors">
                                <p class="font-bold text-pepo-dark text-sm">Maintenance Staff</p>
                                <p class="text-xs text-gray-500 mt-1">Log activities, scan QR codes for quick equipment updates, and manage daily tasks.</p>
                            </div>
                            <div class="p-4 rounded-xl border border-gray-100 hover:border-pepo-green transition-colors">
                                <p class="font-bold text-pepo-dark text-sm">Supply Dept Admin</p>
                                <p class="text-xs text-gray-500 mt-1">Manage inventory, approve equipment check-outs, and handle document uploads.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Features Tab -->
                    <div id="guide-features" class="guide-content hidden space-y-6">
                        <h4 class="text-xl font-bold text-pepo-dark mb-4">Core Functionalities</h4>
                        <div class="grid grid-cols-1 gap-4">
                            <div class="flex items-start gap-3">
                                <span class="text-2xl">📱</span>
                                <div>
                                    <p class="font-bold text-sm">QR Code Integration</p>
                                    <p class="text-xs text-gray-500">Every piece of equipment has a unique QR code for instant identification and logging.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="text-2xl">📋</span>
                                <div>
                                    <p class="font-bold text-sm">Automated Reporting</p>
                                    <p class="text-xs text-gray-500">Generate maintenance and inventory reports with a single click from the dashboard.</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="text-2xl">🔔</span>
                                <div>
                                    <p class="font-bold text-sm">Real-time Notifications</p>
                                    <p class="text-xs text-gray-500">Stay updated on request approvals and maintenance schedules via system alerts.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .guide-tab-btn.active {
            background-color: white;
            color: #0ac347;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        .guide-tab-btn:not(.active) {
            color: #a1a5b7;
        }
        .guide-tab-btn:not(.active):hover {
            color: #1e1e2d;
            background-color: #f3f6f9;
        }
    </style>

    <script>
        function toggleUserGuide(show) {
            const modal = document.getElementById('user-guide-modal');
            if (show) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.body.style.overflow = 'hidden';
            } else {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.style.overflow = 'auto';
            }
        }

        function showRegister() {
            document.getElementById('loginForm').classList.add('hidden-form');
            document.getElementById('registerForm').classList.remove('hidden-form');
        }

        function showLogin() {
            document.getElementById('registerForm').classList.add('hidden-form');
            document.getElementById('loginForm').classList.remove('hidden-form');
        }

        function switchGuideTab(tabId) {
            // Hide all content
            document.querySelectorAll('.guide-content').forEach(el => el.classList.add('hidden'));
            
            // Show selected content
            const targetContent = document.getElementById(`guide-${tabId}`);
            if (targetContent) {
                targetContent.classList.remove('hidden');
            }

            // Update tab buttons
            document.querySelectorAll('.guide-tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Add active class to clicked button
            if (event && event.currentTarget) {
                event.currentTarget.classList.add('active');
            }
        }

        // Close on escape key
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') toggleUserGuide(false);
        });
    </script>
</body>

</html>