<?php
require_once 'includes/session_helper.php';
start_user_session();
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
                            dark: '#1e1e2d',   /* Matches Sidebar */
                            green: '#0ac347',  /* Matches Active Buttons */
                            light: '#f3f6f9',  /* Dashboard Background */
                            muted: '#a1a5b7',  /* Muted Text */
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
                    <h2 class="text-3xl font-bold text-pepo-dark">Welcome Back!</h2>
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
                        <a href="#" class="font-semibold text-pepo-green hover:underline">Forgot Password?</a>
                    </div>

                    <button type="submit" name="login"
                        class="w-full bg-pepo-green text-white font-bold py-4 rounded-xl shadow-lg shadow-green-200 transition-all duration-300 hover:bg-[#00b33c] hover:shadow-green-300 transform hover:-translate-y-1">
                        SIGN IN
                    </button>
                </form>

                <div class="mt-8 text-center">
                    <p class="text-sm text-gray-500">Don't have an account?</p>
                    <button onclick="showRegister()" class="text-pepo-green font-bold hover:underline text-sm">Create an
                        Account</button>
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
                                <option value="user">Property Officer</option>
                                <option value="staff">Maintenance</option>
                                <option value="supply">Supply Dept</option>
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

                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-500">Already registered?</p>
                    <button onclick="showLogin()" class="text-pepo-green font-bold hover:underline text-sm">Sign In
                        here</button>
                </div>
            </div>

        </div>
    </div>

    <script>
        function showLogin() {
            const reg = document.getElementById('registerForm');
            const log = document.getElementById('loginForm');

            // Simple fade switch
            reg.style.opacity = '0';
            setTimeout(() => {
                reg.classList.add('hidden-form');
                log.classList.remove('hidden-form');
                log.classList.add('fade-in-up');
                log.style.opacity = '1';
            }, 200);
        }

        function showRegister() {
            const reg = document.getElementById('registerForm');
            const log = document.getElementById('loginForm');

            log.style.opacity = '0';
            setTimeout(() => {
                log.classList.add('hidden-form');
                reg.classList.remove('hidden-form');
                reg.classList.add('fade-in-up');
                reg.style.opacity = '1';
            }, 200);
        }

        window.onload = function () {
            // Check URL parameters
            if (window.location.href.includes('register')) {
                showRegister();
            }
        };


        // load spinner
        // 1. Toggle between Login and Register views
        function showLogin() {
            const reg = document.getElementById('registerForm');
            const log = document.getElementById('loginForm');

            reg.style.opacity = '0';
            setTimeout(() => {
                reg.classList.add('hidden-form');
                log.classList.remove('hidden-form');
                log.classList.add('fade-in-up');
                log.style.opacity = '1';
            }, 200);
        }

        function showRegister() {
            const reg = document.getElementById('registerForm');
            const log = document.getElementById('loginForm');

            log.style.opacity = '0';
            setTimeout(() => {
                log.classList.add('hidden-form');
                reg.classList.remove('hidden-form');
                reg.classList.add('fade-in-up');
                reg.style.opacity = '1';
            }, 200);
        }

        // 2. Initialize Page
        window.onload = function () {
            if (window.location.href.includes('register')) {
                showRegister();
            }

            // 3. ATTACH LOADER TO FORMS
            const loader = document.getElementById('global-loader');
            const forms = document.querySelectorAll('form');

            forms.forEach(form => {
                form.addEventListener('submit', function (e) {
                    // Only show loader if HTML5 validation passes (fields are not empty)
                    if (this.checkValidity()) {
                        if (loader) {
                            loader.classList.remove('hidden');
                            loader.classList.add('flex');
                        }
                    }
                });
            });
        };

        // 4. Back Button Fix (Hide loader if user goes back)
        window.addEventListener('pageshow', function (event) {
            const loader = document.getElementById('global-loader');
            if (event.persisted && loader) {
                loader.classList.add('hidden');
                loader.classList.remove('flex');
            }
        });
    </script>
</body>

</html>