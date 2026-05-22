
-- Link loans to loan_clients by id (replaces string-match joins)
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `client_id` INT DEFAULT NULL AFTER `phone`;
ALTER TABLE `loans` ADD INDEX IF NOT EXISTS `idx_loans_client_id` (`client_id`);

-- Backfill client_id on all existing loans
UPDATE `loans` l
JOIN `loan_clients` lc ON lc.name = l.client
    AND COALESCE(lc.phone,'') = COALESCE(l.phone,'')
SET l.client_id = lc.id
WHERE l.client_id IS NULL;

