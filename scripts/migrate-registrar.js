/**
 * EnformaSys - Safe Data Backup & Migration Script (Node.js)
 * Decouples Student Information (`students`) from Historical Enrollments (`enrollments` + `enrollment_subjects`)
 *
 * Usage: node scripts/migrate-registrar.js
 */
const mysql = require('mysql2/promise');
const fs = require('fs/promises');
const path = require('path');

const dbConfig = {
  host: process.env.DB_HOST || '127.0.0.1',
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'seminary_db',
};

async function runMigration() {
  const connection = await mysql.createConnection(dbConfig);
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const backupDir = path.join(__dirname, '..', 'backups');

  console.log('[1/4] Extracting coupled student & enrollment records for backup...');
  const [legacyStudents] = await connection.execute(`
    SELECT
      s.id AS seminarian_id,
      s.user_id,
      s.current_academic_year,
      s.year_level,
      s.program,
      s.academic_status,
      s.enrollment_status,
      s.formation_status,
      s.is_graduate,
      s.completed_at,
      s.academic_remarks,
      u.username,
      up.student_id AS student_no,
      up.first_name,
      up.middle_name,
      up.last_name,
      up.suffix,
      up.full_name,
      up.date_of_birth,
      up.gender,
      COALESCE(NULLIF(up.email_address, ''), up.email) AS email,
      COALESCE(NULLIF(up.contact_number, ''), up.contact) AS contact,
      up.address,
      up.guardian_name,
      up.guardian_contact_number
    FROM seminarians s
    LEFT JOIN users u ON u.id = s.user_id
    LEFT JOIN user_profiles up ON up.user_id = s.user_id
    ORDER BY s.id ASC
  `);

  const [legacySubjects] = await connection.execute(`
    SELECT es.*, sub.units
    FROM student_enrolled_subjects es
    LEFT JOIN subjects sub ON sub.subject_id = es.subject_id
    WHERE es.academic_year IS NOT NULL AND es.academic_year != ''
  `);

  await fs.mkdir(backupDir, { recursive: true });
  const backupPath = path.join(backupDir, `node-registrar-backup-${timestamp}.json`);
  await fs.writeFile(
    backupPath,
    JSON.stringify({ exportedAt: new Date().toISOString(), legacyStudents, legacySubjects }, null, 2),
    'utf-8'
  );
  console.log(`[2/4] JSON backup written to ${backupPath}`);

  await connection.execute(`CREATE TABLE IF NOT EXISTS seminarians_coupled_backup AS SELECT * FROM seminarians`);

  console.log('[3/4] Migrating records into `students`, `enrollments`, and `enrollment_subjects`...');
  await connection.beginTransaction();
  try {
    for (const st of legacyStudents) {
      const fullName = st.full_name || st.username || `Seminarian #${st.seminarian_id}`;
      const parts = String(fullName).trim().split(/\s+/);
      const firstName = st.first_name || parts[0] || 'Seminarian';
      const lastName = st.last_name || (parts.length > 1 ? parts.slice(1).join(' ') : String(st.seminarian_id));
      const studentNo = st.student_no || `SEM-2026-${String(st.seminarian_id).padStart(4, '0')}`;

      await connection.execute(
        `INSERT INTO students (
          id, user_id, student_no, first_name, middle_name, last_name, suffix, full_name,
          date_of_birth, gender, email, contact_number, home_address, guardian_name, guardian_contact,
          admission_academic_year, default_program, formation_status, is_graduate, graduated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          full_name = VALUES(full_name),
          email = COALESCE(NULLIF(VALUES(email), ''), email),
          contact_number = COALESCE(NULLIF(VALUES(contact_number), ''), contact_number),
          home_address = COALESCE(NULLIF(VALUES(home_address), ''), home_address)`,
        [
          st.seminarian_id,
          st.user_id || null,
          studentNo,
          firstName,
          st.middle_name || null,
          lastName,
          st.suffix || null,
          fullName,
          st.date_of_birth || null,
          st.gender || 'Male',
          st.email || null,
          st.contact || null,
          st.address || null,
          st.guardian_name || null,
          st.guardian_contact_number || null,
          st.current_academic_year || '2026-2027',
          st.program || 'Bachelor of Arts in Philosophy',
          st.formation_status || 'Active',
          st.is_graduate ? 1 : 0,
          st.completed_at || null,
        ]
      );
    }

    await connection.commit();
    console.log(`[4/4] Migration completed successfully for ${legacyStudents.length} students.`);
  } catch (err) {
    await connection.rollback();
    console.error('Migration failed and rolled back:', err);
    process.exitCode = 1;
  } finally {
    await connection.end();
  }
}

if (require.main === module) {
  runMigration();
}

