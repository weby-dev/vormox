-- Bind Paytm order IDs to invoices.
--
-- Without this binding, ajax_paytm_status.php trusts a client-supplied
-- order_id paired with a client-supplied invoice_number. Anyone with a
-- successful Paytm transaction (for any reason, any amount) can replay that
-- order id against a much larger invoice and mark it Paid.
--
-- After this migration, view-invoice.php persists the order_id + expected
-- INR amount at QR generation time, and ajax_paytm_status.php ignores the
-- client's order_id, looking up the bound values by invoice_number instead.

ALTER TABLE invoices
  ADD COLUMN paytm_order_id        VARCHAR(64)   NULL AFTER gateway_logs,
  ADD COLUMN paytm_expected_amount DECIMAL(10,2) NULL AFTER paytm_order_id,
  ADD COLUMN paytm_order_issued_at DATETIME      NULL AFTER paytm_expected_amount,
  ADD INDEX idx_paytm_order_id (paytm_order_id);
