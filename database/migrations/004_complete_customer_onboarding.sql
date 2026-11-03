USE foneflex;

-- FoneFlex v0.4: fields required by the integrated customer onboarding flow.
-- Run once, after migrations 002 and 003.

ALTER TABLE customers
  ADD COLUMN nationality VARCHAR(80) NULL AFTER email,
  ADD COLUMN gender ENUM('female','male','other','prefer_not_to_say') NULL AFTER nationality;

ALTER TABLE customer_kyc
  ADD COLUMN consent_customer_name VARCHAR(160) NULL AFTER consent_version,
  ADD COLUMN consent_method ENUM('written','verbal','digital','other','not_recorded') NOT NULL DEFAULT 'not_recorded' AFTER consent_customer_name,
  ADD COLUMN customer_consent_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER consent_method,
  ADD COLUMN mpesa_statement_status ENUM('not_required','requested','received','reviewed','rejected') NOT NULL DEFAULT 'not_required' AFTER mpesa_statement_requested,
  ADD COLUMN mpesa_statement_review_note VARCHAR(1000) NULL AFTER mpesa_statement_status,
  ADD COLUMN mpesa_statement_reviewed_by BIGINT UNSIGNED NULL AFTER mpesa_statement_review_note,
  ADD COLUMN mpesa_statement_reviewed_at DATETIME NULL AFTER mpesa_statement_reviewed_by,
  ADD CONSTRAINT fk_kyc_mpesa_reviewer FOREIGN KEY (mpesa_statement_reviewed_by) REFERENCES users(id);

-- Existing v0.3 records already required a customer-consent confirmation and
-- required a statement attachment only if one was explicitly requested.
UPDATE customer_kyc
SET customer_consent_confirmed = CASE WHEN consent_recorded_at IS NOT NULL THEN 1 ELSE 0 END,
    mpesa_statement_status = CASE
      WHEN mpesa_statement_path IS NOT NULL THEN 'received'
      WHEN mpesa_statement_requested = 1 THEN 'requested'
      ELSE 'not_required'
    END;
