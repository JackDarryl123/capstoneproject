<?php

/**
 * PEPO Profile Management Drawer (v2.2-Modernized)
 * Optimized for: Visuals, AJAX, and UX Alignment
 */
if (!isset($currentUser) && isset($_SESSION['user_id'])) {
  global $mysqli;
  if ($mysqli) {
    $stmt = $mysqli->prepare('SELECT id, username, email, status, role, location FROM users WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $currentUser = $result->fetch_assoc();
    $stmt->close();
  }
}
?>

<div id="pepoProfileDrawer" class="pepo-root" style="display: none;">
  <div class="pepo-overlay" id="pepoOverlay"></div>

  <div class="pepo-panel" id="pepoPanel">
    <!-- Header -->
    <div class="pepo-header">
      <div class="pepo-header-bg"></div>
      <div class="pepo-header-content">
        <div class="pepo-avatar-wrap">
          <div class="pepo-avatar" id="pepoAvatarPreview">
            <?php echo strtoupper(substr($currentUser['username'] ?? 'U', 0, 1)); ?>
          </div>
        </div>
        <div class="pepo-user-info">
          <h4 class="pepo-name text-white mb-0 font-black tracking-tight"><?php echo htmlspecialchars($currentUser['username'] ?? 'User'); ?></h4>
          <span class="pepo-badge font-bold uppercase tracking-widest"><?php echo htmlspecialchars(strtoupper($currentUser['role'] ?? 'Staff')); ?></span>
        </div>
        <button type="button" class="pepo-close" id="pepoClose"><i class="fas fa-times"></i></button>
      </div>
    </div>

    <!-- Navigation -->
    <div class="pepo-nav-wrap">
      <nav class="pepo-tabs">
        <button type="button" class="pepo-tab active" data-target="pepo-info">General</button>
        <button type="button" class="pepo-tab" data-target="pepo-security">Security</button>
      </nav>
    </div>


    <!-- Body -->
    <div class="pepo-body custom-scrollbar">
      <form id="pepoForm">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

        <div class="pepo-content active" id="pepo-info">
          <div class="pepo-field mb-6">
            <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 block">Current Location</label>
            <div class="pepo-input-group">
              <i class="fas fa-map-marker-alt"></i>
              <input type="text" name="location" value="<?php echo htmlspecialchars($currentUser['location'] ?? ''); ?>" placeholder="Office/Department" class="font-bold text-gray-700">
            </div>
          </div>

          <div class="pepo-stats">
            <div class="pepo-stat-card">
              <span class="label">System Status</span>
              <span class="value text-emerald-600"><i class="fas fa-check-circle mr-1"></i>Active</span>
            </div>
            <div class="pepo-stat-card">
              <span class="label">Account Role</span>
              <span class="value font-black text-blue-600"><?php echo htmlspecialchars(strtoupper($currentUser['role'] ?? 'Staff')); ?></span>
            </div>
          </div>
        </div>

        <div class="pepo-content" id="pepo-security">
          <div class="pepo-field mb-4">
            <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 block">Verify Identity</label>
            <div class="pepo-input-group">
              <i class="fas fa-shield-alt"></i>
              <input type="password" name="current_password" placeholder="Current Password" class="font-bold">
            </div>
          </div>
          <hr class="pepo-divider my-6 border-gray-100">
          <div class="pepo-field mb-4">
            <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 block">New Password</label>
            <div class="pepo-input-group">
              <i class="fas fa-key"></i>
              <input type="password" name="password" id="pepoPass" placeholder="New Password" class="font-bold">
            </div>
            <div class="pepo-strength mt-3">
              <div class="pepo-strength-bar" id="pepoStrengthBar"></div>
            </div>
          </div>
          <div class="pepo-field">
            <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 block">Confirm Password</label>
            <div class="pepo-input-group">
              <i class="fas fa-check-double"></i>
              <input type="password" name="confirm_password" placeholder="Repeat Password" class="font-bold">
            </div>
          </div>
        </div>

        <div id="pepoToast" class="pepo-toast"></div>

        <div class="pepo-footer flex-col">
          <button type="submit" class="pepo-btn-primary mb-3" id="pepoSave">
            <span class="btn-text">Update Profile</span>
            <div class="btn-loader d-none"></div>
          </button>

          <a href="logout.php" class="pepo-btn-logout">
            <i class="fas fa-sign-out-alt mr-2"></i>Logout Account
          </a>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  .pepo-root {
    position: fixed !important;
    inset: 0 !important;
    z-index: 100000 !important;
    font-family: 'Poppins', sans-serif;
    display: flex !important;
    justify-content: flex-end;
    visibility: hidden;
    pointer-events: none;
    transition: visibility 0.4s;
  }

  .pepo-root.active {
    visibility: visible;
    pointer-events: auto;
  }

  .pepo-overlay {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.4);
    backdrop-filter: blur(12px);
    opacity: 0;
    transition: opacity 0.4s ease;
  }

  .pepo-root.active .pepo-overlay {
    opacity: 1;
  }

  .pepo-panel {
    position: relative;
    width: 460px;
    max-width: 95%;
    height: 100%;
    background: #ffffff;
    z-index: 2;
    display: flex;
    flex-direction: column;
    box-shadow: -20px 0 60px rgba(0, 0, 0, 0.2);
    transform: translateX(100%);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .pepo-root.active .pepo-panel {
    transform: translateX(0);
  }

  .pepo-header {
    position: relative;
    padding: 3.5rem 2.5rem 2.5rem;
    color: white;
    overflow: hidden;
  }

  .pepo-header-bg {
    position: absolute;
    inset: 0;
    background: #0f172a;
    z-index: -1;
  }

  .pepo-header-content {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    position: relative;
  }

  .pepo-avatar {
    width: 70px;
    height: 70px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 22px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    font-weight: 900;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
  }

  .pepo-badge {
    font-size: 0.65rem;
    background: rgba(255, 255, 255, 0.15);
    padding: 3px 10px;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
  }

  .pepo-close {
    position: absolute;
    top: -1.5rem;
    right: -0.5rem;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: white;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: 0.2s;
  }

  .pepo-close:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: rotate(90deg);
  }

  .pepo-nav-wrap {
    padding: 1rem 2rem;
    background: white;
    border-bottom: 1px solid #f1f5f9;
  }

  .pepo-tabs {
    display: flex;
    background: #f8fafc;
    padding: 5px;
    border-radius: 14px;
    border: 1px solid #f1f5f9;
  }

  .pepo-tab {
    flex: 1;
    border: none;
    background: transparent;
    padding: 10px;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 800;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    transition: 0.2s;
  }

  .pepo-tab.active {
    background: white;
    color: #1d4ed8;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
  }

  .pepo-body {
    flex: 1;
    overflow-y: auto;
    padding: 2rem;
  }

  .pepo-content {
    display: none;
    animation: pepoFade 0.3s ease;
  }

  @keyframes pepoFade {
    from {
      opacity: 0;
      transform: translateY(10px);
    }

    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .pepo-content.active {
    display: block;
  }

  .pepo-input-group {
    display: flex;
    align-items: center;
    background: #f8fafc;
    border: 2px solid #f1f5f9;
    border-radius: 16px;
    padding: 0 1rem;
    transition: 0.3s;
  }

  .pepo-input-group:focus-within {
    border-color: #3b82f6;
    background: white;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
  }

  .pepo-input-group i {
    color: #94a3b8;
    font-size: 0.9rem;
  }

  .pepo-input-group input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 14px;
    outline: none;
    font-size: 0.95rem;
  }

  .pepo-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
  }

  .pepo-stat-card {
    background: #f8fafc;
    padding: 1.25rem;
    border-radius: 20px;
    border: 1px solid #f1f5f9;
    transition: 0.3s;
  }

  .pepo-stat-card:hover {
    background: white;
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
  }

  .pepo-stat-card .label {
    display: block;
    font-size: 0.65rem;
    font-weight: 800;
    color: #94a3b8;
    text-transform: uppercase;
    margin-bottom: 0.5rem;
    letter-spacing: 0.05em;
  }

  .pepo-stat-card .value {
    font-weight: 800;
    font-size: 1rem;
    display: block;
  }

  .pepo-strength {
    height: 6px;
    background: #f1f5f9;
    border-radius: 10px;
    overflow: hidden;
  }

  .pepo-strength-bar {
    height: 100%;
    width: 0;
    transition: 0.5s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .pepo-footer {
    display: flex;
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 1px solid #f1f5f9;
  }

  .pepo-btn-primary {
    width: 100%;
    background: #1d4ed8;
    color: white;
    border: none;
    padding: 16px;
    border-radius: 18px;
    font-weight: 800;
    font-size: 0.95rem;
    cursor: pointer;
    transition: 0.3s;
    box-shadow: 0 10px 15px -3px rgba(29, 78, 216, 0.3);
  }

  .pepo-btn-primary:hover {
    background: #1e40af;
    transform: translateY(-2px);
    box-shadow: 0 20px 25px -5px rgba(29, 78, 216, 0.4);
  }

  .pepo-btn-logout {
    width: 100%;
    background: #fef2f2;
    color: #dc2626;
    border: 2px solid #fee2e2;
    padding: 14px;
    border-radius: 18px;
    font-weight: 800;
    font-size: 0.9rem;
    text-align: center;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: 0.3s;
  }

  .pepo-btn-logout:hover {
    background: #fee2e2;
    border-color: #fecaca;
    color: #b91c1c;
  }

  .pepo-toast {
    position: absolute;
    bottom: 120px;
    left: 2rem;
    right: 2rem;
    padding: 12px;
    border-radius: 12px;
    color: white;
    font-size: 0.85rem;
    font-weight: 600;
    text-align: center;
    transform: translateY(10px);
    opacity: 0;
    z-index: 10;
    pointer-events: none;
    transition: 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .pepo-toast.show {
    transform: translateY(0);
    opacity: 1;
  }

  .pepo-toast.success {
    background: #059669;
    box-shadow: 0 10px 15px -3px rgba(5, 150, 105, 0.3);
  }

  .pepo-toast.error {
    background: #dc2626;
    box-shadow: 0 10px 15px -3px rgba(220, 38, 38, 0.3);
  }

  .custom-scrollbar::-webkit-scrollbar {
    width: 5px;
  }

  .custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
  }

  .custom-scrollbar::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
  }

  .btn-loader {
    width: 18px;
    height: 18px;
    border: 3px solid rgba(255, 255, 255, 0.3);
    border-radius: 50%;
    border-top-color: white;
    animation: pepoSpin 0.8s linear infinite;
    display: inline-block;
  }

  @keyframes pepoSpin {
    to {
      transform: rotate(360deg);
    }
  }
</style>

<script>
  (function() {
    "use strict";
    document.addEventListener('DOMContentLoaded', function() {
      const root = document.getElementById('pepoProfileDrawer');
      const panel = document.getElementById('pepoPanel');
      const form = document.getElementById('pepoForm');
      const toast = document.getElementById('pepoToast');
      const saveBtn = document.getElementById('pepoSave');

      if (!root) return;

      function openDrawer(e) {
        e.preventDefault();
        e.stopPropagation();
        root.style.display = 'block';
        setTimeout(() => root.classList.add('active'), 10);
        document.body.style.overflow = 'hidden';
      }

      function closeDrawer() {
        root.classList.remove('active');
        document.body.style.overflow = '';
        setTimeout(() => {
          if (!root.classList.contains('active')) root.style.display = 'none';
        }, 400);
      }

      document.addEventListener('click', function(e) {
        if (e.target.closest('[data-pepo-toggle="profile"]')) {
          openDrawer(e);
        }
      });

      panel.addEventListener('click', e => e.stopPropagation());
      document.getElementById('pepoOverlay').addEventListener('click', closeDrawer);
      document.getElementById('pepoClose').addEventListener('click', closeDrawer);
      document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeDrawer();
      });

      document.querySelectorAll('.pepo-tab').forEach(btn => {
        btn.addEventListener('click', function() {
          document.querySelectorAll('.pepo-tab, .pepo-content').forEach(el => el.classList.remove('active'));
          this.classList.add('active');
          document.getElementById(this.dataset.target).classList.add('active');
        });
      });

      const passInput = document.getElementById('pepoPass');
      const bar = document.getElementById('pepoStrengthBar');
      passInput.addEventListener('input', function() {
        const val = this.value;
        let score = 0;
        if (val.length > 7) score += 33;
        if (/[A-Z]/.test(val)) score += 33;
        if (/[0-9]/.test(val)) score += 34;
        bar.style.width = score + '%';
        bar.style.backgroundColor = score < 40 ? '#ef4444' : score < 80 ? '#f59e0b' : '#10b981';
      });

      form.addEventListener('submit', async function(e) {
        e.preventDefault();
        saveBtn.disabled = true;
        saveBtn.querySelector('.btn-text').classList.add('d-none');
        saveBtn.querySelector('.btn-loader').classList.remove('d-none');
        const formData = new FormData(this);
        formData.append('update', '1');
        try {
          const response = await fetch('process.php', {
            method: 'POST',
            body: formData
          });
          if (response.ok) {
            showToast('Settings saved successfully!', 'success');
            setTimeout(() => window.location.reload(), 1000);
          } else {
            throw new Error();
          }
        } catch (err) {
          showToast('Update failed. Verify password.', 'error');
          saveBtn.disabled = false;
          saveBtn.querySelector('.btn-text').classList.remove('d-none');
          saveBtn.querySelector('.btn-loader').classList.add('d-none');
        }
      });

      function showToast(msg, type) {
        toast.textContent = msg;
        toast.className = `pepo-toast show ${type}`;
        setTimeout(() => toast.classList.remove('show'), 3000);
      }
    });
  })();
</script>