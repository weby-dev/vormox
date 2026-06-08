-- invoices.type was NOT NULL with no DEFAULT, so any INSERT that forgot the
-- column silently failed under MySQL strict mode. We've now added the column
-- to every INSERT site, but to stop this class of bug coming back via future
-- code, give the column a sensible default ('order' is the most common type).
ALTER TABLE invoices
  MODIFY COLUMN type ENUM('order','renew','topup') NOT NULL DEFAULT 'order';
