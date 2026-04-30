-- ==============================================================================
-- Migration Script v2.0 - Water Management System Improvements
-- Run this script ONCE on your existing database to apply all improvements
-- ==============================================================================

-- ==============================================================================
-- 1. Add Void support to Invoices
-- ==============================================================================
ALTER TABLE Invoices
    ADD COLUMN IF NOT EXISTS is_voided  BOOLEAN   DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS voided_at  TIMESTAMP,
    ADD COLUMN IF NOT EXISTS voided_by  INT REFERENCES Users(id);

-- ==============================================================================
-- 2. Add Void support to Driver_Settlements
-- ==============================================================================
ALTER TABLE Driver_Settlements
    ADD COLUMN IF NOT EXISTS is_voided  BOOLEAN   DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS voided_at  TIMESTAMP,
    ADD COLUMN IF NOT EXISTS voided_by  INT REFERENCES Users(id);

-- ==============================================================================
-- 3. Add CHECK constraints to prevent negative amounts (Database-level enforcement)
-- ==============================================================================
ALTER TABLE Invoices
    ADD CONSTRAINT IF NOT EXISTS chk_invoice_total_positive    CHECK (total_amount    >= 0),
    ADD CONSTRAINT IF NOT EXISTS chk_invoice_net_positive      CHECK (net_amount      >= 0),
    ADD CONSTRAINT IF NOT EXISTS chk_invoice_paid_positive     CHECK (paid_amount     >= 0),
    ADD CONSTRAINT IF NOT EXISTS chk_invoice_due_positive      CHECK (due_amount      >= 0),
    ADD CONSTRAINT IF NOT EXISTS chk_invoice_discount_positive CHECK (discount_amount >= 0);

ALTER TABLE Settlement_Details
    ADD CONSTRAINT IF NOT EXISTS chk_detail_paid_positive     CHECK (amount_paid     >= 0),
    ADD CONSTRAINT IF NOT EXISTS chk_detail_discount_positive CHECK (discount_amount >= 0);

ALTER TABLE Expenses
    ADD CONSTRAINT IF NOT EXISTS chk_expense_amount_positive CHECK (amount >= 0);

ALTER TABLE Customers
    ADD CONSTRAINT IF NOT EXISTS chk_customer_lifetime_positive CHECK (total_lifetime_paid >= 0);

-- ==============================================================================
-- 4. Add index for performance on common queries
-- ==============================================================================
CREATE INDEX IF NOT EXISTS idx_invoices_date        ON Invoices (invoice_date);
CREATE INDEX IF NOT EXISTS idx_invoices_customer    ON Invoices (customer_id);
CREATE INDEX IF NOT EXISTS idx_invoices_trip        ON Invoices (trip_id);
CREATE INDEX IF NOT EXISTS idx_invoices_voided      ON Invoices (is_voided);
CREATE INDEX IF NOT EXISTS idx_trips_date           ON Trips    (trip_date);
CREATE INDEX IF NOT EXISTS idx_trips_driver         ON Trips    (driver_id);
CREATE INDEX IF NOT EXISTS idx_trips_status         ON Trips    (status);
CREATE INDEX IF NOT EXISTS idx_settlements_driver   ON Driver_Settlements (driver_id);
CREATE INDEX IF NOT EXISTS idx_settlements_date     ON Driver_Settlements (settlement_date);
CREATE INDEX IF NOT EXISTS idx_fund_date            ON Fund_Transactions  (transaction_date);
CREATE INDEX IF NOT EXISTS idx_expenses_date        ON Expenses (expense_date);
CREATE INDEX IF NOT EXISTS idx_customers_balance    ON Customers (balance) WHERE balance > 0;

-- ==============================================================================
-- 5. Add UNIQUE constraint to prevent duplicate settlements per driver per day
-- ==============================================================================
-- Note: Commented out by default - uncomment if business rules require one settlement per driver per day
-- CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_settlement_driver_date
--     ON Driver_Settlements (driver_id, settlement_date::date)
--     WHERE is_voided = false;

-- ==============================================================================
-- 6. Update existing data: ensure is_voided defaults are set
-- ==============================================================================
UPDATE Invoices          SET is_voided = FALSE WHERE is_voided IS NULL;
UPDATE Driver_Settlements SET is_voided = FALSE WHERE is_voided IS NULL;

-- ==============================================================================
-- Verification queries (run after migration to confirm success)
-- ==============================================================================
-- SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'invoices' ORDER BY ordinal_position;
-- SELECT indexname FROM pg_indexes WHERE tablename IN ('invoices', 'trips', 'customers', 'fund_transactions');

SELECT 'Migration v2.0 completed successfully!' AS status;
