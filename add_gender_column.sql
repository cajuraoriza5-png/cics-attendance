-- Add gender column to users table
ALTER TABLE users ADD COLUMN gender VARCHAR(10) AFTER age;
