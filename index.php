<?php
require_once 'config.php';

try {
    $stmt = $pdo->query("SELECT id, min_nodes, max_nodes, price_per_node FROM pricing_tiers ORDER BY min_nodes ASC");
    $tiers = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("Pricing query failed: " . $e->getMessage());
    $tiers = [];
}

$tierCount = count($tiers);

function tierName($index, $total) {
    if ($total === 3) {
        return ['Starter', 'Pro', 'Enterprise'][$index];
    }
    if ($total === 2) {
        return ['Starter', 'Pro'][$index];
    }
    if ($total === 1) {
        return 'Standard';
    }
    if ($index === 0) return 'Starter';
    if ($index === $total - 1) return 'Enterprise';
    return 'Tier ' . ($index + 1);
}

function tierNodeRange($tier) {
    $min = (int)$tier['min_nodes'];
    $max = (int)$tier['max_nodes'];
    if ($max >= 999) {
        return $min . '+ Proxmox nodes';
    }
    if ($min === $max) {
        return $min . ' Proxmox node' . ($min === 1 ? '' : 's');
    }
    return $min . '–' . $max . ' Proxmox nodes';
}

function tierFeatures($index, $total) {
    $base = [
        ['Up to defined Proxmox nodes', 'Unlimited VMs / LXC containers', 'Basic automation workflows', 'Backup scheduling', 'Standard support'],
        ['Up to defined Proxmox nodes', 'Unlimited VMs &amp; containers', 'Advanced automation &amp; scheduling', 'HA &amp; live migration automation', 'Terraform &amp; Ansible integration', 'Priority support (4h SLA)'],
        ['Up to defined Proxmox nodes', 'Multi-cluster management', 'SSO / SAML / LDAP', 'Custom SLA &amp; dedicated CSM', 'On-prem deployment option', 'White-glove onboarding'],
    ];
    if ($total === 3) return $base[$index];
    if ($total <= 1) return $base[0];
    if ($index === 0) return $base[0];
    if ($index === $total - 1) return $base[2];
    return $base[1];
}

$featuredIndex = $tierCount >= 2 ? (int)floor(($tierCount - 1) / 2) + ($tierCount === 3 ? 0 : 0) : -1;
if ($tierCount === 3) $featuredIndex = 1;
elseif ($tierCount === 2) $featuredIndex = 1;
elseif ($tierCount === 1) $featuredIndex = 0;
elseif ($tierCount > 3) $featuredIndex = (int)floor($tierCount / 2);
?>
<!DOCTYPE html>
<html lang="en" prefix="og: https://ogp.me/ns#">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>Vormox | Fully Managed Proxmox Automation Cloud SaaS</title>
  <meta name="description" content="Vormox is a fully managed Proxmox cloud automation SaaS. Deploy, migrate, and orchestrate your clusters without maintaining a local control plane. Free 14-day trial." />
  <meta name="author" content="Vormox" />
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />
  <meta name="theme-color" content="#050810" />
  <link rel="canonical" href="https://vormox.com/" />

  <!-- Vormox favicon (global) -->
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <link rel="manifest" href="/site.webmanifest">

  <meta property="og:type" content="website" />
  <meta property="og:url" content="https://vormox.com/" />
  <meta property="og:title" content="Vormox | Fully Managed Proxmox Automation Cloud SaaS" />
  <meta property="og:description" content="Stop maintaining your control plane. Deploy, migrate, and orchestrate your entire Proxmox cluster with our fully managed automation SaaS." />
  <meta property="og:image" content="https://vormox.com/og-image.png" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:image:alt" content="Vormox dashboard automating a Proxmox cluster with HA failover and live migration" />
  <meta property="og:site_name" content="Vormox" />
  <meta property="og:locale" content="en_US" />

  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:site" content="@vormox" />
  <meta name="twitter:creator" content="@vormox" />
  <meta name="twitter:title" content="Vormox | Fully Managed Proxmox Automation Cloud SaaS" />
  <meta name="twitter:description" content="Deploy, migrate, and orchestrate your entire Proxmox cluster with a fully managed automation cloud. Free 14-day trial." />
  <meta name="twitter:image" content="https://vormox.com/twitter-card.png" />
  <meta name="twitter:image:alt" content="Vormox dashboard automating a Proxmox cluster with HA failover and live migration" />

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@300;400;500&display=swap" />
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@300;400;500&display=swap" rel="stylesheet" />

  <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" /></noscript>

  <script type="application/ld+json">{"@context":"https://schema.org","@type":"SoftwareApplication","name":"Vormox","applicationCategory":"BusinessApplication","operatingSystem":"Web, Cloud SaaS","description":"Fully managed enterprise Proxmox VE cloud automation SaaS for deploying, managing, and orchestrating virtual machines and LXC containers at scale without maintaining a local control plane.","url":"https://vormox.com","offers":<?php
    $offers = [];
    foreach ($tiers as $i => $t) {
        $offers[] = [
            '@type' => 'Offer',
            'name' => tierName($i, $tierCount),
            'price' => (string)$t['price_per_node'],
            'priceCurrency' => 'USD'
        ];
    }
    echo json_encode($offers, JSON_UNESCAPED_SLASHES);
  ?>,"publisher":{"@type":"Organization","name":"Vormox","url":"https://vormox.com"}}</script>

  <script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"Vormox","url":"https://vormox.com","logo":"https://vormox.com/logo.png","sameAs":["https://twitter.com/vormox","https://github.com/vormox","https://www.linkedin.com/company/vormox","https://www.youtube.com/@vormox"],"contactPoint":{"@type":"ContactPoint","contactType":"customer support","email":"support@vormox.com","availableLanguage":["English"]}}</script>

  <script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","name":"Vormox","url":"https://vormox.com","potentialAction":{"@type":"SearchAction","target":"https://vormox.com/search?q={search_term_string}","query-input":"required name=search_term_string"}}</script>

  <script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"What is Vormox?","acceptedAnswer":{"@type":"Answer","text":"Vormox is a fully managed enterprise Proxmox VE automation cloud SaaS that lets infrastructure teams deploy, manage, and orchestrate VMs and LXC containers at scale using declarative YAML configs and a REST API."}},{"@type":"Question","name":"Does Vormox support Proxmox HA?","acceptedAnswer":{"@type":"Answer","text":"Yes. Vormox automates High Availability policies, failover rules, and live VM migrations across nodes with zero downtime, available on Pro and Enterprise plans."}},{"@type":"Question","name":"How do I get started with Vormox?","acceptedAnswer":{"@type":"Answer","text":"Since Vormox is a cloud SaaS, there is no local management panel to install. Connect Vormox to your Proxmox VE API endpoint. Vormox auto-discovers nodes, storage, and VMs. You can then define automation workflows via YAML, the UI, or CLI. No credit card required for the 14-day trial."}}]}</script>

  <script type="application/ld+json">{"@context":"https://schema.org","@type":"HowTo","name":"How to automate Proxmox with Vormox","description":"Connect, define and deploy automation workflows on your Proxmox cluster using Vormox.","totalTime":"PT5M","step":[{"@type":"HowToStep","position":1,"name":"Connect Your Cluster","text":"Point Vormox at your Proxmox VE API endpoint with your API token. Nodes, storage pools, and VMs are auto-discovered."},{"@type":"HowToStep","position":2,"name":"Define Your Automation","text":"Write YAML playbooks or use the drag-and-drop workflow builder to set deployment templates, backup schedules, scaling rules and HA policies."},{"@type":"HowToStep","position":3,"name":"Deploy and Scale","text":"Trigger workflows via CLI, REST API, webhooks or schedule. Monitor execution in real-time and rollback instantly if anything goes wrong."}]}</script>

  <script>
    const savedTheme = localStorage.getItem('theme');
    const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    const initialTheme = savedTheme ? savedTheme : 'light';
    document.documentElement.setAttribute('data-theme', initialTheme);
    localStorage.setItem('theme', initialTheme);
  </script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root, [data-theme="dark"] {
      --bg: #06080f; --bg2: #0a0d18; --surface: #11162a; --surface2: #1a2138;
      --border: rgba(148,163,184,0.10); --border-strong: rgba(148,163,184,0.22);
      --accent: #3b82f6; --accent2: #60a5fa; --accent-glow: rgba(59,130,246,0.35);
      --accent-green: #22d3ee; --accent-purple: #a78bfa; --accent-orange: #fb923c;
      --text: #f1f5f9; --text-muted: #94a3b8; --text-dim: #475569;
      --nav-bg: rgba(6,8,15,.65); --nav-bg-scroll: rgba(6,8,15,.9);
      --menu-bg: rgba(6,8,15,.98);
      --grid-line: rgba(59,130,246,.05);
      --terminal-bg: #0a1120; --terminal-bar: rgba(255,255,255,.03);
      --marquee-fade-from: #06080f;
      --shadow-card: 0 4px 20px rgba(0,0,0,.25);
      --shadow-lg: 0 32px 80px rgba(0,0,0,.5);
      --font-head: 'Space Grotesk', 'Inter', system-ui, sans-serif;
      --font-mono: 'JetBrains Mono', ui-monospace, monospace;
      --font-body: 'Inter', system-ui, -apple-system, sans-serif;
      --radius: 14px;
    }

    [data-theme="light"] {
      --bg: #fafbfd; --bg2: #f1f4f9; --surface: #ffffff; --surface2: #f1f5f9;
      --border: rgba(15,23,42,0.08); --border-strong: rgba(15,23,42,0.16);
      --accent: #2563eb; --accent2: #1d4ed8; --accent-glow: rgba(37,99,235,0.18);
      --accent-green: #0891b2; --accent-purple: #7c3aed; --accent-orange: #ea580c;
      --text: #0f172a; --text-muted: #475569; --text-dim: #64748b;
      --nav-bg: rgba(255,255,255,.75); --nav-bg-scroll: rgba(255,255,255,.92);
      --menu-bg: rgba(255,255,255,.98);
      --grid-line: rgba(15,23,42,.05);
      --terminal-bg: #0f172a; --terminal-bar: rgba(255,255,255,.04);
      --marquee-fade-from: #fafbfd;
      --shadow-card: 0 1px 3px rgba(15,23,42,.06), 0 4px 16px rgba(15,23,42,.05);
      --shadow-lg: 0 24px 60px -10px rgba(15,23,42,.18), 0 0 0 1px rgba(15,23,42,.05);
    }

    html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
    body {
      background: var(--bg); color: var(--text);
      font-family: var(--font-body);
      font-size: 16px; line-height: 1.6;
      font-feature-settings: 'cv02','cv03','cv04','cv11','ss01';
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: optimizeLegibility;
      overflow-x: hidden; cursor: none;
      transition: background .3s ease, color .3s ease;
    }
    img, svg { max-width: 100%; display: block; }
    ::selection { background: var(--accent-glow); color: var(--text); }

    .cursor {
      position: fixed; top: 0; left: 0;
      width: 10px; height: 10px;
      background: var(--accent);
      border-radius: 50%;
      pointer-events: none; z-index: 9999;
      will-change: transform;
      transition: width .2s, height .2s;
      mix-blend-mode: screen;
    }
    .cursor-ring {
      position: fixed; top: 0; left: 0;
      width: 36px; height: 36px;
      border: 1.5px solid rgba(59,130,246,.6);
      border-radius: 50%;
      pointer-events: none; z-index: 9998;
      will-change: transform;
      transition: width .25s, height .25s, opacity .2s;
    }

    @media (hover: none) and (pointer: coarse) {
        .cursor, .cursor-ring { display: none !important; }
        body { cursor: auto; }
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: .001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .001ms !important;
        scroll-behavior: auto !important;
      }
      .marquee-track { animation: none !important; }
      .cursor, .cursor-ring { display: none !important; }
      body { cursor: auto; }
    }

    body::before {
      content: ''; position: fixed; inset: 0; pointer-events: none; z-index: 999; opacity: .55;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
    }
    [data-theme="light"] body::before { opacity: .25; mix-blend-mode: multiply; }
    .grid-bg {
      position: fixed; inset: 0; pointer-events: none; z-index: 0;
      background-image: linear-gradient(var(--grid-line) 1px,transparent 1px), linear-gradient(90deg,var(--grid-line) 1px,transparent 1px);
      background-size: 60px 60px;
      mask-image: radial-gradient(ellipse at center, #000 30%, transparent 80%);
      -webkit-mask-image: radial-gradient(ellipse at center, #000 30%, transparent 80%);
    }
    .hero-glow {
      position: absolute; top: -200px; left: 50%; transform: translateX(-50%);
      width: min(900px, 95vw); height: 700px; pointer-events: none;
      background: radial-gradient(ellipse at center,rgba(59,130,246,.22) 0%,rgba(167,139,250,.08) 40%,transparent 70%);
      filter: blur(20px);
    }
    [data-theme="light"] .hero-glow {
      background: radial-gradient(ellipse at center,rgba(59,130,246,.10) 0%,rgba(167,139,250,.05) 40%,transparent 70%);
    }
    nav {
      position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
      padding: 0 clamp(20px,5vw,80px); height: 68px;
      display: flex; align-items: center; justify-content: space-between;
      background: var(--nav-bg); backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px) saturate(180%);
      border-bottom: 1px solid var(--border); transition: background .3s, border-color .3s, height .3s;
    }
    nav.scrolled { background: var(--nav-bg-scroll); height: 60px; }

    .logo {
      display: flex; align-items: center; gap: 10px; text-decoration: none;
      font-family: var(--font-head); font-size: 21px; font-weight: 700;
      color: var(--text); letter-spacing: -.02em;
    }
    .logo-icon {
      width: 34px; height: 34px;
      background: linear-gradient(135deg,var(--accent),var(--accent-green));
      border-radius: 9px; display: flex; align-items: center; justify-content: center;
      font-size: 14px; color: #fff; box-shadow: 0 0 24px var(--accent-glow), inset 0 1px 0 rgba(255,255,255,.2);
    }
    .logo span { color: var(--accent2); }
    .brand-logo { height: 40px; width: auto; display: block; }
    .dark-logo  { display: none; }
    .light-logo { display: block; }
    [data-theme="dark"]  .light-logo { display: none; }
    [data-theme="dark"]  .dark-logo  { display: block; }
    [data-theme="light"] .dark-logo  { display: none; }
    [data-theme="light"] .light-logo { display: block; }
    .nav-links { display: flex; align-items: center; gap: 32px; list-style: none; }
    .nav-links a {
      color: var(--text-muted); text-decoration: none; font-size: 14px;
      font-weight: 500; letter-spacing: -.005em; transition: color .2s; position: relative;
    }
    .nav-links a::after {
      content: ''; position: absolute; bottom: -4px; left: 0;
      width: 0; height: 1.5px; background: var(--accent); transition: width .25s ease;
    }
    .nav-links a:hover { color: var(--text); }
    .nav-links a:hover::after { width: 100%; }
    .nav-cta { display: flex; align-items: center; gap: 12px; }

    .theme-toggle { background: transparent; border: 1px solid var(--border); color: var(--text-muted); width: 36px; height: 36px; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .2s; }
    .theme-toggle:hover { color: var(--text); border-color: var(--border-strong); background: var(--surface2); transform: rotate(15deg); }
    [data-theme="dark"] .fa-moon { display: none; }
    [data-theme="light"] .fa-sun { display: none; }

    .btn {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 10px 20px; border-radius: 9px; font-family: var(--font-body);
      font-size: 14px; font-weight: 600; cursor: pointer; border: none;
      text-decoration: none; transition: all .2s ease;
      letter-spacing: -.005em; line-height: 1;
    }
    .btn-ghost { background: transparent; color: var(--text); border: 1px solid var(--border-strong); }
    .btn-ghost:hover { border-color: var(--text-muted); background: var(--surface2); }
    .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 4px 20px rgba(59,130,246,.3), inset 0 1px 0 rgba(255,255,255,.15); }
    .btn-primary:hover { background: #2563eb; box-shadow: 0 6px 28px rgba(59,130,246,.45), inset 0 1px 0 rgba(255,255,255,.18); transform: translateY(-1px); }
    [data-theme="light"] .btn-primary:hover { background: #1d4ed8; }
    .btn-lg { padding: 14px 28px; font-size: 15px; border-radius: 10px; }

    .hamburger { display: none; flex-direction: column; gap: 5px; cursor: pointer; padding: 4px; background: none; border: none; }
    .hamburger span { display: block; width: 24px; height: 2px; background: var(--text-muted); transition: all .3s; }

    main { position: relative; z-index: 1; }
    section { position: relative; }
    #hero {
      min-height: 100vh; display: flex; flex-direction: column;
      align-items: center; justify-content: center; text-align: center;
      padding: 120px clamp(24px,5vw,80px) 80px; overflow: hidden;
    }
    .hero-badge {
      display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px;
      background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.22);
      border-radius: 100px; font-family: var(--font-mono); font-size: 11.5px;
      color: var(--accent2); margin-bottom: 28px; animation: fadeUp .8s ease both;
      box-shadow: 0 0 30px rgba(59,130,246,.08);
    }
    [data-theme="light"] .hero-badge { background: rgba(37,99,235,.06); border-color: rgba(37,99,235,.2); }
    .badge-dot { width: 6px; height: 6px; background: var(--accent-green); border-radius: 50%; animation: pulse 2s infinite; box-shadow: 0 0 10px var(--accent-green); }
    @keyframes pulse { 0%,100% { opacity:1;transform:scale(1); } 50% { opacity:.5;transform:scale(.8); } }
    .hero-title {
      font-family: var(--font-head);
      font-size: clamp(40px, 7.5vw, 88px);
      font-weight: 700;
      line-height: 1.05; letter-spacing: -.035em; color: var(--text);
      margin-bottom: 22px; animation: fadeUp .8s .1s ease both;
      max-width: 14ch;
    }
    .hero-title .line2 {
      background: linear-gradient(90deg,var(--accent),var(--accent-green),var(--accent-purple));
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
      background-clip: text; background-size: 200%;
      animation: gradShift 4s linear infinite, fadeUp .8s .15s ease both;
    }
    @keyframes gradShift { 0% { background-position:0%; } 100% { background-position:200%; } }
    .hero-sub {
      font-size: clamp(15px, 1.6vw, 19px); color: var(--text-muted); max-width: 620px;
      line-height: 1.65; margin-bottom: 36px; animation: fadeUp .8s .2s ease both;
      letter-spacing: -.005em;
    }
    .hero-actions { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; justify-content: center; animation: fadeUp .8s .3s ease both; }
    .hero-stats {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: clamp(20px, 4vw, 56px);
      width: 100%; max-width: 820px; margin: 72px auto 0;
      padding-top: 44px; border-top: 1px solid var(--border); animation: fadeUp .8s .5s ease both;
    }
    .stat-item { text-align: center; }
    .stat-num { font-family: var(--font-head); font-size: clamp(28px, 3.5vw, 38px); font-weight: 700; color: var(--text); letter-spacing: -.03em; line-height: 1; }
    .stat-num span { color: var(--accent2); }
    .stat-label { font-size: 12.5px; color: var(--text-muted); margin-top: 8px; font-family: var(--font-mono); letter-spacing: .01em; }
    .terminal-wrap { width: min(680px,92vw); margin: 56px auto 0; animation: fadeUp .8s .4s ease both; }
    .terminal {
      background: var(--terminal-bg); border: 1px solid var(--border-strong); border-radius: var(--radius); overflow: hidden;
      box-shadow: var(--shadow-lg), inset 0 1px 0 rgba(255,255,255,.04);
    }
    .terminal-bar {
      padding: 12px 16px; background: var(--terminal-bar);
      border-bottom: 1px solid rgba(255,255,255,.06); display: flex; align-items: center; gap: 8px;
    }
    .t-dot { width: 10px; height: 10px; border-radius: 50%; }
    .t-red { background:#ff5f57; } .t-yellow { background:#febc2e; } .t-green { background:#28c840; }
    .terminal-title { flex:1; text-align:center; font-family:var(--font-mono); font-size:12px; color: #64748b; }
    .terminal-body { padding: 20px clamp(16px, 3vw, 24px); font-family: var(--font-mono); font-size: clamp(11.5px, 1.4vw, 13px); line-height: 1.8; min-height: 180px; white-space: pre-wrap; color: #e8edf8; overflow-x: auto; }
    .t-line { display: flex; gap: 10px; }
    .t-prompt { color: #22d3ee; user-select: none; }
    .t-cmd { color: #f1f5f9; }
    .t-out { color: #94a3b8; padding-left: 20px; }
    .t-success { color: #22d3ee; padding-left: 20px; }
    .t-cursor { display: inline-block; width: 8px; height: 14px; background: var(--accent); animation: blink 1.1s step-end infinite; vertical-align: text-bottom; }
    @keyframes blink { 50% { opacity:0; } }

    .section-label { font-family: var(--font-mono); font-size: 11px; letter-spacing: .15em; text-transform: uppercase; color: var(--accent2); margin-bottom: 14px; font-weight: 500; }
    .section-title { font-family: var(--font-head); font-size: clamp(28px, 4.2vw, 52px); font-weight: 700; letter-spacing: -.03em; line-height: 1.1; color: var(--text); }
    .section-sub { font-size: clamp(15px, 1.5vw, 17px); color: var(--text-muted); line-height: 1.65; max-width: 540px; margin-top: 14px; }

    #features { padding: clamp(80px, 12vw, 120px) clamp(20px,5vw,80px); }
    .features-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 56px; gap: 32px; flex-wrap: wrap; }
    .features-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .feature-card { background: var(--bg2); padding: clamp(28px, 4vw, 40px) clamp(24px, 3.5vw, 36px); position: relative; overflow: hidden; transition: background .25s; }
    .feature-card::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,rgba(59,130,246,.06) 0%,transparent 60%); opacity:0; transition:opacity .3s; }
    .feature-card:hover::before { opacity:1; }
    .feature-card:hover { background:var(--surface); }
    [data-theme="light"] .feature-card { background: var(--surface); }
    [data-theme="light"] .feature-card:hover { background: var(--bg2); }
    .feature-card.large { grid-column: span 2; }
    .feat-icon {
      width: 46px; height: 46px; border-radius: 11px; display: flex; align-items: center;
      justify-content: center; margin-bottom: 22px; font-size: 17px;
      border: 1px solid var(--border-strong); position: relative;
    }
    .feat-icon::after { content:''; position:absolute; inset:-1px; border-radius:13px; background:inherit; filter:blur(12px); opacity:.4; z-index:-1; }
    .icon-blue { background:rgba(59,130,246,.12); color:var(--accent2); }
    .icon-green { background:rgba(34,211,238,.12); color:var(--accent-green); }
    .icon-purple { background:rgba(167,139,250,.12); color:var(--accent-purple); }
    .icon-orange { background:rgba(251,146,60,.12); color:var(--accent-orange); }
    .feat-num { position:absolute; top:20px; right:24px; font-family:var(--font-mono); font-size:11px; color:var(--text-dim); }
    .feat-title { font-family:var(--font-head); font-size: clamp(17px, 1.6vw, 20px); font-weight:600; color:var(--text); margin-bottom:10px; letter-spacing:-.015em; }
    .feat-desc { font-size: 14.5px; color:var(--text-muted); line-height:1.65; }
    .feat-tag { display:inline-flex; align-items:center; gap:6px; margin-top:20px; padding:4px 12px; background:rgba(34,211,238,.1); border:1px solid rgba(34,211,238,.22); border-radius:100px; font-family:var(--font-mono); font-size:11px; color:var(--accent-green); }

    #how { padding: clamp(80px, 12vw, 120px) clamp(20px,5vw,80px); background: linear-gradient(180deg,transparent,var(--bg2),transparent); position: relative; }
    .how-inner { max-width: 900px; margin: 0 auto; }
    .how-header { text-align: center; margin-bottom: clamp(40px, 6vw, 72px); }
    .how-header .section-sub { margin-left: auto; margin-right: auto; }
    .steps-list { display: flex; flex-direction: column; gap: 0; position: relative; }
    .steps-list::before {
      content: ''; position: absolute; left: 35px; top: 70px; bottom: 70px;
      width: 1px; background: linear-gradient(180deg,var(--border-strong),var(--border-strong) 60%,transparent);
    }
    .step-item { display: flex; gap: 28px; align-items: flex-start; padding: 0 0 40px 0; position: relative; }
    .step-item:last-child { padding-bottom: 0; }
    .step-left { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; width: 70px; }
    .step-node {
      width: 70px; height: 70px; background: var(--surface2); border: 1px solid var(--border-strong);
      border-radius: 50%; display: flex; align-items: center; justify-content: center;
      font-size: 22px; position: relative; z-index: 2; flex-shrink: 0;
      transition: border-color .3s, box-shadow .3s;
    }
    .step-item:hover .step-node { border-color: var(--accent); box-shadow: 0 0 20px rgba(59,130,246,.25); }
    .icon-blue-n { color: var(--accent2); }
    .icon-purple-n { color: var(--accent-purple); }
    .icon-green-n { color: var(--accent-green); }
    .step-content {
      background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
      padding: 28px 32px; flex: 1; transition: border-color .25s, transform .25s;
    }
    .step-item:hover .step-content { border-color: var(--border-strong); transform: translateX(4px); }
    .step-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; gap: 12px; }
    .step-title { font-family: var(--font-head); font-size: 20px; font-weight: 700; color: var(--text); letter-spacing: -.02em; }
    .step-badge { font-family: var(--font-mono); font-size: 10px; letter-spacing: .1em; text-transform: uppercase; color: var(--text-dim); background: var(--bg2); border: 1px solid var(--border); border-radius: 6px; padding: 3px 10px; white-space: nowrap; flex-shrink: 0; }
    .step-desc { font-size: 15px; color: var(--text-muted); line-height: 1.65; margin-bottom: 16px; }
    .step-pills { display: flex; gap: 8px; flex-wrap: wrap; }
    .step-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 100px; font-family: var(--font-mono); font-size: 11px; border: 1px solid var(--border); color: var(--text-muted); background: var(--bg2); }
    .step-pill i { font-size: 10px; color: var(--accent2); }

    #pricing { padding: clamp(80px, 12vw, 120px) clamp(20px,5vw,80px); }
    .pricing-header { text-align: center; margin-bottom: clamp(40px, 6vw, 64px); }
    .pricing-header .section-sub { margin-left: auto; margin-right: auto; }
    .pricing-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(280px,1fr)); gap: 22px; max-width: 1100px; margin: 0 auto; }
    .price-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: clamp(28px, 3.5vw, 36px) clamp(24px, 3vw, 32px); position: relative; transition: transform .25s, border-color .25s, box-shadow .25s; box-shadow: var(--shadow-card); }
    .price-card:hover { transform: translateY(-4px); border-color: var(--border-strong); }
    .price-card.featured { background: linear-gradient(160deg,rgba(59,130,246,.10),rgba(59,130,246,.02)); border-color: rgba(59,130,246,.35); box-shadow: 0 0 0 1px rgba(59,130,246,.15), 0 24px 64px rgba(59,130,246,.14); }
    [data-theme="light"] .price-card.featured { background: linear-gradient(160deg,rgba(37,99,235,.06),rgba(37,99,235,.01)); border-color: rgba(37,99,235,.25); box-shadow: 0 0 0 1px rgba(37,99,235,.08), 0 20px 50px rgba(37,99,235,.12); }
    .price-badge { position: absolute; top: -14px; left: 50%; transform: translateX(-50%); background: var(--accent); color: #fff; font-size: 11px; font-weight: 700; font-family: var(--font-mono); letter-spacing: .08em; padding: 4px 16px; border-radius: 100px; text-transform: uppercase; white-space: nowrap; }
    .price-plan { font-family: var(--font-mono); font-size: 12px; letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 16px; font-weight: 500; }
    .price-amount { font-family: var(--font-head); font-size: clamp(38px, 4.5vw, 48px); font-weight: 700; letter-spacing: -.035em; color: var(--text); line-height: 1; }
    .price-amount sub { font-size: 18px; font-weight: 500; color: var(--text-muted); vertical-align: middle; }
    .price-amount sup { font-size: 20px; color: var(--text-muted); font-weight: 500; }
    .price-unit { font-size: 14px; color: var(--text-muted); font-weight: 500; margin-left: 4px; font-family: var(--font-body); }
    .price-period { font-size: 13px; color: var(--text-dim); margin-top: 6px; margin-bottom: 14px; font-family: var(--font-mono); }
    .price-range { font-size: 12.5px; color: var(--accent2); margin-bottom: 26px; font-family: var(--font-mono); padding: 6px 12px; background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.18); border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; }
    .price-features { list-style: none; display: flex; flex-direction: column; gap: 12px; margin-bottom: 32px; }
    .price-features li { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text-muted); }
    .check { width:18px; height:18px; background:rgba(34,211,238,.1); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:9px; color:var(--accent-green); flex-shrink:0; }
    .btn-outline { display:block; text-align:center; width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-strong); background:transparent; color:var(--text); font-size:14px; font-weight:600; cursor:pointer; text-decoration:none; transition:all .2s; font-family:var(--font-body); }
    .btn-outline:hover { background:var(--surface2); border-color:var(--accent); }
    .btn-primary-block { display:block; text-align:center; width:100%; padding:12px; border-radius:8px; background:var(--accent); color:#fff; font-size:14px; font-weight:600; cursor:pointer; border:none; text-decoration:none; transition:all .2s; font-family:var(--font-body); box-shadow:0 4px 20px rgba(59,130,246,.3); }
    .btn-primary-block:hover { background:#2563eb; box-shadow:0 4px 28px rgba(59,130,246,.5); transform:translateY(-1px); }
    .pricing-empty { text-align:center; padding:48px; color:var(--text-muted); border:1px dashed var(--border-strong); border-radius:var(--radius); max-width:600px; margin:0 auto; }

    .marquee-wrap { padding:36px 0; overflow:hidden; border-top:1px solid var(--border); border-bottom:1px solid var(--border); position:relative; background: var(--bg2); }
    .marquee-wrap::before,.marquee-wrap::after { content:''; position:absolute; top:0; bottom:0; width:clamp(40px, 12vw, 200px); z-index:2; pointer-events:none; }
    .marquee-wrap::before { left:0; background:linear-gradient(90deg,var(--marquee-fade-from),transparent); }
    .marquee-wrap::after { right:0; background:linear-gradient(-90deg,var(--marquee-fade-from),transparent); }
    [data-theme="light"] .marquee-wrap::before { background:linear-gradient(90deg,var(--bg2),transparent); }
    [data-theme="light"] .marquee-wrap::after { background:linear-gradient(-90deg,var(--bg2),transparent); }
    .marquee-track { display:flex; gap:56px; animation:marquee 28s linear infinite; width:max-content; }
    .marquee-track:hover { animation-play-state:paused; }
    @keyframes marquee { 0% { transform:translateX(0); } 100% { transform:translateX(-50%); } }
    .marquee-item { display:flex; align-items:center; gap:12px; white-space:nowrap; font-family:var(--font-mono); font-size:12.5px; color:var(--text-muted); letter-spacing:.04em; }
    .marquee-item .dot { width:5px; height:5px; border-radius:50%; background:var(--accent2); opacity:.6; }

    #testimonials { padding: clamp(80px, 12vw, 120px) clamp(20px,5vw,80px); }
    .test-header { text-align:center; margin-bottom: clamp(40px, 6vw, 64px); }
    .testimonials-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
    .test-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:clamp(24px, 3vw, 32px); position:relative; transition:border-color .25s, transform .25s, box-shadow .25s; box-shadow: var(--shadow-card); }
    .test-card:hover { border-color:var(--border-strong); transform: translateY(-3px); }
    .test-card::before { content:'"'; position:absolute; top:14px; right:22px; font-family:var(--font-head); font-size:64px; color:rgba(59,130,246,.14); line-height:1; }
    .test-text { font-size:15px; color:var(--text); line-height:1.7; margin-bottom:24px; letter-spacing: -.005em; }
    [data-theme="light"] .test-text { color: var(--text-muted); }
    .test-author { display:flex; align-items:center; gap:12px; }
    .test-avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--accent-green)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13.5px; color:#fff; flex-shrink: 0; box-shadow: 0 4px 12px var(--accent-glow); }
    .test-name { font-weight:600; font-size:14px; color:var(--text); }
    .test-role { font-size:12px; color:var(--text-dim); font-family:var(--font-mono); margin-top: 2px; }
    .test-stars { display:flex; gap:3px; margin-bottom:16px; }
    .star { color:#fbbf24; font-size:13px; }

    #cta { padding: clamp(80px, 12vw, 120px) clamp(20px,5vw,80px); text-align:center; position:relative; overflow:hidden; }
    #cta::before { content:''; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width: min(800px, 95vw); height:400px; background:radial-gradient(ellipse,rgba(59,130,246,.14),transparent 70%); pointer-events:none; }
    [data-theme="light"] #cta::before { background:radial-gradient(ellipse,rgba(37,99,235,.10),transparent 70%); }
    .cta-title { font-family:var(--font-head); font-size:clamp(30px, 5vw, 60px); font-weight:700; letter-spacing:-.035em; color:var(--text); margin-bottom:18px; line-height: 1.08; }
    .cta-sub { font-size: clamp(15px, 1.6vw, 18px); color:var(--text-muted); margin-bottom:36px; max-width:500px; margin-left:auto; margin-right:auto; line-height: 1.6; }
    .cta-actions { display:flex; align-items:center; justify-content:center; gap:14px; flex-wrap:wrap; }
    .cta-note { margin-top:22px; font-family:var(--font-mono); font-size:12px; color:var(--text-dim); }

    footer { border-top:1px solid var(--border); padding: clamp(48px, 7vw, 60px) clamp(20px,5vw,80px) 36px; background: var(--bg2); }
    .footer-grid { display:grid; grid-template-columns:2fr 1fr 1fr 1fr; gap: clamp(28px, 4vw, 48px); margin-bottom: clamp(36px, 5vw, 48px); }
    .footer-brand .logo { margin-bottom:16px; display:inline-flex; }
    .footer-brand p { font-size:14px; color:var(--text-muted); line-height:1.7; max-width:280px; }
    .footer-col h5 { font-family:var(--font-mono); font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:var(--text-dim); margin-bottom:16px; }
    .footer-col ul { list-style:none; display:flex; flex-direction:column; gap:10px; }
    .footer-col ul a { font-size:14px; color:var(--text-muted); text-decoration:none; transition:color .2s; }
    .footer-col ul a:hover { color:var(--text); }
    .footer-bottom { padding-top:28px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
    .footer-bottom p { font-size:13px; color:var(--text-dim); font-family:var(--font-mono); }
    .footer-social { display:flex; gap:12px; }
    .social-link { width:36px; height:36px; border:1px solid var(--border); border-radius:9px; display:flex; align-items:center; justify-content:center; color:var(--text-muted); text-decoration:none; font-size:14px; transition:all .2s; background: var(--surface); }
    .social-link:hover { border-color:var(--accent); color:var(--accent2); background:rgba(59,130,246,.08); transform: translateY(-2px); }

    @keyframes fadeUp { from { opacity:0;transform:translateY(24px); } to { opacity:1;transform:translateY(0); } }
    .reveal { opacity:0; transform:translateY(28px); transition:opacity .7s ease,transform .7s ease; }
    .reveal.visible { opacity:1; transform:translateY(0); }

    /* Tablet landscape */
    @media (max-width:1024px) {
      .features-grid { grid-template-columns:repeat(2,1fr); }
      .feature-card.large { grid-column:span 2; }
      .testimonials-grid { grid-template-columns:1fr 1fr; gap: 18px; }
      .footer-grid { grid-template-columns: 2fr 1fr 1fr 1fr; }
      .nav-links { gap: 22px; }
    }

    /* Tablet portrait */
    @media (max-width:860px) {
      .nav-links li:nth-child(4),
      .nav-links li:nth-child(5) { display: none; }
      .footer-grid { grid-template-columns: 1fr 1fr; }
      .feature-card.large { grid-column:span 1; }
      .features-grid { grid-template-columns: 1fr 1fr; }
      .testimonials-grid { grid-template-columns: 1fr; max-width: 560px; margin-left: auto; margin-right: auto; }
      .hero-stats { grid-template-columns: repeat(2, 1fr); gap: 28px; max-width: 480px; }
    }

    /* Mobile */
    @media (max-width:768px) {
      nav { padding: 0 18px; height: 60px; }
      nav.scrolled { height: 54px; }
      .nav-links, .nav-cta .btn-ghost, .nav-cta .btn-primary, #themeToggleDesktop { display:none; }
      .hamburger { display:flex; }
      .logo { font-size: 19px; }
      .logo-icon { width: 32px; height: 32px; font-size: 13px; }

      #hero { padding: 96px 20px 56px; }
      .hero-badge { font-size: 11px; padding: 5px 12px; margin-bottom: 22px; }
      .hero-title { line-height: 1.08; margin-bottom: 16px; max-width: 100%; }
      .hero-sub { margin-bottom: 28px; }
      .hero-actions .btn-lg { padding: 13px 22px; font-size: 14px; }
      .hero-stats { grid-template-columns: repeat(2, 1fr); gap: 24px; margin-top: 56px; padding-top: 36px; }
      .stat-num { font-size: 26px; }
      .stat-label { font-size: 11.5px; }
      .terminal-wrap { margin-top: 44px; }
      .terminal-body { font-size: 11px; padding: 16px; min-height: 160px; }

      .marquee-wrap { padding: 28px 0; }
      .marquee-item { font-size: 12px; }
      .marquee-track { gap: 40px; }

      .features-grid { grid-template-columns:1fr; }
      .features-header { flex-direction: column; align-items: flex-start; gap: 14px; margin-bottom: 36px; }
      .features-header .section-sub { max-width: 100%; }

      .pricing-grid { grid-template-columns: 1fr; max-width: 420px; margin-left: auto; margin-right: auto; gap: 18px; }

      .step-item { gap: 18px; padding-bottom: 32px; }
      .step-content { padding: 22px 20px; }
      .step-title { font-size: 17px; }
      .step-desc { font-size: 14px; }

      #features, #how, #pricing, #testimonials, #cta { padding: 72px 20px; }
      .footer-grid { grid-template-columns:1fr 1fr; gap: 28px; }
      .footer-brand { grid-column: 1 / -1; }
      footer { padding: 48px 20px 28px; }
      .footer-bottom { flex-direction: column; align-items: flex-start; gap: 18px; text-align: left; }
    }

    @media (max-width:480px) {
      nav { padding: 0 14px; }
      #hero { padding: 88px 16px 48px; }
      .hero-badge { font-size: 10.5px; }
      .hero-actions { width: 100%; flex-direction: column; }
      .hero-actions .btn-lg { width: 100%; justify-content: center; }
      .hero-stats { grid-template-columns: 1fr 1fr; gap: 20px; }

      .steps-list::before { left: 25px; }
      .step-left { width: 50px; }
      .step-node { width: 50px; height: 50px; font-size: 16px; }
      .steps-list::before { top: 50px; bottom: 50px; }

      .price-card { padding: 26px 22px; }
      .price-amount { font-size: 36px; }

      .test-card { padding: 22px 20px; }
      .test-text { font-size: 14px; }

      #features, #how, #pricing, #testimonials, #cta { padding: 60px 16px; }
      .footer-grid { grid-template-columns: 1fr; }
      .footer-brand { grid-column: auto; }

      .cta-actions { width: 100%; flex-direction: column; }
      .cta-actions .btn-lg { width: 100%; justify-content: center; }
    }

    @media (max-width: 360px) {
      .hero-stats { grid-template-columns: 1fr; gap: 18px; }
    }

    .mobile-menu { position:fixed; inset:0; background: var(--menu-bg); z-index:9999; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:28px; opacity:0; pointer-events:none; transition:opacity .3s; backdrop-filter:blur(20px); -webkit-backdrop-filter: blur(20px); padding: 24px; }
    .mobile-menu.open { opacity:1; pointer-events:all; }
    .mobile-menu a { font-family:var(--font-head); font-size: clamp(24px, 7vw, 32px); font-weight:600; color:var(--text-muted); text-decoration:none; transition:color .2s; letter-spacing: -.02em; }
    .mobile-menu a:hover, .mobile-menu a:active { color:var(--accent2); }
    .mobile-menu a.btn { font-size: 16px; }
    .mobile-close { position:absolute; top:20px; right:24px; font-size:24px; color:var(--text-muted); cursor:pointer; background:none; border:1px solid var(--border); border-radius: 9px; line-height:1; width:40px; height:40px; display:flex; align-items:center; justify-content:center; }

    ::-webkit-scrollbar { width:8px; height:8px; }
    ::-webkit-scrollbar-track { background:var(--bg); }
    ::-webkit-scrollbar-thumb { background:var(--border-strong); border-radius:4px; }
    ::-webkit-scrollbar-thumb:hover { background:var(--text-dim); }
    html { scrollbar-width: thin; scrollbar-color: var(--border-strong) var(--bg); }
  </style>
</head>
<body>

<div class="cursor" id="cursor" aria-hidden="true"></div>
<div class="cursor-ring" id="cursorRing" aria-hidden="true"></div>
<div class="grid-bg" aria-hidden="true"></div>

<div class="mobile-menu" id="mobileMenu" role="dialog" aria-modal="true" aria-label="Navigation menu">
  <button class="mobile-close" id="mobileClose" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
  <button class="theme-toggle" id="themeToggleMobile" aria-label="Toggle Theme" style="margin-bottom: 24px; width: 48px; height: 48px; font-size: 20px;">
    <i class="fa-solid fa-sun"></i>
    <i class="fa-solid fa-moon"></i>
  </button>
  <a href="#features" onclick="closeMobileMenu()">Features</a>
  <a href="#how" onclick="closeMobileMenu()">How It Works</a>
  <a href="#pricing" onclick="closeMobileMenu()">Pricing</a>
  <a href="#testimonials" onclick="closeMobileMenu()">Testimonials</a>
  <a href="signup.php" class="btn btn-primary" style="margin-top:8px">Get Started</a>
</div>

<nav id="nav" role="navigation" aria-label="Main navigation">
  <a href="/" class="logo" aria-label="Vormox Home">
    <img src="/assets/images/logo.svg" alt="Vormox" class="brand-logo light-logo">
    <img src="/assets/images/logo-b.svg" alt="Vormox" class="brand-logo dark-logo">
  </a>
  <ul class="nav-links">
    <li><a href="#features">Features</a></li>
    <li><a href="#how">How It Works</a></li>
    <li><a href="#pricing">Pricing</a></li>
    <li><a href="#testimonials">Customers</a></li>
    <li><a href="/docs">Docs</a></li>
  </ul>
  <div class="nav-cta">
    <button class="theme-toggle" id="themeToggleDesktop" aria-label="Toggle Theme">
      <i class="fa-solid fa-sun"></i>
      <i class="fa-solid fa-moon"></i>
    </button>
    <a href="signin.php" class="btn btn-ghost">Sign In</a>
    <a href="signup.php" class="btn btn-primary">Start Free <i class="fa-solid fa-arrow-right"></i></a>
    <button class="hamburger" id="hamburger" aria-label="Open menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<main>
  <section id="hero" aria-label="Hero">
    <div class="hero-glow" aria-hidden="true"></div>
    <div class="hero-badge" role="status">
      <span class="badge-dot" aria-hidden="true"></span>
      Fully Managed Proxmox Cloud SaaS &middot; v3.2 Now Live
    </div>
    <h1 class="hero-title">
      The Fully Managed<br>
      <span class="line2">Proxmox Automation Cloud</span>
    </h1>
    <p class="hero-sub">Stop maintaining your control plane. Vormox is the fully managed SaaS that transforms complex Proxmox VE management into elegant automation workflows. Deploy, scale, and orchestrate your hypervisor fleet without the hosting overhead.</p>
    <div class="hero-actions">
      <a href="signup.php" class="btn btn-primary btn-lg">Deploy in Minutes <i class="fa-solid fa-arrow-right"></i></a>
      <a href="#how" class="btn btn-ghost btn-lg"><i class="fa-solid fa-circle-play"></i> Watch Demo</a>
    </div>
    <div class="terminal-wrap" role="region" aria-label="Live terminal demo">
      <div class="terminal">
        <div class="terminal-bar" aria-hidden="true">
          <span class="t-dot t-red"></span><span class="t-dot t-yellow"></span><span class="t-dot t-green"></span>
          <span class="terminal-title">vormox-cli — bash</span>
        </div>
        <div class="terminal-body" id="termBody" aria-live="polite">
          <div class="t-line"><span class="t-prompt">$</span><span class="t-cmd"> vormox deploy --cluster prod --nodes 12 --ha</span></div>
          <div class="t-line"><span class="t-out">→ Connecting to Proxmox API endpoint...</span></div>
          <div class="t-line"><span class="t-out">→ Provisioning 12 VM instances across 3 hosts</span></div>
          <div class="t-line"><span class="t-out">→ Applying HA policy: failover + replication</span></div>
          <div class="t-line"><span class="t-success">✓ Cluster deployed in 4.2s — all nodes healthy</span></div>
          <div class="t-line"><span class="t-prompt">$</span><span class="t-cmd"> <span class="t-cursor" aria-hidden="true"></span></span></div>
        </div>
      </div>
    </div>
    <div class="hero-stats" role="region" aria-label="Key statistics">
      <div class="stat-item">
        <div class="stat-num" id="stat0">14<span>k+</span></div>
        <div class="stat-label">VMs Deployed</div>
      </div>
      <div class="stat-item">
        <div class="stat-num" id="stat1">99<span>.9%</span></div>
        <div class="stat-label">Uptime SLA</div>
      </div>
      <div class="stat-item">
        <div class="stat-num" id="stat2">3<span>x</span></div>
        <div class="stat-label">Faster Deploys</div>
      </div>
      <div class="stat-item">
        <div class="stat-num" id="stat3">500<span>+</span></div>
        <div class="stat-label">Teams Trust Us</div>
      </div>
    </div>
  </section>

  <div class="marquee-wrap" aria-hidden="true">
    <div class="marquee-track">
      <span class="marquee-item"><span class="dot"></span> Proxmox VE 8.x</span>
      <span class="marquee-item"><span class="dot"></span> VM Cloning &amp; Templates</span>
      <span class="marquee-item"><span class="dot"></span> Live Migration</span>
      <span class="marquee-item"><span class="dot"></span> Storage Pools</span>
      <span class="marquee-item"><span class="dot"></span> HA Clustering</span>
      <span class="marquee-item"><span class="dot"></span> Backup Automation</span>
      <span class="marquee-item"><span class="dot"></span> Network Automation</span>
      <span class="marquee-item"><span class="dot"></span> RBAC &amp; Permissions</span>
      <span class="marquee-item"><span class="dot"></span> REST API</span>
      <span class="marquee-item"><span class="dot"></span> SDN Integration</span>
      <span class="marquee-item"><span class="dot"></span> LXC Containers</span>
      <span class="marquee-item"><span class="dot"></span> Terraform Provider</span>
      <span class="marquee-item"><span class="dot"></span> Proxmox VE 8.x</span>
      <span class="marquee-item"><span class="dot"></span> VM Cloning &amp; Templates</span>
      <span class="marquee-item"><span class="dot"></span> Live Migration</span>
      <span class="marquee-item"><span class="dot"></span> Storage Pools</span>
      <span class="marquee-item"><span class="dot"></span> HA Clustering</span>
      <span class="marquee-item"><span class="dot"></span> Backup Automation</span>
      <span class="marquee-item"><span class="dot"></span> Network Automation</span>
      <span class="marquee-item"><span class="dot"></span> RBAC &amp; Permissions</span>
      <span class="marquee-item"><span class="dot"></span> REST API</span>
      <span class="marquee-item"><span class="dot"></span> SDN Integration</span>
      <span class="marquee-item"><span class="dot"></span> LXC Containers</span>
      <span class="marquee-item"><span class="dot"></span> Terraform Provider</span>
    </div>
  </div>

  <section id="features" aria-labelledby="features-heading">
    <div class="features-header reveal">
      <div>
        <div class="section-label">// SaaS Capabilities</div>
        <h2 class="section-title" id="features-heading">Everything you need to<br>automate at scale</h2>
      </div>
      <p class="section-sub" style="max-width:360px">From single-node homelab to enterprise clusters with hundreds of nodes — our cloud adapts to your infrastructure.</p>
    </div>
    <div class="features-grid reveal">
      <div class="feature-card large">
        <div class="feat-num">01</div>
        <div class="feat-icon icon-blue" aria-hidden="true"><i class="fa-solid fa-cloud"></i></div>
        <h3 class="feat-title">Fully Managed Control Plane</h3>
        <p class="feat-desc">Deploy, clone, snapshot, and migrate virtual machines via our hosted SaaS. Vormox handles Proxmox API complexity in the cloud so your team can focus on infrastructure design, not maintaining the orchestrator. Full support for KVM and LXC containers.</p>
        <span class="feat-tag"><i class="fa-solid fa-bolt"></i> 4x faster deployments</span>
      </div>
      <div class="feature-card">
        <div class="feat-num">02</div>
        <div class="feat-icon icon-green" aria-hidden="true"><i class="fa-solid fa-rotate"></i></div>
        <h3 class="feat-title">HA &amp; Live Migration</h3>
        <p class="feat-desc">Automate high-availability policies, failover rules, and live VM migrations across nodes with zero downtime.</p>
      </div>
      <div class="feature-card">
        <div class="feat-num">03</div>
        <div class="feat-icon icon-purple" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></div>
        <h3 class="feat-title">RBAC &amp; Audit Trails</h3>
        <p class="feat-desc">Fine-grained role-based access control mirroring Proxmox's permission model — with full audit logs and compliance exports.</p>
      </div>
      <div class="feature-card">
        <div class="feat-num">04</div>
        <div class="feat-icon icon-orange" aria-hidden="true"><i class="fa-solid fa-database"></i></div>
        <h3 class="feat-title">Automated Backups</h3>
        <p class="feat-desc">Schedule, monitor and test backups across all your Proxmox storage pools. Restore from any snapshot in a single command.</p>
      </div>
      <div class="feature-card">
        <div class="feat-num">05</div>
        <div class="feat-icon icon-blue" aria-hidden="true"><i class="fa-solid fa-network-wired"></i></div>
        <h3 class="feat-title">Advanced Network Automation</h3>
        <p class="feat-desc">Programmatically manage complex production networking. Automate DHCP configurations, VLAN tagging, and VPC systems, eliminating manual IP management and network interface troubleshooting.</p>
      </div>
      <div class="feature-card">
        <div class="feat-num">06</div>
        <div class="feat-icon icon-green" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></div>
        <h3 class="feat-title">Real-time Monitoring</h3>
        <p class="feat-desc">Live cluster metrics, resource utilization, and health alerts built in. Export to Grafana, Prometheus, or Datadog.</p>
      </div>
    </div>
  </section>

  <section id="how" aria-labelledby="how-heading">
    <div class="how-inner">
      <div class="how-header reveal">
        <div class="section-label">// How It Works</div>
        <h2 class="section-title" id="how-heading">From zero to automated<br>in three steps</h2>
        <p class="section-sub" style="margin:16px auto 0">No local panel to install. Just connect your Proxmox cluster to our cloud and start automating.</p>
      </div>
      <div class="steps-list reveal" role="list">
        <div class="step-item" role="listitem">
          <div class="step-left" aria-hidden="true">
            <div class="step-node"><i class="fa-solid fa-plug icon-blue-n"></i></div>
          </div>
          <div class="step-content">
            <div class="step-header">
              <h3 class="step-title">Connect Your Cluster</h3>
              <span class="step-badge">Step 01</span>
            </div>
            <p class="step-desc">Point Vormox at your Proxmox VE API endpoint with your API token. We auto-discover nodes, storage pools, and all running VMs — no manual inventory required.</p>
            <div class="step-pills">
              <span class="step-pill"><i class="fa-solid fa-key"></i> API Token Auth</span>
              <span class="step-pill"><i class="fa-solid fa-magnifying-glass"></i> Auto-discovery</span>
              <span class="step-pill"><i class="fa-solid fa-cloud"></i> Agentless SaaS</span>
            </div>
          </div>
        </div>
        <div class="step-item" role="listitem">
          <div class="step-left" aria-hidden="true">
            <div class="step-node"><i class="fa-solid fa-code icon-purple-n"></i></div>
          </div>
          <div class="step-content">
            <div class="step-header">
              <h3 class="step-title">Define Your Automation</h3>
              <span class="step-badge">Step 02</span>
            </div>
            <p class="step-desc">Write simple YAML playbooks or use our drag-and-drop workflow builder. Define deployment templates, backup schedules, scaling rules, and HA policies with zero boilerplate.</p>
            <div class="step-pills">
              <span class="step-pill"><i class="fa-solid fa-file-code"></i> YAML Playbooks</span>
              <span class="step-pill"><i class="fa-solid fa-arrows-to-dot"></i> Visual Builder</span>
              <span class="step-pill"><i class="fa-solid fa-cubes"></i> Terraform</span>
            </div>
          </div>
        </div>
        <div class="step-item" role="listitem">
          <div class="step-left" aria-hidden="true">
            <div class="step-node"><i class="fa-solid fa-rocket icon-green-n"></i></div>
          </div>
          <div class="step-content">
            <div class="step-header">
              <h3 class="step-title">Deploy &amp; Scale</h3>
              <span class="step-badge">Step 03</span>
            </div>
            <p class="step-desc">Trigger workflows via CLI, REST API, webhooks, or schedule. Monitor execution in real-time with live logs and rollback instantly if anything goes wrong.</p>
            <div class="step-pills">
              <span class="step-pill"><i class="fa-solid fa-terminal"></i> CLI &amp; REST API</span>
              <span class="step-pill"><i class="fa-solid fa-link"></i> Webhooks</span>
              <span class="step-pill"><i class="fa-solid fa-rotate-left"></i> Instant Rollback</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="pricing" aria-labelledby="pricing-heading">
    <div class="pricing-header reveal">
      <div class="section-label">// Cloud Pricing</div>
      <h2 class="section-title" id="pricing-heading">Simple, transparent SaaS pricing</h2>
      <p class="section-sub" style="margin:16px auto 0">Per-node pricing that scales with your infrastructure. No surprise overages.</p>
    </div>
    <div class="pricing-grid reveal">
      <?php if (empty($tiers)): ?>
        <div class="pricing-empty">Pricing tiers are currently unavailable. Please <a href="/contact" style="color:var(--accent2)">contact sales</a> for a quote.</div>
      <?php else: foreach ($tiers as $i => $tier):
          $name = tierName($i, $tierCount);
          $isFeatured = ($i === $featuredIndex);
          $features = tierFeatures($i, $tierCount);
          $price = number_format((float)$tier['price_per_node'], 2, '.', '');
          if (substr($price, -3) === '.00') {
              $price = substr($price, 0, -3);
          }
      ?>
        <div class="price-card<?= $isFeatured ? ' featured' : '' ?>">
          <?php if ($isFeatured): ?><div class="price-badge">Most Popular</div><?php endif; ?>
          <div class="price-plan"><?= htmlspecialchars($name) ?></div>
          <div class="price-amount"><sup>$</sup><?= htmlspecialchars($price) ?><span class="price-unit">/ node</span></div>
          <div class="price-period">per month, billed monthly</div>
          <div class="price-range"><i class="fa-solid fa-server"></i> <?= htmlspecialchars(tierNodeRange($tier)) ?></div>
          <ul class="price-features" aria-label="<?= htmlspecialchars($name) ?> plan features">
            <?php foreach ($features as $feat): ?>
              <li><span class="check" aria-hidden="true"><i class="fa-solid fa-check"></i></span><?= $feat ?></li>
            <?php endforeach; ?>
          </ul>
          <a href="signup.php?plan=<?= urlencode(strtolower($name)) ?>" class="<?= $isFeatured ? 'btn-primary-block' : 'btn-outline' ?>">
            Get <?= htmlspecialchars($name) ?><?= $isFeatured ? ' <i class="fa-solid fa-arrow-right"></i>' : '' ?>
          </a>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <section id="testimonials" aria-labelledby="test-heading">
    <div class="test-header reveal">
      <div class="section-label">// Customer Stories</div>
      <h2 class="section-title" id="test-heading">Trusted by infrastructure teams</h2>
    </div>
    <div class="testimonials-grid reveal">
      <article class="test-card">
        <div class="test-stars" aria-label="5 out of 5 stars">
          <i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i>
        </div>
        <p class="test-text">"Vormox cut our VM provisioning time from 45 minutes to under 30 seconds. We manage 2k+ VMs across 3 datacenters and the HA automation alone saved us from multiple outages."</p>
        <div class="test-author">
          <div class="test-avatar" aria-hidden="true">DV</div>
          <div>
            <div class="test-name">Dev Varshney</div>
            <div class="test-role">Head of Infrastructure &middot; Getwebup</div>
          </div>
        </div>
      </article>
      <article class="test-card">
        <div class="test-stars" aria-label="5 out of 5 stars">
          <i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i>
        </div>
        <p class="test-text">"The Terraform provider integration is flawless. We have our entire Proxmox cluster defined as code now. Rollbacks take 10 seconds instead of calling the on-call engineer at 3AM."</p>
        <div class="test-author">
          <div class="test-avatar" style="background:linear-gradient(135deg,#a78bfa,#22d3ee)" aria-hidden="true">CP</div>
          <div>
            <div class="test-name">Chandra Prakash</div>
            <div class="test-role">Senior DevOps Engineer &middot; Joy Services</div>
          </div>
        </div>
      </article>
      <article class="test-card">
        <div class="test-stars" aria-label="5 out of 5 stars">
          <i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i><i class="fa-solid fa-star star"></i>
        </div>
        <p class="test-text">"We evaluated every Proxmox automation tool out there. Vormox was the only one that actually understood how Proxmox's permission model works and mapped to our compliance requirements."</p>
        <div class="test-author">
          <div class="test-avatar" style="background:linear-gradient(135deg,#f97316,#fbbf24)" aria-hidden="true">MS</div>
          <div>
            <div class="test-name">Manmeet Singh</div>
            <div class="test-role">CTO &middot; Hostheaven</div>
          </div>
        </div>
      </article>
    </div>
  </section>

  <section id="cta" aria-labelledby="cta-heading">
    <div class="reveal">
      <h2 class="cta-title" id="cta-heading">Start automating your<br>Proxmox today</h2>
      <p class="cta-sub">Join 500+ infrastructure teams running smarter clusters with our managed cloud. No credit card required.</p>
      <div class="cta-actions">
        <a href="signup.php" class="btn btn-primary btn-lg">Get Started Free <i class="fa-solid fa-arrow-right"></i></a>
        <a href="/contact" class="btn btn-ghost btn-lg"><i class="fa-solid fa-headset"></i> Talk to an Expert</a>
      </div>
      <p class="cta-note">Free 14-day trial &middot; No credit card required &middot; Cancel anytime</p>
    </div>
  </section>
</main>

<footer role="contentinfo">
  <div class="footer-grid">
    <div class="footer-brand">
      <a href="/" class="logo" aria-label="Vormox Home">
        <img src="/assets/images/logo.svg" alt="Vormox" class="brand-logo light-logo">
        <img src="/assets/images/logo-b.svg" alt="Vormox" class="brand-logo dark-logo">
      </a>
      <p>Enterprise-grade managed Proxmox cloud automation SaaS. Deployed in production by infrastructure teams worldwide.</p>
    </div>
    <div class="footer-col">
      <h5>Product</h5>
      <ul>
        <li><a href="#features">Features</a></li>
        <li><a href="#pricing">Pricing</a></li>
        <li><a href="/changelog">Changelog</a></li>
        <li><a href="/roadmap">Roadmap</a></li>
        <li><a href="/status">Status</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h5>Developers</h5>
      <ul>
        <li><a href="/docs">Documentation</a></li>
        <li><a href="/docs/api">API Reference</a></li>
        <li><a href="/docs/terraform">Terraform Docs</a></li>
        <li><a href="/docs/cli">CLI Reference</a></li>
        <li><a href="https://github.com/vormox" rel="noopener">GitHub</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h5>Company</h5>
      <ul>
        <li><a href="/about">About Us</a></li>
        <li><a href="/blog">Blog</a></li>
        <li><a href="/careers">Careers</a></li>
        <li><a href="/contact">Contact</a></li>
        <li><a href="/privacy">Privacy Policy</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <p>&copy; <?= date('Y') ?> Vormox. All rights reserved. Built for Proxmox infrastructure teams.</p>
    <div class="footer-social">
      <a href="https://twitter.com/vormox" rel="noopener" class="social-link" aria-label="Follow Vormox on X"><i class="fa-brands fa-x-twitter"></i></a>
      <a href="https://www.linkedin.com/company/vormox" rel="noopener" class="social-link" aria-label="Vormox on LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
      <a href="https://github.com/vormox" rel="noopener" class="social-link" aria-label="Vormox on GitHub"><i class="fa-brands fa-github"></i></a>
      <a href="https://www.youtube.com/@vormox" rel="noopener" class="social-link" aria-label="Vormox on YouTube"><i class="fa-brands fa-youtube"></i></a>
    </div>
  </div>
</footer>

<script>
  const themeToggleDesktop = document.getElementById('themeToggleDesktop');
  const themeToggleMobile = document.getElementById('themeToggleMobile');

  function setTheme(theme) {
      document.documentElement.setAttribute('data-theme', theme);
      localStorage.setItem('theme', theme);
  }
  function toggleTheme() {
      const currentTheme = document.documentElement.getAttribute('data-theme');
      setTheme(currentTheme === 'light' ? 'dark' : 'light');
  }
  themeToggleDesktop.addEventListener('click', toggleTheme);
  themeToggleMobile.addEventListener('click', toggleTheme);

  const cursor = document.getElementById('cursor');
  const ring = document.getElementById('cursorRing');
  let mx = 0, my = 0, rx = 0, ry = 0;
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (window.matchMedia('(pointer: fine)').matches && !prefersReduced) {
    document.addEventListener('mousemove', e => {
      mx = e.clientX;
      my = e.clientY;
    }, { passive: true });

    rx = -100; ry = -100;

    (function animate() {
      cursor.style.transform = `translate3d(${mx - 5}px, ${my - 5}px, 0)`;
      rx += (mx - rx) * 0.18;
      ry += (my - ry) * 0.18;
      ring.style.transform = `translate3d(${rx - 18}px, ${ry - 18}px, 0)`;
      requestAnimationFrame(animate);
    })();

    document.querySelectorAll('a, button').forEach(el => {
      el.addEventListener('mouseenter', () => {
        cursor.style.width = '18px'; cursor.style.height = '18px';
        ring.style.width = '52px'; ring.style.height = '52px'; ring.style.opacity = '0.4';
      });
      el.addEventListener('mouseleave', () => {
        cursor.style.width = '10px'; cursor.style.height = '10px';
        ring.style.width = '36px'; ring.style.height = '36px'; ring.style.opacity = '1';
      });
    });
  }

  const nav = document.getElementById('nav');
  window.addEventListener('scroll', () => nav.classList.toggle('scrolled', window.scrollY > 40), { passive: true });

  const hamburger = document.getElementById('hamburger');
  const mobileMenu = document.getElementById('mobileMenu');
  const mobileClose = document.getElementById('mobileClose');
  hamburger.addEventListener('click', () => { mobileMenu.classList.add('open'); hamburger.setAttribute('aria-expanded', 'true'); });
  mobileClose.addEventListener('click', closeMobileMenu);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMobileMenu(); });
  function closeMobileMenu() { mobileMenu.classList.remove('open'); hamburger.setAttribute('aria-expanded', 'false'); }

  const revealObs = new IntersectionObserver((entries) => {
    entries.forEach((entry, i) => {
      if (entry.isIntersecting) {
        setTimeout(() => entry.target.classList.add('visible'), i * 80);
        revealObs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
  document.querySelectorAll('.reveal').forEach(el => revealObs.observe(el));

  const counters = [
    { id: 'stat0', target: 14, suffix: 'k+' },
    { id: 'stat1', target: 99.9, suffix: '%' },
    { id: 'stat2', target: 3, suffix: 'x' },
    { id: 'stat3', target: 500, suffix: '+' }
  ];
  function animateCount(el, target, suffix) {
    let start = null;
    (function step(ts) {
      if (!start) start = ts;
      const p = Math.min((ts - start) / 1800, 1);
      el.innerHTML = Math.floor((1 - Math.pow(1 - p, 3)) * target) + '<span>' + suffix + '</span>';
      if (p < 1) requestAnimationFrame(step);
    })(performance.now());
  }
  const statsEl = document.getElementById('stat0');
  if (statsEl) {
    const statsObs = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          counters.forEach(c => { const el = document.getElementById(c.id); if (el) animateCount(el, c.target, c.suffix); });
          statsObs.disconnect();
        }
      });
    }, { threshold: 0.5 });
    statsObs.observe(statsEl.closest('.hero-stats'));
  }

  const termLines = [
    { type: 'cmd', text: ' vormox status --cluster prod' },
    { type: 'out', text: '→ Checking 12 nodes...' },
    { type: 'out', text: '→ All nodes: ONLINE  Memory: 67%  CPU: 22%' },
    { type: 'success', text: '✓ Cluster health: EXCELLENT' }
  ];
  let charIdx = 0, isTyping = false;
  function nextTerminalCycle() {
    if (isTyping) return;
    isTyping = true;
    const body = document.getElementById('termBody');
    body.innerHTML = '<div class="t-line"><span class="t-prompt">$</span><span class="t-cmd" id="typingLine"></span></div>';
    const tl = document.getElementById('typingLine');
    function typeChar() {
      if (charIdx < termLines[0].text.length) {
        tl.textContent = termLines[0].text.slice(0, ++charIdx);
        setTimeout(typeChar, 38 + Math.random() * 20);
      } else { charIdx = 0; setTimeout(() => addLines(1), 300); }
    }
    function addLines(idx) {
      if (idx >= termLines.length) {
        body.innerHTML += '<div class="t-line"><span class="t-prompt">$</span><span class="t-cmd"> <span class="t-cursor" aria-hidden="true"></span></span></div>';
        isTyping = false; return;
      }
      const l = termLines[idx];
      const div = document.createElement('div');
      div.className = 't-line';
      div.innerHTML = '<span class="t-' + l.type + '">' + l.text + '</span>';
      body.appendChild(div);
      setTimeout(() => addLines(idx + 1), 420);
    }
    typeChar();
  }
  setInterval(nextTerminalCycle, 8000);

  (window.requestIdleCallback || function (cb) { setTimeout(cb, 1500); })(function () {
    const s = document.createElement('script');
    s.src = 'https://www.googletagmanager.com/gtag/js?id=G-RV6YK10DE3';
    s.async = true;
    document.head.appendChild(s);
    window.dataLayer = window.dataLayer || [];
    function gtag(){ dataLayer.push(arguments); }
    window.gtag = gtag;
    gtag('js', new Date());
    gtag('config', 'G-RV6YK10DE3');
  });
</script>
</body>
</html>