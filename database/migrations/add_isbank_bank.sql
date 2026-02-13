-- Migration: Add Is Bankasi source
-- Date: 2026-02-13
-- Description: Inserts Is Bankasi into banks table if missing

INSERT IGNORE INTO `banks` (`name`, `slug`, `url`, `scraper_class`) VALUES
('İş Bankası', 'is-bankasi', 'https://kur.doviz.com/isbankasi', 'IsBank');
