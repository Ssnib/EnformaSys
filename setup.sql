CREATE DATABASE IF NOT EXISTS seminary_db;

USE seminary_db;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS lodging_payment_allocations;
DROP TABLE IF EXISTS lodging_invoice_items;
DROP TABLE IF EXISTS lodging_payments;
DROP TABLE IF EXISTS lodging_invoices;
DROP TABLE IF EXISTS lodging_stays;
DROP TABLE IF EXISTS lodging_rooms;

DROP TABLE IF EXISTS academic_evaluation_results;
DROP TABLE IF EXISTS academic_evaluation_responses;
DROP TABLE IF EXISTS academic_evaluation_questions;
DROP TABLE IF EXISTS academic_evaluations;

DROP TABLE IF EXISTS spiritual_analytics;
DROP TABLE IF EXISTS spiritual_results;
DROP TABLE IF EXISTS spiritual_remarks;
DROP TABLE IF EXISTS spiritual_responses;
DROP TABLE IF EXISTS spiritual_questions;
DROP TABLE IF EXISTS spiritual_evaluations;

DROP TABLE IF EXISTS human_analytics;
DROP TABLE IF EXISTS human_results;
DROP TABLE IF EXISTS human_remarks;
DROP TABLE IF EXISTS human_responses;
DROP TABLE IF EXISTS human_questions;
DROP TABLE IF EXISTS human_evaluations;

DROP TABLE IF EXISTS pastoral_analytics;
DROP TABLE IF EXISTS pastoral_results;
DROP TABLE IF EXISTS pastoral_remarks;
DROP TABLE IF EXISTS pastoral_responses;
DROP TABLE IF EXISTS pastoral_questions;
DROP TABLE IF EXISTS pastoral_evaluations;

DROP TABLE IF EXISTS academic_notifications;
DROP TABLE IF EXISTS irregular_students;
DROP TABLE IF EXISTS academic_progress;
DROP TABLE IF EXISTS gpa_records;
DROP TABLE IF EXISTS student_grades;
DROP TABLE IF EXISTS semester_subjects;
DROP TABLE IF EXISTS subjects;

DROP TABLE IF EXISTS grades;
DROP TABLE IF EXISTS evaluations;
DROP TABLE IF EXISTS activities;
DROP TABLE IF EXISTS user_profiles;
DROP TABLE IF EXISTS seminarian_assignments;
DROP TABLE IF EXISTS seminarians;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS timetable;
DROP TABLE IF EXISTS applicants;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('Student', 'Registrar', 'Dean', 'AcademicFormator', 'SpiritualDirector', 'HumanFormator', 'PastoralFormator', 'Cashier', 'Shrine') NOT NULL
);

CREATE TABLE user_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    full_name VARCHAR(120) NULL,
    email VARCHAR(120) NULL,
    contact VARCHAR(40) NULL,
    address TEXT NULL,
    parish_priest VARCHAR(150) NULL,
    parish_chaplaincy VARCHAR(150) NULL,
    updated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_profiles_user (user_id),
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE applicants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    dob DATE NOT NULL,
    age INT,
    gender ENUM('Male', 'Female', 'Other'),
    contact VARCHAR(20),
    address TEXT,
    academic_history TEXT,
    parent_info TEXT,
    academic_year VARCHAR(9) NULL,
    program VARCHAR(80) NULL,
    status ENUM('Pending', 'Enrolled') DEFAULT 'Pending',
    user_id INT NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_applicants_user_id (user_id),
    CONSTRAINT fk_applicants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE seminarians (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    year_level VARCHAR(50) NULL,
    formation_stage VARCHAR(50) NULL,
    academic_status ENUM('Regular','Irregular') NOT NULL DEFAULT 'Regular',
    enrollment_status VARCHAR(30) NULL,
    academic_remarks TEXT NULL,
    academic_view_enabled TINYINT(1) NOT NULL DEFAULT 0,
    spiritual_eval_view_enabled TINYINT(1) NOT NULL DEFAULT 0,
    human_eval_view_enabled TINYINT(1) NOT NULL DEFAULT 0,
    pastoral_eval_view_enabled TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE seminarian_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    assignment_type VARCHAR(30) NOT NULL,
    assigned_user_id INT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_assignment (seminarian_id, assignment_type),
    KEY idx_assignment_user (assigned_user_id),
    KEY idx_assignment_type (assignment_type, active),
    CONSTRAINT fk_sa_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_sa_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS seminarian_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    document_type VARCHAR(100) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NULL DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_seminarian_id (seminarian_id),
    CONSTRAINT fk_seminarian_documents_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(30) NOT NULL,
    subject_name VARCHAR(150) NOT NULL,
    units DECIMAL(3,1) NOT NULL DEFAULT 0.0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_subject_code (subject_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE semester_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    semester ENUM('1st','2nd','Summer') NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    year_level VARCHAR(50) NULL,
    formation_stage VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_semester_subject (subject_id, semester, academic_year, year_level),
    KEY idx_semester_subjects_year_level (year_level),
    KEY idx_semester_subjects_ay_sem (academic_year, semester),
    CONSTRAINT fk_semester_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE student_grades (
    grade_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    semester ENUM('1st','2nd','Summer') NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    prelim_grade DECIMAL(5,2) NULL,
    midterm_grade DECIMAL(5,2) NULL,
    final_grade DECIMAL(5,2) NULL,
    final_computed_grade DECIMAL(4,2) NULL,
    status ENUM('Completed','Incomplete','Dropped') NOT NULL DEFAULT 'Completed',
    remarks TEXT NULL,
    assessed_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    KEY idx_student_grades_student (student_id),
    KEY idx_student_grades_ay_sem (academic_year, semester),
    UNIQUE KEY uniq_student_subject_term (student_id, subject_id, semester, academic_year),
    CONSTRAINT fk_student_grades_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_grades_subject FOREIGN KEY (subject_id) REFERENCES subjects(subject_id),
    CONSTRAINT fk_student_grades_assessor FOREIGN KEY (assessed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE gpa_records (
    gpa_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester ENUM('1st','2nd','Summer') NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    semester_gpa DECIMAL(4,2) NULL,
    cumulative_gpa DECIMAL(4,2) NULL,
    performance_category VARCHAR(30) NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_gpa_record (student_id, semester, academic_year),
    KEY idx_gpa_records_student (student_id),
    CONSTRAINT fk_gpa_records_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academic_progress (
    progress_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester ENUM('1st','2nd','Summer') NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    previous_gpa DECIMAL(4,2) NULL,
    current_gpa DECIMAL(4,2) NULL,
    growth_status ENUM('Growing','Maintaining','Declining') NULL,
    growth_percentage DECIMAL(6,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_progress_record (student_id, semester, academic_year),
    KEY idx_progress_student (student_id),
    CONSTRAINT fk_progress_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE irregular_students (
    irregular_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    semester ENUM('1st','2nd','Summer') NULL,
    academic_year VARCHAR(9) NULL,
    reason TEXT NOT NULL,
    date_flagged DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Active','Resolved') NOT NULL DEFAULT 'Active',
    UNIQUE KEY uniq_irregular_term (student_id, semester, academic_year),
    KEY idx_irregular_student (student_id),
    KEY idx_irregular_status (status),
    CONSTRAINT fk_irregular_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academic_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_user_id INT NOT NULL,
    student_id INT NULL,
    type VARCHAR(40) NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    KEY idx_acad_notif_recipient (recipient_user_id, read_at),
    KEY idx_acad_notif_student (student_id),
    CONSTRAINT fk_acad_notif_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_acad_notif_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academic_evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    formator_id INT NOT NULL,
    gpa DECIMAL(4,2) NULL,
    academic_year VARCHAR(9) NOT NULL,
    semester ENUM('1st','2nd','Summer') NOT NULL,
    evaluation_date DATE NOT NULL,
    remarks TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    archived_at DATETIME NULL,
    KEY idx_ae_student (student_id),
    KEY idx_ae_term (academic_year, semester),
    KEY idx_ae_formator (formator_id),
    CONSTRAINT fk_ae_student FOREIGN KEY (student_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_ae_formator FOREIGN KEY (formator_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academic_evaluation_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(60) NOT NULL,
    question_text VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    KEY idx_aeq_active (is_active),
    KEY idx_aeq_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academic_evaluation_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    question_id INT NOT NULL,
    rating TINYINT NOT NULL,
    UNIQUE KEY uniq_aer (evaluation_id, question_id),
    KEY idx_aer_eval (evaluation_id),
    KEY idx_aer_q (question_id),
    CONSTRAINT fk_aer_eval FOREIGN KEY (evaluation_id) REFERENCES academic_evaluations(id) ON DELETE CASCADE,
    CONSTRAINT fk_aer_q FOREIGN KEY (question_id) REFERENCES academic_evaluation_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE academic_evaluation_results (
    evaluation_id INT PRIMARY KEY,
    total_score INT NOT NULL,
    average_score DECIMAL(4,2) NOT NULL,
    rating_label VARCHAR(30) NOT NULL,
    recommendation VARCHAR(80) NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aeres_eval FOREIGN KEY (evaluation_id) REFERENCES academic_evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO academic_evaluation_questions (category, question_text, sort_order, is_active) VALUES
('Study Habits','Maintains a consistent daily study schedule.',1,1),
('Study Habits','Uses effective note-taking strategies during lectures.',2,1),
('Study Habits','Reviews lessons regularly even without upcoming exams.',3,1),
('Study Habits','Studies in a manner that promotes deep understanding, not memorization.',4,1),
('Study Habits','Sets clear academic goals for each semester.',5,1),
('Time Management','Plans weekly tasks and study time ahead of schedule.',6,1),
('Time Management','Prioritizes academic responsibilities over distractions.',7,1),
('Time Management','Balances formation activities and academic workload effectively.',8,1),
('Time Management','Avoids procrastination in completing academic tasks.',9,1),
('Time Management','Uses time efficiently during free periods.',10,1),
('Class Attendance','Attends classes consistently and on time.',11,1),
('Class Attendance','Shows attentiveness during class sessions.',12,1),
('Class Attendance','Participates actively in class discussions when appropriate.',13,1),
('Class Attendance','Comes to class prepared with required materials.',14,1),
('Class Attendance','Demonstrates respect for teachers and classmates during classes.',15,1),
('Academic Responsibility','Submits assignments and requirements on time.',16,1),
('Academic Responsibility','Follows academic policies and classroom rules.',17,1),
('Academic Responsibility','Demonstrates honesty and integrity in academic work.',18,1),
('Academic Responsibility','Accepts accountability for academic performance outcomes.',19,1),
('Academic Responsibility','Communicates responsibly with instructors regarding requirements.',20,1),
('Critical Thinking','Analyzes academic concepts with depth and clarity.',21,1),
('Critical Thinking','Applies learned concepts to new situations or cases.',22,1),
('Critical Thinking','Demonstrates sound problem-solving skills in academic tasks.',23,1),
('Critical Thinking','Forms independent and well-reasoned conclusions.',24,1),
('Critical Thinking','Asks thoughtful questions that reflect understanding.',25,1),
('Learning Attitude','Shows openness to feedback and correction.',26,1),
('Learning Attitude','Demonstrates desire for continuous academic improvement.',27,1),
('Learning Attitude','Shows intellectual curiosity beyond course requirements.',28,1),
('Seminary Academic Performance','Maintains consistent academic performance across subjects.',29,1),
('Seminary Academic Performance','Demonstrates academic discipline in meeting expectations.',30,1);

CREATE TABLE spiritual_evaluations (
    evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    spiritual_director_id INT NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    semester ENUM('1st','2nd','Summer') NOT NULL,
    evaluation_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    archived_at DATETIME NULL,
    KEY idx_se_student (seminarian_id),
    KEY idx_se_term (academic_year, semester),
    KEY idx_se_director (spiritual_director_id),
    CONSTRAINT fk_se_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_se_director FOREIGN KEY (spiritual_director_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE spiritual_questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL,
    question_text VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    KEY idx_sq_active (is_active),
    KEY idx_sq_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE spiritual_responses (
    response_id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    question_id INT NOT NULL,
    rating TINYINT NOT NULL,
    UNIQUE KEY uniq_sresp (evaluation_id, question_id),
    KEY idx_sresp_eval (evaluation_id),
    KEY idx_sresp_q (question_id),
    CONSTRAINT fk_sresp_eval FOREIGN KEY (evaluation_id) REFERENCES spiritual_evaluations(evaluation_id) ON DELETE CASCADE,
    CONSTRAINT fk_sresp_q FOREIGN KEY (question_id) REFERENCES spiritual_questions(question_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE spiritual_remarks (
    remark_id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    strengths TEXT NULL,
    areas_for_growth TEXT NULL,
    challenges TEXT NULL,
    recommendations TEXT NULL,
    formation_plan TEXT NULL,
    follow_up_schedule TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_srem_eval (evaluation_id),
    CONSTRAINT fk_srem_eval FOREIGN KEY (evaluation_id) REFERENCES spiritual_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE spiritual_results (
    evaluation_id INT PRIMARY KEY,
    total_score INT NOT NULL,
    average_rating DECIMAL(4,2) NOT NULL,
    interpretation VARCHAR(60) NOT NULL,
    prayer_life_avg DECIMAL(4,2) NULL,
    sacramental_participation_avg DECIMAL(4,2) NULL,
    spiritual_discipline_avg DECIMAL(4,2) NULL,
    relationship_vocation_avg DECIMAL(4,2) NULL,
    christian_virtues_avg DECIMAL(4,2) NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sres_eval FOREIGN KEY (evaluation_id) REFERENCES spiritual_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE spiritual_analytics (
    analytics_id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    evaluation_id INT NOT NULL,
    previous_score DECIMAL(4,2) NULL,
    current_score DECIMAL(4,2) NULL,
    growth_status ENUM('Growing','Stable','At Risk') NULL,
    growth_percentage DECIMAL(6,2) NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_sa_eval (evaluation_id),
    KEY idx_sa_student (seminarian_id),
    CONSTRAINT fk_sa_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_eval FOREIGN KEY (evaluation_id) REFERENCES spiritual_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO spiritual_questions (category, question_text, sort_order, is_active) VALUES
('Prayer Life','Demonstrates consistency in personal prayer.',1,1),
('Prayer Life','Participates actively in community prayer activities.',2,1),
('Prayer Life','Maintains a regular schedule for spiritual reflection.',3,1),
('Prayer Life','Shows attentiveness and reverence during prayer.',4,1),
('Prayer Life','Integrates prayer into daily decision-making.',5,1),
('Prayer Life','Demonstrates a sincere desire to deepen his prayer life.',6,1),
('Sacramental Participation','Participates faithfully in the Holy Eucharist.',7,1),
('Sacramental Participation','Approaches the Sacrament of Reconciliation regularly.',8,1),
('Sacramental Participation','Demonstrates reverence during liturgical celebrations.',9,1),
('Sacramental Participation','Understands the importance of sacramental life.',10,1),
('Sacramental Participation','Actively participates in liturgical ministries when assigned.',11,1),
('Sacramental Participation','Exhibits devotion and respect during worship.',12,1),
('Spiritual Discipline','Practices self-discipline in daily life.',13,1),
('Spiritual Discipline','Demonstrates commitment to spiritual exercises.',14,1),
('Spiritual Discipline','Fulfills assigned spiritual obligations responsibly.',15,1),
('Spiritual Discipline','Shows perseverance in spiritual commitments.',16,1),
('Spiritual Discipline','Uses time effectively for spiritual growth.',17,1),
('Spiritual Discipline','Demonstrates consistency in spiritual practices.',18,1),
('Relationship with God & Vocation','Shows a growing awareness of God''s presence.',19,1),
('Relationship with God & Vocation','Demonstrates trust in God''s providence.',20,1),
('Relationship with God & Vocation','Reflects seriously on his vocation.',21,1),
('Relationship with God & Vocation','Displays commitment to priestly formation.',22,1),
('Relationship with God & Vocation','Shows openness to God''s will.',23,1),
('Relationship with God & Vocation','Demonstrates authenticity in his vocational discernment.',24,1),
('Christian Virtues & Community Spirituality','Demonstrates humility in daily interactions.',25,1),
('Christian Virtues & Community Spirituality','Practices charity toward others.',26,1),
('Christian Virtues & Community Spirituality','Shows patience during challenges.',27,1),
('Christian Virtues & Community Spirituality','Forgives others readily and sincerely.',28,1),
('Christian Virtues & Community Spirituality','Promotes peace and unity within the community.',29,1),
('Christian Virtues & Community Spirituality','Serves as a positive spiritual example to fellow seminarians.',30,1);

CREATE TABLE human_evaluations (
    evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    formator_id INT NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    semester ENUM('1st','2nd','Summer') NOT NULL,
    evaluation_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    archived_at DATETIME NULL,
    KEY idx_he_student (seminarian_id),
    KEY idx_he_term (academic_year, semester),
    KEY idx_he_formator (formator_id),
    CONSTRAINT fk_he_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_he_formator FOREIGN KEY (formator_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE human_questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL,
    question_text VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    KEY idx_hq_active (is_active),
    KEY idx_hq_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE human_responses (
    response_id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    question_id INT NOT NULL,
    rating TINYINT NOT NULL,
    UNIQUE KEY uniq_hresp (evaluation_id, question_id),
    KEY idx_hresp_eval (evaluation_id),
    KEY idx_hresp_q (question_id),
    CONSTRAINT fk_hresp_eval FOREIGN KEY (evaluation_id) REFERENCES human_evaluations(evaluation_id) ON DELETE CASCADE,
    CONSTRAINT fk_hresp_q FOREIGN KEY (question_id) REFERENCES human_questions(question_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE human_remarks (
    remark_id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    strengths TEXT NULL,
    weaknesses TEXT NULL,
    behavioral_concerns TEXT NULL,
    recommendations TEXT NULL,
    follow_up_actions TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_hrem_eval (evaluation_id),
    CONSTRAINT fk_hrem_eval FOREIGN KEY (evaluation_id) REFERENCES human_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE human_results (
    evaluation_id INT PRIMARY KEY,
    overall_score INT NOT NULL,
    average_rating DECIMAL(4,2) NOT NULL,
    interpretation VARCHAR(60) NOT NULL,
    responsibility_avg DECIMAL(4,2) NULL,
    integrity_avg DECIMAL(4,2) NULL,
    emotional_maturity_avg DECIMAL(4,2) NULL,
    interpersonal_avg DECIMAL(4,2) NULL,
    leadership_avg DECIMAL(4,2) NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hres_eval FOREIGN KEY (evaluation_id) REFERENCES human_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE human_analytics (
    analytics_id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    evaluation_id INT NOT NULL,
    previous_score DECIMAL(4,2) NULL,
    current_score DECIMAL(4,2) NULL,
    growth_status ENUM('Improving','Stable','Declining') NULL,
    growth_percentage DECIMAL(6,2) NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ha_eval (evaluation_id),
    KEY idx_ha_student (seminarian_id),
    CONSTRAINT fk_ha_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_ha_eval FOREIGN KEY (evaluation_id) REFERENCES human_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO human_questions (category, question_text, sort_order, is_active) VALUES
('Personal Responsibility','Demonstrates accountability for his actions and decisions.',1,1),
('Personal Responsibility','Completes assigned responsibilities on time.',2,1),
('Personal Responsibility','Manages personal tasks without constant supervision.',3,1),
('Personal Responsibility','Shows initiative in carrying out assigned duties.',4,1),
('Personal Responsibility','Accepts correction with humility and openness.',5,1),
('Personal Responsibility','Demonstrates honesty in reporting accomplishments and failures.',6,1),
('Moral Character and Integrity','Acts according to moral and ethical principles.',7,1),
('Moral Character and Integrity','Demonstrates honesty in daily interactions.',8,1),
('Moral Character and Integrity','Shows consistency between words and actions.',9,1),
('Moral Character and Integrity','Respects institutional rules and policies.',10,1),
('Moral Character and Integrity','Avoids behaviors that may cause scandal or conflict.',11,1),
('Moral Character and Integrity','Displays integrity even when not being observed.',12,1),
('Emotional Maturity','Maintains emotional stability under pressure.',13,1),
('Emotional Maturity','Responds constructively to criticism.',14,1),
('Emotional Maturity','Handles disappointments appropriately.',15,1),
('Emotional Maturity','Demonstrates patience in challenging situations.',16,1),
('Emotional Maturity','Shows self-control during conflicts.',17,1),
('Emotional Maturity','Displays healthy emotional awareness and expression.',18,1),
('Interpersonal Relationships','Treats others with respect and dignity.',19,1),
('Interpersonal Relationships','Works effectively with fellow seminarians.',20,1),
('Interpersonal Relationships','Demonstrates empathy toward others.',21,1),
('Interpersonal Relationships','Resolves conflicts peacefully and maturely.',22,1),
('Interpersonal Relationships','Maintains healthy relationships within the seminary community.',23,1),
('Interpersonal Relationships','Communicates respectfully with superiors, peers, and staff.',24,1),
('Leadership and Community Participation','Demonstrates willingness to serve others.',25,1),
('Leadership and Community Participation','Participates actively in community activities.',26,1),
('Leadership and Community Participation','Shows leadership when opportunities arise.',27,1),
('Leadership and Community Participation','Encourages unity and cooperation among peers.',28,1),
('Leadership and Community Participation','Contributes positively to the seminary environment.',29,1),
('Leadership and Community Participation','Serves as a good example to fellow seminarians.',30,1);

CREATE TABLE pastoral_evaluations (
    evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    formator_id INT NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    semester ENUM('1st','2nd','Summer') NOT NULL,
    evaluation_date DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    archived_at DATETIME NULL,
    KEY idx_pe_student (seminarian_id),
    KEY idx_pe_term (academic_year, semester),
    KEY idx_pe_formator (formator_id),
    CONSTRAINT fk_pe_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_pe_formator FOREIGN KEY (formator_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pastoral_questions (
    question_id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL,
    question_text VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    KEY idx_pq_active (is_active),
    KEY idx_pq_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pastoral_responses (
    response_id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    question_id INT NOT NULL,
    rating TINYINT NOT NULL,
    UNIQUE KEY uniq_presp (evaluation_id, question_id),
    KEY idx_presp_eval (evaluation_id),
    KEY idx_presp_q (question_id),
    CONSTRAINT fk_presp_eval FOREIGN KEY (evaluation_id) REFERENCES pastoral_evaluations(evaluation_id) ON DELETE CASCADE,
    CONSTRAINT fk_presp_q FOREIGN KEY (question_id) REFERENCES pastoral_questions(question_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pastoral_remarks (
    remark_id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    strengths TEXT NULL,
    areas_for_improvement TEXT NULL,
    challenges TEXT NULL,
    recommendations TEXT NULL,
    ministry_development_plan TEXT NULL,
    follow_up_actions TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_prem_eval (evaluation_id),
    CONSTRAINT fk_prem_eval FOREIGN KEY (evaluation_id) REFERENCES pastoral_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pastoral_results (
    evaluation_id INT PRIMARY KEY,
    total_score INT NOT NULL,
    average_rating DECIMAL(4,2) NOT NULL,
    interpretation VARCHAR(60) NOT NULL,
    pastoral_ministry_avg DECIMAL(4,2) NULL,
    leadership_avg DECIMAL(4,2) NULL,
    communication_avg DECIMAL(4,2) NULL,
    community_engagement_avg DECIMAL(4,2) NULL,
    apostolic_zeal_avg DECIMAL(4,2) NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pres_eval FOREIGN KEY (evaluation_id) REFERENCES pastoral_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pastoral_analytics (
    analytics_id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    evaluation_id INT NOT NULL,
    previous_score DECIMAL(4,2) NULL,
    current_score DECIMAL(4,2) NULL,
    growth_status ENUM('Growing','Stable','Declining') NULL,
    growth_percentage DECIMAL(6,2) NULL,
    computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pa_eval (evaluation_id),
    KEY idx_pa_student (seminarian_id),
    CONSTRAINT fk_pa_student FOREIGN KEY (seminarian_id) REFERENCES seminarians(id) ON DELETE CASCADE,
    CONSTRAINT fk_pa_eval FOREIGN KEY (evaluation_id) REFERENCES pastoral_evaluations(evaluation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pastoral_questions (category, question_text, sort_order, is_active) VALUES
('Pastoral Ministry and Service','Demonstrates dedication in assigned pastoral ministries.',1,1),
('Pastoral Ministry and Service','Performs pastoral responsibilities faithfully.',2,1),
('Pastoral Ministry and Service','Shows concern for the spiritual welfare of others.',3,1),
('Pastoral Ministry and Service','Participates actively in ministry activities.',4,1),
('Pastoral Ministry and Service','Displays initiative in serving communities.',5,1),
('Pastoral Ministry and Service','Demonstrates commitment to pastoral assignments.',6,1),
('Leadership and Responsibility','Accepts leadership responsibilities willingly.',7,1),
('Leadership and Responsibility','Demonstrates accountability in ministry work.',8,1),
('Leadership and Responsibility','Makes sound decisions during pastoral activities.',9,1),
('Leadership and Responsibility','Inspires cooperation among peers.',10,1),
('Leadership and Responsibility','Organizes activities effectively.',11,1),
('Leadership and Responsibility','Serves as a positive role model in ministry.',12,1),
('Communication and Interpersonal Skills','Communicates clearly and respectfully.',13,1),
('Communication and Interpersonal Skills','Listens attentively to others.',14,1),
('Communication and Interpersonal Skills','Demonstrates empathy when interacting with people.',15,1),
('Communication and Interpersonal Skills','Handles conflicts constructively.',16,1),
('Communication and Interpersonal Skills','Shows confidence in public speaking situations.',17,1),
('Communication and Interpersonal Skills','Establishes healthy relationships with parishioners and community members.',18,1),
('Community Engagement and Collaboration','Participates actively in community outreach programs.',19,1),
('Community Engagement and Collaboration','Works effectively with teams and ministry groups.',20,1),
('Community Engagement and Collaboration','Demonstrates respect for diverse backgrounds and experiences.',21,1),
('Community Engagement and Collaboration','Supports fellow seminarians in ministry activities.',22,1),
('Community Engagement and Collaboration','Contributes positively to community-building efforts.',23,1),
('Community Engagement and Collaboration','Cooperates with formators and pastoral supervisors.',24,1),
('Apostolic Zeal and Mission Awareness','Demonstrates enthusiasm for evangelization.',25,1),
('Apostolic Zeal and Mission Awareness','Shows willingness to serve marginalized communities.',26,1),
('Apostolic Zeal and Mission Awareness','Displays genuine concern for the needs of others.',27,1),
('Apostolic Zeal and Mission Awareness','Promotes the mission of the Church through actions and words.',28,1),
('Apostolic Zeal and Mission Awareness','Demonstrates perseverance in ministry challenges.',29,1),
('Apostolic Zeal and Mission Awareness','Shows commitment to ongoing pastoral growth and development.',30,1);

CREATE TABLE grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT,
    subject VARCHAR(100),
    units DECIMAL(3,1) NOT NULL DEFAULT 0.0,
    grade DECIMAL(5,2),
    grade_percent DECIMAL(5,2) DEFAULT NULL,
    term VARCHAR(20) DEFAULT NULL,
    school_year VARCHAR(9) DEFAULT NULL,
    remarks TEXT,
    assessed_by INT DEFAULT NULL,
    date DATE,
    archived_at DATETIME NULL,
    FOREIGN KEY (seminarian_id) REFERENCES seminarians(id)
);

CREATE TABLE evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT,
    type ENUM('Spiritual', 'Human', 'Pastoral'),
    score DECIMAL(5,2),
    comments TEXT,
    date DATE,
    archived_at DATETIME NULL,
    FOREIGN KEY (seminarian_id) REFERENCES seminarians(id)
);

CREATE TABLE timetable (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event VARCHAR(255),
    date DATE,
    time TIME,
    type ENUM('Academic', 'Formation', 'Church', 'Spiritual', 'Human', 'Pastoral'),
    note TEXT NULL,
    archived_at DATETIME NULL
);

CREATE TABLE activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS lodging_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    capacity INT NOT NULL DEFAULT 1,
    monthly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lodging_stays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    room_id INT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lodging_stays_seminarian (seminarian_id),
    KEY idx_lodging_stays_room (room_id),
    CONSTRAINT fk_lodging_stays_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id),
    CONSTRAINT fk_lodging_stays_room FOREIGN KEY (room_id) REFERENCES lodging_rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lodging_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    invoice_no VARCHAR(30) NOT NULL,
    issue_date DATE NOT NULL,
    due_date DATE NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    academic_year VARCHAR(9) NULL,
    status ENUM('draft','issued','partially_paid','paid','void') NOT NULL DEFAULT 'issued',
    notes TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    UNIQUE KEY uniq_lodging_invoice_no (invoice_no),
    KEY idx_lodging_invoices_seminarian (seminarian_id),
    KEY idx_lodging_invoices_issue_date (issue_date),
    CONSTRAINT fk_lodging_invoices_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id),
    CONSTRAINT fk_lodging_invoices_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lodging_invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lodging_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_lodging_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES lodging_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lodging_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seminarian_id INT NOT NULL,
    payment_date DATE NOT NULL,
    academic_year VARCHAR(9) NULL,
    amount DECIMAL(10,2) NOT NULL,
    method ENUM('cash','gcash','bank','check','other') NOT NULL DEFAULT 'cash',
    reference_no VARCHAR(80) NULL,
    notes TEXT NULL,
    received_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lodging_payments_seminarian (seminarian_id),
    KEY idx_lodging_payments_payment_date (payment_date),
    CONSTRAINT fk_lodging_payments_seminarian FOREIGN KEY (seminarian_id) REFERENCES seminarians(id),
    CONSTRAINT fk_lodging_payments_received_by FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lodging_payment_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    invoice_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_lodging_payment_invoice (payment_id, invoice_id),
    KEY idx_lodging_alloc_payment (payment_id),
    KEY idx_lodging_alloc_invoice (invoice_id),
    CONSTRAINT fk_lodging_alloc_payment FOREIGN KEY (payment_id) REFERENCES lodging_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_lodging_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES lodging_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (username, password, role) VALUES 
('student1', '$2y$10$AOw5lwFqtx.O/f203U0nZe/y07zDCr5y9dEYB.gBiBMDt10BRn9fi', 'Student'), 
('registrar', '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2', 'Registrar'), 
('admin', '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2', 'Dean'),
('academicformator', '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2', 'AcademicFormator'),
('spiritualdirector', '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2', 'SpiritualDirector'),
('humanformator', '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2', 'HumanFormator'),
('pastoralformator', '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2', 'PastoralFormator'),
('cashier', '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2', 'Cashier'),
('shrine', '$2y$10$Wsfc0D4G6bdW75LA8wR7Z.M90Qqv/HOv3FLsFI2tKOZUzY/Ng4BK2', 'Shrine');

INSERT INTO seminarians (user_id) VALUES (1);
