<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$error = '';

function getClientIp(): string {
    // REMOTE_ADDR is used; forwarded headers are not trusted.
    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

function getDeviceDetails(string $ua): array {
    $device = 'Desktop';

    if (preg_match('/ipad|tablet/i', $ua)) {
        $device = 'Tablet';
    } elseif (preg_match('/mobile|android|iphone|ipod/i', $ua)) {
        $device = 'Mobile';
    }

    $browser = 'Unknown Browser';

    if (preg_match('/Edg\/([\d.]+)/i', $ua, $m)) {
        $browser = 'Microsoft Edge ' . $m[1];
    } elseif (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) {
        $browser = 'Opera ' . $m[1];
    } elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m) && !preg_match('/Edg/i', $ua)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) {
        $browser = 'Safari ' . $m[1];
    }

    $os = 'Unknown OS';

    if (preg_match('/Windows NT 10/i', $ua)) {
        $os = 'Windows 10/11';
    } elseif (preg_match('/Windows NT 6.3/i', $ua)) {
        $os = 'Windows 8.1';
    } elseif (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (preg_match('/iPhone OS ([\d_]+)/i', $ua, $m)) {
        $os = 'iOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/iPad.*OS ([\d_]+)/i', $ua, $m)) {
        $os = 'iPadOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m)) {
        $os = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    return [$device, $browser, $os];
}

function getApproxLocation(string $ip): array {
    $result = [
        'city' => null,
        'region' => null,
        'country' => null
    ];

    // Private/local IP addresses cannot be geolocated.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $result;
    }

    // Optional third-party IP geolocation lookup.
    // The IP address is shared with ip-api.com for approximate location.
    if (!function_exists('curl_init')) {
        return $result;
    }

    $url = 'http://ip-api.com/json/' . rawurlencode($ip)
         . '?fields=status,city,regionName,country';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return $result;
    }

    $data = json_decode($response, true);

    if (is_array($data) && ($data['status'] ?? '') === 'success') {
        $result['city'] = $data['city'] ?? null;
        $result['region'] = $data['regionName'] ?? null;
        $result['country'] = $data['country'] ?? null;
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['login_csrf'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Refresh the page.';
    } else {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare(
            "SELECT id, name, username, email, password, role,
             status, email_verified_at, session_version
             FROM admins
             WHERE username = ? OR email = ?
             LIMIT 1"
        );

        $stmt->execute([$login, $login]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || !password_verify($password, $admin['password'])) {
            $error = 'Incorrect username/email or password.';
        } elseif ($admin['status'] !== 'active') {
            $error = 'Your account is disabled.';
        } elseif (empty($admin['email_verified_at'])) {
            $error = 'Please verify your email first.';
        } else {
            session_regenerate_id(true);

            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_role'] = $admin['role'];
            $_SESSION['admin_login_time'] = time();
            $_SESSION['admin_last_activity'] = time();
            $_SESSION['admin_session_version'] = (int)$admin['session_version'];
            $ip = getClientIp();
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            [$device, $browser, $os] = getDeviceDetails($ua);
            $location = getApproxLocation($ip);

            $sessionHash = hash('sha256', session_id());

            try {
                $log = $pdo->prepare("
                    INSERT INTO admin_login_history
                    (
                        admin_id, session_hash, ip_address,
                        device_type, browser, operating_system,
                        user_agent, city, region, country,
                        login_time, last_activity
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");

                $log->execute([
                    (int)$admin['id'],
                    $sessionHash,
                    $ip,
                    $device,
                    $browser,
                    $os,
                    $ua,
                    $location['city'],
                    $location['region'],
                    $location['country']
                ]);

                $_SESSION['admin_login_history_id'] = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                // Do not expose internal database errors on the login screen.
                error_log('Admin login history error: ' . $e->getMessage());
            }

            unset($_SESSION['login_csrf']);

            header('Location: dashboard.php');
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Access | Leather Store BD</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;background:#05040c;color:white;font-family:Arial,sans-serif;overflow:hidden}
.scene{position:fixed;inset:0;perspective:1000px;overflow:hidden;background:radial-gradient(ellipse at 50% 45%,#282044 0%,#100d20 35%,#05040c 75%)}
.ambient{position:absolute;width:600px;height:600px;border-radius:50%;filter:blur(100px);opacity:.35;animation:float 8s ease-in-out infinite alternate}
.ambient.one{background:#5735bb;left:-180px;top:-180px}
.ambient.two{background:#007fbd;right:-180px;bottom:-180px;animation-delay:-4s}
@keyframes float{to{transform:translate(100px,80px) scale(1.3)}}
.mask-scene{position:absolute;width:340px;height:420px;left:50%;top:47%;transform:translate(-50%,-50%);perspective:900px;animation:maskFloat 6s ease-in-out infinite}
.mask{width:230px;height:290px;position:absolute;left:55px;top:45px;border-radius:48% 48% 43% 43%;background:radial-gradient(ellipse at 35% 25%,#b5a9b9,#51465d 32%,#17131e 75%);box-shadow:inset -25px -20px 40px #08070d,inset 15px 10px 35px #c0b5d2,0 0 50px #8155d5,0 0 100px #34206d;transform-style:preserve-3d;animation:maskTurn 9s ease-in-out infinite alternate}
.mask:before{content:"";position:absolute;width:55px;height:22px;left:35px;top:120px;border-radius:50%;background:#05030a;box-shadow:105px 0 #05030a,0 0 15px #b000ff,105px 0 15px #b000ff}
.mask:after{content:"";position:absolute;width:85px;height:35px;left:72px;bottom:48px;border-bottom:5px solid #08060b;border-radius:0 0 50% 50%;box-shadow:0 7px 12px #a000ff}
.eye-glow{position:absolute;width:25px;height:12px;top:135px;left:70px;background:#e9aaff;border-radius:50%;box-shadow:0 0 12px 5px #a900ff,0 0 35px #a900ff;animation:eyePulse 1.8s infinite alternate}
.eye-glow.right{left:175px}
@keyframes eyePulse{to{opacity:.35;box-shadow:0 0 5px #a900ff}}
@keyframes maskTurn{from{transform:rotateY(-13deg) rotateX(3deg)}to{transform:rotateY(13deg) rotateX(-3deg)}}
@keyframes maskFloat{0%,100%{margin-top:-12px}50%{margin-top:15px}}
.lightning{position:fixed;inset:0;pointer-events:none;background:#c5a8ff;opacity:0;z-index:2;animation:lightning 7s infinite}
@keyframes lightning{0%,93%,95%,97%,100%{opacity:0}94%{opacity:.13}96%{opacity:.20}}
.login-wrap{position:relative;z-index:5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-card{width:100%;max-width:410px;padding:36px;border:1px solid rgba(190,160,255,.35);border-radius:20px;background:rgba(13,11,25,.78);backdrop-filter:blur(20px);box-shadow:0 0 30px rgba(127,69,255,.2),0 0 80px rgba(0,174,255,.1),inset 0 0 30px rgba(255,255,255,.025)}
.brand{text-align:center;margin-bottom:28px}
.brand h1{font-size:25px;letter-spacing:2px;color:#e6d9ff;text-shadow:0 0 15px #8f55ff}
.brand p{color:#aaa1bd;font-size:12px;letter-spacing:3px;margin-top:9px}
label{display:block;color:#c9c1d9;font-size:13px;margin:17px 0 8px}
input{width:100%;padding:14px;border:1px solid #39314f;border-radius:8px;background:rgba(255,255,255,.045);color:white;outline:none}
input:focus{border-color:#a77aff;box-shadow:0 0 15px #7041bb55}
.password-box{position:relative}
.password-box input{padding-right:55px}
.toggle{position:absolute;right:7px;top:6px;height:39px;width:42px;border:0;background:transparent;color:#d7b9ff;cursor:pointer}
.login-btn{width:100%;margin-top:25px;padding:15px;border:0;border-radius:8px;background:linear-gradient(100deg,#6639bb,#176fa7);color:white;font-weight:bold;letter-spacing:1px;cursor:pointer;box-shadow:0 0 20px #7041bb55;transition:.3s}
.login-btn:hover{transform:translateY(-3px);box-shadow:0 0 30px #925bff99}
.signup{text-align:center;margin-top:23px;font-size:13px;color:#aaa1bd}
.signup a{color:#c9a4ff;text-decoration:none}
.signup a:hover{text-decoration:underline}
.error{background:#541e35;border:1px solid #a53a65;color:#ffb9cf;padding:11px;border-radius:7px;font-size:13px;margin-bottom:15px}
@media(max-width:600px){.mask-scene{transform:translate(-50%,-50%) scale(.85);opacity:.35}.login-card{padding:27px 22px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation-duration:.01ms!important;animation-iteration-count:1!important}}
</style>
</head>

<body>
<div class="scene">
    <div class="ambient one"></div>
    <div class="ambient two"></div>
    <div class="mask-scene">
        <div class="mask"></div>
        <div class="eye-glow"></div>
        <div class="eye-glow right"></div>
    </div>
</div>
<div class="lightning"></div>

<div class="login-wrap">
<div class="login-card">
    <div class="brand">
        <h1>LEATHER STORE BD</h1>
        <p>SECURE ADMIN ACCESS</p>
    </div>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token"
        value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <label>Username or Email</label>
        <input type="text" name="login" placeholder="Enter username or email" required>

        <label>Password</label>
        <div class="password-box">
            <input type="password" id="password" name="password" placeholder="Enter password" required>
            <button type="button" class="toggle" onclick="togglePassword()">👁</button>
        </div>
        <div style="text-align:right;margin-top:8px;">
        <a href="forgot-password.php"
       style="color:#c9a4ff;text-decoration:none;font-size:13px;">
        Forgot Password?
        </a>
        </div>
        <button type="submit" class="login-btn">ENTER ADMIN PANEL</button>
    </form>

    <div class="signup">
        Need an admin account?
        <a href="signup.php">Admin Sign Up</a>
        <br><br>
        <small>Invite only • Approval required</small>
    </div>
</div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const button = document.querySelector('.toggle');

    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = '🙈';
    } else {
        input.type = 'password';
        button.textContent = '👁';
    }
}
</script>
</body>
</html>
