@echo off
REM Push CICS Attendance System to GitHub
REM This script connects the local git repository to GitHub and pushes the code
REM Repository: https://github.com/cajuraoriza5-png/cics-attendance
REM Description: Hybrid face recognition system using dlib + LBPH algorithms

cd /d "c:\Users\840G3\OneDrive\Desktop\cics-attendance"
echo Adding remote...
git remote add origin https://github.com/cajuraoriza5-png/cics-attendance.git
echo Setting branch to main...
git branch -M main
echo Pushing to GitHub...
git push -u origin main
pause
