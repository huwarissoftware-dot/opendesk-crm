-- OpenDesk CRM - database schema (MySQL 8 / MariaDB 10.6+). Creates EMPTY tables only.
-- No accounts, no customers, no tickets are inserted. Designations, departments and
-- default settings are configuration lists required by the application.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL, role VARCHAR(30) NOT NULL DEFAULT 'Team Member',
  designation VARCHAR(120) NULL, department VARCHAR(120) NULL, phone VARCHAR(40) NULL,
  active TINYINT NOT NULL DEFAULT 1, force_change TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS departments (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE, active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS designations (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL UNIQUE, active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settings ( k VARCHAR(120) PRIMARY KEY, v TEXT NULL ) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY, company VARCHAR(190) NOT NULL, contact_person VARCHAR(120) NULL,
  email VARCHAR(190) NULL, emails TEXT NULL, phone VARCHAR(60) NULL, address TEXT NULL,
  service VARCHAR(190) NULL, sheet_type VARCHAR(60) NULL DEFAULT 'Managed Links',
  circuit_id VARCHAR(90) NULL, bandwidth VARCHAR(60) NULL, pop_site VARCHAR(120) NULL,
  vendor_id INT NULL, sla_document VARCHAR(255) NULL, notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS vendors (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, contact VARCHAR(120) NULL,
  email VARCHAR(190) NULL, phone VARCHAR(60) NULL, service VARCHAR(190) NULL, sla_document VARCHAR(255) NULL,
  esc1_name VARCHAR(120) NULL, esc1_title VARCHAR(120) NULL, esc1_phone VARCHAR(60) NULL, esc1_email VARCHAR(190) NULL, esc1_after_hours INT NULL,
  esc2_name VARCHAR(120) NULL, esc2_title VARCHAR(120) NULL, esc2_phone VARCHAR(60) NULL, esc2_email VARCHAR(190) NULL, esc2_after_hours INT NULL,
  esc3_name VARCHAR(120) NULL, esc3_title VARCHAR(120) NULL, esc3_phone VARCHAR(60) NULL, esc3_email VARCHAR(190) NULL, esc3_after_hours INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_daily_counters (
  customer_id INT NOT NULL, day DATE NOT NULL, last_seq INT NOT NULL DEFAULT 0,
  PRIMARY KEY (customer_id, day)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(220) NOT NULL UNIQUE,
  customer_id INT NOT NULL, customer_company VARCHAR(190) NOT NULL, customer_email VARCHAR(190) NULL,
  type VARCHAR(20) NOT NULL, category VARCHAR(80) NOT NULL, priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
  requirement TEXT NOT NULL, service VARCHAR(190) NULL, address TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Open', pending_at VARCHAR(190) NULL,
  department VARCHAR(120) NULL, assignee_id INT NULL, vendor_id INT NULL,
  etr DATETIME NULL, next_update_due DATETIME NULL, deployment_priority VARCHAR(20) NULL,
  sof_order_type VARCHAR(60) NULL, sof_effective_date DATE NULL, sof_document VARCHAR(255) NULL,
  confirmation_status VARCHAR(30) NULL, confirmation_by VARCHAR(120) NULL,
  confirmation_department VARCHAR(120) NULL, confirmation_at DATETIME NULL, confirmation_note TEXT NULL,
  rfo_reason VARCHAR(120) NULL, rca TEXT NULL, rfo_by VARCHAR(120) NULL, rfo_department VARCHAR(120) NULL, rfo_at DATETIME NULL,
  portal_token VARCHAR(64) NULL,
  opened_at DATETIME NULL, opened_by VARCHAR(120) NULL, opened_by_department VARCHAR(120) NULL,
  resolved_at DATETIME NULL, resolved_by VARCHAR(120) NULL, resolved_by_department VARCHAR(120) NULL,
  closed_at DATETIME NULL, closed_by VARCHAR(120) NULL, closed_by_department VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS remarks (
  id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, body TEXT NOT NULL,
  update_type VARCHAR(60) NOT NULL DEFAULT 'General Update', author_name VARCHAR(120) NOT NULL,
  author_department VARCHAR(120) NULL, is_call_log TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(ticket_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS attachments (
  id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, file_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL, size_bytes INT NOT NULL, uploaded_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(ticket_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS approvals (
  id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, step VARCHAR(40) NOT NULL,
  step_order INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  decided_by VARCHAR(120) NULL, decided_at DATETIME NULL, note TEXT NULL, INDEX(ticket_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY, actor_name VARCHAR(120) NOT NULL, actor_email VARCHAR(190) NULL,
  actor_department VARCHAR(120) NULL, action VARCHAR(190) NOT NULL, target VARCHAR(255) NULL,
  changed_fields TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS alerts (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, message TEXT NOT NULL,
  ticket_id INT NULL, is_read TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS colo_sites (
  id INT AUTO_INCREMENT PRIMARY KEY, site VARCHAR(190) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'Primary',
  customer VARCHAR(190) NULL, rack VARCHAR(60) NULL, u_space VARCHAR(60) NULL, power VARCHAR(60) NULL,
  equipment TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT 'Installed'
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS colo_visits (
  id INT AUTO_INCREMENT PRIMARY KEY, site_id INT NOT NULL, person VARCHAR(120) NOT NULL,
  entry_at DATETIME NULL, exit_at DATETIME NULL, purpose TEXT NULL, equipment_in TEXT NULL,
  equipment_out TEXT NULL, serials TEXT NULL, approver VARCHAR(120) NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS stock_items (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, serial VARCHAR(120) NULL,
  total INT NOT NULL DEFAULT 0, issued_to VARCHAR(190) NULL, issued_at DATETIME NULL, returned_at DATETIME NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS kb_articles (
  id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, category VARCHAR(80) NULL,
  rfo_reason VARCHAR(120) NULL, solution TEXT NOT NULL, created_by VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS processed_emails (
  message_id VARCHAR(255) PRIMARY KEY, ticket_id INT NULL, processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS import_mappings (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, mapping TEXT NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO departments(name) VALUES
 ('Online Technical Support'),('NOC'),('Fiber Team'),('Radio Team'),('Cloud Team'),('Cloud NOC'),
 ('Linux Team'),('Vendor'),('Accounts'),('Sales Team 1'),('Sales Team 2');
INSERT IGNORE INTO designations(name) VALUES
 ('CTO'),('Director Sales'),('Director Operations'),('Key Account Manager (KAM)'),('Sales Head'),
 ('DGM (Deputy General Manager)'),('GM (General Manager)'),('Senior Sales Team Lead'),('Team Lead'),
 ('Manager NOC'),('Manager Cloud'),('Admin'),('Helpdesk / Receptionist'),('Support Engineer');
INSERT IGNORE INTO settings(k,v) VALUES
 ('org_name','OpenDesk CRM'),('timezone','Asia/Karachi'),('session_timeout','1800'),
 ('sla_critical','4'),('sla_high','8'),('sla_medium','24'),('sla_low','48'),
 ('normal_deployment_update_days','9'),('external_access','1'),('access_mode','open'),
 ('allowed_ips',''),('mail_preset','imap'),('imap_port','993'),('imap_security','ssl'),('smtp_port','587'),('smtp_security','starttls'),
 ('mail_poll_minutes','5'),('monitoring_token',''),('whatsapp_enabled','0');
