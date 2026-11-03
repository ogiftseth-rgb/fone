USE foneflex;

-- FoneFlex v0.7: persist the annual nominal rate and calculation method.
-- Historical agreements are intentionally labelled legacy_flat and retain
-- their original agreed amounts and installment allocations unchanged.

ALTER TABLE agreements
  ADD COLUMN annual_interest_rate DECIMAL(8,4) NOT NULL DEFAULT 0.0000
    COMMENT 'Nominal annual interest rate in percent, used for new reducing-balance agreements',
  ADD COLUMN interest_method VARCHAR(30) NOT NULL DEFAULT 'legacy_flat'
    COMMENT 'legacy_flat for historical schedules; reducing_balance for new amortised schedules';

CREATE INDEX idx_agreements_interest_method
  ON agreements (organization_id, interest_method, created_at);
