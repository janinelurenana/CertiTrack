-- MySQL 8 / MySQL Community Edition. Run as an administrative user:  mysql -u root -p < database/create-database.sql
-- CHANGE the password below and use the same value for DB_PASSWORD in backend/.env.
CREATE DATABASE IF NOT EXISTS certitrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'certitrack'@'localhost' IDENTIFIED BY 'change_me_db_password';
GRANT ALL PRIVILEGES ON certitrack.* TO 'certitrack'@'localhost';
FLUSH PRIVILEGES;
