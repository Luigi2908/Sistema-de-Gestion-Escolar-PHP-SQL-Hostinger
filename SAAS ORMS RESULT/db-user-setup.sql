-- Developed by Mohammad Rameez Imdad (Rameez Scripts)
-- WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
-- YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
--
-- One-time local DB setup for ORMS on this XAMPP.
-- config.php uses db/user `u247530633_orms` (same as the production host);
-- this creates that db + user locally so the app runs without config changes.
-- The 172.18.% twin lets the WSL dev environment reach the db for testing.
-- Run via DB-USER-SETUP.bat (or paste into phpMyAdmin > SQL).

CREATE DATABASE IF NOT EXISTS `u247530633_orms` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'u247530633_orms'@'localhost' IDENTIFIED BY 'Claude@code.astoe.org1';
CREATE USER IF NOT EXISTS 'u247530633_orms'@'172.18.%' IDENTIFIED BY 'Claude@code.astoe.org1';
GRANT ALL PRIVILEGES ON `u247530633_orms`.* TO 'u247530633_orms'@'localhost';
GRANT ALL PRIVILEGES ON `u247530633_orms`.* TO 'u247530633_orms'@'172.18.%';
FLUSH PRIVILEGES;
SELECT 'ORMS local DB user ready' AS status;
