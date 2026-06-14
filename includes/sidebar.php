<?php $current_page = basename($_SERVER['PHP_SELF']); ?>

<style>
.logo img {
  height: 40px;         /* matches the landing-page nav logo (.brand-logo) */
  width: auto;
  max-width: 100%;      /* stops the logo overflowing a narrow sidebar */
  object-fit: contain;  /* preserves aspect ratio, no squish/stretch */
}

/* Sensible default if no theme attribute is set yet */
.dark-logo  { display: none; }
.light-logo { display: block; }

[data-theme="dark"]  .light-logo { display: none; }
[data-theme="dark"]  .dark-logo  { display: block; }

[data-theme="light"] .dark-logo  { display: none; }
[data-theme="light"] .light-logo { display: block; }
</style>

<aside>
  <a href="dashboard.php" class="logo">
    <img src="assets/images/logo-b.svg" alt="Vormox" class="dark-logo">
    <img src="assets/images/logo.svg" alt="Vormox" class="light-logo">
  </a>
  <nav>
    <div class="nav-label">Infrastructure</div>
    <a href="dashboard.php" class="nav-item <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
      <i class="fa-solid fa-house"></i> Overview
    </a>
    <a href="panels.php" class="nav-item <?= $current_page == 'panels.php' ? 'active' : '' ?>">
      <i class="fa-solid fa-server"></i> Panels
    </a>
    <a href="invoices.php" class="nav-item <?= in_array($current_page, ['invoices.php', 'view-invoice.php']) ? 'active' : '' ?>">
      <i class="fa-solid fa-credit-card"></i> Billing
    </a>
    <a href="#" class="nav-item">
      <i class="fa-solid fa-clock-rotate-left"></i> Backups
    </a>
    <a href="#" class="nav-item">
      <i class="fa-solid fa-key"></i> API Keys
    </a>
    
    <div class="nav-label">Help & Updates</div>
    <a href="updates.php" class="nav-item <?= $current_page == 'updates.php' ? 'active' : '' ?>">
      <i class="fa-solid fa-bell"></i> Updates
    </a>
    <a href="tickets.php" class="nav-item <?= in_array($current_page, ['tickets.php', 'new-ticket.php', 'view-ticket.php']) ? 'active' : '' ?>">
      <i class="fa-solid fa-ticket"></i> Tickets
    </a>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php" class="nav-item" style="color: var(--accent-red);">
      <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
    </a>
  </div>
</aside>