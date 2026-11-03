USE foneflex;

-- Private customer KYC metadata. Document bytes are stored outside the public web root.
CREATE TABLE IF NOT EXISTS customer_kyc (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  date_of_birth DATE NULL,
  id_front_path VARCHAR(500) NOT NULL,
  id_back_path VARCHAR(500) NOT NULL,
  customer_photo_path VARCHAR(500) NOT NULL,
  mpesa_statement_path VARCHAR(500) NULL,
  mpesa_statement_requested TINYINT(1) NOT NULL DEFAULT 0,
  mpesa_statement_reason VARCHAR(255) NULL,
  statement_period_from DATE NULL,
  statement_period_to DATE NULL,
  county VARCHAR(100) NULL,
  town VARCHAR(100) NULL,
  estate VARCHAR(120) NULL,
  street VARCHAR(120) NULL,
  nearest_landmark VARCHAR(180) NULL,
  gps_latitude DECIMAL(10,7) NULL,
  gps_longitude DECIMAL(10,7) NULL,
  gps_accuracy_m DECIMAL(10,2) NULL,
  gps_captured_at DATETIME NULL,
  next_of_kin_name VARCHAR(160) NOT NULL,
  next_of_kin_phone VARCHAR(30) NOT NULL,
  next_of_kin_relationship VARCHAR(60) NOT NULL,
  next_of_kin_address VARCHAR(255) NULL,
  kin_notice_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  kyc_status ENUM('pending_review','verified','rejected') NOT NULL DEFAULT 'pending_review',
  review_note VARCHAR(1000) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  consent_version VARCHAR(40) NOT NULL DEFAULT 'v1',
  consent_recorded_at DATETIME NOT NULL,
  last_updated_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_kyc (organization_id, customer_id),
  INDEX idx_kyc_org_status (organization_id, kyc_status),
  CONSTRAINT fk_kyc_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_kyc_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_kyc_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
  CONSTRAINT fk_kyc_updated_by FOREIGN KEY (last_updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Immutable-style action history for agreement completion and authorised device release requests.
CREATE TABLE IF NOT EXISTS device_control_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  agreement_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  action_type ENUM('release') NOT NULL DEFAULT 'release',
  action_status ENUM('blocked_no_provider','queued','submitted','confirmed','failed') NOT NULL DEFAULT 'blocked_no_provider',
  request_reference VARCHAR(80) NOT NULL UNIQUE,
  provider_reference VARCHAR(150) NULL,
  reason VARCHAR(500) NOT NULL,
  provider_response JSON NULL,
  requested_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_device_action_org_date (organization_id, requested_at),
  INDEX idx_device_action_agreement (agreement_id, requested_at),
  CONSTRAINT fk_device_action_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_device_action_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id),
  CONSTRAINT fk_device_action_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_device_action_user FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Feature availability and provider connectivity are deliberately separate flags.
CREATE TABLE IF NOT EXISTS platform_services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_key VARCHAR(80) NOT NULL UNIQUE,
  service_label VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  integration_status ENUM('built_in','not_configured','connected','disabled') NOT NULL DEFAULT 'not_configured',
  config_json JSON NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  CONSTRAINT fk_platform_service_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO platform_services(service_key,service_label,description,enabled,integration_status) VALUES
('customer_kyc','Customer KYC','Customer biodata, camera-based ID capture, customer photograph, next of kin and secure document storage.',1,'built_in'),
('mpesa_statement_capture','M-Pesa statement capture','Optional secure M-Pesa statement collection for affordability and verification review.',1,'built_in'),
('device_release','Hire-purchase device release','Settlement verification and device release request history. An approved OEM/financing provider adapter is still required for actual remote release.',1,'not_configured'),
('daraja_mpesa','Safaricom Daraja','STK Push, callbacks and payment reconciliation. Requires Safaricom credentials and production integration.',0,'not_configured'),
('sms_notifications','SMS notifications','SMS reminders and customer notifications. Requires an approved SMS gateway.',0,'not_configured'),
('whatsapp_notifications','WhatsApp notifications','WhatsApp notifications. Requires an approved WhatsApp Business provider.',0,'not_configured'),
('subscription_billing','Subscription billing','Online merchant subscription checkout, automated billing and receipts. Requires payment gateway integration.',0,'not_configured')
ON DUPLICATE KEY UPDATE service_label=VALUES(service_label),description=VALUES(description);
