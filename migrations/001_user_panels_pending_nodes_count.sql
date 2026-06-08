-- Tracks the target node count requested in a plan-upgrade invoice (UPG-).
-- Applied when the matching invoice flips Paid.
ALTER TABLE user_panels
  ADD COLUMN pending_nodes_count INT NULL AFTER nodes_count;
