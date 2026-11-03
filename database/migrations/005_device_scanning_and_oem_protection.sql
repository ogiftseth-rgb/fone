USE foneflex;

-- FoneFlex v0.5: barcode/QR scan audit, cross-tenant IMEI uniqueness, OEM enrolment and device events.
-- No destructive changes to customers, inventory_items or agreements are made.

CREATE TABLE IF NOT EXISTS device_imei_registry (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  imei_hmac BINARY(32) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  imei_slot ENUM('imei1','imei2') NOT NULL,
  registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_device_imei_registry_hmac (imei_hmac),
  KEY idx_device_imei_registry_item (organization_id, inventory_item_id),
  CONSTRAINT fk_device_imei_registry_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_device_imei_registry_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_protection_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  provider_key VARCHAR(60) NOT NULL DEFAULT 'unconfigured',
  protection_status ENUM('not_assessed','eligible','unsupported','enrolment_pending','provider_confirmed','failed','release_requested','released') NOT NULL DEFAULT 'not_assessed',
  enrollment_method ENUM('manual','reseller_preload','zero_touch','setup_qr','apple_ade') NOT NULL DEFAULT 'manual',
  provider_device_ref VARCHAR(150) NULL,
  scan_method ENUM('camera','manual') NOT NULL DEFAULT 'manual',
  scan_format VARCHAR(50) NULL,
  scan_digest BINARY(32) NULL,
  provider_notes VARCHAR(1000) NULL,
  provider_checked_by BIGINT UNSIGNED NULL,
  provider_checked_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_device_protection_item (inventory_item_id),
  KEY idx_device_protection_org_status (organization_id, protection_status),
  KEY idx_device_protection_provider (provider_key, protection_status),
  CONSTRAINT fk_device_protection_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_device_protection_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_device_protection_checked_by FOREIGN KEY (provider_checked_by) REFERENCES users(id),
  CONSTRAINT fk_device_protection_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_device_protection_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_protection_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  event_details JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_device_protection_events_item (organization_id, inventory_item_id, created_at),
  KEY idx_device_protection_events_type (event_type, created_at),
  CONSTRAINT fk_device_protection_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_device_protection_events_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_device_protection_events_user FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO platform_services(service_key,service_label,description,enabled,integration_status)
VALUES
('device_identity_scanning','Phone barcode / QR identity scanning','Camera barcode/QR decoding, IMEI Luhn validation, serial capture and duplicate device identity controls.',1,'built_in'),
('oem_device_protection','OEM / financing provider device protection','Provider-neutral enrolment register. Actual payment-based remote restrictions require a supported OEM/financing provider contract and API credentials.',1,'not_configured');
