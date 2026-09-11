-- Add school year and semester tracking to users table
ALTER TABLE users ADD COLUMN school_year VARCHAR(20) DEFAULT '2024-2025';
ALTER TABLE users ADD COLUMN current_semester ENUM('1st Semester', '2nd Semester', 'Summer') DEFAULT '1st Semester';
ALTER TABLE users ADD COLUMN enrollment_date DATE DEFAULT NULL;
