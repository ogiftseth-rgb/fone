CREATE DATABASE IF NOT EXISTS foneflex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE foneflex;

CREATE TABLE plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_rate DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  max_users INT NOT NULL DEFAULT 5,
  max_branches INT NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE organizations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  business_email VARCHAR(190) NOT NULL,
  business_phone VARCHAR(30) NOT NULL,
  kra_pin VARCHAR(30) NULL,
  registration_number VARCHAR(80) NULL,
  address VARCHAR(255) NULL,
  status ENUM('trial','active','past_due','suspended','closed') NOT NULL DEFAULT 'trial',
  plan_id BIGINT UNSIGNED NULL,
  commission_rate DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  trial_ends_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_org_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('platform_admin','owner','admin','sales','collections','cashier','accountant','auditor') NOT NULL DEFAULT 'owner',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  INDEX idx_users_org (organization_id)
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  status ENUM('trial','active','past_due','cancelled','suspended') NOT NULL DEFAULT 'trial',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  monthly_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sub_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans(id),
  INDEX idx_sub_org_status (organization_id,status)
) ENGINE=InnoDB;

CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_no VARCHAR(40) NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  alternative_phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  id_type VARCHAR(30) NULL,
  id_number_encrypted VARBINARY(512) NULL,
  general_location VARCHAR(160) NULL,
  privacy_notice_version VARCHAR(40) NULL,
  privacy_notice_provided_at DATETIME NULL,
  status ENUM('active','inactive','blacklisted','deceased') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_customer_no (organization_id,customer_no),
  INDEX idx_customer_phone (organization_id,phone),
  CONSTRAINT fk_customer_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_customer_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE inventory_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  inventory_no VARCHAR(40) NOT NULL,
  manufacturer VARCHAR(80) NOT NULL,
  model VARCHAR(120) NOT NULL,
  storage_capacity VARCHAR(40) NULL,
  colour VARCHAR(50) NULL,
  imei1 VARCHAR(30) NULL,
  imei2 VARCHAR(30) NULL,
  serial_no VARCHAR(100) NULL,
  purchase_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  cash_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  supplier_name VARCHAR(160) NULL,
  warranty_until DATE NULL,
  status ENUM('in_stock','reserved','hire_purchase','cash_sold','returned','repair','written_off') NOT NULL DEFAULT 'in_stock',
  financing_eligibility ENUM('unknown','eligible','unsupported','pending_provider_review') NOT NULL DEFAULT 'unknown',
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inventory_no (organization_id,inventory_no),
  UNIQUE KEY uq_imei1 (organization_id,imei1),
  UNIQUE KEY uq_imei2 (organization_id,imei2),
  INDEX idx_inventory_status (organization_id,status),
  CONSTRAINT fk_inventory_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

CREATE TABLE agreements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  agreement_no VARCHAR(40) NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  cash_price DECIMAL(12,2) NOT NULL,
  deposit DECIMAL(12,2) NOT NULL,
  interest_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  other_charges DECIMAL(12,2) NOT NULL DEFAULT 0,
  hp_price DECIMAL(12,2) NOT NULL,
  balance_due DECIMAL(12,2) NOT NULL,
  number_of_installments INT NOT NULL,
  frequency ENUM('daily','weekly','fortnightly','monthly') NOT NULL,
  first_due_date DATE NOT NULL,
  status ENUM('active','overdue','under_review','restructured','settled','terminated','draft') NOT NULL DEFAULT 'active',
  agreement_version VARCHAR(40) NULL,
  customer_acknowledged_at DATETIME NULL,
  device_notice_acknowledged_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agreement_no (organization_id,agreement_no),
  INDEX idx_agreement_customer (organization_id,customer_id),
  INDEX idx_agreement_status (organization_id,status),
  CONSTRAINT fk_agreement_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_agreement_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_agreement_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_agreement_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE installment_schedules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  agreement_id BIGINT UNSIGNED NOT NULL,
  installment_no INT NOT NULL,
  due_date DATE NOT NULL,
  principal_due DECIMAL(12,2) NOT NULL DEFAULT 0,
  interest_due DECIMAL(12,2) NOT NULL DEFAULT 0,
  principal_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  interest_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','partial','paid','waived') NOT NULL DEFAULT 'pending',
  UNIQUE KEY uq_agreement_installment (agreement_id,installment_no),
  INDEX idx_schedule_due (organization_id,due_date,status),
  CONSTRAINT fk_schedule_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_schedule_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  agreement_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  receipt_no VARCHAR(40) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  principal_allocated DECIMAL(12,2) NOT NULL DEFAULT 0,
  interest_allocated DECIMAL(12,2) NOT NULL DEFAULT 0,
  channel ENUM('mpesa','cash','bank','other') NOT NULL,
  external_reference VARCHAR(100) NULL,
  payment_status ENUM('pending','verified','failed','reversed') NOT NULL DEFAULT 'verified',
  received_at DATETIME NOT NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipt (organization_id,receipt_no),
  UNIQUE KEY uq_payment_ref (organization_id,external_reference),
  INDEX idx_payment_agreement (organization_id,agreement_id),
  CONSTRAINT fk_payment_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_payment_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id),
  CONSTRAINT fk_payment_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_payment_user FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE platform_commissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  agreement_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  interest_collected DECIMAL(12,2) NOT NULL,
  commission_rate DECIMAL(5,2) NOT NULL,
  commission_amount DECIMAL(12,2) NOT NULL,
  status ENUM('accrued','invoiced','paid','reversed') NOT NULL DEFAULT 'accrued',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_commission_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_commission_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id),
  CONSTRAINT fk_commission_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  UNIQUE KEY uq_commission_payment (payment_id)
) ENGINE=InnoDB;

CREATE TABLE platform_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  invoice_no VARCHAR(40) NOT NULL UNIQUE,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  subscription_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  adjustments DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_due DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('draft','issued','part_paid','paid','void') NOT NULL DEFAULT 'draft',
  issued_at DATETIME NULL,
  due_at DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invoice_org FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

CREATE TABLE device_enrollments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  agreement_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  provider_code VARCHAR(80) NULL,
  eligibility_status ENUM('unknown','eligible','unsupported','pending_review') NOT NULL DEFAULT 'unknown',
  enrollment_status ENUM('not_started','pending','enrolled','failed','released') NOT NULL DEFAULT 'not_started',
  remote_state ENUM('unknown','normal','restriction_pending','restricted_confirmed','restoration_pending','restored') NOT NULL DEFAULT 'unknown',
  provider_reference VARCHAR(150) NULL,
  last_synced_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_device_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_device_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id),
  CONSTRAINT fk_device_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_org_date (organization_id,created_at),
  CONSTRAINT fk_audit_org FOREIGN KEY (organization_id) REFERENCES organizations(id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NULL,
  provider VARCHAR(50) NOT NULL,
  event_reference VARCHAR(150) NOT NULL,
  payload JSON NOT NULL,
  processing_status ENUM('received','processed','failed','ignored') NOT NULL DEFAULT 'received',
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_webhook_event (provider,event_reference)
) ENGINE=InnoDB;

INSERT INTO plans (name,monthly_fee,commission_rate,max_users,max_branches,is_active)
VALUES ('Pilot',0.00,30.00,5,1,1);
