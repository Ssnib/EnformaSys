-- ==============================================================================
-- EnformaSys: Seminarian Profile Picture & Cooldown Limit Migration
--
-- Adds `profile_picture` (VARCHAR for avatar storage path) and
-- `last_profile_update` (DATETIME for 1-week edit cooldown tracking) to `seminarians`.
-- ==============================================================================

ALTER TABLE `seminarians`
  ADD COLUMN `profile_picture` VARCHAR(255) NULL AFTER `academic_remarks`,
  ADD COLUMN `last_profile_update` DATETIME NULL AFTER `profile_picture`;

