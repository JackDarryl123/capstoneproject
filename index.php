<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PEPO Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body {
            background: #fff;
            font-family: Arial, sans-serif;
        }   

        .main-container {
            display: flex;
            height: 100vh;
            align-items: center;
            justify-content: center;
            gap: 30px;
        }

        .left-panel {
            flex: 0.8;
            text-align: center;
            padding: 20px;
            min-width: 350px;
        }

        .left-panel img {
            max-width: 300px;
            margin-bottom: 20px;
        }

        .left-panel h2 {
            font-weight: bold;
            color: #014d01;
        }

        .left-panel p {
            color: #555;
        }

        .right-panel {
            flex: 1.2;
            display: flex;
            justify-content: center;
            align-items: center;
            min-width: 300px;
            width: 100%;
            height: auto;

        }

        .card {
            width: 100%;
            max-width: 550px;
            background: #e0e0e0;
            border-radius: 40px;
            padding: 32px 20px;
            min-height: 600px;
            height: auto;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        @media (max-width: 900px) {
            .main-container {
                flex-direction: column;
                gap: 10px;
                padding: 20px 0;
            }

            .left-panel,
            .right-panel {
                min-width: 0;
                width: 100%;
                padding: 10px 0;
            }

            .card {
                max-width: 100%;
                padding: 20px 10px;
                border-radius: 20px;
            }
        }

        @media (max-width: 600px) {
            .main-container {
                padding: 10px 0;
            }

            .card {
                padding: 10px 5px;
                border-radius: 10px;
            }

            .left-panel img {
                max-width: 180px;
            }

            .left-panel h2 {
                font-size: 1.2rem;
            }
        }

        .form-control {
            width: 100%;
            border-radius: 15px;
            padding: 18px;
            background: #fff6f6;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.10);
            border: none;
            margin-bottom: 18px;
            font-size: 1.1rem;
        }

        .btn-success {
            background: #00cc44;
            border: none;
            border-radius: 15px;
            font-weight: bold;
            font-size: 1.4rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.10);
            margin-bottom: 22px;
            padding: 16px 0;
            width: 100%;
        }

        .btn-success:hover {
            background: #00b33c;
        }

        .btn-register,
        .register-btn {
            background: #6ee7b7;
            color: #5f6363ff;
            border: none;
            border-radius: 10px;
            font-weight: bold;
            font-size: 1.2rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            padding: 16px 0;
            width: 100%;
            margin: 0 auto 10px auto;
            display: block;
        }

        .separator {
            display: flex;
            align-items: center;
            margin: 18px 0;
        }

        .separator-line {
            flex: 1;
            height: 2px;
            background: #c4c4c4;
            margin: 0 10px;
        }

        .separator-text {
            color: #888;
            font-weight: 500;
        }

        .hidden {
            display: none;
        }

        .extra-links {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            margin-top: -10px;
        }

        .extra-links a {
            text-decoration: none;
            color: black;
            font-weight: 500;
        }

        .divider {
            text-align: center;
            margin: 15px 0;
            color: #999;
        }
    </style>
</head>

<body>
    <div class="main-container">

        <!-- LEFT SIDE -->
        <div class="left-panel">
            <img src="./rs/Pepo_Logo.png" alt="Seal"> <!-- Replace with actual seal image -->
            <h2>PROVINCIAL EQUIPMENT<br>POOL OFFICE</h2>
            <p>Empowering Public Service for Every Mindoreño</p>
        </div>

        <!-- RIGHT SIDE -->
        <div class="right-panel">
            <!-- Login Form -->
            <div class="card" id="loginForm">
                <form method="POST" action="process.php" style="width:100%;max-width:350px;display:flex;flex-direction:column;align-items:center;">
                    <div class="mb-1 w-100">
                        <input type="email" name="email" class="form-control" placeholder="Enter your email address" required>
                    </div>
                    <div class="mb-4 w-100">
                        <input type="password" name="password" class="form-control" placeholder="Password" required minlength="8" maxlength="8">
                    </div>
                    <div class="extra-links mb-3 w-100">
                        <div>
                            <input type="checkbox"> Remember me
                        </div>
                        <a href="#">Forgot Password?</a>
                    </div>
                    <button type="submit" name="login" class="btn btn-success w-100">SIGN IN</button>
                </form>
                <div class="divider">or</div>
                <button type="button" class="btn btn-register" style="max-width:350px;margin:16px auto 0 auto;display:block;" onclick="showRegister()">SIGN UP</button>
            </div>
            

            <!-- Register Form -->
            <div class="card hidden" id="registerForm">
                <form method="POST" action="process.php" style="width:100%;max-width:350px;display:flex;flex-direction:column;align-items:center;">
                    <div class="mb-3 w-100">
                        <input type="text" name="username" class="form-control" placeholder="Username" required>
                    </div>
                    <div class="mb-3 w-100">
                        <input type="email" name="email" class="form-control" placeholder="Enter your email address" required>
                    </div>
                    <div class="mb-3 w-100">
                        <input type="password" name="password" class="form-control" placeholder="Password" required minlength="8" maxlength="8">
                    </div>
                    <button type="submit" name="register" class="btn btn-success">SIGN UP</button>
                </form>
                <div class="divider">Already have an account?</div>
                <button type="button" class="btn btn-register" style="max-width:350px;margin:16px auto 0 auto;display:block;" onclick="showLogin()">SIGN IN</button>
            </div>
        </div>
    </div>

    <script>
        function showLogin() {
            document.getElementById('registerForm').classList.add('hidden');
            document.getElementById('loginForm').classList.remove('hidden');
        }

        function showRegister() {
            document.getElementById('loginForm').classList.add('hidden');
            document.getElementById('registerForm').classList.remove('hidden');
        }
        window.onload = function() {
            if (window.location.href.includes("register")) {
                showRegister();
            }
        };
    </script>
</body>

</html>