<?php
// includes/email_layout.php
// Shared HTML layout for every transactional email. Web-side and cron both
// require this file so the brand stays consistent across all notifications.
//
// Email clients are picky:
//   - Web fonts won't load (use safe fallbacks)
//   - CSS grid/flexbox don't work (use nested tables)
//   - Background images often blocked
//   - Inline styles required
//
// All `vormox_email_*` helpers here return raw HTML snippets meant to be
// composed into vormox_email_template($title, $bodyHtml, $previewText).

if (!function_exists('vormox_email_template')) {

    /**
     * Wrap a content block in the Vormox brand chrome.
     *
     * @param string $title         <title> + screen reader title
     * @param string $bodyHtml      Inner HTML (use the vormox_email_* helpers)
     * @param string $previewText   Inbox preview snippet (Gmail / Apple Mail)
     * @return string Full HTML email body
     */
    function vormox_email_template($title, $bodyHtml, $previewText = '') {
        $title       = htmlspecialchars($title,       ENT_QUOTES);
        $previewText = htmlspecialchars($previewText, ENT_QUOTES);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>{$title}</title>
</head>
<body style="margin:0; padding:0; background-color:#050810; -webkit-font-smoothing:antialiased; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">

<!-- Inbox preview text (hidden in body) -->
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#050810;">{$previewText}</div>

<!-- Faint grid background using a subtle color (most clients drop the linear-gradient overlay) -->
<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="background-color:#050810;">
  <tr>
    <td align="center" style="padding:40px 16px;">

      <!-- Logo header -->
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="width:100%; max-width:560px; margin:0 auto 28px auto;">
        <tr>
          <td align="center">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0">
              <tr>
                <td valign="middle" style="background-color:#3b82f6; width:36px; height:36px; border-radius:8px; text-align:center; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:18px; font-weight:800; color:#ffffff; line-height:36px;">V</td>
                <td valign="middle" style="padding-left:12px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:22px; font-weight:800; letter-spacing:-0.5px; line-height:36px;">
                  <span style="color:#e8edf8;">Vorm</span><span style="color:#60a5fa;">ox</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <!-- Main content card -->
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="width:100%; max-width:560px; background-color:#0d1426; border:1px solid #1a2540; border-radius:16px;">
        <tr>
          <td style="padding:40px 36px;">
            {$bodyHtml}
          </td>
        </tr>
      </table>

      <!-- Footer -->
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="width:100%; max-width:560px; margin:20px auto 0 auto;">
        <tr>
          <td align="center" style="padding:16px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:11px; color:#3a4a68; line-height:1.6; letter-spacing:0.04em;">
            <p style="margin:0 0 6px 0; text-transform:uppercase; color:#3a4a68;">Vormox Automation Cloud</p>
            <p style="margin:0; color:#3a4a68;">This email was sent because you have a Vormox account.</p>
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }

    // -----------------------------------------------------------------------
    // Content building blocks. Each returns an HTML fragment for the card.
    // Compose any number of these inside vormox_email_template().
    // -----------------------------------------------------------------------

    /**
     * Top of the card — short eyebrow + bold headline + paragraph.
     * Accent colors mirror the app: blue/green/orange/red/purple.
     *
     * $accent — one of: blue, green, orange, red, purple
     */
    function vormox_email_hero($firstName, $eyebrow, $headline, $subHtml = '', $accent = 'blue') {
        $colors = [
            'blue'   => '#60a5fa',
            'green'  => '#22d3ee',
            'orange' => '#fb923c',
            'red'    => '#f87171',
            'purple' => '#a78bfa',
        ];
        $color   = $colors[$accent] ?? $colors['blue'];
        $name    = htmlspecialchars($firstName ?: 'there', ENT_QUOTES);
        $eyebrow = htmlspecialchars($eyebrow, ENT_QUOTES);
        $head    = htmlspecialchars($headline, ENT_QUOTES);
        // $subHtml is meant to be safe HTML — the caller controls it.

        return <<<HTML
<p style="margin:0 0 8px 0; font-family:'Courier New',Consolas,monospace; font-size:11px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:{$color};">{$eyebrow}</p>
<h1 style="margin:0 0 12px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:24px; font-weight:700; color:#e8edf8; line-height:1.25; letter-spacing:-0.02em;">{$head}</h1>
<p style="margin:0 0 24px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:15px; line-height:1.6; color:#7a8aa8;">
  Hi {$name},<br>
  {$subHtml}
</p>
HTML;
    }

    /**
     * Key/value details box — like a small panel card in the app.
     * $rows = [['Label', 'Value'], …]   (values rendered as plain text)
     */
    function vormox_email_details($rows, $accent = 'blue') {
        $colors = [
            'blue'   => ['bg' => 'rgba(59,130,246,0.08)',  'border' => '#1e3a5f'],
            'green'  => ['bg' => 'rgba(34,211,238,0.08)',  'border' => '#114a55'],
            'orange' => ['bg' => 'rgba(251,146,60,0.08)',  'border' => '#5f3a18'],
            'red'    => ['bg' => 'rgba(248,113,113,0.08)', 'border' => '#5f2a2a'],
            'purple' => ['bg' => 'rgba(167,139,250,0.08)', 'border' => '#3a2c5f'],
        ];
        $c = $colors[$accent] ?? $colors['blue'];

        $rowsHtml = '';
        foreach ($rows as $r) {
            $label = htmlspecialchars($r[0], ENT_QUOTES);
            $value = htmlspecialchars($r[1], ENT_QUOTES);
            $rowsHtml .= <<<ROW
<tr>
  <td style="padding:8px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:13px; color:#7a8aa8;">{$label}</td>
  <td align="right" style="padding:8px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; color:#e8edf8; font-weight:600;">{$value}</td>
</tr>
ROW;
        }

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="width:100%; background-color:{$c['bg']}; border:1px solid {$c['border']}; border-radius:10px; margin:0 0 24px 0;">
  <tr>
    <td style="padding:18px 20px;">
      <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">{$rowsHtml}</table>
    </td>
  </tr>
</table>
HTML;
    }

    /**
     * Primary call-to-action button (blue) or a secondary outline variant.
     * Centered. Bulletproof-button pattern with VML fallback for Outlook.
     */
    function vormox_email_button($label, $href, $style = 'primary') {
        $label = htmlspecialchars($label, ENT_QUOTES);
        $href  = htmlspecialchars($href,  ENT_QUOTES);

        if ($style === 'secondary') {
            $bg     = '#0d1426';
            $border = '1px solid #1e3a5f';
            $color  = '#60a5fa';
        } else {
            $bg     = '#3b82f6';
            $border = '1px solid #3b82f6';
            $color  = '#ffffff';
        }

        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:8px auto 4px auto;">
  <tr>
    <td align="center" style="background-color:{$bg}; border:{$border}; border-radius:8px;">
      <a href="{$href}" target="_blank" style="display:inline-block; padding:14px 32px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; color:{$color}; text-decoration:none; letter-spacing:0.01em;">{$label}</a>
    </td>
  </tr>
</table>
HTML;
    }

    /**
     * Big OTP code display, monospaced & spaced.
     */
    function vormox_email_code($code) {
        $code = htmlspecialchars((string) $code, ENT_QUOTES);
        return <<<HTML
<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:8px auto 24px auto;">
  <tr>
    <td align="center" style="background-color:#111b35; border:1px solid #2b3a6b; border-radius:10px; padding:20px 28px;">
      <span style="font-family:'Courier New',Consolas,monospace; font-size:30px; font-weight:700; color:#a78bfa; letter-spacing:10px; line-height:1;">{$code}</span>
    </td>
  </tr>
</table>
HTML;
    }

    /**
     * Small muted footnote inside the card (e.g. "Expires in 15 minutes").
     */
    function vormox_email_footnote($text) {
        $text = htmlspecialchars($text, ENT_QUOTES);
        return <<<HTML
<p style="margin:16px 0 0 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:12px; color:#3a4a68; line-height:1.6; text-align:center;">{$text}</p>
HTML;
    }
}
