<?php
session_start();
if (!empty($_SESSION['loggedIn'])) {
    $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: index.php' . $queryString);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - IPSW Master Control Panel</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="public/css/style.css">
  <style>
    body.login-theme {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background-color: #090d16;
      background-image:
        radial-gradient(at 0% 0%, rgba(121, 40, 202, 0.18) 0px, transparent 50%),
        radial-gradient(at 100% 100%, rgba(0, 242, 254, 0.15) 0px, transparent 50%);
      background-attachment: fixed;
      color: #f1f5f9;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }
    .login-container { width: 100%; max-width: 440px; animation: fadeInUp 0.5s ease-out; }
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(20px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .login-card {
      background: rgba(21, 29, 46, 0.65);
      border: 1px solid rgba(0, 242, 254, 0.25);
      border-radius: 16px;
      padding: 2.25rem 2rem;
      box-shadow: 0 20px 40px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.08);
      backdrop-filter: blur(16px);
    }
    .login-header { text-align: center; margin-bottom: 2rem; }
    .login-header h2 {
      font-family: 'Outfit', sans-serif;
      font-size: 1.35rem; font-weight: 700; color: #fff; margin-bottom: 0.4rem;
    }
    .login-header p { font-size: 0.85rem; color: #94a3b8; }
    .login-form-group { margin-bottom: 1.5rem; position: relative; }
    .login-form-group label {
      display: block; font-size: 0.8rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.05em;
      color: #00f2fe; margin-bottom: 0.6rem;
    }
    .input-wrapper { position: relative; }
    .input-wrapper > i {
      position: absolute; left: 1rem; top: 50%;
      transform: translateY(-50%); color: #64748b;
      font-size: 1rem; pointer-events: none;
    }
    .login-control {
      width: 100%; padding: 0.85rem 1rem 0.85rem 2.75rem;
      background: rgba(15,23,42,0.6); border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px; color: #fff; font-size: 0.95rem; transition: all 0.25s ease;
      box-sizing: border-box;
    }
    .login-control:focus {
      outline: none; border-color: #00f2fe;
      box-shadow: 0 0 12px rgba(0,242,254,0.25); background: rgba(15,23,42,0.85);
    }
    .toggle-pass-btn {
      position: absolute; right: 1rem; top: 50%; transform: translateY(-50%);
      background: transparent; border: none; color: #64748b; cursor: pointer; font-size: 1rem; padding: 0;
    }
    .toggle-pass-btn:hover { color: #fff; }
    .login-btn-submit {
      width: 100%; background: linear-gradient(135deg, #00f2fe 0%, #4facfe 100%);
      color: #040914; font-weight: 700; font-size: 0.95rem; padding: 0.85rem;
      border: none; border-radius: 10px; cursor: pointer;
      box-shadow: 0 4px 15px rgba(0,242,254,0.35); transition: all 0.25s ease;
      display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 2rem;
    }
    .login-btn-submit:hover {
      transform: translateY(-1px); box-shadow: 0 6px 20px rgba(0,242,254,0.5); color: #000;
    }
    .login-error-box {
      background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3);
      color: #f87171; padding: 0.75rem 1rem; border-radius: 8px;
      font-size: 0.88rem; margin-bottom: 1.5rem;
      display: none; align-items: center; gap: 0.5rem;
      animation: shake 0.3s ease-in-out;
    }
    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25%       { transform: translateX(-6px); }
      75%       { transform: translateX(6px); }
    }
  </style>
</head>
<body class="login-theme">
  <div class="login-container">

    <div style="margin-bottom: 2rem; display:flex; justify-content:center;">
      <img src="https://visiontech.com.bd/logo/vision-logo-latest.svg" alt="Vision Technologies Logo" style="width:260px; height:auto; display:block;">
    </div>

    <div class="login-card">
      <div class="login-header">
        <h2>System Control Access</h2>
        <p>Enter administrative credentials to proceed</p>
      </div>

      <div class="login-error-box" id="error-box">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span id="error-text">Invalid username or password.</span>
      </div>

      <form id="login-form">
        <div class="login-form-group">
          <label for="username">Username</label>
          <div class="input-wrapper">
            <i class="fa-solid fa-user"></i>
            <input type="text" id="username" class="login-control" placeholder="e.g. admin" required autocomplete="username">
          </div>
        </div>

        <div class="login-form-group">
          <label for="password">Password</label>
          <div class="input-wrapper">
            <i class="fa-solid fa-lock"></i>
            <input type="password" id="password" class="login-control" placeholder="••••••••" required autocomplete="current-password">
            <button type="button" class="toggle-pass-btn" id="toggle-password" title="Toggle password visibility">
              <i class="fa-solid fa-eye" id="pass-eye-icon"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="login-btn-submit">
          <i class="fa-solid fa-shield-halved"></i> Authenticate System
        </button>
      </form>
    </div>

  </div>
  <script src="public/js/login.js"></script>
</body>
</html>
