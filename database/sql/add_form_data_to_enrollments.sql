-- Required for enrollment wizard submit (stores full application JSON + uploaded file metadata).
-- Run in phpMyAdmin: select database `eerc_lms` → SQL tab → paste → Go.
-- Or from XAMPP shell: mysql -u root eerc_lms < database/sql/add_form_data_to_enrollments.sql

ALTER TABLE `enrollments`
  ADD COLUMN IF NOT EXISTS `form_data` JSON NULL AFTER `payment_proof_mime`,
  ADD COLUMN IF NOT EXISTS `documents` JSON NULL AFTER `form_data`;

-- MariaDB 10.4 (XAMPP) may not support IF NOT EXISTS on ADD COLUMN.
-- If the statement above fails, run these one at a time (skip if column already exists):

-- ALTER TABLE `enrollments` ADD COLUMN `form_data` JSON NULL AFTER `payment_proof_mime`;
-- ALTER TABLE `enrollments` ADD COLUMN `documents` JSON NULL AFTER `form_data`;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_06_14_180000_add_form_data_to_enrollments_table', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations`
  WHERE `migration` = '2026_06_14_180000_add_form_data_to_enrollments_table'
);
