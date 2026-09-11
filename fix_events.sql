-- Fix Events Table Structure
-- Run this SQL file in phpMyAdmin or MySQL console

-- Add event_banner column
ALTER TABLE events ADD COLUMN event_banner VARCHAR(255) AFTER event_description;

-- Add event_type column
ALTER TABLE events ADD COLUMN event_type ENUM('Regular', 'Special', 'Exam', 'Meeting', 'Activity') DEFAULT 'Regular' AFTER event_banner;

-- Add start_date column
ALTER TABLE events ADD COLUMN start_date DATE AFTER event_type;

-- Add end_date column
ALTER TABLE events ADD COLUMN end_date DATE AFTER start_date;

-- Add qr_code column
ALTER TABLE events ADD COLUMN qr_code VARCHAR(255) AFTER end_date;

-- Add mobile_scan_link column
ALTER TABLE events ADD COLUMN mobile_scan_link VARCHAR(255) AFTER qr_code;

-- Add event_history column
ALTER TABLE events ADD COLUMN event_history TEXT AFTER mobile_scan_link;

-- Update existing events to use start_date = event_date and end_date = event_date
UPDATE events SET start_date = event_date, end_date = event_date WHERE start_date IS NULL OR end_date IS NULL;
