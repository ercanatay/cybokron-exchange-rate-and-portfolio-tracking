-- Fix charset: convert all tables to utf8mb4 and fix double-encoded Turkish characters
-- This resolves garbled text like "DolarÄ±" → "Doları", "Ä°sviÃ§re" → "İsviçre"

-- Convert tables to utf8mb4
ALTER TABLE `currencies` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `banks` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `portfolio` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `portfolio_groups` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `portfolio_goals` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `rates` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `rate_history` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `settings` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `alerts` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `schema_migrations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Fix currency names (overwrite with correct UTF-8 values)
UPDATE `currencies` SET `name_tr` = 'BAE Dirhemi' WHERE `code` = 'AED';
UPDATE `currencies` SET `name_tr` = 'Avustralya Doları' WHERE `code` = 'AUD';
UPDATE `currencies` SET `name_tr` = 'Azerbaycan Manatı' WHERE `code` = 'AZN';
UPDATE `currencies` SET `name_tr` = 'Kanada Doları' WHERE `code` = 'CAD';
UPDATE `currencies` SET `name_tr` = 'İsviçre Frangı' WHERE `code` = 'CHF';
UPDATE `currencies` SET `name_tr` = 'Çin Yuanı' WHERE `code` = 'CNY';
UPDATE `currencies` SET `name_tr` = 'Danimarka Kronu' WHERE `code` = 'DKK';
UPDATE `currencies` SET `name_tr` = 'Euro' WHERE `code` = 'EUR';
UPDATE `currencies` SET `name_tr` = 'İngiliz Sterlini' WHERE `code` = 'GBP';
UPDATE `currencies` SET `name_tr` = 'Japon Yeni' WHERE `code` = 'JPY';
UPDATE `currencies` SET `name_tr` = 'Güney Kore Wonu' WHERE `code` = 'KRW';
UPDATE `currencies` SET `name_tr` = 'Kuveyt Dinarı' WHERE `code` = 'KWD';
UPDATE `currencies` SET `name_tr` = 'Kazakistan Tengesi' WHERE `code` = 'KZT';
UPDATE `currencies` SET `name_tr` = 'Norveç Kronu' WHERE `code` = 'NOK';
UPDATE `currencies` SET `name_tr` = 'Pakistan Rupisi' WHERE `code` = 'PKR';
UPDATE `currencies` SET `name_tr` = 'Katar Riyali' WHERE `code` = 'QAR';
UPDATE `currencies` SET `name_tr` = 'Rumen Leyi' WHERE `code` = 'RON';
UPDATE `currencies` SET `name_tr` = 'Rus Rublesi' WHERE `code` = 'RUB';
UPDATE `currencies` SET `name_tr` = 'Suudi Riyali' WHERE `code` = 'SAR';
UPDATE `currencies` SET `name_tr` = 'İsveç Kronu' WHERE `code` = 'SEK';
UPDATE `currencies` SET `name_tr` = 'Amerikan Doları' WHERE `code` = 'USD';
UPDATE `currencies` SET `name_tr` = 'Gümüş' WHERE `code` = 'XAG';
UPDATE `currencies` SET `name_tr` = 'Altın' WHERE `code` = 'XAU';
UPDATE `currencies` SET `name_tr` = 'Özel Çekme Hakkı (SDR)' WHERE `code` = 'XDR';
UPDATE `currencies` SET `name_tr` = 'Paladyum' WHERE `code` = 'XPD';
UPDATE `currencies` SET `name_tr` = 'Platin' WHERE `code` = 'XPT';

-- Fix bank names
UPDATE `banks` SET `name` = 'Dünya Katılım' WHERE `slug` = 'dunya-katilim';
UPDATE `banks` SET `name` = 'İş Bankası' WHERE `slug` = 'is-bankasi';
UPDATE `banks` SET `name` = 'TCMB' WHERE `slug` = 'tcmb';
