-- ============================================================================
-- MediCare Cloud — Hospital Management SaaS
-- MySQL 8 / MariaDB 10.4+ schema: multi-tenant SaaS core + hospital modules.
-- Every business table carries tenant_id and is covered by tenant-aware
-- indexes; the PHP layer enforces tenant_id on every query.
-- Import:  mysql -u root -p < database.sql
-- ============================================================================
CREATE DATABASE IF NOT EXISTS medicare_cloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE medicare_cloud;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================ PLATFORM / TENANCY ============================
CREATE TABLE tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(80) NULL,
  country VARCHAR(80) NULL,
  logo VARCHAR(255) NULL,
  status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  scope ENUM('platform','tenant') NOT NULL DEFAULT 'tenant',
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  module VARCHAR(60) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(40) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME NULL,
  verification_token VARCHAR(100) NULL,
  remember_token VARCHAR(100) NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_tenant (tenant_id),
  INDEX idx_users_role (role_id),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE tenant_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  skey VARCHAR(80) NOT NULL,
  svalue TEXT NULL,
  UNIQUE KEY uq_tenant_setting (tenant_id, skey),
  CONSTRAINT fk_ts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE platform_settings (
  skey VARCHAR(80) PRIMARY KEY,
  svalue TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  email VARCHAR(190) NOT NULL,
  token VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pr_email (email)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_email_time (email, attempted_at)
) ENGINE=InnoDB;

-- ============================ SUBSCRIPTIONS ============================
CREATE TABLE subscription_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(60) NOT NULL UNIQUE,
  monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  yearly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  trial_days INT NOT NULL DEFAULT 14,
  max_doctors INT NOT NULL DEFAULT 2,
  max_staff INT NOT NULL DEFAULT 5,
  max_patients INT NOT NULL DEFAULT 200,
  max_departments INT NOT NULL DEFAULT 3,
  max_storage_mb INT NOT NULL DEFAULT 500,
  features TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  status ENUM('trial','active','past_due','cancelled','expired') NOT NULL DEFAULT 'trial',
  starts_at DATE NULL,
  ends_at DATE NULL,
  trial_ends_at DATE NULL,
  grace_ends_at DATE NULL,
  cancelled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sub_tenant (tenant_id),
  CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB;

CREATE TABLE subscription_usage (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  metric VARCHAR(60) NOT NULL,
  used_value INT NOT NULL DEFAULT 0,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  UNIQUE KEY uq_usage (tenant_id, metric, period_start),
  CONSTRAINT fk_usage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_uses INT NULL,
  used_count INT NOT NULL DEFAULT 0,
  valid_from DATE NULL,
  valid_until DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NULL,
  invoice_no VARCHAR(30) NOT NULL UNIQUE,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('draft','pending','paid','overdue','cancelled') NOT NULL DEFAULT 'pending',
  due_date DATE NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inv_tenant (tenant_id),
  CONSTRAINT fk_inv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- A payment is only ever 'verified' after confirmation is recorded by the
-- platform team; checkout/creation alone leaves it 'pending' (never fake-paid).
CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  subscription_id INT UNSIGNED NULL,
  invoice_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency VARCHAR(3) NOT NULL DEFAULT 'USD',
  method VARCHAR(40) NOT NULL DEFAULT 'manual',
  status ENUM('pending','verified','failed','refunded') NOT NULL DEFAULT 'pending',
  transaction_ref VARCHAR(120) NULL,
  paid_at DATETIME NULL,
  verified_by INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pay_tenant (tenant_id),
  CONSTRAINT fk_pay_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_inv FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE coupon_redemptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NULL,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cr_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================ COMMS / LOGS ============================
CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(500) NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'info',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  body TEXT NULL,
  audience ENUM('all','tenants','staff') NOT NULL DEFAULT 'all',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ann_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE support_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  subject VARCHAR(200) NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'General',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ticket_tenant (tenant_id, status),
  CONSTRAINT fk_ticket_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE support_ticket_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reply_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_reply_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_act_tenant (tenant_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(80) NULL,
  entity_id INT NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_tenant (tenant_id, created_at)
) ENGINE=InnoDB;

-- ============================ HOSPITAL MODULES ============================
CREATE TABLE departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  floor VARCHAR(30) NULL,
  phone VARCHAR(40) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dept_tenant_name (tenant_id, name),
  CONSTRAINT fk_dept_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE doctors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  specialization VARCHAR(120) NULL,
  qualification VARCHAR(160) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  consultation_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  schedule VARCHAR(190) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_doc_tenant (tenant_id),
  CONSTRAINT fk_doc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE departments
  ADD COLUMN head_doctor_id INT UNSIGNED NULL,
  ADD CONSTRAINT fk_dept_head FOREIGN KEY (head_doctor_id) REFERENCES doctors(id) ON DELETE SET NULL;

CREATE TABLE patients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  patient_code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  gender ENUM('male','female','other') NOT NULL DEFAULT 'other',
  dob DATE NULL,
  blood_group VARCHAR(5) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  address VARCHAR(255) NULL,
  emergency_contact VARCHAR(120) NULL,
  allergies TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_patient_code (tenant_id, patient_code),
  INDEX idx_pat_tenant (tenant_id),
  CONSTRAINT fk_pat_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NULL,
  appointment_date DATE NOT NULL,
  appointment_time TIME NOT NULL,
  type ENUM('OPD','Follow-up','Emergency','Teleconsult') NOT NULL DEFAULT 'OPD',
  reason VARCHAR(255) NULL,
  status ENUM('scheduled','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
  fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_appt_tenant_date (tenant_id, appointment_date),
  INDEX idx_appt_doctor (doctor_id, appointment_date),
  CONSTRAINT fk_appt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE wards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  type ENUM('General','ICU','CCU','Pediatric','Maternity','Private') NOT NULL DEFAULT 'General',
  floor VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ward_tenant (tenant_id),
  CONSTRAINT fk_ward_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE beds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  ward_id INT UNSIGNED NOT NULL,
  bed_number VARCHAR(20) NOT NULL,
  status ENUM('available','occupied','reserved','maintenance') NOT NULL DEFAULT 'available',
  current_patient_id INT UNSIGNED NULL,
  UNIQUE KEY uq_bed (ward_id, bed_number),
  INDEX idx_bed_tenant (tenant_id, status),
  CONSTRAINT fk_bed_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_bed_ward FOREIGN KEY (ward_id) REFERENCES wards(id) ON DELETE CASCADE,
  CONSTRAINT fk_bed_patient FOREIGN KEY (current_patient_id) REFERENCES patients(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE admissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  ward_id INT UNSIGNED NOT NULL,
  bed_id INT UNSIGNED NOT NULL,
  admission_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  discharge_date DATETIME NULL,
  reason VARCHAR(255) NULL,
  status ENUM('admitted','discharged','transferred') NOT NULL DEFAULT 'admitted',
  notes TEXT NULL,
  INDEX idx_adm_tenant (tenant_id, status),
  CONSTRAINT fk_adm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_adm_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_adm_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
  CONSTRAINT fk_adm_ward FOREIGN KEY (ward_id) REFERENCES wards(id) ON DELETE CASCADE,
  CONSTRAINT fk_adm_bed FOREIGN KEY (bed_id) REFERENCES beds(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE medical_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  appointment_id INT UNSIGNED NULL,
  record_type ENUM('diagnosis','observation','document','note') NOT NULL DEFAULT 'note',
  title VARCHAR(200) NOT NULL,
  details TEXT NULL,
  file_url VARCHAR(255) NULL,
  visibility ENUM('staff','patient') NOT NULL DEFAULT 'staff',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rec_patient (tenant_id, patient_id),
  CONSTRAINT fk_rec_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
  CONSTRAINT fk_rec_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE medicines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  generic_name VARCHAR(120) NULL,
  category VARCHAR(80) NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'tablet',
  stock INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 10,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  expiry_date DATE NULL,
  INDEX idx_med_tenant (tenant_id),
  CONSTRAINT fk_med_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE prescriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NOT NULL,
  appointment_id INT UNSIGNED NULL,
  prescription_date DATE NOT NULL,
  diagnosis VARCHAR(255) NULL,
  notes TEXT NULL,
  status ENUM('pending','dispensed','partially_dispensed') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rx_tenant (tenant_id, status),
  CONSTRAINT fk_rx_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_rx_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_rx_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
  CONSTRAINT fk_rx_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE prescription_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prescription_id INT UNSIGNED NOT NULL,
  medicine_id INT UNSIGNED NULL,
  medicine_name VARCHAR(120) NOT NULL,
  dosage VARCHAR(60) NULL,
  frequency VARCHAR(60) NULL,
  duration VARCHAR(60) NULL,
  quantity INT NOT NULL DEFAULT 1,
  instructions VARCHAR(255) NULL,
  CONSTRAINT fk_rxi_rx FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
  CONSTRAINT fk_rxi_med FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE lab_tests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(80) NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  normal_range VARCHAR(80) NULL,
  unit VARCHAR(30) NULL,
  description VARCHAR(255) NULL,
  INDEX idx_lt_tenant (tenant_id),
  CONSTRAINT fk_lt_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE lab_results (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NULL,
  lab_test_id INT UNSIGNED NOT NULL,
  technician_id INT UNSIGNED NULL,
  result_value VARCHAR(120) NULL,
  status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  remarks VARCHAR(255) NULL,
  ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  INDEX idx_lr_tenant (tenant_id, status),
  CONSTRAINT fk_lr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
  CONSTRAINT fk_lr_test FOREIGN KEY (lab_test_id) REFERENCES lab_tests(id) ON DELETE CASCADE,
  CONSTRAINT fk_lr_tech FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Hospital billing (separate from platform subscription invoices above).
CREATE TABLE patient_invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NOT NULL,
  invoice_no VARCHAR(30) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
  due_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pinv_no (tenant_id, invoice_no),
  INDEX idx_pinv_patient (tenant_id, patient_id),
  CONSTRAINT fk_pinv_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pinv_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE patient_invoice_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  description VARCHAR(200) NOT NULL,
  item_type ENUM('consultation','lab','pharmacy','bed','procedure','other') NOT NULL DEFAULT 'other',
  ref_id INT UNSIGNED NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_pii_invoice FOREIGN KEY (invoice_id) REFERENCES patient_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE patient_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  invoice_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  method VARCHAR(40) NOT NULL DEFAULT 'cash',
  received_by INT UNSIGNED NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  INDEX idx_ppay_tenant (tenant_id, paid_at),
  CONSTRAINT fk_ppay_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ppay_invoice FOREIGN KEY (invoice_id) REFERENCES patient_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_ppay_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ppay_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =================== UPGRADE V2 TABLES (migration 001) ===================
CREATE TABLE IF NOT EXISTS patient_documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  file_name VARCHAR(190) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_type VARCHAR(60) NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  visibility ENUM('staff','patient') NOT NULL DEFAULT 'staff',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pdoc_patient (tenant_id, patient_id),
  CONSTRAINT fk_pdoc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_pdoc_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_pdoc_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'Operations',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  expense_date DATE NOT NULL,
  vendor VARCHAR(120) NULL,
  payment_method VARCHAR(40) NOT NULL DEFAULT 'card',
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exp_tenant_date (tenant_id, expense_date),
  CONSTRAINT fk_exp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_exp_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS emergency_cases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NULL,
  patient_name VARCHAR(120) NOT NULL,
  doctor_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  triage_level ENUM('critical','urgent','non_urgent') NOT NULL DEFAULT 'urgent',
  complaint VARCHAR(255) NULL,
  arrival_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('waiting','in_treatment','admitted','discharged','referred') NOT NULL DEFAULT 'waiting',
  disposition VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  INDEX idx_emg_tenant (tenant_id, arrival_at),
  INDEX idx_emg_status (tenant_id, status),
  CONSTRAINT fk_emg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_emg_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
  CONSTRAINT fk_emg_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
  CONSTRAINT fk_emg_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_emg_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reminders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  patient_id INT UNSIGNED NULL,
  appointment_id INT UNSIGNED NULL,
  type ENUM('appointment','follow_up','payment','custom') NOT NULL DEFAULT 'appointment',
  title VARCHAR(200) NOT NULL,
  remind_at DATETIME NOT NULL,
  channel ENUM('sms','email','call','in_app') NOT NULL DEFAULT 'in_app',
  status ENUM('pending','sent','done','cancelled') NOT NULL DEFAULT 'pending',
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rem_tenant (tenant_id, remind_at, status),
  CONSTRAINT fk_rem_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_rem_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE SET NULL,
  CONSTRAINT fk_rem_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
  CONSTRAINT fk_rem_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS doctor_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  doctor_id INT UNSIGNED NOT NULL,
  day_of_week TINYINT NOT NULL,
  start_time TIME NOT NULL DEFAULT '09:00:00',
  end_time TIME NOT NULL DEFAULT '17:00:00',
  slot_minutes INT NOT NULL DEFAULT 30,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_doc_schedule (doctor_id, day_of_week),
  INDEX idx_sched_tenant (tenant_id, doctor_id),
  CONSTRAINT fk_sched_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================ SEED: ROLES & PERMISSIONS ============================
INSERT INTO roles (id, slug, name, scope, description) VALUES
(1,'super_admin','Super Admin','platform','Platform owner — manages tenants, plans, billing'),
(2,'hospital_admin','Hospital Admin','tenant','Full control of one hospital tenant'),
(3,'doctor','Doctor','tenant','Consults patients, prescribes, orders labs'),
(4,'receptionist','Receptionist','tenant','Front desk — registrations, appointments, admissions'),
(5,'nurse','Nurse','tenant','Ward care, beds, patient observations'),
(6,'accountant','Accountant','tenant','Billing, invoices and payments'),
(7,'pharmacist','Pharmacist','tenant','Medicine stock and prescription dispensing'),
(8,'lab_technician','Lab Technician','tenant','Laboratory tests and results'),
(9,'patient','Patient','tenant','Patient portal — own appointments, bills, records');

INSERT INTO permissions (slug, name, module) VALUES
('dashboard.view','View dashboard','dashboard'),
('patients.view','View patients','patients'),('patients.manage','Manage patients','patients'),
('doctors.view','View doctors','doctors'),('doctors.manage','Manage doctors','doctors'),
('appointments.view','View appointments','appointments'),('appointments.manage','Manage appointments','appointments'),
('departments.view','View departments','departments'),('departments.manage','Manage departments','departments'),
('staff.view','View staff','staff'),('staff.manage','Manage staff','staff'),
('wards.view','View wards & beds','wards'),('wards.manage','Manage wards & beds','wards'),
('admissions.view','View admissions','admissions'),('admissions.manage','Manage admissions','admissions'),
('pharmacy.view','View pharmacy','pharmacy'),('pharmacy.manage','Manage pharmacy','pharmacy'),
('lab.view','View laboratory','lab'),('lab.manage','Manage laboratory','lab'),
('records.view','View medical records','records'),('records.manage','Manage medical records','records'),
('billing.view','View billing','billing'),('billing.manage','Manage billing','billing'),
('payments.view','View payments','payments'),('payments.manage','Record payments','payments'),
('reports.view','View reports','reports'),
('subscription.view','View subscription','subscription'),('subscription.manage','Manage subscription','subscription'),
('tickets.view','View support tickets','tickets'),('tickets.manage','Manage support tickets','tickets'),
('settings.view','View settings','settings'),('settings.manage','Manage settings','settings'),
('portal.view','Access patient portal','portal');

-- Super admin + hospital admin: everything
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug IN ('super_admin','hospital_admin');
-- Doctor
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('dashboard.view','patients.view','doctors.view','appointments.view','appointments.manage','departments.view','pharmacy.view','lab.view','records.view','records.manage','reports.view','tickets.view','tickets.manage') WHERE r.slug='doctor';
-- Receptionist
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('dashboard.view','patients.view','patients.manage','doctors.view','appointments.view','appointments.manage','departments.view','wards.view','admissions.view','admissions.manage','billing.view','billing.manage','payments.view','tickets.view','tickets.manage') WHERE r.slug='receptionist';
-- Nurse
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('dashboard.view','patients.view','appointments.view','wards.view','wards.manage','admissions.view','records.view','departments.view') WHERE r.slug='nurse';
-- Accountant
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('dashboard.view','billing.view','billing.manage','payments.view','payments.manage','reports.view','subscription.view','patients.view','tickets.view','tickets.manage') WHERE r.slug='accountant';
-- Pharmacist
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('dashboard.view','pharmacy.view','pharmacy.manage','patients.view','reports.view') WHERE r.slug='pharmacist';
-- Lab technician
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('dashboard.view','lab.view','lab.manage','patients.view','reports.view') WHERE r.slug='lab_technician';
-- Patient
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('portal.view') WHERE r.slug='patient';

-- ============================ SEED: PLANS ============================
INSERT INTO subscription_plans (id, name, slug, monthly_price, yearly_price, trial_days, max_doctors, max_staff, max_patients, max_departments, max_storage_mb, features, sort_order) VALUES
(1,'Free','free',0,0,14,2,5,200,3,500,'["Up to 2 doctors","Patient registration","Appointment booking","Email support"]',1),
(2,'Starter','starter',49,470,14,10,25,2000,8,2048,'["Everything in Free","Bed management","Laboratory module","Billing & invoices"]',2),
(3,'Professional','professional',99,950,14,30,75,10000,15,10240,'["Everything in Starter","Pharmacy & prescriptions","Patient portal","Reports & analytics","Priority support"]',3),
(4,'Business','business',199,1910,14,80,200,50000,30,51200,'["Everything in Professional","Multi-branch ready","API access","Audit logs","Phone support"]',4),
(5,'Enterprise','enterprise',399,3830,30,-1,-1,-1,-1,512000,'["Everything in Business","Unlimited doctors, staff & patients","Dedicated account manager","Custom integrations","99.9% uptime SLA"]',5);

INSERT INTO coupons (code, description, discount_type, discount_value, max_uses, valid_until, is_active) VALUES
('WELCOME10','Welcome offer — 10% off your first subscription payment','percent',10.00,NULL,DATE_ADD(CURDATE(), INTERVAL 1 YEAR),1),
('HOSPITAL20','Hospital launch week — 20% off annual plans','percent',20.00,100,DATE_ADD(CURDATE(), INTERVAL 3 MONTH),1),
('FLAT25','Flat $25 off any paid plan','fixed',25.00,50,DATE_ADD(CURDATE(), INTERVAL 6 MONTH),1);

INSERT INTO platform_settings (skey, svalue) VALUES
('platform_name','MediCare Cloud'),('support_email','support@medicare.test'),
('default_trial_days','14'),('grace_days','7'),('currency','USD'),('maintenance_mode','0');

-- ============================ SEED: SUPER ADMIN & DEMO TENANT ============================
-- All demo passwords are the bcrypt hash of: password
INSERT INTO users (id, tenant_id, name, email, phone, password_hash, role_id, status, email_verified_at) VALUES
(1, NULL, 'Platform Owner', 'super@medicare.test', '+1 555 000 1111', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'active', NOW());

INSERT INTO tenants (id, name, slug, email, phone, address, city, country, status) VALUES
(1,'City Care Hospital','city-care-hospital','info@citycare.example','+1 555 010 2233','120 Wellness Avenue','Springfield','USA','active'),
(2,'Riverside Medical Center','riverside-medical','hello@riverside.example','+1 555 014 7788','8 River Road','Riverton','USA','active'),
(3,'Sunrise Children Clinic','sunrise-children','care@sunriseclinic.example','+1 555 019 3344','45 Palm Street','Sunnyvale','USA','active');

INSERT INTO tenant_settings (tenant_id, skey, svalue) VALUES
(1,'hospital_name','City Care Hospital'),(1,'tagline','Compassionate care, powered by the cloud'),
(1,'currency','USD'),(1,'appointment_slot_minutes','30'),(1,'invoice_tax_percent','0'),
(2,'hospital_name','Riverside Medical Center'),(3,'hospital_name','Sunrise Children Clinic');

INSERT INTO subscriptions (tenant_id, plan_id, billing_cycle, status, starts_at, ends_at, trial_ends_at) VALUES
(1, 3, 'monthly', 'active', DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 20 DAY), NULL),
(2, 2, 'yearly', 'active', DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 305 DAY), NULL),
(3, 1, 'monthly', 'trial', CURDATE(), NULL, DATE_ADD(CURDATE(), INTERVAL 14 DAY));

INSERT INTO invoices (tenant_id, subscription_id, invoice_no, amount, discount, total, status, due_date, paid_at) VALUES
(1, 1, 'INV-2026-0001', 99.00, 9.90, 89.10, 'paid', DATE_SUB(CURDATE(), INTERVAL 9 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY)),
(2, 2, 'INV-2026-0002', 470.00, 0, 470.00, 'paid', DATE_SUB(CURDATE(), INTERVAL 59 DAY), DATE_SUB(NOW(), INTERVAL 59 DAY)),
(1, 1, 'INV-2026-0003', 99.00, 0, 99.00, 'pending', DATE_ADD(CURDATE(), INTERVAL 20 DAY), NULL);

INSERT INTO payments (tenant_id, subscription_id, invoice_id, amount, method, status, transaction_ref, paid_at, verified_by, notes) VALUES
(1, 1, 1, 89.10, 'card', 'verified', 'TXN-DEMO-1001', DATE_SUB(NOW(), INTERVAL 9 DAY), 1, 'Verified against gateway settlement report'),
(2, 2, 2, 470.00, 'bank_transfer', 'verified', 'TXN-DEMO-1002', DATE_SUB(NOW(), INTERVAL 59 DAY), 1, 'Verified against bank statement');

INSERT INTO coupon_redemptions (coupon_id, tenant_id, invoice_id, discount_amount)
SELECT id, 1, 1, 9.90 FROM coupons WHERE code='WELCOME10';
UPDATE coupons SET used_count = 1 WHERE code='WELCOME10';

INSERT INTO users (id, tenant_id, name, email, phone, password_hash, role_id, status, email_verified_at) VALUES
(2, 1, 'Ayesha Rahman', 'admin@demo.com', '+1 555 010 9001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 'active', NOW()),
(3, 1, 'Dr. Sarah Khan', 'doctor@demo.com', '+1 555 010 9002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3, 'active', NOW()),
(4, 1, 'Maria Gomez', 'reception@demo.com', '+1 555 010 9003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 4, 'active', NOW()),
(5, 1, 'Emily Chen', 'nurse@demo.com', '+1 555 010 9004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 5, 'active', NOW()),
(6, 1, 'David Osei', 'accounts@demo.com', '+1 555 010 9005', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 6, 'active', NOW()),
(7, 1, 'Priya Nair', 'pharmacy@demo.com', '+1 555 010 9006', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 7, 'active', NOW()),
(8, 1, 'Tom Becker', 'lab@demo.com', '+1 555 010 9007', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 8, 'active', NOW()),
(9, 1, 'John Miller', 'patient@demo.com', '+1 555 010 9008', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 9, 'active', NOW());

-- ============================ SEED: HOSPITAL DATA (tenant 1) ============================
INSERT INTO departments (id, tenant_id, name, description, floor, phone) VALUES
(1,1,'Cardiology','Heart and cardiovascular care','2nd Floor','ext. 201'),
(2,1,'Neurology','Brain, spine and nervous system','3rd Floor','ext. 301'),
(3,1,'Pediatrics','Child and adolescent healthcare','1st Floor','ext. 101'),
(4,1,'Emergency','24/7 emergency and trauma care','Ground Floor','ext. 911'),
(5,1,'Orthopedics','Bones, joints and sports injuries','2nd Floor','ext. 210'),
(6,1,'General Surgery','Surgical procedures and recovery','4th Floor','ext. 401');

INSERT INTO doctors (id, tenant_id, user_id, department_id, name, specialization, qualification, phone, email, consultation_fee, schedule, status) VALUES
(1,1,3,1,'Dr. Sarah Khan','Interventional Cardiology','MBBS, MD Cardiology','+1 555 010 9002','doctor@demo.com',80.00,'Mon–Fri · 9:00 AM – 2:00 PM','active'),
(2,1,NULL,2,'Dr. James Patel','Neurology','MBBS, DM Neurology','+1 555 011 2201','j.patel@citycare.example',90.00,'Mon, Wed, Fri · 10:00 AM – 4:00 PM','active'),
(3,1,NULL,3,'Dr. Laura Kim','Pediatrics','MBBS, MD Pediatrics','+1 555 011 2202','l.kim@citycare.example',60.00,'Mon–Sat · 9:00 AM – 1:00 PM','active'),
(4,1,NULL,4,'Dr. Omar Farooq','Emergency Medicine','MBBS, FCPS','+1 555 011 2203','o.farooq@citycare.example',100.00,'24/7 on-call rotation','active'),
(5,1,NULL,5,'Dr. Nina Rodriguez','Orthopedic Surgery','MBBS, MS Orthopedics','+1 555 011 2204','n.rodriguez@citycare.example',85.00,'Tue, Thu, Sat · 11:00 AM – 5:00 PM','active'),
(6,1,NULL,6,'Dr. Peter Novak','General Surgery','MBBS, MS Surgery','+1 555 011 2205','p.novak@citycare.example',95.00,'Mon–Thu · 8:00 AM – 12:00 PM','active');

UPDATE departments SET head_doctor_id = 1 WHERE id = 1;
UPDATE departments SET head_doctor_id = 2 WHERE id = 2;
UPDATE departments SET head_doctor_id = 3 WHERE id = 3;

INSERT INTO patients (id, tenant_id, user_id, patient_code, name, gender, dob, blood_group, phone, email, address, emergency_contact, allergies) VALUES
(1,1,9,'PAT-0001','John Miller','male','1988-04-12','O+','+1 555 010 9008','patient@demo.com','22 Oak Lane, Springfield','Anna Miller +1 555 010 9010','Penicillin'),
(2,1,NULL,'PAT-0002','Fatima Noor','female','1995-09-03','A+','+1 555 012 3301','fatima.noor@example.com','9 Rose Street, Springfield','Bilal Noor +1 555 012 3302',NULL),
(3,1,NULL,'PAT-0003','Carlos Diaz','male','1979-01-27','B-','+1 555 012 3303','carlos.diaz@example.com','310 Pine Ave, Springfield','Lucia Diaz +1 555 012 3304','Sulfa drugs'),
(4,1,NULL,'PAT-0004','Grace Adeyemi','female','2016-06-15','AB+','+1 555 012 3305','grace.a@example.com','17 Cedar Court, Springfield','Mother +1 555 012 3306',NULL),
(5,1,NULL,'PAT-0005','Henry Wilson','male','1962-11-30','O-','+1 555 012 3307','henry.w@example.com','5 Birch Road, Springfield','Son +1 555 012 3308','Latex'),
(6,1,NULL,'PAT-0006','Mei Ling','female','2001-02-19','A-','+1 555 012 3309','mei.ling@example.com','88 Willow Way, Springfield','Wei Ling +1 555 012 3310',NULL),
(7,1,NULL,'PAT-0007','Ahmed Hassan','male','1990-07-08','B+','+1 555 012 3311','ahmed.h@example.com','41 Elm Drive, Springfield','Sara Hassan +1 555 012 3312',NULL),
(8,1,NULL,'PAT-0008','Sofia Rossi','female','1985-12-01','O+','+1 555 012 3313','sofia.r@example.com','3 Maple Square, Springfield','Marco Rossi +1 555 012 3314','Peanuts');

INSERT INTO wards (id, tenant_id, name, type, floor) VALUES
(1,1,'General Ward A','General','1st Floor'),
(2,1,'ICU','ICU','3rd Floor'),
(3,1,'Private Suites','Private','5th Floor');

INSERT INTO beds (tenant_id, ward_id, bed_number, status, current_patient_id) VALUES
(1,1,'A-01','occupied',3),(1,1,'A-02','occupied',5),(1,1,'A-03','available',NULL),(1,1,'A-04','available',NULL),
(1,2,'ICU-01','occupied',7),(1,2,'ICU-02','available',NULL),(1,2,'ICU-03','reserved',NULL),(1,2,'ICU-04','maintenance',NULL),
(1,3,'P-01','available',NULL),(1,3,'P-02','occupied',2),(1,3,'P-03','available',NULL),(1,3,'P-04','available',NULL);

INSERT INTO admissions (tenant_id, patient_id, doctor_id, ward_id, bed_id, admission_date, reason, status)
SELECT 1, 3, 6, 1, b.id, DATE_SUB(NOW(), INTERVAL 2 DAY), 'Appendectomy recovery', 'admitted' FROM beds b WHERE b.ward_id=1 AND b.bed_number='A-01';
INSERT INTO admissions (tenant_id, patient_id, doctor_id, ward_id, bed_id, admission_date, reason, status)
SELECT 1, 5, 1, 1, b.id, DATE_SUB(NOW(), INTERVAL 4 DAY), 'Cardiac observation after chest pain', 'admitted' FROM beds b WHERE b.ward_id=1 AND b.bed_number='A-02';
INSERT INTO admissions (tenant_id, patient_id, doctor_id, ward_id, bed_id, admission_date, reason, status)
SELECT 1, 7, 4, 2, b.id, DATE_SUB(NOW(), INTERVAL 1 DAY), 'Post-accident monitoring', 'admitted' FROM beds b WHERE b.ward_id=2 AND b.bed_number='ICU-01';
INSERT INTO admissions (tenant_id, patient_id, doctor_id, ward_id, bed_id, admission_date, discharge_date, reason, status)
SELECT 1, 2, 3, 3, b.id, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY), 'Dehydration treatment', 'discharged' FROM beds b WHERE b.ward_id=3 AND b.bed_number='P-02';

INSERT INTO appointments (tenant_id, patient_id, doctor_id, department_id, appointment_date, appointment_time, type, reason, status, fee, created_by) VALUES
(1,1,1,1,CURDATE(),'09:30:00','OPD','Follow-up for blood pressure management','confirmed',80.00,4),
(1,2,3,3,CURDATE(),'10:00:00','OPD','Child vaccination and check-up','scheduled',60.00,4),
(1,6,2,2,CURDATE(),'11:00:00','OPD','Recurring migraines — first consult','scheduled',90.00,4),
(1,8,1,1,CURDATE(),'12:30:00','Follow-up','ECG review','completed',80.00,4),
(1,4,3,3,DATE_ADD(CURDATE(), INTERVAL 1 DAY),'09:00:00','OPD','Fever follow-up','scheduled',60.00,4),
(1,7,5,5,DATE_ADD(CURDATE(), INTERVAL 1 DAY),'11:30:00','OPD','Knee pain assessment','confirmed',85.00,4),
(1,3,6,6,DATE_SUB(CURDATE(), INTERVAL 1 DAY),'08:30:00','Follow-up','Post-surgery wound review','completed',95.00,4),
(1,5,1,1,DATE_SUB(CURDATE(), INTERVAL 2 DAY),'10:30:00','Emergency','Chest pain — admitted for observation','completed',100.00,4);

INSERT INTO medicines (tenant_id, name, generic_name, category, unit, stock, reorder_level, price, expiry_date) VALUES
(1,'Paracetamol 500mg','Acetaminophen','Analgesic','tablet',480,50,0.20,DATE_ADD(CURDATE(), INTERVAL 14 MONTH)),
(1,'Amoxicillin 250mg','Amoxicillin','Antibiotic','capsule',220,40,0.55,DATE_ADD(CURDATE(), INTERVAL 9 MONTH)),
(1,'Atorvastatin 20mg','Atorvastatin','Cardiology','tablet',310,30,0.80,DATE_ADD(CURDATE(), INTERVAL 18 MONTH)),
(1,'Metformin 850mg','Metformin','Antidiabetic','tablet',540,60,0.35,DATE_ADD(CURDATE(), INTERVAL 12 MONTH)),
(1,'Salbutamol Inhaler','Salbutamol','Respiratory','inhaler',45,20,6.50,DATE_ADD(CURDATE(), INTERVAL 7 MONTH)),
(1,'Omeprazole 20mg','Omeprazole','Antacid','capsule',390,40,0.45,DATE_ADD(CURDATE(), INTERVAL 15 MONTH)),
(1,'Insulin Glargine','Insulin','Antidiabetic','vial',18,15,24.00,DATE_ADD(CURDATE(), INTERVAL 5 MONTH)),
(1,'Cetirizine 10mg','Cetirizine','Antihistamine','tablet',600,50,0.15,DATE_ADD(CURDATE(), INTERVAL 20 MONTH));

INSERT INTO prescriptions (id, tenant_id, patient_id, doctor_id, prescription_date, diagnosis, notes, status) VALUES
(1,1,1,1,CURDATE(),'Hypertension — controlled','Continue current dosage, review in 4 weeks','pending'),
(2,1,8,1,DATE_SUB(CURDATE(), INTERVAL 1 DAY),'Hyperlipidemia','Take at night','dispensed'),
(3,1,6,2,CURDATE(),'Migraine prophylaxis','Avoid screen strain, hydrate','pending');

INSERT INTO prescription_items (prescription_id, medicine_id, medicine_name, dosage, frequency, duration, quantity, instructions)
SELECT 1, id, name, '20mg', 'Once daily', '30 days', 30, 'After dinner' FROM medicines WHERE name='Atorvastatin 20mg';
INSERT INTO prescription_items (prescription_id, medicine_id, medicine_name, dosage, frequency, duration, quantity, instructions)
SELECT 2, id, name, '500mg', 'Twice daily', '14 days', 28, 'After meals' FROM medicines WHERE name='Paracetamol 500mg';
INSERT INTO prescription_items (prescription_id, medicine_id, medicine_name, dosage, frequency, duration, quantity, instructions)
SELECT 3, id, name, '10mg', 'Once daily at bedtime', '10 days', 10, 'May cause drowsiness' FROM medicines WHERE name='Cetirizine 10mg';

INSERT INTO lab_tests (tenant_id, name, category, price, normal_range, unit, description) VALUES
(1,'Complete Blood Count (CBC)','Hematology',25.00,'—','—','Full blood panel including WBC, RBC, hemoglobin'),
(1,'Lipid Profile','Biochemistry',40.00,'< 200','mg/dL','Cholesterol, HDL, LDL, triglycerides'),
(1,'HbA1c (Glycated Hemoglobin)','Biochemistry',30.00,'< 5.7','%','3-month average blood glucose'),
(1,'ECG','Cardiology',35.00,'—','—','12-lead electrocardiogram'),
(1,'Chest X-Ray','Radiology',55.00,'—','—','PA view chest radiograph'),
(1,'Liver Function Test (LFT)','Biochemistry',38.00,'—','—','ALT, AST, bilirubin, albumin'),
(1,'Thyroid Panel (T3/T4/TSH)','Endocrinology',45.00,'0.4 – 4.2','mIU/L','Complete thyroid function');

INSERT INTO lab_results (tenant_id, patient_id, doctor_id, lab_test_id, technician_id, result_value, status, remarks, ordered_at, completed_at)
SELECT 1, 1, 1, t.id, 8, '186 mg/dL', 'completed', 'Within acceptable range', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 20 HOUR) FROM lab_tests t WHERE t.name='Lipid Profile';
INSERT INTO lab_results (tenant_id, patient_id, doctor_id, lab_test_id, technician_id, result_value, status, remarks, ordered_at, completed_at)
SELECT 1, 1, 1, t.id, 8, '5.9 %', 'completed', 'Slightly above target — diet review advised', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 19 HOUR) FROM lab_tests t WHERE t.name LIKE 'HbA1c%';
INSERT INTO lab_results (tenant_id, patient_id, doctor_id, lab_test_id, technician_id, result_value, status, remarks, ordered_at)
SELECT 1, 6, 2, t.id, NULL, NULL, 'pending', 'Fasting sample required', NOW() FROM lab_tests t WHERE t.name='Complete Blood Count (CBC)';
INSERT INTO lab_results (tenant_id, patient_id, doctor_id, lab_test_id, technician_id, result_value, status, remarks, ordered_at)
SELECT 1, 5, 1, t.id, 8, NULL, 'in_progress', 'Tracing being reviewed by cardiologist', DATE_SUB(NOW(), INTERVAL 3 HOUR) FROM lab_tests t WHERE t.name='ECG';
INSERT INTO lab_results (tenant_id, patient_id, doctor_id, lab_test_id, technician_id, result_value, status, remarks, ordered_at, completed_at)
SELECT 1, 8, 1, t.id, 8, 'Normal sinus rhythm', 'completed', 'No abnormalities detected', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY) FROM lab_tests t WHERE t.name='ECG';

INSERT INTO medical_records (tenant_id, patient_id, doctor_id, record_type, title, details, visibility) VALUES
(1,1,1,'diagnosis','Essential hypertension','Diagnosed 2024. Managed with Atorvastatin and lifestyle plan. BP stable at last three visits.','patient'),
(1,1,1,'document','Lipid profile report (summary)','Total cholesterol 186 mg/dL, LDL 104 mg/dL. Full lab report available in Laboratory module.','patient'),
(1,5,1,'observation','Cardiac observation — day 4','Patient stable, pain-free for 24h. Continue monitoring, echo scheduled.','staff'),
(1,3,6,'note','Post-operative note — appendectomy','Procedure uneventful. Patient recovering in General Ward A, bed A-01.','staff'),
(1,7,4,'observation','ICU monitoring note','Vitals stable overnight. Conscious and oriented.','staff');

INSERT INTO patient_invoices (id, tenant_id, patient_id, invoice_no, subtotal, discount, tax, total, amount_paid, status, due_date) VALUES
(1,1,1,'BILL-0001',115.00,0,0,115.00,115.00,'paid',CURDATE()),
(2,1,3,'BILL-0002',1250.00,0,0,1250.00,500.00,'partial',DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
(3,1,5,'BILL-0003',860.00,0,0,860.00,0,'unpaid',DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(4,1,8,'BILL-0004',115.00,0,0,115.00,115.00,'paid',DATE_SUB(CURDATE(), INTERVAL 1 DAY));

INSERT INTO patient_invoice_items (invoice_id, description, item_type, quantity, unit_price, total) VALUES
(1,'Consultation — Dr. Sarah Khan','consultation',1,80.00,80.00),
(1,'ECG','lab',1,35.00,35.00),
(2,'Appendectomy procedure','procedure',1,950.00,950.00),
(2,'General ward bed — 2 days','bed',2,120.00,240.00),
(2,'Pharmacy charges','pharmacy',1,60.00,60.00),
(3,'Cardiology consultation','consultation',1,100.00,100.00),
(3,'ICU / observation bed — 4 days','bed',4,190.00,760.00),
(4,'Consultation — Dr. Sarah Khan','consultation',1,80.00,80.00),
(4,'Lipid profile','lab',1,35.00,35.00);

INSERT INTO patient_payments (tenant_id, invoice_id, patient_id, amount, method, received_by) VALUES
(1,1,1,115.00,'card',6),(1,2,3,500.00,'cash',6),(1,4,8,115.00,'card',6);

INSERT INTO notifications (tenant_id, user_id, title, body, type) VALUES
(1,2,'3 appointments today need confirmation','Reception has 3 scheduled appointments awaiting confirmation.','appointment'),
(1,2,'ICU bed ICU-04 under maintenance','Facilities flagged bed ICU-04 for maintenance until further notice.','bed'),
(1,2,'Insulin Glargine stock is low','Only 18 vials left (reorder level 15). Consider a purchase order.','stock');

INSERT INTO announcements (title, body, audience, is_active, created_by) VALUES
('MediCare Cloud — November update','New: patient portal document sharing and bed-board maintenance states are now live for all tenants.','all',1,1);

INSERT INTO support_tickets (id, tenant_id, user_id, subject, category, priority, status) VALUES
(1,1,2,'Need higher patient limit for flu season','Billing','normal','open');
INSERT INTO support_ticket_replies (ticket_id, user_id, message) VALUES
(1,2,'Hello team — our patient registrations spike every winter. Can we temporarily raise the patient limit on the Professional plan, or should we upgrade to Business?');

-- ===================== UPGRADE V2 DEMO SEEDS (tenant 1) =====================
INSERT IGNORE INTO doctor_schedules (tenant_id, doctor_id, day_of_week, start_time, end_time, slot_minutes) VALUES
(1,1,1,'09:00:00','14:00:00',30),(1,1,2,'09:00:00','14:00:00',30),(1,1,3,'09:00:00','14:00:00',30),(1,1,4,'09:00:00','14:00:00',30),(1,1,5,'09:00:00','14:00:00',30),
(1,2,1,'10:00:00','16:00:00',30),(1,2,3,'10:00:00','16:00:00',30),(1,2,5,'10:00:00','16:00:00',30),
(1,3,1,'09:00:00','13:00:00',30),(1,3,2,'09:00:00','13:00:00',30),(1,3,3,'09:00:00','13:00:00',30),(1,3,4,'09:00:00','13:00:00',30),(1,3,5,'09:00:00','13:00:00',30),(1,3,6,'09:00:00','13:00:00',30),
(1,4,0,'08:00:00','20:00:00',60),(1,4,1,'08:00:00','20:00:00',60),(1,4,2,'08:00:00','20:00:00',60),(1,4,3,'08:00:00','20:00:00',60),(1,4,4,'08:00:00','20:00:00',60),(1,4,5,'08:00:00','20:00:00',60),(1,4,6,'08:00:00','20:00:00',60),
(1,5,2,'11:00:00','17:00:00',30),(1,5,4,'11:00:00','17:00:00',30),(1,5,6,'11:00:00','17:00:00',30),
(1,6,1,'08:00:00','12:00:00',30),(1,6,2,'08:00:00','12:00:00',30),(1,6,3,'08:00:00','12:00:00',30),(1,6,4,'08:00:00','12:00:00',30);

INSERT INTO expenses (tenant_id, title, category, amount, expense_date, vendor, payment_method, notes, created_by) VALUES
(1,'Medical supplies restock','Supplies',1240.00,DATE_SUB(CURDATE(), INTERVAL 3 DAY),'MediSupply Co','card','Surgical gloves, IV fluids, bandages',6),
(1,'Laboratory reagents','Laboratory',620.00,DATE_SUB(CURDATE(), INTERVAL 1 DAY),'LabCore Ltd','bank_transfer','CBC + biochemistry reagent kits',6),
(1,'Ambulance fuel & maintenance','Operations',380.50,DATE_SUB(CURDATE(), INTERVAL 5 DAY),'City Motors','card','Unit 2 — service + fuel',6),
(1,'Staff training workshop','HR',450.00,DATE_SUB(CURDATE(), INTERVAL 10 DAY),'CareSkills Academy','card','Emergency response refresher for nursing staff',6);

INSERT INTO emergency_cases (tenant_id, patient_id, patient_name, doctor_id, department_id, triage_level, complaint, arrival_at, status, disposition, created_by) VALUES
(1,7,'Ahmed Hassan',4,4,'critical','Road traffic accident — head injury, conscious',DATE_SUB(NOW(), INTERVAL 1 DAY),'admitted','Admitted to ICU bed ICU-01 for monitoring',4),
(1,NULL,'Walk-in — Male, ~40',4,4,'urgent','Severe abdominal pain, suspected appendicitis',DATE_SUB(NOW(), INTERVAL 2 HOUR),'in_treatment','CT scan ordered; surgical review pending',4),
(1,8,'Sofia Rossi',4,4,'non_urgent','Minor laceration — left forearm',DATE_SUB(NOW(), INTERVAL 5 HOUR),'discharged','Wound cleaned and sutured (4 stitches); follow-up in 7 days',4);

INSERT INTO reminders (tenant_id, patient_id, appointment_id, type, title, remind_at, channel, status, created_by) VALUES
(1,1,1,'appointment','Call John Miller to confirm today''s 9:30 AM appointment',DATE_SUB(NOW(), INTERVAL 1 HOUR),'call','pending',4),
(1,2,NULL,'follow_up','Post-discharge follow-up call — Fatima Noor (discharged 3 days ago)',DATE_ADD(NOW(), INTERVAL 1 DAY),'call','pending',4),
(1,5,NULL,'payment','Send payment reminder for invoice BILL-0003 (Henry Wilson)',DATE_ADD(NOW(), INTERVAL 2 HOUR),'sms','pending',6);

INSERT INTO patient_documents (tenant_id, patient_id, uploaded_by, title, file_name, file_path, file_type, file_size, visibility) VALUES
(1,1,4,'Insurance card — policy note','sample-insurance-note.txt','storage/documents/sample-insurance-note.txt','text/plain',243,'patient');
