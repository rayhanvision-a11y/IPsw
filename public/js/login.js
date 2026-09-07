document.addEventListener('DOMContentLoaded', () => {
  const loginForm         = document.getElementById('login-form');
  const usernameInput     = document.getElementById('username');
  const passwordInput     = document.getElementById('password');
  const togglePasswordBtn = document.getElementById('toggle-password');
  const passEyeIcon       = document.getElementById('pass-eye-icon');
  const errorBox          = document.getElementById('error-box');
  const errorText         = document.getElementById('error-text');

  // Check login status on page load — use RELATIVE paths (works in any subfolder)
  async function checkAuthStatus() {
    try {
      const res  = await fetch('api/auth/status.php');
      const data = await res.json();
      if (data.loggedIn) window.location.href = 'index.php' + window.location.search;
    } catch (e) {
      console.error('Error checking auth status:', e);
    }
  }
  checkAuthStatus();

  // Toggle Password Visibility
  if (togglePasswordBtn && passwordInput) {
    togglePasswordBtn.addEventListener('click', () => {
      const isPass = passwordInput.getAttribute('type') === 'password';
      passwordInput.setAttribute('type', isPass ? 'text' : 'password');
      passEyeIcon.className = isPass ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    });
  }

  // Handle Login Form Submit
  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const username = usernameInput.value.trim();
      const password = passwordInput.value.trim();

      if (!username || !password) {
        showError('Please fill in both username and password.');
        return;
      }

      errorBox.style.display = 'none';

      try {
        const res = await fetch('api/auth/login.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username, password })
        });

        const data = await res.json();

        if (res.ok && data.success) {
          window.location.href = 'index.php' + window.location.search;
        } else {
          showError(data.error || 'Invalid credentials. Please try again.');
        }
      } catch (err) {
        showError('Network error. Failed to connect to server.');
      }
    });
  }

  function showError(msg) {
    if (errorBox && errorText) {
      errorText.textContent = msg;
      errorBox.style.display = 'flex';
      errorBox.style.animation = 'none';
      errorBox.offsetHeight; // trigger reflow for shake animation
      errorBox.style.animation = null;
    }
  }
});
