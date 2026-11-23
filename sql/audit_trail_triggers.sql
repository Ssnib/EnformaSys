-- ============================================================================
-- EnformaSys Audit Trail Schema & MySQL Triggers
-- Captures OLD row snapshots into `history_logs` on UPDATE and DELETE
-- across sensitive grade and formation evaluation tables.
-- Compatible with MySQL 5.7.8+ / MariaDB 10.2.3+
-- ============================================================================

CREATE TABLE IF NOT EXISTS `history_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `table_name` VARCHAR(64) NOT NULL,
    `record_id` BIGINT NULL,
    `action_type` ENUM('UPDATE', 'DELETE') NOT NULL,
    `old_data` LONGTEXT NOT NULL,
    `new_data` LONGTEXT NULL,
    `summary` VARCHAR(255) NULL,
    `changed_by` VARCHAR(100) NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_history_table_record` (`table_name`, `record_id`),
    KEY `idx_history_action` (`action_type`),
    KEY `idx_history_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

-- 1. student_grades
DROP TRIGGER IF EXISTS `trg_student_grades_after_update`$$
CREATE TRIGGER `trg_student_grades_after_update` AFTER UPDATE ON `student_grades`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'student_grades',
        OLD.`grade_id`,
        'UPDATE',
        JSON_OBJECT('grade_id', OLD.`grade_id`, 'student_id', OLD.`student_id`, 'subject_id', OLD.`subject_id`, 'semester', OLD.`semester`, 'academic_year', OLD.`academic_year`, 'prelim_grade', OLD.`prelim_grade`, 'midterm_grade', OLD.`midterm_grade`, 'final_grade', OLD.`final_grade`, 'final_computed_grade', OLD.`final_computed_grade`, 'status', OLD.`status`, 'remarks', OLD.`remarks`, 'is_locked', OLD.`is_locked`, 'assessed_by', OLD.`assessed_by`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`),
        JSON_OBJECT('grade_id', NEW.`grade_id`, 'student_id', NEW.`student_id`, 'subject_id', NEW.`subject_id`, 'semester', NEW.`semester`, 'academic_year', NEW.`academic_year`, 'prelim_grade', NEW.`prelim_grade`, 'midterm_grade', NEW.`midterm_grade`, 'final_grade', NEW.`final_grade`, 'final_computed_grade', NEW.`final_computed_grade`, 'status', NEW.`status`, 'remarks', NEW.`remarks`, 'is_locked', NEW.`is_locked`, 'assessed_by', NEW.`assessed_by`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`),
        LEFT(CONCAT('Student #', OLD.student_id, ' · Subject #', OLD.subject_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Final: ', COALESCE(CAST(OLD.final_computed_grade AS CHAR), 'N/A')), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

DROP TRIGGER IF EXISTS `trg_student_grades_before_delete`$$
CREATE TRIGGER `trg_student_grades_before_delete` BEFORE DELETE ON `student_grades`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'student_grades',
        OLD.`grade_id`,
        'DELETE',
        JSON_OBJECT('grade_id', OLD.`grade_id`, 'student_id', OLD.`student_id`, 'subject_id', OLD.`subject_id`, 'semester', OLD.`semester`, 'academic_year', OLD.`academic_year`, 'prelim_grade', OLD.`prelim_grade`, 'midterm_grade', OLD.`midterm_grade`, 'final_grade', OLD.`final_grade`, 'final_computed_grade', OLD.`final_computed_grade`, 'status', OLD.`status`, 'remarks', OLD.`remarks`, 'is_locked', OLD.`is_locked`, 'assessed_by', OLD.`assessed_by`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`),
        NULL,
        LEFT(CONCAT('Student #', OLD.student_id, ' · Subject #', OLD.subject_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Final: ', COALESCE(CAST(OLD.final_computed_grade AS CHAR), 'N/A')), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

-- 2. grades (legacy)
DROP TRIGGER IF EXISTS `trg_grades_after_update`$$
CREATE TRIGGER `trg_grades_after_update` AFTER UPDATE ON `grades`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'grades',
        OLD.`id`,
        'UPDATE',
        JSON_OBJECT('id', OLD.`id`, 'seminarian_id', OLD.`seminarian_id`, 'subject', OLD.`subject`, 'units', OLD.`units`, 'grade', OLD.`grade`, 'grade_percent', OLD.`grade_percent`, 'term', OLD.`term`, 'school_year', OLD.`school_year`, 'remarks', OLD.`remarks`, 'assessed_by', OLD.`assessed_by`, 'date', OLD.`date`, 'archived_at', OLD.`archived_at`),
        JSON_OBJECT('id', NEW.`id`, 'seminarian_id', NEW.`seminarian_id`, 'subject', NEW.`subject`, 'units', NEW.`units`, 'grade', NEW.`grade`, 'grade_percent', NEW.`grade_percent`, 'term', NEW.`term`, 'school_year', NEW.`school_year`, 'remarks', NEW.`remarks`, 'assessed_by', NEW.`assessed_by`, 'date', NEW.`date`, 'archived_at', NEW.`archived_at`),
        LEFT(CONCAT('Seminarian #', COALESCE(CAST(OLD.seminarian_id AS CHAR), '?'), ' · ', COALESCE(OLD.subject, 'Subject'), ' Grade: ', COALESCE(CAST(OLD.grade AS CHAR), 'N/A')), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

DROP TRIGGER IF EXISTS `trg_grades_before_delete`$$
CREATE TRIGGER `trg_grades_before_delete` BEFORE DELETE ON `grades`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'grades',
        OLD.`id`,
        'DELETE',
        JSON_OBJECT('id', OLD.`id`, 'seminarian_id', OLD.`seminarian_id`, 'subject', OLD.`subject`, 'units', OLD.`units`, 'grade', OLD.`grade`, 'grade_percent', OLD.`grade_percent`, 'term', OLD.`term`, 'school_year', OLD.`school_year`, 'remarks', OLD.`remarks`, 'assessed_by', OLD.`assessed_by`, 'date', OLD.`date`, 'archived_at', OLD.`archived_at`),
        NULL,
        LEFT(CONCAT('Seminarian #', COALESCE(CAST(OLD.seminarian_id AS CHAR), '?'), ' · ', COALESCE(OLD.subject, 'Subject'), ' Grade: ', COALESCE(CAST(OLD.grade AS CHAR), 'N/A')), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

-- 3. gpa_records
DROP TRIGGER IF EXISTS `trg_gpa_records_after_update`$$
CREATE TRIGGER `trg_gpa_records_after_update` AFTER UPDATE ON `gpa_records`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'gpa_records',
        OLD.`gpa_id`,
        'UPDATE',
        JSON_OBJECT('gpa_id', OLD.`gpa_id`, 'student_id', OLD.`student_id`, 'semester', OLD.`semester`, 'academic_year', OLD.`academic_year`, 'semester_gpa', OLD.`semester_gpa`, 'cumulative_gpa', OLD.`cumulative_gpa`, 'performance_category', OLD.`performance_category`, 'computed_at', OLD.`computed_at`),
        JSON_OBJECT('gpa_id', NEW.`gpa_id`, 'student_id', NEW.`student_id`, 'semester', NEW.`semester`, 'academic_year', NEW.`academic_year`, 'semester_gpa', NEW.`semester_gpa`, 'cumulative_gpa', NEW.`cumulative_gpa`, 'performance_category', NEW.`performance_category`, 'computed_at', NEW.`computed_at`),
        LEFT(CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Sem GPA: ', COALESCE(CAST(OLD.semester_gpa AS CHAR), 'N/A')), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

DROP TRIGGER IF EXISTS `trg_gpa_records_before_delete`$$
CREATE TRIGGER `trg_gpa_records_before_delete` BEFORE DELETE ON `gpa_records`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'gpa_records',
        OLD.`gpa_id`,
        'DELETE',
        JSON_OBJECT('gpa_id', OLD.`gpa_id`, 'student_id', OLD.`student_id`, 'semester', OLD.`semester`, 'academic_year', OLD.`academic_year`, 'semester_gpa', OLD.`semester_gpa`, 'cumulative_gpa', OLD.`cumulative_gpa`, 'performance_category', OLD.`performance_category`, 'computed_at', OLD.`computed_at`),
        NULL,
        LEFT(CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Sem GPA: ', COALESCE(CAST(OLD.semester_gpa AS CHAR), 'N/A')), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

-- 4. academic_evaluations
DROP TRIGGER IF EXISTS `trg_academic_evaluations_after_update`$$
CREATE TRIGGER `trg_academic_evaluations_after_update` AFTER UPDATE ON `academic_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'academic_evaluations',
        OLD.`id`,
        'UPDATE',
        JSON_OBJECT('id', OLD.`id`, 'student_id', OLD.`student_id`, 'formator_id', OLD.`formator_id`, 'gpa', OLD.`gpa`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'remarks', OLD.`remarks`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`),
        JSON_OBJECT('id', NEW.`id`, 'student_id', NEW.`student_id`, 'formator_id', NEW.`formator_id`, 'gpa', NEW.`gpa`, 'academic_year', NEW.`academic_year`, 'semester', NEW.`semester`, 'evaluation_date', NEW.`evaluation_date`, 'remarks', NEW.`remarks`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`, 'archived_at', NEW.`archived_at`),
        LEFT(CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') GPA: ', COALESCE(CAST(OLD.gpa AS CHAR), 'N/A')), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

DROP TRIGGER IF EXISTS `trg_academic_evaluations_before_delete`$$
CREATE TRIGGER `trg_academic_evaluations_before_delete` BEFORE DELETE ON `academic_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'academic_evaluations',
        OLD.`id`,
        'DELETE',
        JSON_OBJECT('id', OLD.`id`, 'student_id', OLD.`student_id`, 'formator_id', OLD.`formator_id`, 'gpa', OLD.`gpa`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'remarks', OLD.`remarks`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`),
        NULL,
        LEFT(CONCAT('Student #', OLD.student_id, ' (', OLD.academic_year, ' ', OLD.semester, ') GPA: ', COALESCE(CAST(OLD.gpa AS CHAR), 'N/A')), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

-- 5. human_evaluations
DROP TRIGGER IF EXISTS `trg_human_evaluations_after_update`$$
CREATE TRIGGER `trg_human_evaluations_after_update` AFTER UPDATE ON `human_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'human_evaluations',
        OLD.`evaluation_id`,
        'UPDATE',
        JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'formator_id', OLD.`formator_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`),
        JSON_OBJECT('evaluation_id', NEW.`evaluation_id`, 'seminarian_id', NEW.`seminarian_id`, 'formator_id', NEW.`formator_id`, 'academic_year', NEW.`academic_year`, 'semester', NEW.`semester`, 'evaluation_date', NEW.`evaluation_date`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`, 'archived_at', NEW.`archived_at`),
        LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

DROP TRIGGER IF EXISTS `trg_human_evaluations_before_delete`$$
CREATE TRIGGER `trg_human_evaluations_before_delete` BEFORE DELETE ON `human_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'human_evaluations',
        OLD.`evaluation_id`,
        'DELETE',
        JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'formator_id', OLD.`formator_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`),
        NULL,
        LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

-- 6. pastoral_evaluations
DROP TRIGGER IF EXISTS `trg_pastoral_evaluations_after_update`$$
CREATE TRIGGER `trg_pastoral_evaluations_after_update` AFTER UPDATE ON `pastoral_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'pastoral_evaluations',
        OLD.`evaluation_id`,
        'UPDATE',
        JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'formator_id', OLD.`formator_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`),
        JSON_OBJECT('evaluation_id', NEW.`evaluation_id`, 'seminarian_id', NEW.`seminarian_id`, 'formator_id', NEW.`formator_id`, 'academic_year', NEW.`academic_year`, 'semester', NEW.`semester`, 'evaluation_date', NEW.`evaluation_date`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`, 'archived_at', NEW.`archived_at`),
        LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

DROP TRIGGER IF EXISTS `trg_pastoral_evaluations_before_delete`$$
CREATE TRIGGER `trg_pastoral_evaluations_before_delete` BEFORE DELETE ON `pastoral_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'pastoral_evaluations',
        OLD.`evaluation_id`,
        'DELETE',
        JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'formator_id', OLD.`formator_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`),
        NULL,
        LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

-- 7. spiritual_evaluations
DROP TRIGGER IF EXISTS `trg_spiritual_evaluations_after_update`$$
CREATE TRIGGER `trg_spiritual_evaluations_after_update` AFTER UPDATE ON `spiritual_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'spiritual_evaluations',
        OLD.`evaluation_id`,
        'UPDATE',
        JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'spiritual_director_id', OLD.`spiritual_director_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`),
        JSON_OBJECT('evaluation_id', NEW.`evaluation_id`, 'seminarian_id', NEW.`seminarian_id`, 'spiritual_director_id', NEW.`spiritual_director_id`, 'academic_year', NEW.`academic_year`, 'semester', NEW.`semester`, 'evaluation_date', NEW.`evaluation_date`, 'created_at', NEW.`created_at`, 'updated_at', NEW.`updated_at`, 'archived_at', NEW.`archived_at`),
        LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

DROP TRIGGER IF EXISTS `trg_spiritual_evaluations_before_delete`$$
CREATE TRIGGER `trg_spiritual_evaluations_before_delete` BEFORE DELETE ON `spiritual_evaluations`
FOR EACH ROW
BEGIN
    INSERT INTO `history_logs` (`table_name`, `record_id`, `action_type`, `old_data`, `new_data`, `summary`, `changed_by`, `changed_at`)
    VALUES (
        'spiritual_evaluations',
        OLD.`evaluation_id`,
        'DELETE',
        JSON_OBJECT('evaluation_id', OLD.`evaluation_id`, 'seminarian_id', OLD.`seminarian_id`, 'spiritual_director_id', OLD.`spiritual_director_id`, 'academic_year', OLD.`academic_year`, 'semester', OLD.`semester`, 'evaluation_date', OLD.`evaluation_date`, 'created_at', OLD.`created_at`, 'updated_at', OLD.`updated_at`, 'archived_at', OLD.`archived_at`),
        NULL,
        LEFT(CONCAT('Seminarian #', OLD.seminarian_id, ' (', OLD.academic_year, ' ', OLD.semester, ') Date: ', OLD.evaluation_date), 255),
        LEFT(COALESCE(NULLIF(@enformasys_actor, ''), CURRENT_USER()), 100),
        NOW()
    );
END$$

DELIMITER ;

