-- ==============================================================================
-- System: EnformaSys
-- Script: update_propaedeutic_curriculum_subjects.sql
-- Description:
--   1. Schema Migration: Alters `subjects.units` and `grades.units` to DECIMAL(3,1)
--      to allow fractional credit units (1.5 units) without integer truncation.
--   2. Update Existing Records (1st Semester):
--      Finds all subjects under 'Propaedeutic Stage' for '1st Semester' that
--      currently have 3 units and updates their units to 1.5.
--   3. Insert New Records (2nd Semester):
--      Inserts 15 designated 2nd Semester subjects for 'Propaedeutic Stage' with
--      1.5 units each into the `subjects` table with standard codes (102 series)
--      and maps prerequisites to their 1st Semester counterparts.
--   4. Curriculum Mapping:
--      Maps all 15 new subjects to `curriculum_subjects` under 'Propaedeutic Stage'
--      and '2nd Semester' linked to the active Propaedeutic curriculum version.
-- ==============================================================================

START TRANSACTION;

-- ------------------------------------------------------------------------------
-- STEP 1: Schema Migration (Support 1.5 Fractional Units)
-- ------------------------------------------------------------------------------
ALTER TABLE subjects MODIFY COLUMN units DECIMAL(3,1) NOT NULL DEFAULT 0.0;
ALTER TABLE grades MODIFY COLUMN units DECIMAL(3,1) NOT NULL DEFAULT 0.0;

-- ------------------------------------------------------------------------------
-- STEP 2: Update Existing 1st Semester Subjects (3 units -> 1.5 units)
-- ------------------------------------------------------------------------------
UPDATE subjects s
JOIN curriculum_subjects cs ON cs.subject_id = s.subject_id
SET s.units = 1.5,
    s.updated_at = NOW()
WHERE cs.year_level = 'Propaedeutic Stage'
  AND cs.semester = '1st'
  AND s.units = 3.0;

-- ------------------------------------------------------------------------------
-- STEP 3: Identify Active Curriculum Version & Academic Year for Propaedeutic
-- ------------------------------------------------------------------------------
SET @prop_cv_id = (
    SELECT id 
    FROM curriculum_versions 
    WHERE program = 'Propaedeutic' 
      AND is_active = 1 
      AND archived_at IS NULL 
    ORDER BY is_current DESC, id DESC 
    LIMIT 1
);
SET @prop_cv_id = COALESCE(@prop_cv_id, 1249);

SET @prop_ay_id = (
    SELECT academic_year_id 
    FROM curriculum_versions 
    WHERE id = @prop_cv_id
);
SET @prop_ay_id = COALESCE(@prop_ay_id, 1);

-- ------------------------------------------------------------------------------
-- STEP 4: Insert 15 New 2nd Semester Subjects into `subjects` Table (1.5 Units Each)
-- ------------------------------------------------------------------------------
INSERT INTO subjects (subject_code, subject_name, units, prerequisite_subject_id, created_at)
VALUES
    ('LAT 102',   'Latin 1b',                                         1.5, (SELECT s.subject_id FROM subjects s WHERE s.subject_code = 'LAT 101' LIMIT 1),   NOW()),
    ('WRIT 102',  'Introduction to Writing 2',                        1.5, (SELECT s.subject_id FROM subjects s WHERE s.subject_code = 'WRIT 101' LIMIT 1),  NOW()),
    ('SPCH 102',  'Speech 2',                                         1.5, (SELECT s.subject_id FROM subjects s WHERE s.subject_code = 'SPCH 101' LIMIT 1),  NOW()),
    ('CCC 102',   'Catechism of the Catholic Church',                 1.5, NULL,                                                                             NOW()),
    ('CHRI 102',  'Introduction to Christology',                      1.5, NULL,                                                                             NOW()),
    ('ECCL 102',  'Introduction to Ecclesiology',                     1.5, NULL,                                                                             NOW()),
    ('SACR 102',  'Introduction to the Sacraments',                   1.5, NULL,                                                                             NOW()),
    ('SCRIP 102', 'Introduction to Scripture 2',                       1.5, (SELECT s.subject_id FROM subjects s WHERE s.subject_code = 'SCRIP 101' LIMIT 1), NOW()),
    ('LIT 102',   'Introduction to Liturgy 2',                         1.5, (SELECT s.subject_id FROM subjects s WHERE s.subject_code = 'LIT 101' LIMIT 1),   NOW()),
    ('HAF 102',   'Human and Affective Formation',                    1.5, NULL,                                                                             NOW()),
    ('VOC 102',   'Discernment of Vocation',                          1.5, (SELECT s.subject_id FROM subjects s WHERE s.subject_code = 'VOC 101' LIMIT 1),   NOW()),
    ('PRAY 102',  'Lectio Divina and Biblical Prayer',                1.5, (SELECT s.subject_id FROM subjects s WHERE s.subject_code = 'PRAY 101' LIMIT 1),  NOW()),
    ('PAST 102',  'Pastoral Exposure and Apostolic Service',          1.5, NULL,                                                                             NOW()),
    ('LANG 102',  'Filipino or Local Language for Pastoral Ministry', 1.5, NULL,                                                                             NOW()),
    ('SKILL 102', 'Skills Development 2',                             1.5, (SELECT s.subject_id FROM subjects s WHERE s.subject_code = 'SKILL 101' LIMIT 1), NOW())
ON DUPLICATE KEY UPDATE 
    subject_name = VALUES(subject_name),
    units = VALUES(units),
    prerequisite_subject_id = COALESCE(VALUES(prerequisite_subject_id), prerequisite_subject_id),
    updated_at = NOW();

-- ------------------------------------------------------------------------------
-- STEP 5: Map New Subjects to `curriculum_subjects` (Propaedeutic Stage, 2nd Semester)
-- ------------------------------------------------------------------------------
INSERT INTO curriculum_subjects (
    curriculum_version_id, 
    academic_year_id, 
    subject_id, 
    prerequisite_subject_id, 
    year_level, 
    semester, 
    is_active, 
    sort_order, 
    created_at
)
SELECT 
    @prop_cv_id,
    @prop_ay_id,
    s.subject_id,
    s.prerequisite_subject_id,
    'Propaedeutic Stage',
    '2nd',
    1,
    ord.sort_order,
    NOW()
FROM (
    SELECT 'LAT 102' AS code, 1 AS sort_order UNION ALL
    SELECT 'WRIT 102', 2 UNION ALL
    SELECT 'SPCH 102', 3 UNION ALL
    SELECT 'CCC 102', 4 UNION ALL
    SELECT 'CHRI 102', 5 UNION ALL
    SELECT 'ECCL 102', 6 UNION ALL
    SELECT 'SACR 102', 7 UNION ALL
    SELECT 'SCRIP 102', 8 UNION ALL
    SELECT 'LIT 102', 9 UNION ALL
    SELECT 'HAF 102', 10 UNION ALL
    SELECT 'VOC 102', 11 UNION ALL
    SELECT 'PRAY 102', 12 UNION ALL
    SELECT 'PAST 102', 13 UNION ALL
    SELECT 'LANG 102', 14 UNION ALL
    SELECT 'SKILL 102', 15
) ord
JOIN subjects s ON s.subject_code = ord.code
ON DUPLICATE KEY UPDATE
    academic_year_id = VALUES(academic_year_id),
    prerequisite_subject_id = VALUES(prerequisite_subject_id),
    is_active = 1,
    sort_order = VALUES(sort_order),
    updated_at = NOW();

COMMIT;

-- ==============================================================================
-- VERIFICATION QUERY
-- ==============================================================================
SELECT 
    cs.semester,
    cs.sort_order,
    s.subject_code,
    s.subject_name,
    s.units,
    pr.subject_code AS prerequisite_code
FROM curriculum_subjects cs
JOIN subjects s ON s.subject_id = cs.subject_id
LEFT JOIN subjects pr ON pr.subject_id = cs.prerequisite_subject_id
WHERE cs.year_level = 'Propaedeutic Stage'
ORDER BY cs.semester ASC, cs.sort_order ASC, s.subject_code ASC;
