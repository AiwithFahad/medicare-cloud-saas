-- ============================================================================
-- MediCare Cloud — Migration 001 (Upgrade v2, 2026-11-06)
-- Non-destructive: adds the tenant-approval state and the tables required by
-- the separate role panels (Super Admin / Hospital Admin / Doctor /
-- Receptionist / Patient), the JSON API and the reminder system.
-- Safe to run once against an existing batch-1/2 database. Existing data and
-- demo logins are untouched. The same changes are folded into database.sql
-- for fresh installs.
-- ============================================================================
USE medicare_cloud;

-- 1) Hospital registration approvals: new tenants start as 'pending' and are
--    activated by the Super Admin from super-admin/hospitals.php.
ALTER TABLE tenants
  MODIFY status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active';

-- 2) Patient documents (files live in storage/documents/, streamed through a
--    permission-checked controller — never linked directly).
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

-- 3) Hospital expenses (financial transactions out; patient_payments are in).
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

-- 4) Emergency department cases (walk-ins and emergency arrivals).
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

-- 5) Reminders (appointment reminders, follow-ups, payment reminders).
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

-- 6) Structured weekly doctor schedules (drives the availability calendar
--    and the slot picker; day_of_week: 0 = Sunday … 6 = Saturday).
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

-- ============================ DEMO SEEDS (tenant 1) ============================
-- Weekly schedules matching the doctors' published hours in database.sql.
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
