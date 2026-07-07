-- Run this SQL to add the missing columns to your reservations table

ALTER TABLE `reservations` 
ADD COLUMN `actual_pickup_time` DATETIME NULL DEFAULT NULL AFTER `updated_at`,
ADD COLUMN `actual_return_time` DATETIME NULL DEFAULT NULL AFTER `actual_pickup_time`,
ADD COLUMN `check_in_notes` TEXT DEFAULT NULL AFTER `actual_return_time`,
ADD COLUMN `check_out_notes` TEXT DEFAULT NULL AFTER `check_in_notes`,
ADD COLUMN `late_fee` DECIMAL(10,2) DEFAULT 0.00 AFTER `check_out_notes`,
ADD COLUMN `total_extension_fee` DECIMAL(10,2) DEFAULT 0.00 AFTER `late_fee`,
ADD COLUMN `contract_id` INT NULL DEFAULT NULL AFTER `total_extension_fee`,
ADD COLUMN `contract_signed_at` DATETIME NULL DEFAULT NULL AFTER `contract_id`;
