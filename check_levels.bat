@echo off
cd /d C:\xampp\htdocs\cics_attendance
mysql -u root attendance -e "SELECT year_level, COUNT(*) as count FROM users WHERE role='student' GROUP BY year_level;"
echo.
pause
