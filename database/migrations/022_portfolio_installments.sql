-- 022_portfolio_installments.sql
-- Kredi kartı taksitli alım: peşin fiyatına taksit, eşit tutarlı, her ekstreye bir taksit.
-- Taksit takvimi saklanmaz; alış tarihi, ekstre kesim günü ve son ödeme gününden hesaplanır
-- (InstallmentSchedule). Son ödeme tarihi geçen taksit ödendi sayılır.
-- installment_count NULL = peşin alım.

ALTER TABLE portfolio
    ADD COLUMN installment_count TINYINT UNSIGNED DEFAULT NULL COMMENT 'Number of equal card installments; NULL = paid in cash' AFTER notes,
    ADD COLUMN installment_card VARCHAR(50) DEFAULT NULL COMMENT 'Card the installments are charged to, e.g. Enpara' AFTER installment_count,
    ADD COLUMN installment_statement_day TINYINT UNSIGNED DEFAULT NULL COMMENT 'Card statement (hesap kesim) day of month, clamped to month end' AFTER installment_card,
    ADD COLUMN installment_due_days TINYINT UNSIGNED DEFAULT NULL COMMENT 'Days from statement to payment due date (son ödeme)' AFTER installment_statement_day;
