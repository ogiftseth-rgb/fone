USE foneflex;

-- FoneFlex v0.6: provider credentials, device enrolment references, API action history,
-- and explicit device-management notice evidence. Existing records are preserved.

CREATE TABLE IF NOT EXISTS device_provider_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope_code VARCHAR(50) NOT NULL,
  organization_id BIGINT UNSIGNED NULL,
  provider_key VARCHAR(40) NOT NULL,
  connection_name VARCHAR(150) NOT NULL,
  environment VARCHAR(20) NOT NULL DEFAULT 'production',
  region_code VARCHAR(10) NULL,
  public_config_json JSON NULL,
  secrets_ciphertext MEDIUMTEXT NOT NULL,
  multi_tenant_authorized TINYINT(1) NOT NULL DEFAULT 0,
  connection_status VARCHAR(40) NOT NULL DEFAULT 'configured',
  last_tested_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_provider_scope (scope_code, provider_key),
  KEY idx_provider_connection_org (organization_id, provider_key),
  KEY idx_provider_connection_status (connection_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_provider_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  provider_connection_id BIGINT UNSIGNED NOT NULL,
  provider_key VARCHAR(40) NOT NULL,
  device_uid VARCHAR(80) NOT NULL,
  approve_id VARCHAR(100) NULL,
  provider_object_id VARCHAR(150) NULL,
  provider_upload_id VARCHAR(150) NULL,
  provider_assignment_activity_id VARCHAR(150) NULL,
  enrollment_status VARCHAR(50) NOT NULL DEFAULT 'not_submitted',
  last_provider_state VARCHAR(100) NULL,
  last_checked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_provider_device (organization_id, inventory_item_id, provider_key),
  KEY idx_provider_device_uid (provider_key, device_uid),
  KEY idx_provider_device_state (organization_id, enrollment_status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_provider_actions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  agreement_id BIGINT UNSIGNED NULL,
  provider_connection_id BIGINT UNSIGNED NOT NULL,
  provider_key VARCHAR(40) NOT NULL,
  action_type VARCHAR(40) NOT NULL,
  action_status VARCHAR(40) NOT NULL DEFAULT 'submitted',
  request_reference VARCHAR(100) NOT NULL,
  provider_request_id VARCHAR(150) NULL,
  reason VARCHAR(500) NULL,
  response_summary JSON NULL,
  requested_by BIGINT UNSIGNED NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY idx_provider_action_agreement (organization_id, agreement_id, requested_at),
  KEY idx_provider_action_item (organization_id, inventory_item_id, requested_at),
  KEY idx_provider_action_status (provider_key, action_status, requested_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_management_consents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  agreement_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  consent_customer_name VARCHAR(160) NOT NULL,
  acknowledgement_method VARCHAR(30) NOT NULL,
  notice_version VARCHAR(30) NOT NULL DEFAULT 'device-financing-v1',
  notice_summary VARCHAR(1000) NOT NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_device_consent_agreement (organization_id, agreement_id, recorded_at),
  KEY idx_device_consent_item (organization_id, inventory_item_id, recorded_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO platform_services(service_key,service_label,description,enabled,integration_status)
VALUES
('samsung_knox_guard','Samsung Knox Guard API','Samsung-authorised device financing enrolment, lock/unlock and plan-completion integration. Requires an approved Knox Guard account and API access.',1,'credentials_required'),
('apple_business_ade','Apple Business API / Automated Device Enrolment','Apple Business device inventory and assignment to an approved MDM server. Remote device commands require the associated MDM service.',1,'credentials_required');
