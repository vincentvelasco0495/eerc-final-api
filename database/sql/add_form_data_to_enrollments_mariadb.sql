-- Run these in phpMyAdmin if IF NOT EXISTS is not supported (MariaDB 10.4 / XAMPP).

ALTER TABLE `enrollments` ADD COLUMN `form_data` JSON NULL AFTER `payment_proof_mime`;
ALTER TABLE `enrollments` ADD COLUMN `documents` JSON NULL AFTER `form_data`;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_06_14_180000_add_form_data_to_enrollments_table', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
WHERE NOT EXISTS (
  SELECT 1 FROM `migrations`
  WHERE `migration` = '2026_06_14_180000_add_form_data_to_enrollments_table'
);
