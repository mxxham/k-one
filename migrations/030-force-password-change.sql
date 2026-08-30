-- Migration 030: Force password change on first login
-- Adds must_change_password column to users table

ALTER TABLE `users`
ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0
AFTER `is_active`;

-- Set admin to force password change on next login
UPDATE `users` SET `must_change_password` = 1 WHERE `username` = 'admin';
