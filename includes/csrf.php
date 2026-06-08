<?php
// includes/csrf.php
// Tiny CSRF helper. Single per-session token works for all forms and AJAX.
// Required from config.php, so every page that talks to the DB has it.
//
// API:
//   csrf_token()     — return the current session token (lazy-created).
//   csrf_field()     — return a hidden <input name="csrf_token"> for HTML forms.
//   csrf_meta()      — return a <meta name="csrf-token"> tag for AJAX use.
//   csrf_check()     — true/false validation of a POST.
//   csrf_require()   — call at the top of any POST handler; aborts on mismatch.
//   csrf_rotate()    — regenerate the token (call on login/logout).
//
// NOTE: Do NOT put literal short-echo tags inside the comments here. PHP exits
// PHP mode at the closing tag even inside a // comment, which would silently
// break this file. Keep examples plain-text.

if (!function_exists('csrf_token')) {

    /**
     * Return the current session's CSRF token. Lazily creates one on first call.
     */
    function csrf_token() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Hidden input for HTML forms.
     */
    function csrf_field() {
        $t = htmlspecialchars(csrf_token(), ENT_QUOTES);
        return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$t}\">";
    }

    /**
     * <meta> tag so JS can pick up the token for AJAX calls.
     * Include once in every authenticated page's <head>.
     */
    function csrf_meta() {
        $t = htmlspecialchars(csrf_token(), ENT_QUOTES);
        return "<meta name=\"csrf-token\" content=\"{$t}\">";
    }

    /**
     * Validate the request and return true/false. Checks (in order):
     *   1. POST field `csrf_token`
     *   2. Header `X-CSRF-Token`  (preferred for AJAX)
     */
    function csrf_check() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $expected = $_SESSION['csrf_token'] ?? null;
        if (empty($expected)) return false;

        $supplied = $_POST['csrf_token'] ?? null;
        if (!$supplied && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $supplied = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (!is_string($supplied) || $supplied === '') return false;

        return hash_equals($expected, $supplied);
    }

    /**
     * Hard guard for POST handlers. Aborts the request with a clear error
     * if the token is missing or wrong. Returns JSON for XHR calls, plain
     * text otherwise.
     */
    function csrf_require() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (csrf_check()) return;

        http_response_code(419); // "Page Expired" — Laravel-style, conveys CSRF
        $accept = $_SERVER['HTTP_ACCEPT']               ?? '';
        $xrw    = $_SERVER['HTTP_X_REQUESTED_WITH']     ?? '';
        $xcrf   = $_SERVER['HTTP_X_CSRF_TOKEN']         ?? '';
        $isXhr  = stripos($accept, 'application/json') !== false
                  || $xrw === 'XMLHttpRequest'
                  || $xcrf !== '';

        if ($isXhr) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'status'  => 'error',
                'error'   => 'csrf',
                'message' => 'Your session has expired. Please reload the page and try again.',
            ]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Your session has expired. Please go back, reload the page, and try again.";
        }
        exit;
    }

    /**
     * Rotate the token. Call this on login/logout so a token harvested before
     * the auth boundary can't be reused after.
     */
    function csrf_rotate() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
