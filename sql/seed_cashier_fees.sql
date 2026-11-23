-- ============================================================================
-- EnformaSys / Littleway College Seminary
-- Cashiering Fee Templates & Schedules Seed Data
-- Standard Semester Fee Structure (Philosophy 1-4 & Propaedeutic Stage)
-- ============================================================================

USE seminary_db;

-- 1. Ensure Table Schema Exists
CREATE TABLE IF NOT EXISTS cashier_fee_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(9) NOT NULL,
    semester ENUM('1st','2nd') NOT NULL,
    program VARCHAR(80) NULL,
    year_level VARCHAR(30) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cashier_fee_sched_term (academic_year, semester),
    KEY idx_cashier_fee_sched_scope (program, year_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cashier_fee_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cashier_fee_items_schedule (schedule_id),
    CONSTRAINT fk_cashier_fee_items_sched FOREIGN KEY (schedule_id) REFERENCES cashier_fee_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Ensure fee_schedule_id column exists on lodging_invoices
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lodging_invoices' AND COLUMN_NAME = 'fee_schedule_id');
SET @alter_sql = IF(@col_exists = 0, 'ALTER TABLE lodging_invoices ADD COLUMN fee_schedule_id INT NULL AFTER invoice_type, ADD KEY idx_inv_fee_sched (fee_schedule_id)', 'SELECT 1');
PREPARE stmt_alter FROM @alter_sql;
EXECUTE stmt_alter;
DEALLOCATE PREPARE stmt_alter;

-- 3. Clear Previous Default Fee Schedules for 2026-2027
DELETE FROM cashier_fee_items WHERE schedule_id IN (
    SELECT id FROM cashier_fee_schedules WHERE academic_year = '2026-2027'
);
DELETE FROM cashier_fee_schedules WHERE academic_year = '2026-2027';

-- 4. First Semester Standard Fee Schedule (All Programs & Stages: Philosophy 1-4 & Propaedeutic)
INSERT INTO cashier_fee_schedules (academic_year, semester, program, year_level, is_active, created_by)
VALUES ('2026-2027', '1st', NULL, NULL, 1, 1);
SET @sched_1st = LAST_INSERT_ID();

INSERT INTO cashier_fee_items (schedule_id, description, amount, sort_order) VALUES
(@sched_1st, 'Board and Lodging Fee', 20000.00, 1),
(@sched_1st, 'Bus Fee', 1500.00, 2),
(@sched_1st, 'Computers Fee', 1000.00, 3),
(@sched_1st, 'Miscellaneous Fee', 500.00, 4),
(@sched_1st, 'Rice Fee', 0.00, 5);

-- 5. Second Semester Standard Fee Schedule (All Programs & Stages: Philosophy 1-4 & Propaedeutic)
INSERT INTO cashier_fee_schedules (academic_year, semester, program, year_level, is_active, created_by)
VALUES ('2026-2027', '2nd', NULL, NULL, 1, 1);
SET @sched_2nd = LAST_INSERT_ID();

INSERT INTO cashier_fee_items (schedule_id, description, amount, sort_order) VALUES
(@sched_2nd, 'Board and Lodging Fee', 20000.00, 1),
(@sched_2nd, 'Bus Fee', 1500.00, 2),
(@sched_2nd, 'Computers Fee', 1000.00, 3),
(@sched_2nd, 'Miscellaneous Fee', 500.00, 4),
(@sched_2nd, 'Rice Fee', 0.00, 5);
