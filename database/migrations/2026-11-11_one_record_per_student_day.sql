USE attendance_db;

ALTER TABLE attendance_records
    ADD UNIQUE KEY uq_student_attendance_date (student_id, attendance_date);
