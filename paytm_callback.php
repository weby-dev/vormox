<?php
// Paytm static-UPI QR flow does not POST back to a callback URL.
// Payment confirmation goes through ajax_paytm_status.php (browser polls
// /order/status, then ajax_paytm_status.php verifies server-side and marks
// the invoice paid).
//
// Disabling this endpoint so it can't be hit to bypass the verified flow.

http_response_code(410);
header('Content-Type: text/plain');
exit("Endpoint disabled. Payment confirmation runs through ajax_paytm_status.php.");
