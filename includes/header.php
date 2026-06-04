<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= isset($page_title) ? htmlspecialchars($page_title) : 'Dashboard' ?> — Vormox Automation Cloud</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=JetBrains+Mono:wght@300;400;500&family=Instrument+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    
    :root, [data-theme="dark"] {
      --bg: #050810; --bg2: #070c18; --surface: #0d1426; --surface2: #111b35;
      --border: rgba(99,179,237,0.12); --border-strong: rgba(99,179,237,0.25);
      --accent: #3b82f6; --accent2: #60a5fa; --accent-glow: rgba(59,130,246,0.35);
      --accent-green: #22d3ee; --accent-purple: #a78bfa; --accent-orange: #fb923c; --accent-red: #f87171;
      --text: #e8edf8; --text-muted: #7a8aa8; --text-dim: #3a4a68;
      --font-head: 'Syne', sans-serif; --font-mono: 'JetBrains Mono', monospace;
      --font-body: 'Instrument Sans', sans-serif; --radius: 14px;
    }

    [data-theme="light"] {
      --bg: #f8fafc; --bg2: #f1f5f9; --surface: #ffffff; --surface2: #e2e8f0;
      --border: #e2e8f0; --border-strong: #cbd5e1;
      --accent: #2563eb; --accent2: #3b82f6; --accent-glow: rgba(37,99,235,0.15);
      --accent-green: #0891b2; --accent-purple: #7c3aed; --accent-orange: #ea580c; --accent-red: #dc2626;
      --text: #0f172a; --text-muted: #475569; --text-dim: #64748b;
    }

    body { background: var(--bg); color: var(--text); font-family: var(--font-body); min-height: 100vh; display: flex; overflow-x: hidden; transition: background 0.3s, color 0.3s; }
    
    /* Light Mode UI Adjustments */
    [data-theme="light"] .welcome-banner { background: linear-gradient(160deg, rgba(37,99,235,0.08), rgba(37,99,235,0.02)); border-color: rgba(37,99,235,0.2); }
    [data-theme="light"] .card { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); }
    [data-theme="light"] aside { background: var(--bg); }
    [data-theme="light"] header { background: rgba(255,255,255,0.8); }
    [data-theme="light"] .profile-dropdown { box-shadow: 0 10px 40px rgba(0,0,0,0.1); }

    /* Layout & Sidebar */
    aside { width: 260px; background: rgba(5,8,16,.95); border-right: 1px solid var(--border); padding: 24px; display: flex; flex-direction: column; z-index: 10; flex-shrink: 0; transition: background 0.3s; }
    .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; font-family: var(--font-head); font-size: 20px; font-weight: 800; color: var(--text); letter-spacing: -.5px; margin-bottom: 48px; }
    .logo-icon { width: 32px; height: 32px; background: linear-gradient(135deg,var(--accent),var(--accent-green)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: #fff; box-shadow: 0 0 20px var(--accent-glow); }
    .logo span { color: var(--accent2); }
    .nav-label { margin: 24px 0 8px 16px; font-size: 11px; font-family: var(--font-mono); color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; }
    .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: var(--text-muted); text-decoration: none; border-radius: 8px; font-weight: 500; font-size: 14px; transition: all 0.2s; margin-bottom: 4px; }
    .nav-item i { width: 20px; text-align: center; }
    .nav-item:hover { background: rgba(59,130,246,.08); color: var(--text); }
    .nav-item.active { background: var(--accent); color: #fff; box-shadow: 0 4px 12px rgba(59,130,246,.25); }
    .sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding-top: 24px; }
    
    main { flex: 1; display: flex; flex-direction: column; position: relative; height: 100vh; overflow-y: auto; }
    .grid-bg { position: absolute; inset: 0; pointer-events: none; z-index: 0; background-image: linear-gradient(var(--border) 1px,transparent 1px), linear-gradient(90deg,var(--border) 1px,transparent 1px); background-size: 60px 60px; }
    
    /* Top Header - Z-INDEX MAXED TO PREVENT OVERLAPPING BUGS */
    header { padding: 24px 48px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; z-index: 9999; background: rgba(5,8,16,.65); backdrop-filter: blur(20px); position: sticky; top: 0; transition: background 0.3s; }
    .header-title { font-family: var(--font-head); font-size: 24px; font-weight: 700; color: var(--text); }
    .header-actions { display: flex; align-items: center; gap: 24px; }
    
    /* Theme Toggle */
    .theme-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-muted); width: 36px; height: 36px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .theme-toggle:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); }
    [data-theme="dark"] .fa-moon { display: none; }
    [data-theme="light"] .fa-sun { display: none; }

    /* Profile & Dropdown Settings - PERFECTED */
    .profile-wrapper { position: relative; display: flex; align-items: center; cursor: pointer; padding: 10px 0; }
    .user-profile { display: flex; align-items: center; gap: 12px; }
    .avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--accent2), var(--accent-purple)); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; color: #fff; }
    
    .profile-dropdown { 
        position: absolute; top: 100%; right: 0; 
        background: var(--bg2); /* Richer solid background */
        border: 1px solid var(--border-strong); 
        border-radius: 12px; width: 220px; 
        box-shadow: 0 10px 50px rgba(0,0,0,0.5); /* Heavy shadow for depth */
        opacity: 0; visibility: hidden; transform: translateY(-10px); 
        transition: opacity 0.2s, transform 0.2s, visibility 0.2s; 
        display: flex; flex-direction: column; gap: 4px; padding: 8px; z-index: 10000; 
    }
    
    /* The invisible bridge that prevents the hover from dropping */
    .profile-wrapper::after {
        content: '';
        position: absolute;
        top: 100%; 
        left: 0;
        right: 0;
        height: 20px;
        background: transparent;
        z-index: 999;
    }

    .profile-wrapper:hover .profile-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
    
    .p-dropdown-item { 
        padding: 12px 16px; color: var(--text); text-decoration: none; 
        font-size: 14px; font-weight: 500; border-radius: 8px; transition: background 0.2s, color 0.2s; 
        display: flex; align-items: center; gap: 12px; 
    }
    .p-dropdown-item i { color: var(--text-muted); width: 16px; text-align: center; transition: color 0.2s; }
    .p-dropdown-item:hover { background: var(--surface2); color: var(--accent2); }
    .p-dropdown-item:hover i { color: var(--accent2); }
    
    /* Shared Components */
    .content-area { padding: 48px; z-index: 1; flex: 1; max-width: 1400px; margin: 0 auto; width: 100%; }
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 24px; position: relative; overflow: hidden; display: flex; flex-direction: column; transition: background 0.3s, border-color 0.3s; }
    .btn-primary { padding: 12px 24px; background: var(--accent); color: #fff; border: none; border-radius: 8px; font-family: var(--font-body); font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; box-shadow: 0 4px 20px rgba(59,130,246,.3); transition: all 0.2s; }
    .btn-primary:hover { background: #2563eb; transform: translateY(-1px); }
    .btn-ghost { padding: 6px 12px; background: transparent; color: var(--text-muted); border: 1px solid var(--border); border-radius: 6px; font-size: 13px; cursor: pointer; transition: all 0.2s; text-decoration: none; }
    .btn-ghost:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); }

    /* Dashboard Specific Overrides */
    .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-top: 32px; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .card-title { font-family: var(--font-head); font-size: 18px; font-weight: 600; color: var(--text); }
    .card-label { font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; }
    .card-value { font-family: var(--font-head); font-size: 32px; font-weight: 700; color: var(--text); }
    .card-sub { font-size: 14px; color: var(--text-dim); margin-top: 8px; }
    .welcome-banner { background: linear-gradient(160deg, rgba(59,130,246,.12), rgba(59,130,246,.02)); border: 1px solid rgba(59,130,246,.2); border-radius: 16px; padding: 32px; display: flex; justify-content: space-between; align-items: center; transition: background 0.3s, border-color 0.3s; }
    .welcome-text h2 { font-family: var(--font-head); font-size: 28px; margin-bottom: 8px; color: var(--text); }
    .welcome-text p { color: var(--text-muted); line-height: 1.6; }
    
    /* Lists & Scrollable Tags */
    .split-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 24px; }
    
    .list-group { 
        display: flex; flex-direction: column; gap: 12px; 
        max-height: 250px; 
        overflow-y: auto; padding-right: 8px; 
    }
    .list-group::-webkit-scrollbar { width: 6px; }
    .list-group::-webkit-scrollbar-track { background: transparent; }
    .list-group::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 10px; }
    .list-group::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

    .list-item { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 16px; background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; transition: border-color 0.2s, background 0.3s; }
    .list-item:hover { border-color: var(--border-strong); }
    .list-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: rgba(59,130,246,0.1); color: var(--accent2); flex-shrink: 0; }
    .icon-green { background: rgba(34,211,238,0.1); color: var(--accent-green); }
    .list-content { flex: 1; }
    .list-title { font-weight: 600; font-size: 15px; color: var(--text); margin-bottom: 4px; }
    .list-desc { font-size: 13px; color: var(--text-muted); line-height: 1.5; }
    .list-meta { text-align: right; flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
    .list-time { font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); }
    
    .tag { padding: 4px 10px; border-radius: 100px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
    .tag-open { background: rgba(34,211,238,0.1); color: var(--accent-green); border: 1px solid rgba(34,211,238,0.2); }
    .tag-closed { background: var(--surface2); color: var(--text-muted); border: 1px solid var(--border); }
    
    @media (max-width: 1024px) { .split-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body data-theme="<?= htmlspecialchars($user['theme'] ?? 'dark') ?>">

<?php include 'sidebar.php'; ?>

<main>
  <div class="grid-bg"></div>
  <header>
    <div class="header-title"><?= isset($header_title) ? htmlspecialchars($header_title) : 'Control Plane' ?></div>
    
    <div class="header-actions">
      <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle Theme">
        <i class="fa-solid fa-sun"></i>
        <i class="fa-solid fa-moon"></i>
      </button>

      <div class="profile-wrapper">
        <div class="user-profile">
          <div style="text-align: right;">
            <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
            <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($user['email']) ?></div>
          </div>
          <div class="avatar"><?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?></div>
        </div>

        <div class="profile-dropdown">
          <a href="profile.php" class="p-dropdown-item"><i class="fa-solid fa-user"></i> Profile Settings</a>
          <a href="billing.php" class="p-dropdown-item"><i class="fa-solid fa-credit-card"></i> Billing Settings</a>
          <a href="logout.php" class="nav-item" style="color: var(--accent-red);">
              <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out</a>
        </div>
      </div>
      
    </div>
  </header>