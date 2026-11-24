<?php
/**
 * Registrar Module Decoupling: Students (Static Profile) & Enrollments (Historical Term Records)
 *
 * Separates static student identity (`students`) from term-by-term enrollment records
 * (`enrollments` and `enrollment_subjects`), provides automated non-destructive backup
 * and data migration from coupled legacy tables, and exposes helper functions for the
 * Registrar UI modules.
 */

require_once __DIR__ . '/lodging.php';

if (!function_exists('registrar_ensure_decoupled_schema')) {
    /**
     * Ensures the decoupled `students`, `enrollments`, `enrollment_subjects` tables
     * and the `v_student_current_status` view exist in the database.
     */
    function registrar_ensure_decoupled_schema(mysqli $conn): void
    {
        static $verified = false;
        if ($verified) {
            return;
        }
        $verified = true;

        $skipSchemaSync = !defined('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC')
            && (
                (function_exists('enformasys_env') && (enformasys_env('VERCEL', '') !== '' || enformasys_env('VERCEL_ENV', '') !== ''))
                || (function_exists('enformasys_is_production') && enformasys_is_production())
                || (function_exists('enformasys_env') && strtolower(trim((string)enformasys_env('DB_SKIP_SCHEMA_SYNC', 'false'))) === 'true')
            );
        if ($skipSchemaSync) {
            return;
        }
        // 1. Static Student Profile Table (`id` matches `seminarians.id` for seamless 1:1 compatibility)
        $conn->query(
            "CREATE TABLE IF NOT EXISTS students (\n"
            . "  id INT NOT NULL PRIMARY KEY,\n"
            . "  user_id INT NULL,\n"
            . "  student_no VARCHAR(40) NOT NULL,\n"
            . "  first_name VARCHAR(100) NOT NULL DEFAULT '',\n"
            . "  middle_name VARCHAR(100) NULL,\n"
            . "  last_name VARCHAR(100) NOT NULL DEFAULT '',\n"
            . "  suffix VARCHAR(20) NULL,\n"
            . "  full_name VARCHAR(200) NOT NULL,\n"
            . "  date_of_birth DATE NULL,\n"
            . "  place_of_birth VARCHAR(150) NULL,\n"
            . "  age INT NULL,\n"
            . "  gender VARCHAR(20) NULL,\n"
            . "  civil_status VARCHAR(30) NULL,\n"
            . "  nationality VARCHAR(60) NULL,\n"
            . "  religion VARCHAR(80) NULL,\n"
            . "  parish_priest VARCHAR(150) NULL,\n"
            . "  parish_chaplaincy VARCHAR(150) NULL,\n"
            . "  email VARCHAR(150) NULL,\n"
            . "  email_address VARCHAR(150) NULL,\n"
            . "  contact_number VARCHAR(50) NULL,\n"
            . "  home_address TEXT NULL,\n"
            . "  permanent_address TEXT NULL,\n"
            . "  present_address TEXT NULL,\n"
            . "  province VARCHAR(80) NULL,\n"
            . "  municipality VARCHAR(80) NULL,\n"
            . "  barangay VARCHAR(80) NULL,\n"
            . "  zip_code VARCHAR(15) NULL,\n"
            . "  father_name VARCHAR(150) NULL,\n"
            . "  father_occupation VARCHAR(120) NULL,\n"
            . "  mother_name VARCHAR(150) NULL,\n"
            . "  mother_occupation VARCHAR(120) NULL,\n"
            . "  guardian_name VARCHAR(150) NULL,\n"
            . "  guardian_contact VARCHAR(50) NULL,\n"
            . "  previous_school VARCHAR(150) NULL,\n"
            . "  educational_attainment VARCHAR(120) NULL,\n"
            . "  year_graduated VARCHAR(20) NULL,\n"
            . "  academic_records TEXT NULL,\n"
            . "  admission_academic_year VARCHAR(20) NOT NULL DEFAULT '',\n"
            . "  admission_date DATE NULL,\n"
            . "  default_program VARCHAR(100) NOT NULL DEFAULT 'Bachelor of Arts in Philosophy',\n"
            . "  formation_status VARCHAR(40) NOT NULL DEFAULT 'Active',\n"
            . "  is_graduate TINYINT(1) NOT NULL DEFAULT 0,\n"
            . "  graduated_at DATE NULL,\n"
            . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
            . "  UNIQUE KEY uq_students_student_no (student_no),\n"
            . "  KEY idx_students_user_id (user_id),\n"
            . "  KEY idx_students_name (last_name, first_name)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        try {
            $col_chk = $conn->query("SHOW COLUMNS FROM students LIKE 'email_address'");
            if ($col_chk && $col_chk->num_rows === 0) {
                $conn->query("ALTER TABLE students ADD COLUMN email_address VARCHAR(150) NULL AFTER email");
                $conn->query("UPDATE students SET email_address = email WHERE (email_address IS NULL OR email_address = '') AND email IS NOT NULL AND email != ''");
            }
        } catch (Throwable $e) {}

        // 2. Term-Scoped Enrollments Table (1 row per student per Academic Year + Semester)
        $conn->query(
            "CREATE TABLE IF NOT EXISTS enrollments (\n"
            . "  id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "  student_id INT NOT NULL,\n"
            . "  academic_year VARCHAR(20) NOT NULL,\n"
            . "  semester ENUM('1st','2nd','Summer') NOT NULL DEFAULT '1st',\n"
            . "  program VARCHAR(100) NOT NULL DEFAULT 'Bachelor of Arts in Philosophy',\n"
            . "  year_level VARCHAR(50) NOT NULL DEFAULT 'First Year',\n"
            . "  curriculum_version_id INT NULL,\n"
            . "  academic_standing ENUM('Regular','Irregular','Probationary') NOT NULL DEFAULT 'Regular',\n"
            . "  enrollment_status ENUM('Pending','Enrolled','Partially Paid','Dropped','Withdrawn','Completed','Graduated') NOT NULL DEFAULT 'Enrolled',\n"
            . "  total_assessed_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
            . "  total_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
            . "  fee_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,\n"
            . "  remarks TEXT NULL,\n"
            . "  enrolled_by INT NULL,\n"
            . "  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  is_locked TINYINT(1) NOT NULL DEFAULT 0,\n"
            . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
            . "  UNIQUE KEY uq_student_term (student_id, academic_year, semester),\n"
            . "  KEY idx_enrollments_term (academic_year, semester, enrollment_status),\n"
            . "  KEY idx_enrollments_student (student_id),\n"
            . "  CONSTRAINT fk_enrollments_student_id FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 3. Enrollment Subjects Junction Table (linked to a specific `enrollment_id`)
        $conn->query(
            "CREATE TABLE IF NOT EXISTS enrollment_subjects (\n"
            . "  id INT AUTO_INCREMENT PRIMARY KEY,\n"
            . "  enrollment_id INT NOT NULL,\n"
            . "  subject_id INT NOT NULL,\n"
            . "  units DECIMAL(4,1) NOT NULL DEFAULT 3.0,\n"
            . "  final_grade VARCHAR(20) NULL,\n"
            . "  subject_status ENUM('Enrolled','Completed','Dropped','Failed','INC') NOT NULL DEFAULT 'Enrolled',\n"
            . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,\n"
            . "  UNIQUE KEY uq_enrollment_subject (enrollment_id, subject_id),\n"
            . "  KEY idx_es_subject (subject_id),\n"
            . "  CONSTRAINT fk_es_enrollment_id FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE ON UPDATE CASCADE\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 4. Backward-compatibility SQL View (`v_student_current_status`)
        @$conn->query(
            "CREATE OR REPLACE VIEW v_student_current_status AS\n"
            . "SELECT\n"
            . "  s.id AS student_id,\n"
            . "  s.user_id,\n"
            . "  s.student_no,\n"
            . "  s.full_name,\n"
            . "  s.first_name,\n"
            . "  s.last_name,\n"
            . "  s.email,\n"
            . "  COALESCE(s.email, '') AS email_address,\n"
            . "  s.contact_number,\n"
            . "  s.home_address,\n"
            . "  s.admission_academic_year,\n"
            . "  s.is_graduate,\n"
            . "  e.id AS current_enrollment_id,\n"
            . "  e.academic_year AS current_academic_year,\n"
            . "  e.semester AS current_semester,\n"
            . "  COALESCE(e.program, s.default_program) AS program,\n"
            . "  e.year_level,\n"
            . "  e.academic_standing AS academic_status,\n"
            . "  e.enrollment_status,\n"
            . "  e.total_assessed_fee,\n"
            . "  e.total_paid,\n"
            . "  e.fee_balance\n"
            . "FROM students s\n"
            . "LEFT JOIN enrollments e ON e.id = (\n"
            . "  SELECT e2.id\n"
            . "  FROM enrollments e2\n"
            . "  WHERE e2.student_id = s.id\n"
            . "  ORDER BY e2.academic_year DESC, FIELD(e2.semester, 'Summer', '2nd', '1st') ASC, e2.id DESC\n"
            . "  LIMIT 1\n"
            . ")"
        );
    }
}

if (!function_exists('registrar_export_backup_snapshot')) {
    /**
     * Creates a safe SQL backup table (`seminarians_coupled_backup`) and writes a JSON snapshot
     * of coupled + decoupled student and enrollment records to `/backups/`.
     */
    function registrar_export_backup_snapshot(mysqli $conn, string $label = 'auto'): array
    {
        $chk_bkp = @$conn->query("SHOW TABLES LIKE 'seminarians_coupled_backup'");
        if (!$chk_bkp || $chk_bkp->num_rows === 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS seminarians_coupled_backup LIKE seminarians");
            $conn->query("INSERT IGNORE INTO seminarians_coupled_backup SELECT * FROM seminarians");
        }

        $backup_dir = dirname(__DIR__) . '/backups';
        if (!is_dir($backup_dir)) {
            @mkdir($backup_dir, 0775, true);
        }

        $coupled_rows = [];
        $res_s = $conn->query(
            "SELECT s.*, u.username, up.student_id AS profile_student_no, up.full_name, up.email, up.contact, up.address\n"
            . "FROM seminarians s\n"
            . "LEFT JOIN users u ON u.id = s.user_id\n"
            . "LEFT JOIN user_profiles up ON up.user_id = s.user_id\n"
            . "ORDER BY s.id ASC"
        );
        if ($res_s) {
            while ($r = $res_s->fetch_assoc()) {
                $coupled_rows[] = $r;
            }
        }

        $enrolled_subjs = [];
        $res_es = $conn->query("SELECT * FROM student_enrolled_subjects ORDER BY id ASC");
        if ($res_es) {
            while ($r = $res_es->fetch_assoc()) {
                $enrolled_subjs[] = $r;
            }
        }

        $decoupled_enrollments = [];
        $res_de = @$conn->query("SELECT * FROM enrollments ORDER BY id ASC");
        if ($res_de) {
            while ($r = $res_de->fetch_assoc()) {
                $decoupled_enrollments[] = $r;
            }
        }

        $payload = [
            'exported_at' => date('c'),
            'label' => $label,
            'counts' => [
                'coupled_seminarians' => count($coupled_rows),
                'enrolled_subjects' => count($enrolled_subjs),
                'decoupled_enrollments' => count($decoupled_enrollments),
            ],
            'seminarians' => $coupled_rows,
            'student_enrolled_subjects' => $enrolled_subjs,
            'enrollments' => $decoupled_enrollments,
        ];

        $filename = ($label === 'initial')
            ? 'registrar_initial_coupled_backup.json'
            : ('registrar_backup_' . date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $label) . '.json');

        $filepath = $backup_dir . '/' . $filename;
        @file_put_contents($filepath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return [
            'file' => $filename,
            'path' => $filepath,
            'students_count' => count($coupled_rows),
            'subjects_count' => count($enrolled_subjs),
            'enrollments_count' => count($decoupled_enrollments),
        ];
    }
}

if (!function_exists('registrar_sync_and_migrate')) {
    /**
     * Safely extracts coupled records from `seminarians`, `user_profiles`, `applicants`,
     * `student_enrolled_subjects`, and `student_grades`, backs them up, and migrates/syncs
     * them into the decoupled `students`, `enrollments`, and `enrollment_subjects` tables.
     */
    function registrar_sync_and_migrate(mysqli $conn, bool $force_full_sync = false): array
    {
        registrar_ensure_decoupled_schema($conn);

        // Check if initial migration has already populated `students` and all enrolled `seminarians`
        if (!$force_full_sync) {
            $sem_c = (int)($conn->query("SELECT COUNT(*) AS c FROM seminarians")->fetch_assoc()['c'] ?? 0);
            $stu_c = (int)($conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'] ?? 0);
            $enr_c = (int)($conn->query("SELECT COUNT(*) AS c FROM enrollments")->fetch_assoc()['c'] ?? 0);
            $missing_stu = (int)($conn->query("SELECT COUNT(*) AS c FROM seminarians s LEFT JOIN students st ON st.id = s.id WHERE st.id IS NULL")->fetch_assoc()['c'] ?? 0);
            $missing_enr = (int)($conn->query("SELECT COUNT(*) AS c FROM seminarians s LEFT JOIN enrollments e ON e.student_id = s.id WHERE ((s.enrollment_status IS NOT NULL AND s.enrollment_status <> '') OR (s.year_level IS NOT NULL AND s.year_level <> '')) AND e.id IS NULL")->fetch_assoc()['c'] ?? 0);
            if ($sem_c === 0 && $stu_c === 0) {
                return ['status' => 'already_synced', 'students' => 0, 'enrollments' => 0];
            }
            if ($stu_c > 0 && $missing_stu === 0 && $missing_enr === 0 && $enr_c > 0) {
                return ['status' => 'already_synced', 'students' => $stu_c, 'enrollments' => $enr_c];
            }
        }

        // Create initial backup if not yet present
        $initial_backup_file = dirname(__DIR__) . '/backups/registrar_initial_coupled_backup.json';
        if (!file_exists($initial_backup_file)) {
            registrar_export_backup_snapshot($conn, 'initial');
        }

        $current_ay = function_exists('get_current_academic_year') ? get_current_academic_year() : date('Y') . '-' . (date('Y') + 1);
        $active_sem = function_exists('get_active_semester') ? get_active_semester() : '1st';
        if (!in_array($active_sem, ['1st', '2nd', 'Summer'], true)) {
            $active_sem = '1st';
        }

        // Fetch all coupled student records
        $sql_coupled = "SELECT
                s.id AS seminarian_id,
                s.user_id,
                s.current_academic_year,
                s.year_level,
                s.formation_stage,
                s.academic_status,
                s.enrollment_status,
                s.program,
                s.admission_date,
                s.formation_status,
                s.is_graduate,
                s.completed_at,
                s.academic_remarks,
                u.username,
                up.student_id AS up_student_no,
                up.first_name,
                up.middle_name,
                up.last_name,
                up.suffix,
                up.full_name AS up_full_name,
                up.date_of_birth AS up_dob,
                up.place_of_birth,
                up.age AS up_age,
                up.gender AS up_gender,
                up.civil_status,
                up.nationality,
                up.religion,
                up.parish_priest,
                up.parish_chaplaincy,
                COALESCE(NULLIF(up.email_address, ''), up.email) AS up_email,
                COALESCE(NULLIF(up.contact_number, ''), up.contact) AS up_contact,
                up.address AS up_address,
                up.permanent_address,
                up.present_address,
                up.province,
                up.municipality,
                up.barangay,
                up.zip_code,
                up.father_name,
                up.father_occupation,
                up.mother_name,
                up.mother_occupation,
                up.guardian_name,
                up.guardian_contact_number,
                up.previous_school,
                up.educational_attainment,
                up.year_graduated,
                up.academic_records,
                a.full_name AS app_full_name,
                a.dob AS app_dob,
                a.age AS app_age,
                a.gender AS app_gender,
                a.contact AS app_contact,
                a.address AS app_address,
                a.academic_year AS app_academic_year
            FROM seminarians s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN user_profiles up ON up.user_id = s.user_id
            LEFT JOIN applicants a ON a.id = (
                SELECT a2.id FROM applicants a2
                WHERE a2.user_id = s.user_id AND a2.archived_at IS NULL
                ORDER BY a2.id DESC LIMIT 1
            )
            ORDER BY s.id ASC";

        $res_coupled = $conn->query($sql_coupled);
        if (!$res_coupled) {
            return ['status' => 'error', 'message' => $conn->error];
        }

        $students_list = [];
        while ($row = $res_coupled->fetch_assoc()) {
            $students_list[] = $row;
        }

        // Pre-fetch all enrolled subjects grouped by student_id
        $enrolled_by_student = [];
        try {
            $res_es = $conn->query(
                "SELECT es.*, sub.units, sg.final_grade, sg.final_computed_grade, sg.status AS grade_status\n"
                . "FROM student_enrolled_subjects es\n"
                . "LEFT JOIN subjects sub ON sub.subject_id = es.subject_id\n"
                . "LEFT JOIN student_grades sg ON (sg.student_id = es.student_id AND sg.subject_id = es.subject_id AND sg.academic_year = es.academic_year AND sg.semester = es.semester)\n"
                . "WHERE es.academic_year IS NOT NULL AND es.academic_year <> ''\n"
                . "ORDER BY es.academic_year ASC, FIELD(es.semester, '1st', '2nd', 'Summer') ASC, es.id ASC"
            );
            if ($res_es) {
                while ($r = $res_es->fetch_assoc()) {
                    $sid = (int)$r['student_id'];
                    $enrolled_by_student[$sid][] = $r;
                }
            }
        } catch (Throwable $e) {
            // Ignore if optional table is missing
        }

        // Also check standalone student_grades that might not have a row in student_enrolled_subjects
        $grades_by_student = [];
        try {
            $res_sg = $conn->query(
                "SELECT sg.*, MAX(sub.units) AS units, MAX(cs.year_level) AS cs_year_level, MAX(cv.program) AS cv_program\n"
                . "FROM student_grades sg\n"
                . "LEFT JOIN subjects sub ON sub.subject_id = sg.subject_id\n"
                . "LEFT JOIN curriculum_subjects cs ON cs.subject_id = sg.subject_id\n"
                . "LEFT JOIN curriculum_versions cv ON cv.id = cs.curriculum_version_id\n"
                . "WHERE sg.academic_year IS NOT NULL AND sg.academic_year <> ''\n"
                . "GROUP BY sg.grade_id"
            );
            if ($res_sg) {
                while ($r = $res_sg->fetch_assoc()) {
                    $sid = (int)$r['student_id'];
                    $grades_by_student[$sid][] = $r;
                }
            }
        } catch (Throwable $e) {
            // Ignore if optional table or group by fails
        }

        // Pre-fetch existing rows in `enrollments` so manually enrolled terms are preserved
        $existing_enrollments_by_student = [];
        try {
            $res_ex_enr = $conn->query("SELECT * FROM enrollments ORDER BY id ASC");
            if ($res_ex_enr) {
                while ($r = $res_ex_enr->fetch_assoc()) {
                    $sid = (int)$r['student_id'];
                    $existing_enrollments_by_student[$sid][] = $r;
                }
            }
        } catch (Throwable $e) {
        }

        // Pre-reserve all explicit `up_student_no` values to prevent auto-generated `student_no` collisions
        $used_student_nos = [];
        foreach ($students_list as $st_pre) {
            $sid_pre = (int)$st_pre['seminarian_id'];
            $raw_sno = trim((string)($st_pre['up_student_no'] ?? ''));
            if ($raw_sno !== '') {
                $key_sno = strtoupper($raw_sno);
                if (!isset($used_student_nos[$key_sno])) {
                    $used_student_nos[$key_sno] = $sid_pre;
                }
            }
        }
        try {
            $res_ex_stu = $conn->query("SELECT id, student_no FROM students");
            if ($res_ex_stu) {
                while ($r = $res_ex_stu->fetch_assoc()) {
                    $key_sno = strtoupper(trim((string)($r['student_no'] ?? '')));
                    if ($key_sno !== '' && !isset($used_student_nos[$key_sno])) {
                        $used_student_nos[$key_sno] = (int)$r['id'];
                    }
                }
            }
        } catch (Throwable $e) {
        }

        $migrated_students = 0;
        $migrated_enrollments = 0;

        $conn->begin_transaction();
        try {
            $stmt_stu = $conn->prepare(
                "INSERT INTO students (\n"
                . "  id, user_id, student_no, first_name, middle_name, last_name, suffix, full_name,\n"
                . "  date_of_birth, place_of_birth, age, gender, civil_status, nationality, religion,\n"
                . "  parish_priest, parish_chaplaincy, email, contact_number, home_address,\n"
                . "  permanent_address, present_address, province, municipality, barangay, zip_code,\n"
                . "  father_name, father_occupation, mother_name, mother_occupation, guardian_name, guardian_contact,\n"
                . "  previous_school, educational_attainment, year_graduated, academic_records,\n"
                . "  admission_academic_year, admission_date, default_program, formation_status, is_graduate, graduated_at\n"
                . ") VALUES (\n"
                . "  ?, ?, ?, ?, ?, ?, ?, ?,\n"
                . "  ?, ?, ?, ?, ?, ?, ?,\n"
                . "  ?, ?, ?, ?, ?,\n"
                . "  ?, ?, ?, ?, ?, ?,\n"
                . "  ?, ?, ?, ?, ?, ?,\n"
                . "  ?, ?, ?, ?,\n"
                . "  ?, ?, ?, ?, ?, ?\n"
                . ") ON DUPLICATE KEY UPDATE\n"
                . "  user_id = VALUES(user_id),\n"
                . "  student_no = VALUES(student_no),\n"
                . "  full_name = VALUES(full_name),\n"
                . "  first_name = COALESCE(NULLIF(VALUES(first_name), ''), first_name),\n"
                . "  last_name = COALESCE(NULLIF(VALUES(last_name), ''), last_name),\n"
                . "  email = COALESCE(NULLIF(VALUES(email), ''), email),\n"
                . "  contact_number = COALESCE(NULLIF(VALUES(contact_number), ''), contact_number),\n"
                . "  home_address = COALESCE(NULLIF(VALUES(home_address), ''), home_address),\n"
                . "  parish_priest = COALESCE(NULLIF(VALUES(parish_priest), ''), parish_priest),\n"
                . "  parish_chaplaincy = COALESCE(NULLIF(VALUES(parish_chaplaincy), ''), parish_chaplaincy),\n"
                . "  default_program = COALESCE(NULLIF(VALUES(default_program), ''), default_program),\n"
                . "  formation_status = COALESCE(NULLIF(VALUES(formation_status), ''), formation_status),\n"
                . "  is_graduate = VALUES(is_graduate),\n"
                . "  graduated_at = VALUES(graduated_at)"
            );

            $stmt_fix_sno = $conn->prepare("UPDATE students SET student_no = CONCAT(student_no, '-DUP-', id) WHERE student_no = ? AND id <> ?");

            $stmt_enr = $conn->prepare(
                "INSERT INTO enrollments (\n"
                . "  student_id, academic_year, semester, program, year_level, curriculum_version_id,\n"
                . "  academic_standing, enrollment_status, total_assessed_fee, total_paid, fee_balance,\n"
                . "  remarks, enrolled_at, is_locked\n"
                . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)\n"
                . "ON DUPLICATE KEY UPDATE\n"
                . "  program = COALESCE(NULLIF(VALUES(program), ''), program),\n"
                . "  year_level = COALESCE(NULLIF(VALUES(year_level), ''), year_level),\n"
                . "  total_assessed_fee = VALUES(total_assessed_fee),\n"
                . "  total_paid = VALUES(total_paid),\n"
                . "  fee_balance = VALUES(fee_balance)"
            );

            $stmt_es = $conn->prepare(
                "INSERT INTO enrollment_subjects (enrollment_id, subject_id, units, final_grade, subject_status)\n"
                . "VALUES (?, ?, ?, ?, ?)\n"
                . "ON DUPLICATE KEY UPDATE\n"
                . "  units = VALUES(units),\n"
                . "  final_grade = COALESCE(VALUES(final_grade), final_grade),\n"
                . "  subject_status = VALUES(subject_status)"
            );

            $ay_prefix_year = substr($current_ay, 0, 4);
            if (!preg_match('/^\d{4}$/', $ay_prefix_year)) {
                $ay_prefix_year = date('Y');
            }
            $seq_counter = 1;

            foreach ($students_list as $st) {
                $sid = (int)$st['seminarian_id'];
                $uid = !empty($st['user_id']) ? (int)$st['user_id'] : null;

                $full_name = trim((string)($st['up_full_name'] ?: ($st['app_full_name'] ?: ($st['username'] ?: ('Seminarian #' . $sid)))));
                $first_name = trim((string)($st['first_name'] ?? ''));
                $middle_name = trim((string)($st['middle_name'] ?? ''));
                $last_name = trim((string)($st['last_name'] ?? ''));
                $suffix = trim((string)($st['suffix'] ?? ''));

                if ($first_name === '' && $last_name === '') {
                    $parts = preg_split('/\s+/', $full_name);
                    $first_name = $parts[0] ?? 'Seminarian';
                    $last_name = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : (string)$sid;
                }

                $student_no = trim((string)($st['up_student_no'] ?? ''));
                $sno_key = strtoupper($student_no);
                if ($student_no === '' || (isset($used_student_nos[$sno_key]) && $used_student_nos[$sno_key] !== $sid)) {
                    if (strtolower((string)($st['username'] ?? '')) === 'student1' && $student_no === '') {
                        $candidate_sno = 'STU-DEFAULT-' . str_pad((string)$sid, 4, '0', STR_PAD_LEFT);
                    } else {
                        do {
                            $candidate_sno = sprintf('SEM-%s-%04d', $ay_prefix_year, $seq_counter);
                            $seq_counter++;
                        } while (isset($used_student_nos[strtoupper($candidate_sno)]) && $used_student_nos[strtoupper($candidate_sno)] !== $sid);
                    }
                    $student_no = $candidate_sno;
                    $sno_key = strtoupper($student_no);
                }
                $used_student_nos[$sno_key] = $sid;

                // Ensure no other row in `students` holds this `student_no` so `INSERT` never collides on `uq_students_student_no`
                if ($stmt_fix_sno) {
                    $stmt_fix_sno->bind_param('si', $student_no, $sid);
                    $stmt_fix_sno->execute();
                }

                $dob = !empty($st['up_dob']) ? $st['up_dob'] : (!empty($st['app_dob']) ? $st['app_dob'] : null);
                $age = !empty($st['up_age']) ? (int)$st['up_age'] : (!empty($st['app_age']) ? (int)$st['app_age'] : null);
                $gender = trim((string)($st['up_gender'] ?: ($st['app_gender'] ?: 'Male')));
                $email = trim((string)($st['up_email'] ?? ''));
                $contact = trim((string)($st['up_contact'] ?: ($st['app_contact'] ?? '')));
                $home_addr = trim((string)($st['up_address'] ?: ($st['present_address'] ?: ($st['permanent_address'] ?: ($st['app_address'] ?? '')))));

                $adm_ay = trim((string)($st['app_academic_year'] ?: ($st['current_academic_year'] ?: $current_ay)));
                $adm_date = !empty($st['admission_date']) ? $st['admission_date'] : null;
                $def_prog = trim((string)($st['program'] ?: 'Bachelor of Arts in Philosophy'));
                $form_status = trim((string)($st['formation_status'] ?: 'Active'));
                $is_grad = (int)($st['is_graduate'] ?? 0);
                $grad_at = !empty($st['completed_at']) ? $st['completed_at'] : null;

                $place_of_birth = $st['place_of_birth'] ?? null;
                $civil_status = $st['civil_status'] ?? null;
                $nationality = $st['nationality'] ?? null;
                $religion = $st['religion'] ?? null;
                $parish_priest = $st['parish_priest'] ?? null;
                $parish_chaplaincy = $st['parish_chaplaincy'] ?? null;
                $perm_addr = $st['permanent_address'] ?? null;
                $pres_addr = $st['present_address'] ?? null;
                $province = $st['province'] ?? null;
                $municipality = $st['municipality'] ?? null;
                $barangay = $st['barangay'] ?? null;
                $zip_code = $st['zip_code'] ?? null;
                $father_name = $st['father_name'] ?? null;
                $father_occ = $st['father_occupation'] ?? null;
                $mother_name = $st['mother_name'] ?? null;
                $mother_occ = $st['mother_occupation'] ?? null;
                $guard_name = $st['guardian_name'] ?? null;
                $guard_contact = $st['guardian_contact_number'] ?? null;
                $prev_school = $st['previous_school'] ?? null;
                $edu_attain = $st['educational_attainment'] ?? null;
                $yr_grad = $st['year_graduated'] ?? null;
                $acad_rec = $st['academic_records'] ?? null;

                $types_stu = 'ii' . str_repeat('s', 8) . 'i' . str_repeat('s', 29) . 'is';
                $stmt_stu->bind_param(
                    $types_stu,
                    $sid,
                    $uid,
                    $student_no,
                    $first_name,
                    $middle_name,
                    $last_name,
                    $suffix,
                    $full_name,
                    $dob,
                    $place_of_birth,
                    $age,
                    $gender,
                    $civil_status,
                    $nationality,
                    $religion,
                    $parish_priest,
                    $parish_chaplaincy,
                    $email,
                    $contact,
                    $home_addr,
                    $perm_addr,
                    $pres_addr,
                    $province,
                    $municipality,
                    $barangay,
                    $zip_code,
                    $father_name,
                    $father_occ,
                    $mother_name,
                    $mother_occ,
                    $guard_name,
                    $guard_contact,
                    $prev_school,
                    $edu_attain,
                    $yr_grad,
                    $acad_rec,
                    $adm_ay,
                    $adm_date,
                    $def_prog,
                    $form_status,
                    $is_grad,
                    $grad_at
                );
                $stmt_stu->execute();
                $migrated_students++;

                // Build chronological term map for this student
                $terms_map = [];

                // 0) Include any existing decoupled enrollments for this student first
                if (!empty($existing_enrollments_by_student[$sid])) {
                    foreach ($existing_enrollments_by_student[$sid] as $ex_enr) {
                        $ay = trim((string)$ex_enr['academic_year']);
                        $sem = in_array($ex_enr['semester'], ['1st', '2nd', 'Summer'], true) ? $ex_enr['semester'] : '1st';
                        if ($ay === '') {
                            continue;
                        }
                        $tkey = $ay . '|' . $sem;
                        $terms_map[$tkey] = [
                            'academic_year' => $ay,
                            'semester' => $sem,
                            'year_level' => trim((string)($ex_enr['year_level'] ?: ($st['year_level'] ?: 'First Year'))),
                            'program' => trim((string)($ex_enr['program'] ?: $def_prog)),
                            'curriculum_version_id' => !empty($ex_enr['curriculum_version_id']) ? (int)$ex_enr['curriculum_version_id'] : null,
                            'existing_status' => $ex_enr['enrollment_status'] ?? null,
                            'existing_locked' => isset($ex_enr['is_locked']) ? (int)$ex_enr['is_locked'] : null,
                            'existing_remarks' => $ex_enr['remarks'] ?? null,
                            'subjects' => [],
                        ];
                    }
                }

                // 1) From student_enrolled_subjects
                if (!empty($enrolled_by_student[$sid])) {
                    foreach ($enrolled_by_student[$sid] as $es_row) {
                        $ay = trim((string)$es_row['academic_year']);
                        $sem = in_array($es_row['semester'], ['1st', '2nd', 'Summer'], true) ? $es_row['semester'] : '1st';
                        $tkey = $ay . '|' . $sem;
                        $yl = trim((string)($es_row['year_level'] ?: ($st['year_level'] ?: 'First Year')));
                        $prog = trim((string)($es_row['program'] ?: $def_prog));
                        if (!isset($terms_map[$tkey])) {
                            $terms_map[$tkey] = [
                                'academic_year' => $ay,
                                'semester' => $sem,
                                'year_level' => $yl,
                                'program' => $prog,
                                'curriculum_version_id' => !empty($es_row['curriculum_version_id']) ? (int)$es_row['curriculum_version_id'] : null,
                                'subjects' => [],
                            ];
                        } else {
                            if (!empty($es_row['year_level'])) {
                                $terms_map[$tkey]['year_level'] = $yl;
                            }
                            if (!empty($es_row['program'])) {
                                $terms_map[$tkey]['program'] = $prog;
                            }
                        }
                        $terms_map[$tkey]['subjects'][(int)$es_row['subject_id']] = [
                            'subject_id' => (int)$es_row['subject_id'],
                            'units' => (float)($es_row['units'] ?? 3.0),
                            'final_grade' => $es_row['final_grade'] ?? $es_row['final_computed_grade'] ?? null,
                            'status' => $es_row['status'] ?? 'Enrolled',
                            'grade_status' => $es_row['grade_status'] ?? null,
                        ];
                    }
                }

                // 2) From standalone student_grades
                if (!empty($grades_by_student[$sid])) {
                    foreach ($grades_by_student[$sid] as $sg_row) {
                        $ay = trim((string)$sg_row['academic_year']);
                        $sem = in_array($sg_row['semester'], ['1st', '2nd', 'Summer'], true) ? $sg_row['semester'] : '1st';
                        $tkey = $ay . '|' . $sem;
                        if (!isset($terms_map[$tkey])) {
                            $yl = trim((string)($sg_row['cs_year_level'] ?: ($st['year_level'] ?: 'First Year')));
                            $prog = trim((string)($sg_row['cv_program'] ?: $def_prog));
                            $terms_map[$tkey] = [
                                'academic_year' => $ay,
                                'semester' => $sem,
                                'year_level' => $yl,
                                'program' => $prog,
                                'curriculum_version_id' => null,
                                'subjects' => [],
                            ];
                        }
                        $sub_id = (int)$sg_row['subject_id'];
                        if (!isset($terms_map[$tkey]['subjects'][$sub_id])) {
                            $terms_map[$tkey]['subjects'][$sub_id] = [
                                'subject_id' => $sub_id,
                                'units' => (float)($sg_row['units'] ?? 3.0),
                                'final_grade' => $sg_row['final_grade'] ?? $sg_row['final_computed_grade'] ?? null,
                                'status' => 'Completed',
                                'grade_status' => $sg_row['status'] ?? 'Completed',
                            ];
                        }
                    }
                }

                // 3) Ensure the student's current coupled term in `seminarians` is represented (skip blank placeholder `student1`)
                $is_placeholder_seed = (
                    strtolower((string)($st['username'] ?? '')) === 'student1'
                    && empty($st['up_full_name'])
                    && empty($st['app_full_name'])
                    && empty($st['year_level'])
                    && empty($st['enrollment_status'])
                    && empty($terms_map)
                );

                if ($is_placeholder_seed) {
                    continue;
                }

                $coupled_ay = trim((string)($st['current_academic_year'] ?: $current_ay));
                $coupled_yl = trim((string)($st['year_level'] ?: 'First Year'));
                $has_coupled_ay = false;
                foreach ($terms_map as $tk => $tv) {
                    if ($tv['academic_year'] === $coupled_ay) {
                        $has_coupled_ay = true;
                        break;
                    }
                }
                if (empty($terms_map) || (!$has_coupled_ay && (!empty($st['year_level']) || !empty($st['enrollment_status'])))) {
                    $tkey = $coupled_ay . '|1st';
                    $terms_map[$tkey] = [
                        'academic_year' => $coupled_ay,
                        'semester' => '1st',
                        'year_level' => $coupled_yl,
                        'program' => $def_prog,
                        'curriculum_version_id' => null,
                        'subjects' => [],
                    ];
                }

                // Sort terms chronologically so we know which one is the latest term
                $term_keys = array_keys($terms_map);
                usort($term_keys, static function (string $a, string $b): int {
                    [$ayA, $semA] = explode('|', $a);
                    [$ayB, $semB] = explode('|', $b);
                    if ($ayA !== $ayB) {
                        return strcmp($ayA, $ayB);
                    }
                    $order = ['1st' => 1, '2nd' => 2, 'Summer' => 3];
                    return ($order[$semA] ?? 1) <=> ($order[$semB] ?? 1);
                });

                $latest_tkey = end($term_keys);

                foreach ($term_keys as $tkey) {
                    $tdata = $terms_map[$tkey];
                    $is_latest = ($tkey === $latest_tkey);

                    // Compute term fee summary from lodging/cashier helper
                    $bal_info = lodging_get_seminarian_balance($conn, $sid, $tdata['academic_year'], $tdata['semester']);
                    $assessed_fee = (float)($bal_info['current_term_due'] ?? 0.0);
                    $paid_amt = (float)($bal_info['total_paid'] ?? 0.0);
                    $fee_bal = max(0.0, round($assessed_fee - $paid_amt, 2));

                    $standing = ($st['academic_status'] ?? 'Regular') === 'Irregular' ? 'Irregular' : 'Regular';
                    $raw_enr_status = trim((string)($st['enrollment_status'] ?: 'Enrolled'));
                    if (isset($tdata['existing_status']) && $tdata['existing_status'] !== null) {
                        $enr_status = (string)$tdata['existing_status'];
                        $is_locked = (int)($tdata['existing_locked'] ?? 0);
                    } elseif (!$is_latest) {
                        $enr_status = 'Completed';
                        $is_locked = 1;
                    } else {
                        if ($is_grad || $tdata['year_level'] === 'Completed') {
                            $enr_status = 'Graduated';
                            $is_locked = 1;
                        } elseif (in_array($raw_enr_status, ['Pending', 'Enrolled', 'Partially Paid', 'Dropped', 'Withdrawn', 'Completed', 'Graduated'], true)) {
                            $enr_status = $raw_enr_status;
                            $is_locked = 0;
                        } else {
                            $enr_status = 'Enrolled';
                            $is_locked = 0;
                        }
                    }

                    $remarks = $tdata['existing_remarks'] ?? ($is_latest ? ($st['academic_remarks'] ?? null) : 'Historical semester record');
                    $cv_id_val = $tdata['curriculum_version_id'];

                    $stmt_enr->bind_param(
                        'issssisssddsi',
                        $sid,
                        $tdata['academic_year'],
                        $tdata['semester'],
                        $tdata['program'],
                        $tdata['year_level'],
                        $cv_id_val,
                        $standing,
                        $enr_status,
                        $assessed_fee,
                        $paid_amt,
                        $fee_bal,
                        $remarks,
                        $is_locked
                    );
                    $stmt_enr->execute();

                    $enrollment_id = (int)$conn->insert_id;
                    if ($enrollment_id <= 0) {
                        $stmt_find_enr = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
                        $stmt_find_enr->bind_param('iss', $sid, $tdata['academic_year'], $tdata['semester']);
                        $stmt_find_enr->execute();
                        $enrollment_id = (int)($stmt_find_enr->get_result()->fetch_assoc()['id'] ?? 0);
                    }

                    if ($enrollment_id > 0) {
                        $migrated_enrollments++;
                        foreach ($tdata['subjects'] as $sub_item) {
                            $sub_id = (int)$sub_item['subject_id'];
                            $units = (float)$sub_item['units'];
                            $fgrade = $sub_item['final_grade'] !== null ? (string)$sub_item['final_grade'] : null;

                            $sub_status = 'Enrolled';
                            if ($sub_item['status'] === 'Dropped' || $sub_item['grade_status'] === 'Dropped') {
                                $sub_status = 'Dropped';
                            } elseif ($sub_item['grade_status'] === 'Incomplete') {
                                $sub_status = 'INC';
                            } elseif ($fgrade !== null && is_numeric($fgrade) && (float)$fgrade > 0 && (float)$fgrade < 75.0 && (float)$fgrade > 5.0) {
                                $sub_status = 'Failed';
                            } elseif ($sub_item['status'] === 'Completed' || $sub_item['grade_status'] === 'Completed' || !$is_latest) {
                                $sub_status = 'Completed';
                            }

                            $stmt_es->bind_param('iidss', $enrollment_id, $sub_id, $units, $fgrade, $sub_status);
                            $stmt_es->execute();
                        }
                    }
                }
            }

            $conn->commit();
            registrar_backfill_graduation_metadata($conn);
            if (function_exists('enformasys_audit_log')) {
                $actorId = isset($_SESSION['user']) && (int)$_SESSION['user'] > 0 ? (int)$_SESSION['user'] : null;
                enformasys_audit_log(
                    $conn,
                    $actorId,
                    'Completed registrar data sync/migration (' . $migrated_students . ' students, ' . $migrated_enrollments . ' enrollments)',
                    'migration',
                    null,
                    'registrar_sync_and_migrate',
                    $force_full_sync ? 'full_sync' : 'auto_sync',
                    'students=' . $migrated_students . ';enrollments=' . $migrated_enrollments
                );
            }
            return [
                'status' => 'migrated',
                'students' => $migrated_students,
                'enrollments' => $migrated_enrollments,
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('EnformaSys registrar_sync_and_migrate error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => (function_exists('enformasys_is_production') && enformasys_is_production()) ? 'Data migration failed.' : $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('registrar_is_graduated_status')) {
    /**
     * Checks whether a status or year-level string represents graduation/program completion.
     */
    function registrar_is_graduated_status(?string $value): bool
    {
        $clean = strtolower(trim((string)$value));
        return in_array($clean, ['graduated', 'graduate', 'completed', 'complete', 'alumni'], true);
    }
}

if (!function_exists('registrar_sync_graduation_metadata')) {
    /**
     * Keeps graduation flags and metadata (`is_graduate`, `completed_at`/`graduated_at`,
     * `academic_year_completed`, `program_completed`) synchronized across `students` and `seminarians`.
     */
    function registrar_sync_graduation_metadata(
        mysqli $conn,
        int $student_id,
        bool $is_graduated,
        ?string $ay_completed = null,
        ?string $program_completed = null,
        ?string $completed_at = null,
        ?string $status_label = null
    ): void {
        if ($student_id <= 0) {
            return;
        }

        if ($is_graduated) {
            $resolved_ay = trim((string)$ay_completed);
            $resolved_prog = trim((string)$program_completed);

            if ($resolved_ay === '' || $resolved_prog === '') {
                $stmt_ctx = $conn->prepare(
                    "SELECT
                        COALESCE(NULLIF(TRIM(s.academic_year_completed), ''), NULLIF(TRIM(e.academic_year), ''), NULLIF(TRIM(s.current_academic_year), '')) AS best_ay,
                        COALESCE(NULLIF(TRIM(s.program_completed), ''), NULLIF(TRIM(e.program), ''), NULLIF(TRIM(s.program), ''), NULLIF(TRIM(stu.default_program), '')) AS best_prog
                     FROM students stu
                     LEFT JOIN seminarians s ON s.id = stu.id
                     LEFT JOIN enrollments e ON e.student_id = stu.id
                     WHERE stu.id = ?
                     ORDER BY e.academic_year DESC, FIELD(e.semester, 'Summer', '2nd', '1st') ASC, e.id DESC
                     LIMIT 1"
                );
                if ($stmt_ctx) {
                    $stmt_ctx->bind_param('i', $student_id);
                    $stmt_ctx->execute();
                    $ctx_row = $stmt_ctx->get_result()->fetch_assoc();
                    if ($resolved_ay === '') {
                        $resolved_ay = trim((string)($ctx_row['best_ay'] ?? ''));
                    }
                    if ($resolved_prog === '') {
                        $resolved_prog = trim((string)($ctx_row['best_prog'] ?? ''));
                    }
                }
            }

            if ($resolved_ay === '' && function_exists('get_current_academic_year')) {
                $resolved_ay = (string)get_current_academic_year();
            }
            if ($resolved_prog === '') {
                $resolved_prog = 'Bachelor of Arts in Philosophy';
            }

            $clean_date = trim((string)$completed_at);
            if ($clean_date === '' || $clean_date === '0000-00-00') {
                $clean_date = null;
            }
            $form_status = trim((string)$status_label);
            if ($form_status === '' || !registrar_is_graduated_status($form_status)) {
                $form_status = 'Graduated';
            }

            $stmt_stu = $conn->prepare(
                "UPDATE students SET
                    is_graduate = 1,
                    graduated_at = COALESCE(?, NULLIF(graduated_at, '0000-00-00'), CURDATE()),
                    formation_status = CASE
                        WHEN LOWER(TRIM(COALESCE(formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni') THEN formation_status
                        ELSE ?
                    END
                 WHERE id = ?"
            );
            if ($stmt_stu) {
                $stmt_stu->bind_param('ssi', $clean_date, $form_status, $student_id);
                $stmt_stu->execute();
            }

            $stmt_sem = $conn->prepare(
                "UPDATE seminarians SET
                    is_graduate = 1,
                    year_level = 'Completed',
                    formation_stage = 'Completed',
                    enrollment_status = 'Graduated',
                    formation_status = CASE
                        WHEN LOWER(TRIM(COALESCE(formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni') THEN formation_status
                        ELSE ?
                    END,
                    completed_at = COALESCE(?, NULLIF(completed_at, '0000-00-00'), CURDATE()),
                    academic_year_completed = COALESCE(NULLIF(TRIM(academic_year_completed), ''), ?),
                    program_completed = COALESCE(NULLIF(TRIM(program_completed), ''), ?)
                 WHERE id = ?"
            );
            if ($stmt_sem) {
                $stmt_sem->bind_param('ssssi', $form_status, $clean_date, $resolved_ay, $resolved_prog, $student_id);
                $stmt_sem->execute();
            }
        } else {
            $active_status = trim((string)$status_label);
            if ($active_status === '' || registrar_is_graduated_status($active_status)) {
                $active_status = 'Active';
            }
            $stmt_stu = $conn->prepare("UPDATE students SET is_graduate = 0, graduated_at = NULL, formation_status = ? WHERE id = ?");
            if ($stmt_stu) {
                $stmt_stu->bind_param('si', $active_status, $student_id);
                $stmt_stu->execute();
            }
            $stmt_sem = $conn->prepare(
                "UPDATE seminarians SET is_graduate = 0, completed_at = NULL, academic_year_completed = NULL, program_completed = NULL, formation_status = ? WHERE id = ?"
            );
            if ($stmt_sem) {
                $stmt_sem->bind_param('si', $active_status, $student_id);
                $stmt_sem->execute();
            }
        }
    }
}

if (!function_exists('registrar_backfill_graduation_metadata')) {
    /**
     * Backfills missing graduation metadata for any existing graduated students/seminarians.
     */
    function registrar_backfill_graduation_metadata(mysqli $conn): void
    {
        $sql = "SELECT
                    COALESCE(stu.id, s.id) AS student_id,
                    COALESCE(NULLIF(TRIM(s.academic_year_completed), ''), NULLIF(TRIM(le.academic_year), ''), NULLIF(TRIM(s.current_academic_year), '')) AS ay_completed,
                    COALESCE(NULLIF(TRIM(s.program_completed), ''), NULLIF(TRIM(le.program), ''), NULLIF(TRIM(s.program), ''), NULLIF(TRIM(stu.default_program), '')) AS program_completed,
                    COALESCE(NULLIF(CAST(s.completed_at AS CHAR), '0000-00-00'), NULLIF(CAST(stu.graduated_at AS CHAR), '0000-00-00'), DATE_FORMAT(le.updated_at, '%Y-%m-%d'), DATE_FORMAT(stu.updated_at, '%Y-%m-%d')) AS best_date,
                    COALESCE(NULLIF(TRIM(stu.formation_status), ''), NULLIF(TRIM(s.formation_status), ''), NULLIF(TRIM(s.enrollment_status), ''), 'Graduated') AS status_label
                FROM students stu
                LEFT JOIN seminarians s ON s.id = stu.id
                LEFT JOIN enrollments le ON le.id = (
                    SELECT e2.id FROM enrollments e2
                    WHERE e2.student_id = stu.id
                    ORDER BY e2.academic_year DESC, FIELD(e2.semester, 'Summer', '2nd', '1st') ASC, e2.id DESC
                    LIMIT 1
                )
                WHERE (
                    COALESCE(s.is_graduate, 0) = 1
                    OR COALESCE(stu.is_graduate, 0) = 1
                    OR (s.completed_at IS NOT NULL AND CAST(s.completed_at AS CHAR) NOT IN ('', '0000-00-00'))
                    OR (stu.graduated_at IS NOT NULL AND CAST(stu.graduated_at AS CHAR) NOT IN ('', '0000-00-00'))
                    OR LOWER(TRIM(COALESCE(s.enrollment_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni')
                    OR LOWER(TRIM(COALESCE(s.formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni')
                    OR LOWER(TRIM(COALESCE(stu.formation_status, ''))) IN ('graduated', 'graduate', 'completed', 'complete', 'alumni')
                    OR LOWER(TRIM(COALESCE(s.year_level, ''))) IN ('graduated', 'graduate', 'completed', 'alumni')
                    OR LOWER(TRIM(COALESCE(s.formation_stage, ''))) IN ('graduated', 'graduate', 'completed', 'alumni')
                    OR LOWER(TRIM(COALESCE(le.enrollment_status, ''))) IN ('graduated', 'graduate', 'alumni')
                    OR LOWER(TRIM(COALESCE(le.year_level, ''))) IN ('graduated', 'graduate', 'completed', 'alumni')
                )
                AND (
                    COALESCE(stu.is_graduate, 0) = 0
                    OR stu.graduated_at IS NULL
                    OR (s.id IS NOT NULL AND (COALESCE(s.is_graduate, 0) = 0 OR s.completed_at IS NULL OR TRIM(COALESCE(s.academic_year_completed, '')) = '' OR TRIM(COALESCE(s.program_completed, '')) = ''))
                )";
        $res = @$conn->query($sql);
        if (!$res) {
            return;
        }
        while ($row = $res->fetch_assoc()) {
            $sid = (int)($row['student_id'] ?? 0);
            if ($sid > 0) {
                registrar_sync_graduation_metadata(
                    $conn,
                    $sid,
                    true,
                    $row['ay_completed'] ?? null,
                    $row['program_completed'] ?? null,
                    $row['best_date'] ?? null,
                    $row['status_label'] ?? null
                );
            }
        }
    }
}

if (!function_exists('registrar_update_static_student_profile')) {
    /**
     * Updates ONLY the static Student Information (`students` table + `user_profiles` + `applicants`),
     * leaving all semester enrollment records (`enrollments`) untouched.
     */
    function registrar_update_static_student_profile(mysqli $conn, int $student_id, array $data, int $actor_user_id = 0): bool
    {
        if ($student_id <= 0) {
            return false;
        }

        $stmt_cur = $conn->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
        $stmt_cur->bind_param('i', $student_id);
        $stmt_cur->execute();
        $cur = $stmt_cur->get_result()->fetch_assoc();
        if (!$cur) {
            return false;
        }

        $first_name = trim((string)($data['first_name'] ?? $cur['first_name']));
        $middle_name = trim((string)($data['middle_name'] ?? $cur['middle_name']));
        $last_name = trim((string)($data['last_name'] ?? $cur['last_name']));
        $suffix = trim((string)($data['suffix'] ?? $cur['suffix']));
        $student_no = trim((string)($data['student_no'] ?? $cur['student_no']));

        $name_parts = array_values(array_filter([$first_name, $middle_name, $last_name], static fn($v) => $v !== ''));
        $full_name = trim(implode(' ', $name_parts) . ($suffix !== '' ? (' ' . $suffix) : ''));
        if ($full_name === '') {
            $full_name = (string)$cur['full_name'];
        }

        $dob = !empty($data['date_of_birth']) ? trim((string)$data['date_of_birth']) : $cur['date_of_birth'];
        $place_of_birth = isset($data['place_of_birth']) ? trim((string)$data['place_of_birth']) : $cur['place_of_birth'];
        $gender = isset($data['gender']) ? trim((string)$data['gender']) : $cur['gender'];
        $civil_status = isset($data['civil_status']) ? trim((string)$data['civil_status']) : $cur['civil_status'];
        $nationality = isset($data['nationality']) ? trim((string)$data['nationality']) : $cur['nationality'];
        $religion = isset($data['religion']) ? trim((string)$data['religion']) : $cur['religion'];
        $parish_priest = isset($data['parish_priest']) ? trim((string)$data['parish_priest']) : $cur['parish_priest'];
        $parish_chaplaincy = isset($data['parish_chaplaincy']) ? trim((string)$data['parish_chaplaincy']) : $cur['parish_chaplaincy'];
        $email = isset($data['email']) ? trim((string)$data['email']) : $cur['email'];
        $contact = isset($data['contact_number']) ? trim((string)$data['contact_number']) : $cur['contact_number'];
        $home_address = isset($data['home_address']) ? trim((string)$data['home_address']) : $cur['home_address'];
        $guardian_name = isset($data['guardian_name']) ? trim((string)$data['guardian_name']) : $cur['guardian_name'];
        $guardian_contact = isset($data['guardian_contact']) ? trim((string)$data['guardian_contact']) : $cur['guardian_contact'];
        $father_name = isset($data['father_name']) ? trim((string)$data['father_name']) : $cur['father_name'];
        $mother_name = isset($data['mother_name']) ? trim((string)$data['mother_name']) : $cur['mother_name'];
        $previous_school = isset($data['previous_school']) ? trim((string)$data['previous_school']) : $cur['previous_school'];
        $default_program = isset($data['default_program']) && trim((string)$data['default_program']) !== ''
            ? trim((string)$data['default_program'])
            : $cur['default_program'];
        $formation_status = isset($data['formation_status']) && trim((string)$data['formation_status']) !== ''
            ? trim((string)$data['formation_status'])
            : $cur['formation_status'];

        $conn->begin_transaction();
        try {
            $stmt_upd = $conn->prepare(
                "UPDATE students SET\n"
                . "  student_no = ?,\n"
                . "  first_name = ?,\n"
                . "  middle_name = ?,\n"
                . "  last_name = ?,\n"
                . "  suffix = ?,\n"
                . "  full_name = ?,\n"
                . "  date_of_birth = ?,\n"
                . "  place_of_birth = ?,\n"
                . "  gender = ?,\n"
                . "  civil_status = ?,\n"
                . "  nationality = ?,\n"
                . "  religion = ?,\n"
                . "  parish_priest = ?,\n"
                . "  parish_chaplaincy = ?,\n"
                . "  email = ?,\n"
                . "  contact_number = ?,\n"
                . "  home_address = ?,\n"
                . "  father_name = ?,\n"
                . "  mother_name = ?,\n"
                . "  guardian_name = ?,\n"
                . "  guardian_contact = ?,\n"
                . "  previous_school = ?,\n"
                . "  default_program = ?,\n"
                . "  formation_status = ?\n"
                . "WHERE id = ?"
            );
            $stmt_upd->bind_param(
                str_repeat('s', 24) . 'i',
                $student_no,
                $first_name,
                $middle_name,
                $last_name,
                $suffix,
                $full_name,
                $dob,
                $place_of_birth,
                $gender,
                $civil_status,
                $nationality,
                $religion,
                $parish_priest,
                $parish_chaplaincy,
                $email,
                $contact,
                $home_address,
                $father_name,
                $mother_name,
                $guardian_name,
                $guardian_contact,
                $previous_school,
                $default_program,
                $formation_status,
                $student_id
            );
            $stmt_upd->execute();

            // Sync static fields to `user_profiles` and `applicants` for system-wide consistency
            $user_id = (int)($cur['user_id'] ?? 0);
            if ($user_id > 0) {
                $stmt_up = $conn->prepare(
                    "UPDATE user_profiles SET\n"
                    . "  student_id = ?,\n"
                    . "  first_name = ?,\n"
                    . "  middle_name = ?,\n"
                    . "  last_name = ?,\n"
                    . "  suffix = ?,\n"
                    . "  full_name = ?,\n"
                    . "  date_of_birth = ?,\n"
                    . "  place_of_birth = ?,\n"
                    . "  gender = ?,\n"
                    . "  civil_status = ?,\n"
                    . "  nationality = ?,\n"
                    . "  religion = ?,\n"
                    . "  parish_priest = ?,\n"
                    . "  parish_chaplaincy = ?,\n"
                    . "  email = ?,\n"
                    . "  email_address = ?,\n"
                    . "  contact = ?,\n"
                    . "  contact_number = ?,\n"
                    . "  address = ?,\n"
                    . "  present_address = ?,\n"
                    . "  father_name = ?,\n"
                    . "  mother_name = ?,\n"
                    . "  guardian_name = ?,\n"
                    . "  guardian_contact_number = ?,\n"
                    . "  previous_school = ?,\n"
                    . "  updated_at = NOW()\n"
                    . "WHERE user_id = ?"
                );
                $stmt_up->bind_param(
                    str_repeat('s', 25) . 'i',
                    $student_no,
                    $first_name,
                    $middle_name,
                    $last_name,
                    $suffix,
                    $full_name,
                    $dob,
                    $place_of_birth,
                    $gender,
                    $civil_status,
                    $nationality,
                    $religion,
                    $parish_priest,
                    $parish_chaplaincy,
                    $email,
                    $email,
                    $contact,
                    $contact,
                    $home_address,
                    $home_address,
                    $father_name,
                    $mother_name,
                    $guardian_name,
                    $guardian_contact,
                    $previous_school,
                    $user_id
                );
                $stmt_up->execute();

                $stmt_app = $conn->prepare(
                    "UPDATE applicants SET full_name = ?, dob = ?, gender = ?, contact = ?, address = ? WHERE user_id = ? AND archived_at IS NULL"
                );
                $stmt_app->bind_param('sssssi', $full_name, $dob, $gender, $contact, $home_address, $user_id);
                $stmt_app->execute();
            }

            $stmt_sem = $conn->prepare("UPDATE seminarians SET formation_status = ? WHERE id = ?");
            $stmt_sem->bind_param('si', $formation_status, $student_id);
            $stmt_sem->execute();

            $is_grad_now = registrar_is_graduated_status($formation_status);
            $was_grad = ((int)($cur['is_graduate'] ?? 0) === 1) || registrar_is_graduated_status((string)($cur['formation_status'] ?? ''));
            if ($is_grad_now) {
                $active_ay_val = function_exists('get_current_academic_year') ? (string)get_current_academic_year() : null;
                registrar_sync_graduation_metadata($conn, $student_id, true, $active_ay_val, $default_program, null, $formation_status);
            } elseif ($was_grad) {
                registrar_sync_graduation_metadata($conn, $student_id, false, null, null, null, $formation_status);
            }

            if ($actor_user_id > 0 && function_exists('log_activity')) {
                log_activity($actor_user_id, "Updated static student profile for {$full_name} ({$student_no})");
            }

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}

if (!function_exists('registrar_has_completed_propaedeutic')) {
    /**
     * Checks whether a student has legitimately completed both 1st and 2nd Semesters
     * of the Propaedeutic Stage without uncompleted terms or failing backlogs.
     */
    function registrar_has_completed_propaedeutic(mysqli $conn, int $student_id): bool
    {
        if ($student_id <= 0) {
            return false;
        }

        // Check both 1st and 2nd semesters of Propaedeutic Stage in `enrollments` / `student_enrolled_subjects`
        foreach (['1st', '2nd'] as $sem) {
            $has_term = false;
            $term_completed = false;

            $stmt_e = $conn->prepare(
                "SELECT id, enrollment_status FROM enrollments
                 WHERE student_id = ? AND semester = ?
                   AND (year_level LIKE '%Propaedeutic%' OR program LIKE '%Propaedeutic%')
                 LIMIT 1"
            );
            if ($stmt_e) {
                $stmt_e->bind_param('is', $student_id, $sem);
                $stmt_e->execute();
                $erow = $stmt_e->get_result()->fetch_assoc();
                if ($erow) {
                    $has_term = true;
                    $est = (string)($erow['enrollment_status'] ?? '');
                    if (in_array($est, ['Completed', 'Graduated'], true)) {
                        $term_completed = true;
                    }
                }
            }

            if (!$has_term) {
                $stmt_ses = $conn->prepare(
                    "SELECT COUNT(*) AS c FROM student_enrolled_subjects
                     WHERE student_id = ? AND semester = ?
                       AND (year_level LIKE '%Propaedeutic%' OR program LIKE '%Propaedeutic%')
                       AND status <> 'Dropped'"
                );
                if ($stmt_ses) {
                    $stmt_ses->bind_param('is', $student_id, $sem);
                    $stmt_ses->execute();
                    $has_term = ((int)($stmt_ses->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
                }
            }

            if (!$has_term) {
                return false;
            }

            // Ensure no failing / incomplete grades in that Propaedeutic semester, and if not enrollment-completed, all enrolled subjects have passing grades
            $stmt_bad = $conn->prepare(
                "SELECT COUNT(*) AS bad_cnt
                 FROM student_enrolled_subjects es
                 LEFT JOIN student_grades sg ON (
                     sg.student_id = es.student_id
                     AND sg.subject_id = es.subject_id
                     AND sg.academic_year = es.academic_year
                     AND sg.semester = es.semester
                 )
                 WHERE es.student_id = ? AND es.semester = ?
                   AND (es.year_level LIKE '%Propaedeutic%' OR es.program LIKE '%Propaedeutic%')
                   AND es.status <> 'Dropped'
                   AND (
                       sg.status IN ('Incomplete', 'Dropped')
                       OR (sg.final_grade IS NOT NULL AND sg.final_grade > 0 AND sg.final_grade < 75.0)
                       OR (sg.final_computed_grade IS NOT NULL AND sg.final_computed_grade > 3.00)
                   )"
            );
            if ($stmt_bad) {
                $stmt_bad->bind_param('is', $student_id, $sem);
                $stmt_bad->execute();
                if (((int)($stmt_bad->get_result()->fetch_assoc()['bad_cnt'] ?? 0)) > 0) {
                    return false;
                }
            }

            if (!$term_completed) {
                $stmt_unpassed = $conn->prepare(
                    "SELECT COUNT(*) AS cnt
                     FROM student_enrolled_subjects es
                     LEFT JOIN student_grades sg ON (
                         sg.student_id = es.student_id
                         AND sg.subject_id = es.subject_id
                         AND sg.academic_year = es.academic_year
                         AND sg.semester = es.semester
                     )
                     WHERE es.student_id = ? AND es.semester = ?
                       AND (es.year_level LIKE '%Propaedeutic%' OR es.program LIKE '%Propaedeutic%')
                       AND es.status <> 'Dropped'
                       AND sg.grade_id IS NULL"
                );
                if ($stmt_unpassed) {
                    $stmt_unpassed->bind_param('is', $student_id, $sem);
                    $stmt_unpassed->execute();
                    if (((int)($stmt_unpassed->get_result()->fetch_assoc()['cnt'] ?? 0)) > 0) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}

if (!function_exists('registrar_validate_enrollment_guardrails')) {
    /**
     * Validates stage prerequisites, program/year-level alignment, and academic-year progression.
     * Returns null when allowed, or a user-facing error message when blocked.
     */
    function registrar_validate_enrollment_guardrails(
        mysqli $conn,
        int $student_id,
        string $target_ay,
        string $target_sem,
        string $target_yl,
        string $target_prog
    ): ?string {
        $target_ay = trim($target_ay);
        $target_sem = trim($target_sem);
        $target_yl = trim($target_yl);
        $target_prog = trim($target_prog);

        if ($student_id <= 0 || $target_ay === '') {
            return 'Invalid student or academic year.';
        }

        // 1. Validate academic year format and prevent far-future academic years (e.g., 2028-2029 when current AY is 2026-2027)
        if (!preg_match('/^(\d{4})-(\d{4})$/', $target_ay, $ay_m) || ((int)$ay_m[2] !== ((int)$ay_m[1] + 1))) {
            return "Invalid Academic Year '{$target_ay}'. Academic years must follow consecutive YYYY-YYYY format.";
        }
        $target_start_year = (int)$ay_m[1];

        $current_ay = function_exists('get_current_academic_year') ? (string)get_current_academic_year() : date('Y') . '-' . (date('Y') + 1);
        $cur_start_year = preg_match('/^(\d{4})-(\d{4})$/', $current_ay, $cur_m) ? (int)$cur_m[1] : (int)date('Y');

        if ($target_start_year > ($cur_start_year + 1)) {
            return "Enrollment blocked: Academic Year {$target_ay} is too far in the future (active Academic Year is {$current_ay}).";
        }

        // 2. Enforce program and year_level pairing (except Completed/Graduated)
        if ($target_yl === 'Propaedeutic Stage' && $target_prog !== '' && stripos($target_prog, 'Propaedeutic') === false) {
            return "Stage mismatch: 'Propaedeutic Stage' can only be enrolled under the 'Propaedeutic' program.";
        }
        if ($target_yl !== 'Propaedeutic Stage' && $target_yl !== 'Completed' && stripos($target_prog, 'Propaedeutic') !== false) {
            return "Stage mismatch: '{$target_yl}' cannot be paired with the 'Propaedeutic' program.";
        }

        // 3. Check student's current active enrollment and stage history
        $stmt_sem = $conn->prepare("SELECT year_level, program, current_academic_year FROM seminarians WHERE id = ? LIMIT 1");
        $sem_row = null;
        if ($stmt_sem) {
            $stmt_sem->bind_param('i', $student_id);
            $stmt_sem->execute();
            $sem_row = $stmt_sem->get_result()->fetch_assoc();
        }
        $cur_sem_yl = trim((string)($sem_row['year_level'] ?? ''));
        if ($cur_sem_yl !== '' && function_exists('normalize_year_level') && $cur_sem_yl !== 'Completed') {
            $cur_sem_yl = normalize_year_level($cur_sem_yl);
        }

        // Block jumping into a future Academic Year (> current_ay) while still holding an active/uncompleted enrollment in current_ay (especially 1st Sem -> must do 2nd Sem first)
        if ($target_start_year > $cur_start_year) {
            $stmt_act = $conn->prepare(
                "SELECT academic_year, semester, year_level, enrollment_status
                 FROM enrollments
                 WHERE student_id = ?
                   AND academic_year = ?
                   AND is_locked = 0
                   AND enrollment_status IN ('Pending', 'Enrolled', 'Partially Paid')
                 ORDER BY FIELD(semester, 'Summer', '2nd', '1st') ASC, id DESC
                 LIMIT 1"
            );
            if ($stmt_act) {
                $stmt_act->bind_param('is', $student_id, $current_ay);
                $stmt_act->execute();
                $act_row = $stmt_act->get_result()->fetch_assoc();
                if ($act_row && trim((string)$act_row['semester']) === '1st') {
                    return "Premature future-year enrollment blocked: Seminarian is still active in AY {$current_ay} (1st Semester — {$act_row['year_level']}) and must complete 2nd Semester before enrolling in AY {$target_ay}.";
                }
            }
        }

        // 4. Propaedeutic Stage completion prerequisite before entering Philosophy I..IV
        if (in_array($target_yl, ['First Year', 'Second Year', 'Third Year', 'Fourth Year'], true)) {
            $has_philo_history = false;
            $stmt_ph = $conn->prepare(
                "SELECT COUNT(*) AS c FROM enrollments
                 WHERE student_id = ? AND year_level IN ('First Year', 'Second Year', 'Third Year', 'Fourth Year')"
            );
            if ($stmt_ph) {
                $stmt_ph->bind_param('i', $student_id);
                $stmt_ph->execute();
                $has_philo_history = ((int)($stmt_ph->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
            }

            $is_currently_prop = ($cur_sem_yl === 'Propaedeutic Stage');
            if ($is_currently_prop || !$has_philo_history) {
                // Only block if student actually entered via Propaedeutic Stage (or is currently in Propaedeutic Stage)
                $has_any_prop = $is_currently_prop;
                if (!$has_any_prop) {
                    $stmt_pr = $conn->prepare("SELECT COUNT(*) AS c FROM enrollments WHERE student_id = ? AND (year_level LIKE '%Propaedeutic%' OR program LIKE '%Propaedeutic%')");
                    if ($stmt_pr) {
                        $stmt_pr->bind_param('i', $student_id);
                        $stmt_pr->execute();
                        $has_any_prop = ((int)($stmt_pr->get_result()->fetch_assoc()['c'] ?? 0)) > 0;
                    }
                }
                if ($has_any_prop && !registrar_has_completed_propaedeutic($conn, $student_id)) {
                    return "Stage prerequisite not met: Seminarian cannot be enrolled or promoted to {$target_yl} (Bachelor of Arts in Philosophy) until both 1st and 2nd Semesters of Propaedeutic Stage are officially completed.";
                }
            }
        }

        return null;
    }
}

if (!function_exists('registrar_filter_subjects_for_stage')) {
    /**
     * Strictly restricts a list of subject IDs to active subjects mapped to the exact
     * curriculum_version_id and year_level so subjects from higher/other stages are never enlisted.
     */
    function registrar_filter_subjects_for_stage(mysqli $conn, int $curriculum_version_id, string $year_level, array $subject_ids): array
    {
        $clean_ids = array_values(array_unique(array_filter(array_map('intval', $subject_ids), static fn ($v) => $v > 0)));
        if (empty($clean_ids) || $curriculum_version_id <= 0 || trim($year_level) === '' || $year_level === 'Completed') {
            return $clean_ids;
        }

        $allowed_map = [];
        $stmt = $conn->prepare(
            "SELECT DISTINCT cs.subject_id
             FROM curriculum_subjects cs
             JOIN subjects s ON s.subject_id = cs.subject_id
             WHERE cs.curriculum_version_id = ? AND cs.year_level = ? AND cs.is_active = 1 AND s.archived_at IS NULL"
        );
        if (!$stmt) {
            return $clean_ids;
        }
        $stmt->bind_param('is', $curriculum_version_id, $year_level);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $allowed_map[(int)$r['subject_id']] = true;
        }
        if (empty($allowed_map)) {
            return $clean_ids;
        }
        return array_values(array_filter($clean_ids, static fn ($sid) => isset($allowed_map[$sid])));
    }
}

if (!function_exists('registrar_enroll_student_in_term')) {
    /**
     * Enrolls a student into a specific (academic_year, semester) inside `enrollments`
     * without overwriting any previous semester enrollment records.
     * Automatically locks prior semesters as historical records.
     */
    function registrar_enroll_student_in_term(
        mysqli $conn,
        int $student_id,
        string $academic_year,
        string $semester,
        string $year_level,
        string $program = '',
        string $standing = 'Regular',
        array $subject_ids = [],
        ?string $remarks = null,
        int $actor_user_id = 0
    ): array {
        if ($student_id <= 0 || trim($academic_year) === '') {
            return ['ok' => false, 'error' => 'Invalid student or academic year.'];
        }
        if (!in_array($semester, ['1st', '2nd', 'Summer'], true)) {
            $semester = '1st';
        }
        if (!in_array($standing, ['Regular', 'Irregular', 'Probationary'], true)) {
            $standing = 'Regular';
        }

        // Ensure student exists in `students`
        $stmt_st = $conn->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
        $stmt_st->bind_param('i', $student_id);
        $stmt_st->execute();
        $student = $stmt_st->get_result()->fetch_assoc();
        if (!$student) {
            registrar_sync_and_migrate($conn, true);
            $stmt_st->execute();
            $student = $stmt_st->get_result()->fetch_assoc();
            if (!$student) {
                // Direct fallback: create the `students` record from `seminarians` + `user_profiles` + `users`
                $stmt_sem_fb = $conn->prepare(
                    "SELECT s.*, u.username, up.student_id AS up_student_no, up.full_name AS up_full_name, up.first_name, up.last_name\n"
                    . "FROM seminarians s\n"
                    . "LEFT JOIN users u ON u.id = s.user_id\n"
                    . "LEFT JOIN user_profiles up ON up.user_id = s.user_id\n"
                    . "WHERE s.id = ? LIMIT 1"
                );
                if ($stmt_sem_fb) {
                    $stmt_sem_fb->bind_param('i', $student_id);
                    $stmt_sem_fb->execute();
                    $sem_fb = $stmt_sem_fb->get_result()->fetch_assoc();
                    if ($sem_fb) {
                        $fb_uid = !empty($sem_fb['user_id']) ? (int)$sem_fb['user_id'] : null;
                        $fb_sno = trim((string)($sem_fb['up_student_no'] ?: ('SEM-' . substr($academic_year, 0, 4) . '-' . str_pad((string)$student_id, 4, '0', STR_PAD_LEFT))));
                        $fb_name = trim((string)($sem_fb['up_full_name'] ?: ($sem_fb['username'] ?: ('Seminarian #' . $student_id))));
                        $fb_fn = trim((string)($sem_fb['first_name'] ?: $fb_name));
                        $fb_ln = trim((string)($sem_fb['last_name'] ?: (string)$student_id));
                        $fb_prog = trim((string)($sem_fb['program'] ?: 'Bachelor of Arts in Philosophy'));
                        $stmt_ins_fb = $conn->prepare(
                            "INSERT IGNORE INTO students (id, user_id, student_no, first_name, last_name, full_name, admission_academic_year, default_program, formation_status)\n"
                            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')"
                        );
                        if ($stmt_ins_fb) {
                            $stmt_ins_fb->bind_param('iissssss', $student_id, $fb_uid, $fb_sno, $fb_fn, $fb_ln, $fb_name, $academic_year, $fb_prog);
                            $stmt_ins_fb->execute();
                        }
                        $stmt_st->execute();
                        $student = $stmt_st->get_result()->fetch_assoc();
                    }
                }
            }
            if (!$student) {
                return ['ok' => false, 'error' => 'Student profile not found.'];
            }
        }

        if (function_exists('normalize_year_level') && $year_level !== 'Completed') {
            $norm_yl = normalize_year_level($year_level);
            if ($norm_yl !== '') {
                $year_level = $norm_yl;
            }
        }

        if ($program === '' || $year_level === 'Propaedeutic Stage') {
            $program = ($year_level === 'Propaedeutic Stage')
                ? 'Propaedeutic'
                : (string)($student['default_program'] ?: 'Bachelor of Arts in Philosophy');
        }
        if ($year_level !== 'Propaedeutic Stage' && $year_level !== 'Completed' && stripos($program, 'Propaedeutic') !== false) {
            $program = 'Bachelor of Arts in Philosophy';
        }

        $guard_error = registrar_validate_enrollment_guardrails($conn, $student_id, $academic_year, $semester, $year_level, $program);
        if ($guard_error !== null) {
            return ['ok' => false, 'error' => $guard_error];
        }

        // Resolve curriculum version
        $curriculum_version_id = 0;
        $stmt_cv = $conn->prepare(
            "SELECT id FROM curriculum_versions WHERE program = ? AND is_active = 1 AND archived_at IS NULL ORDER BY is_current DESC, id DESC LIMIT 1"
        );
        if ($stmt_cv) {
            $stmt_cv->bind_param('s', $program);
            $stmt_cv->execute();
            $cv_row = $stmt_cv->get_result()->fetch_assoc();
            $curriculum_version_id = (int)($cv_row['id'] ?? 0);
        }

        // Auto-load curriculum subjects if none explicitly passed and year_level is not Completed
        if (empty($subject_ids) && $curriculum_version_id > 0 && $year_level !== 'Completed') {
            $stmt_cs = $conn->prepare(
                "SELECT cs.subject_id FROM curriculum_subjects cs\n"
                . "JOIN subjects s ON s.subject_id = cs.subject_id\n"
                . "WHERE cs.curriculum_version_id = ? AND cs.year_level = ? AND cs.semester = ? AND cs.is_active = 1 AND s.archived_at IS NULL"
            );
            $stmt_cs->bind_param('iss', $curriculum_version_id, $year_level, $semester);
            $stmt_cs->execute();
            $res_cs = $stmt_cs->get_result();
            while ($r = $res_cs->fetch_assoc()) {
                $subject_ids[] = (int)$r['subject_id'];
            }
        } elseif (!empty($subject_ids) && $curriculum_version_id > 0 && $year_level !== 'Completed') {
            // Ensure explicitly passed subjects strictly match the student's current program and year_level
            $subject_ids = registrar_filter_subjects_for_stage($conn, $curriculum_version_id, $year_level, $subject_ids);
        }

        // Calculate fee assessment for this term
        $bal_info = lodging_get_seminarian_balance($conn, $student_id, $academic_year, $semester);
        $assessed_fee = (float)($bal_info['current_term_due'] ?? 23000.0);
        $paid_amt = (float)($bal_info['total_paid'] ?? 0.0);
        $fee_bal = max(0.0, round($assessed_fee - $paid_amt, 2));

        $conn->begin_transaction();
        try {
            // 1. Archive/Lock prior semesters in `enrollments` so historical records remain immutable
            $stmt_lock_prev = $conn->prepare(
                "UPDATE enrollments\n"
                . "SET is_locked = 1,\n"
                . "    enrollment_status = CASE WHEN enrollment_status IN ('Enrolled', 'Partially Paid') THEN 'Completed' ELSE enrollment_status END\n"
                . "WHERE student_id = ? AND NOT (academic_year = ? AND semester = ?)"
            );
            $stmt_lock_prev->bind_param('iss', $student_id, $academic_year, $semester);
            $stmt_lock_prev->execute();

            // 2. Insert or update this specific term's `enrollments` record
            // Payment-gated enrollment rule: term stays 'Pending' until cumulative Cashier payments >= PHP 500.00
            $enr_status = ($year_level === 'Completed')
                ? 'Graduated'
                : ($paid_amt >= 500.0 ? 'Enrolled' : 'Pending');
            $is_locked = ($year_level === 'Completed') ? 1 : 0;
            $cv_nullable = $curriculum_version_id > 0 ? $curriculum_version_id : null;
            $actor_nullable = $actor_user_id > 0 ? $actor_user_id : null;

            $stmt_ins = $conn->prepare(
                "INSERT INTO enrollments (\n"
                . "  student_id, academic_year, semester, program, year_level, curriculum_version_id,\n"
                . "  academic_standing, enrollment_status, total_assessed_fee, total_paid, fee_balance,\n"
                . "  remarks, enrolled_by, enrolled_at, is_locked\n"
                . ") VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)\n"
                . "ON DUPLICATE KEY UPDATE\n"
                . "  program = VALUES(program),\n"
                . "  year_level = VALUES(year_level),\n"
                . "  curriculum_version_id = VALUES(curriculum_version_id),\n"
                . "  academic_standing = VALUES(academic_standing),\n"
                . "  enrollment_status = VALUES(enrollment_status),\n"
                . "  total_assessed_fee = VALUES(total_assessed_fee),\n"
                . "  total_paid = VALUES(total_paid),\n"
                . "  fee_balance = VALUES(fee_balance),\n"
                . "  remarks = COALESCE(VALUES(remarks), remarks),\n"
                . "  is_locked = VALUES(is_locked)"
            );
            $stmt_ins->bind_param(
                'issssisssddsii',
                $student_id,
                $academic_year,
                $semester,
                $program,
                $year_level,
                $cv_nullable,
                $standing,
                $enr_status,
                $assessed_fee,
                $paid_amt,
                $fee_bal,
                $remarks,
                $actor_nullable,
                $is_locked
            );
            $stmt_ins->execute();

            $stmt_get_id = $conn->prepare("SELECT id FROM enrollments WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
            $stmt_get_id->bind_param('iss', $student_id, $academic_year, $semester);
            $stmt_get_id->execute();
            $enrollment_id = (int)($stmt_get_id->get_result()->fetch_assoc()['id'] ?? 0);

            // 3. Insert subjects into `enrollment_subjects` AND `student_enrolled_subjects`
            $subjects_added = 0;
            if ($enrollment_id > 0 && !empty($subject_ids)) {
                $stmt_sub_info = $conn->prepare("SELECT units FROM subjects WHERE subject_id = ? LIMIT 1");
                $stmt_es_ins = $conn->prepare(
                    "INSERT INTO enrollment_subjects (enrollment_id, subject_id, units, subject_status)\n"
                    . "VALUES (?, ?, ?, 'Enrolled')\n"
                    . "ON DUPLICATE KEY UPDATE units = VALUES(units)"
                );
                $stmt_sem_sub = $conn->prepare(
                    "INSERT IGNORE INTO semester_subjects (subject_id, semester, academic_year, year_level) VALUES (?, ?, ?, ?)"
                );
                $stmt_legacy_es = $conn->prepare(
                    "INSERT IGNORE INTO student_enrolled_subjects (student_id, subject_id, curriculum_version_id, program, year_level, semester, academic_year, status)\n"
                    . "VALUES (?, ?, ?, ?, ?, ?, ?, 'Enrolled')"
                );

                foreach (array_unique(array_map('intval', $subject_ids)) as $sub_id) {
                    if ($sub_id <= 0) {
                        continue;
                    }
                    $stmt_sub_info->bind_param('i', $sub_id);
                    $stmt_sub_info->execute();
                    $u_row = $stmt_sub_info->get_result()->fetch_assoc();
                    $units = (float)($u_row['units'] ?? 3.0);

                    $stmt_es_ins->bind_param('iid', $enrollment_id, $sub_id, $units);
                    $stmt_es_ins->execute();

                    $stmt_sem_sub->bind_param('isss', $sub_id, $semester, $academic_year, $year_level);
                    $stmt_sem_sub->execute();

                    $stmt_legacy_es->bind_param('iiissss', $student_id, $sub_id, $cv_nullable, $program, $year_level, $semester, $academic_year);
                    $stmt_legacy_es->execute();
                    $subjects_added++;
                }
            }

            // 4. Sync active pointer on `seminarians` for backward compatibility with Cashier & Formators
            $sem_standing = ($standing === 'Irregular') ? 'Irregular' : 'Regular';
            if ($year_level === 'Completed') {
                $stmt_upd_sem = $conn->prepare(
                    "UPDATE seminarians SET current_academic_year = ?, year_level = 'Completed', formation_stage = 'Completed', program = ?, academic_status = ?, enrollment_status = 'Graduated', formation_status = 'Graduated', is_graduate = 1, completed_at = COALESCE(completed_at, CURDATE()), academic_year_completed = ?, program_completed = ? WHERE id = ?"
                );
                $stmt_upd_sem->bind_param('sssssi', $academic_year, $program, $sem_standing, $academic_year, $program, $student_id);
                $stmt_upd_sem->execute();

                $conn->query("UPDATE students SET is_graduate = 1, graduated_at = COALESCE(graduated_at, CURDATE()), formation_status = 'Graduated' WHERE id = " . (int)$student_id);
            } else {
                $stmt_upd_sem = $conn->prepare(
                    "UPDATE seminarians SET current_academic_year = ?, year_level = ?, program = ?, academic_status = ?, enrollment_status = ? WHERE id = ?"
                );
                $stmt_upd_sem->bind_param('sssssi', $academic_year, $year_level, $program, $sem_standing, $enr_status, $student_id);
                $stmt_upd_sem->execute();
            }

            if ($actor_user_id > 0 && function_exists('log_activity')) {
                log_activity($actor_user_id, "Enrolled student ID #{$student_id} in {$academic_year} ({$semester} Sem) - {$year_level} ({$subjects_added} subjects) [{$enr_status}]");
            }

            $conn->commit();
            return [
                'ok' => true,
                'enrollment_id' => $enrollment_id,
                'enrollment_status' => $enr_status,
                'subjects_count' => $subjects_added,
                'academic_year' => $academic_year,
                'semester' => $semester,
                'year_level' => $year_level,
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

// Automatically initialize decoupled schema and sync existing records in local/migration contexts
$__is_serverless_or_prod = function_exists('enformasys_env')
    && (enformasys_is_production()
        || enformasys_env('VERCEL', '') !== ''
        || enformasys_env('VERCEL_ENV', '') !== ''
        || strtolower(trim((string)enformasys_env('DB_SKIP_SCHEMA_SYNC', 'false'))) === 'true');
if (isset($conn) && $conn instanceof mysqli && (defined('ENFORMASYS_ALLOW_RUNTIME_SCHEMA_SYNC') || !$__is_serverless_or_prod)) {
    registrar_sync_and_migrate($conn, false);
}

