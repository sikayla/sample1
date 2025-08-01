<?php
session_start();

// ==================== Configuration ====================
define('DASHBOARD_PATH', '/ventech_locator/users/user_dashboard.php');
define('SIGNUP_PATH', '/ventech_locator/users/user_signup.php');
define('FORGOT_PASSWORD_PATH', '#'); // Replace with actual path when available

// ==================== Redirect if Logged In ====================
// This check is for direct access to user_login.php when already logged in.
// If loaded in an iframe, the parent page might handle this differently.
if (isset($_SESSION['user_id'])) {
    // If already logged in, redirect the parent window (if in iframe) or self
    echo '<script type="text/javascript">';
    echo 'if (window.self !== window.top) {'; // Check if inside an iframe
    echo '    window.top.location.href = "' . DASHBOARD_PATH . '";'; // Redirect parent
    echo '} else {';
    echo '    window.location.href = "' . DASHBOARD_PATH . '";'; // Redirect self
    echo '}';
    echo '</script>';
    exit;
}

// ==================== Database Connection ====================
// It's highly recommended to centralize this into a shared db_connection.php file
// and include it here instead of defining it directly.
$host = 'localhost';
$db = 'ventech_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$error = "";
$login_val = ""; // To retain email/username input value
$success_message = ''; // For registration success message

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("DB connection error in user_login.php: " . $e->getMessage());
    $error = "We're experiencing technical issues. Please try again later.";
}

// ==================== Handle POST Login ====================
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($error)) {

    // -------- Guest Login --------
    if (isset($_POST['login_as_guest'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'guest' LIMIT 1");
            $stmt->execute();
            $guest_user = $stmt->fetch();

            if ($guest_user) {
                // Use existing guest
                $_SESSION['user_id'] = $guest_user['id'];
                $_SESSION['username'] = 'Guest';
                $_SESSION['role'] = 'guest';
            } else {
                // Create new guest account
                $guest_username = 'guest_' . uniqid();
                $guest_email = $guest_username . '@example.com';
                // Generate a random password for the guest, hash it.
                $guest_password_hash = password_hash('guest_' . uniqid(), PASSWORD_DEFAULT);

                $stmt_guest = $pdo->prepare("INSERT INTO users (username, email, password, role, created_at) VALUES (:username, :email, :password, 'guest', NOW())");
                $stmt_guest->execute([
                    ':username' => $guest_username,
                    ':email' => $guest_email,
                    ':password' => $guest_password_hash,
                ]);

                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['username'] = $guest_username;
                $_SESSION['role'] = 'guest';
            }
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            // Redirect the parent window (if in iframe) or self
            echo '<script type="text/javascript">';
            echo 'if (window.self !== window.top) {'; // Check if inside an iframe
            echo '    window.top.location.href = "' . DASHBOARD_PATH . '";'; // Redirect parent
            echo '} else {';
            echo '    window.location.href = "' . DASHBOARD_PATH . '";'; // Redirect self
            echo '}';
            echo '</script>';
            exit;

        } catch (PDOException $e) {
            error_log("Guest login error: " . $e->getMessage());
            $error = "Guest login failed. Please try again.";
        }

    } else {
        // -------- Regular User Login --------
        $login_val = trim($_POST['email_or_username'] ?? '');
        $password = $_POST['password'] ?? '';
        // Sanitize for display, but use raw for password_verify and DB query
        $login_display = htmlspecialchars($login_val, ENT_QUOTES, 'UTF-8');

        if (empty($login_val) || empty($password)) {
            $error = "Please enter both username/email and password.";
        } else {
            try {
                // Prepare statement to find user by email or username
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
                $stmt->execute([$login_val, $login_val]); // Use raw input for query
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Password is correct, set session and redirect
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    // Redirect the parent window (if in iframe) or self
                    echo '<script type="text/javascript">';
                    echo 'if (window.self !== window.top) {'; // Check if inside an iframe
                    echo '    window.top.location.href = "' . DASHBOARD_PATH . '";'; // Redirect parent
                    echo '} else {';
                    echo '    window.location.href = "' . DASHBOARD_PATH . '";'; // Redirect self
                    echo '}';
                    echo '</script>';
                    exit;
                } else {
                    $error = "Invalid login credentials.";
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "Login failed. Please try again.";
            }
        }
    }
}

// ==================== Handle Registration Redirect Message ====================
if (isset($_GET['registered']) && $_GET['registered'] == 1) {
    $success_message = "Registration successful! Please log in.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>User Login - Ventech Locator</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css"
  />
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@600&display=swap');
    /* Custom styles for the loading overlay, copied from original user_login.php */
    #loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(255, 255, 255, 0.8); /* White with transparency */
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999; /* Ensure it's on top of everything */
        transition: opacity 0.3s ease-in-out;
        opacity: 0; /* Start hidden */
        visibility: hidden; /* Start hidden */
    }

    #loading-overlay.visible {
        opacity: 1;
        visibility: visible;
    }

    /* Loading Animation Styles */
    .loader-container {
        text-align: center;
    }

    .loader-pin {
        color: #ff6347; /* Orange color for the pin */
        font-size: 3rem; /* Adjust size as needed */
        margin-bottom: 10px;
    }

    .loader-bar {
        width: 200px; /* Width of the loading bar */
        height: 4px;
        background-color: #e0e0e0; /* Light gray track */
        border-radius: 2px;
        position: relative;
        margin: 0 auto; /* Center the bar */
    }

    .loader-indicator {
        width: 10px; /* Size of the moving dot */
        height: 10px;
        background-color: #ff6347; /* Orange dot */
        border-radius: 50%;
        position: absolute;
        top: -3px; /* Center vertically on the bar */
        left: 0;
        animation: moveIndicator 2s infinite ease-in-out; /* Animation */
    }

    /* Keyframes for the animation */
    @keyframes moveIndicator {
        0% { left: 0; }
        50% { left: calc(100% - 10px); } /* Move to the end of the bar */
        100% { left: 0; }
    }
  </style>
</head>
<body>
  <div id="loading-overlay">
      <div class="loader-container">
          <i class="fas fa-map-marker-alt loader-pin"></i>
          <div class="loader-bar">
              <div class="loader-indicator"></div>
          </div>
      </div>
  </div>

  <div class="min-h-screen flex items-center justify-center p-6"> <!-- Removed bg-gradient-to-r classes -->
    <div class="flex flex-col md:flex-row bg-white rounded-3xl w-full max-w-4xl overflow-hidden">
      <div class="flex flex-col justify-center px-10 py-12 md:w-1/2">
        <h2 class="font-poppins font-semibold text-2xl mb-6 text-black">User Login</h2>
        
        <div class="flex space-x-3 mb-6">
          <button aria-label="Login with Google" class="border border-[#00303f] text-[#00303f] rounded-md w-10 h-10 flex items-center justify-center text-xs font-semibold hover:bg-[#00303f] hover:text-white transition">
            G+
          </button>
          <button aria-label="Login with Facebook" class="border border-[#00303f] text-[#00303f] rounded-md w-10 h-10 flex items-center justify-center text-base font-semibold hover:bg-[#00303f] hover:text-white transition">
            <i class="fab fa-facebook-f"></i>
          </button>
          <button aria-label="Login with GitHub" class="border border-[#00303f] text-[#00303f] rounded-md w-10 h-10 flex items-center justify-center text-base font-semibold hover:bg-[#00303f] hover:text-white transition">
            <i class="fab fa-github"></i>
          </button>
          <button aria-label="Login with LinkedIn" class="border border-[#00303f] text-[#00303f] rounded-md w-10 h-10 flex items-center justify-center text-base font-semibold hover:bg-[#00303f] hover:text-white transition">
            <i class="fab fa-linkedin-in"></i>
          </button>
        </div>
        <div class="text-center mb-4">
          <span class="font-poppins font-semibold text-lg text-black">OR</span>
          <p class="text-xs text-black">Login With Your Email & Password</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded text-sm" role="alert">
                <p class="font-bold">Login Error:</p>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 mb-4 rounded text-sm" role="alert">
                <p class="font-bold">Success!</p>
                <p><?= htmlspecialchars($success_message) ?></p>
            </div>
        <?php endif; ?>

        <form id="loginForm" method="POST" action="" class="space-y-3" aria-label="User Login form" novalidate="">
          <input
            type="text"
            name="email_or_username"
            placeholder="Email or Username"
            class="w-full bg-gray-300 text-black text-sm rounded-sm px-3 py-2 focus:outline-none"
            value="<?= htmlspecialchars($login_val) ?>"
            required
          />
          <input
            type="password"
            name="password"
            placeholder="Password"
            class="w-full bg-gray-300 text-black text-sm rounded-sm px-3 py-2 focus:outline-none"
            required
          />
          <button
            type="submit"
            class="bg-[#b5b600] text-white font-semibold text-xs rounded-sm px-6 py-2 mt-3 hover:bg-[#a0a000] transition"
          >
            LOGIN
          </button>
        </form>

        <form id="guestLoginForm" method="POST" action="" class="mt-4">
            <button type="submit" name="login_as_guest" class="w-full bg-[#00303f] text-white font-semibold text-xs rounded-sm px-6 py-2 hover:bg-[#1a4a5f] transition">
                LOGIN AS GUEST
            </button>
        </form>

        <p class="text-center text-xs mt-4">
            <a class="font-bold text-blue-700 hover:underline" href="<?= FORGOT_PASSWORD_PATH ?>">Forgot your password?</a>
        </p>
        <p class="text-center text-xs">
            Don't have an account? <a class="font-bold text-blue-700 hover:underline" href="<?= SIGNUP_PATH ?>">Register here</a>
        </p>
      </div>

      <div class="md:w-1/2 bg-[#00303f] rounded-tr-3xl rounded-br-3xl flex flex-col justify-center items-center px-10 py-12 text-white text-center">
        <h2 class="font-poppins font-semibold text-2xl mb-3">Hello</h2>
        <p class="text-xs mb-6">Register to use all features in our site</p>
        <a href="<?= SIGNUP_PATH ?>" class="border border-white text-white text-xs font-semibold px-6 py-2 rounded-sm hover:bg-white hover:text-[#00303f] transition">
          SIGN UP
        </a>
      </div>
    </div>
  </div>

  <script>
        document.addEventListener("DOMContentLoaded", function() {
            const loginForm = document.getElementById('loginForm');
            const guestLoginForm = document.getElementById('guestLoginForm');
            const loadingOverlay = document.getElementById('loading-overlay');

            // Function to show the loading overlay
            function showLoadingOverlay() {
                if (loadingOverlay) {
                    loadingOverlay.classList.add('visible');
                }
            }

            // Attach event listener to the regular login form
            if (loginForm) {
                loginForm.addEventListener('submit', function(event) {
                    // No event.preventDefault() here, allow form submission to PHP
                    showLoadingOverlay();
                });
            }

            // Attach event listener to the guest login form
            if (guestLoginForm) {
                guestLoginForm.addEventListener('submit', function(event) {
                    // No event.preventDefault() here, allow form submission to PHP
                    showLoadingOverlay();
                });
            }

            // Hide loading overlay when the page has fully loaded (after redirect or initial load)
            window.addEventListener('load', function() {
                if (loadingOverlay) {
                    loadingOverlay.classList.remove('visible');
                    // Optional: Remove the element from the DOM after transition
                    loadingOverlay.addEventListener('transitionend', function() {
                        if (!loadingOverlay.classList.contains('visible')) {
                            loadingOverlay.remove();
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
