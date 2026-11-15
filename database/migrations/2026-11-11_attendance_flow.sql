USE attendance_db;

ALTER TABLE attendance_sessions
    MODIFY course_id INT NULL,
    MODIFY year_level_id INT NULL,
    MODIFY section_id INT NULL,
    MODIFY status ENUM('active', 'inactive', 'expired') NOT NULL DEFAULT 'active',
    ADD UNIQUE KEY uq_session_name_date (session_name, session_date);

ALTER TABLE attendance_records
    MODIFY time_in TIME NULL,
    ADD COLUMN time_out TIME NULL AFTER time_in,
    MODIFY status ENUM('PRESENT', 'LATE', 'ABSENT', 'INCOMPLETE') NOT NULL DEFAULT 'PRESENT';

CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    student_id INT NULL,
    session_id INT NULL,
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_audit_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
