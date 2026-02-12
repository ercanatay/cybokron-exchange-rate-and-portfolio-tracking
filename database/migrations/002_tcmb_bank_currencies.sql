-- Migration: TCMB bank + missing currencies
-- Run: php database/migrate.php

USE `cybokron`;

-- Add TCMB bank
INSERT IGNORE INTO `banks` (`name`, `slug`, `url`, `scraper_class`) VALUES
('TCMB', 'tcmb', 'https://www.tcmb.gov.tr/kurlar/today.xml', 'TCMB');

-- Add TCMB currencies not in default seed
INSERT IGNORE INTO `currencies` (`code`, `name_tr`, `name_en`, `symbol`, `type`, `decimal_places`) VALUES
('DKK', 'Danimarka Kronu', 'Danish Krone', 'kr', 'fiat', 4),
('NOK', 'Norveç Kronu', 'Norwegian Krone', 'kr', 'fiat', 4),
('SEK', 'İsveç Kronu', 'Swedish Krona', 'kr', 'fiat', 4),
('KWD', 'Kuveyt Dinarı', 'Kuwaiti Dinar', 'KD', 'fiat', 4),
('RON', 'Rumen Leyi', 'Romanian Leu', 'lei', 'fiat', 4),
('RUB', 'Rus Rublesi', 'Russian Rouble', '₽', 'fiat', 4),
('PKR', 'Pakistan Rupisi', 'Pakistani Rupee', '₨', 'fiat', 4),
('QAR', 'Katar Riyali', 'Qatari Rial', 'QR', 'fiat', 4),
('KRW', 'Güney Kore Wonu', 'South Korean Won', '₩', 'fiat', 4),
('AZN', 'Azerbaycan Manatı', 'Azerbaijani Manat', '₼', 'fiat', 4),
('KZT', 'Kazakistan Tengesi', 'Kazakhstan Tenge', '₸', 'fiat', 4),
('XDR', 'Özel Çekme Hakkı (SDR)', 'Special Drawing Right', 'XDR', 'fiat', 4);
