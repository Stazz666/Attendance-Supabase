CREATE DATABASE IF NOT EXISTS attendance_db;
USE attendance_db;

CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS year_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    course_id INT NOT NULL,
    year_level_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_section_course_year (name, course_id, year_level_id),
    CONSTRAINT fk_sections_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_sections_year FOREIGN KEY (year_level_id) REFERENCES year_levels(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','faculty','student') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS faculty (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    faculty_code VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_faculty_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    student_id VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    course_id INT NOT NULL,
    year_level_id INT NOT NULL,
    section_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_students_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_students_year FOREIGN KEY (year_level_id) REFERENCES year_levels(id) ON DELETE RESTRICT,
    CONSTRAINT fk_students_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    late_time TIME NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_date_status (event_date, status),
    CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_student_qrs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    student_id INT NOT NULL,
    qr_token CHAR(64) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_student_qr (event_id, student_id),
    CONSTRAINT fk_event_student_qrs_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_student_qrs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    student_id INT NOT NULL,
    time_in DATETIME NOT NULL,
    status ENUM('PRESENT', 'LATE') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_attendance_student (event_id, student_id),
    INDEX idx_event_attendance_time (event_id, time_in),
    CONSTRAINT fk_event_attendance_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_attendance_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_id INT NOT NULL,
    creator_role ENUM('admin','faculty') NOT NULL,
    session_name VARCHAR(100) NOT NULL,
    course_id INT NULL,
    year_level_id INT NULL,
    section_id INT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    late_threshold TIME NOT NULL,
    qr_token VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('active','inactive','expired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_name_date (session_name, session_date),
    CONSTRAINT fk_session_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_session_year FOREIGN KEY (year_level_id) REFERENCES year_levels(id) ON DELETE RESTRICT,
    CONSTRAINT fk_session_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id INT NOT NULL,
    student_name VARCHAR(100) NOT NULL,
    course_id INT NOT NULL,
    year_level_id INT NOT NULL,
    section_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    time_in TIME NULL,
    time_out TIME NULL,
    status ENUM('PRESENT','LATE','ABSENT','INCOMPLETE') NOT NULL DEFAULT 'PRESENT',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_student (session_id, student_id),
    UNIQUE KEY uq_student_attendance_date (student_id, attendance_date),
    CONSTRAINT fk_records_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_records_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_records_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_records_year FOREIGN KEY (year_level_id) REFERENCES year_levels(id) ON DELETE RESTRICT,
    CONSTRAINT fk_records_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS audit_logs (
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
);

INSERT IGNORE INTO courses (name, code) VALUES
('BS Computer Science', 'BSCS'),
('BS Computer Engineering', 'BSCpE');

INSERT IGNORE INTO year_levels (name) VALUES
('1st Year'),
('2nd Year'),
('3rd Year'),
('4th Year');

INSERT IGNORE INTO sections (name, course_id, year_level_id) VALUES
('1A', 1, 1),
('1B', 1, 1),
('1C', 1, 1),
('1D', 1, 1),
('2A', 1, 2),
('1A', 2, 1),
('1B', 2, 1),
('1C', 2, 1),
('1D', 2, 1),
('2A', 2, 2);

INSERT IGNORE INTO users (name, email, password, role) VALUES
('System Administrator', 'admin@college.edu', '$2y$10$zKabUp5UONIwoC.5XC8UQeDau.EFzSWY282ozxaKQreL.UQtugi1u', 'admin'),
('Faculty Instructor', 'faculty@college.edu', '$2y$10$mxe52g57bTUaHYEeLVsCy.DuZ9sHHiFwZAzvWfqLEpd1Mrr.z6hPG', 'faculty'),
('Student One', 'student1@college.edu', '$2y$10$9EEhccmQ0w1IbYx/trAq0.VWL5.POV.E.REFeQFPFf2dlc1uxUzuq', 'student');

INSERT IGNORE INTO faculty (user_id, faculty_code, full_name, email) VALUES
(2, 'FAC-001', 'Faculty Instructor', 'faculty@college.edu');

INSERT IGNORE INTO students (user_id, student_id, full_name, email, course_id, year_level_id, section_id) VALUES
(3, '2024001', 'Student One', 'student1@college.edu', 1, 1, 1);
