<?php
/*
 * face_recognition_scan.php
 * -----------------------------------------------------------------------
 * PURPOSE : Main face recognition attendance scanner page.
 *           Accessible by: admin OR officer (student with is_officer = 1).
 *           Unauthorized users are redirected to ADMIN_LOGIN.PHP.
 *
 * HOW IT WORKS:
 *   1. PHP renders the scanner UI (camera + stats + event info).
 *   2. JavaScript captures webcam frames and sends them as Base64 to
 *      face_server.py (Flask API on port 5001) via fetch().
 *   3. Flask runs LBPH + ArcFace and returns separate LBPH, ArcFace, and Hybrid results.
 *   4. JS posts the result to scan_attendance.php to record attendance.
 *
 * AJAX ENDPOINTS (called by JavaScript on the same page):
 *   ?scanned_ids  - Returns array of already-scanned student IDs (prevents double scan)
 *   ?stats_only   - Returns live attendance counts (present/late/absent)
 *
 * KEY SESSION VARIABLES:
 *   $_SESSION['admin_id']   - Set when admin is logged in
 *   $_SESSION['student_id'] - Set when student/officer is logged in
 * -----------------------------------------------------------------------
 */
date_default_timezone_set('Asia/Manila');
session_start();
include("db.php");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}
// Migration: ensure is_officer column exists before querying it
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_officer TINYINT(1) NOT NULL DEFAULT 0");

// --- Access Control: allow admin OR officer student only ---
$isAdmin   = isset($_SESSION['admin_id']);
$isOfficer = false;
if(!$isAdmin && isset($_SESSION['student_id'])){
    $oc = $conn->query("SELECT is_officer FROM users WHERE id=".intval($_SESSION['student_id'])." LIMIT 1")->fetch_assoc();
    $isOfficer = ($oc && !empty($oc['is_officer']));
}
if(!$isAdmin && !$isOfficer){ header("Location: ADMIN_LOGIN.PHP"); exit; }

$event = $conn->query("SELECT * FROM events WHERE event_date = CURDATE() ORDER BY id DESC LIMIT 1")->fetch_assoc();
$event_name = $event['event_name'] ?? 'No Event Today';
$event_id   = $event['id'] ?? 0;

// ── Scanned-IDs AJAX endpoint (window-aware: only block the current direction) ──
if(isset($_GET['scanned_ids'])){
    header('Content-Type: application/json');
    $ids = [];
    if($event_id){
        $win = $_GET['window'] ?? 'any';
        if($win === 'morning_login')        $cond = 'morning_in  IS NOT NULL';
        elseif($win === 'morning_logout')   $cond = 'morning_out IS NOT NULL';
        elseif($win === 'afternoon_login')  $cond = 'afternoon_in  IS NOT NULL';
        elseif($win === 'afternoon_logout') $cond = 'afternoon_out IS NOT NULL';
        else $cond = '(morning_in IS NOT NULL OR afternoon_in IS NOT NULL)';
        $r = $conn->query("SELECT student_id FROM attendance WHERE event_id='$event_id' AND date=CURDATE() AND ($cond)");
        while($r && ($row = $r->fetch_assoc())){ $ids[] = (int)$row['student_id']; }
    }
    echo json_encode($ids);
    exit;
}

// ── Server-status proxy (relays Flask /status to JS without CORS) ──────────
if(isset($_GET['server_status'])){
    header('Content-Type: application/json');
    $config = require __DIR__ . '/config.php';
    $ch = curl_init($config['python_service']['url'] . '/status');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>2]);
    $r    = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($code===200 && $r) echo $r;
    else echo json_encode(['ok'=>false,'loading'=>true,'offline'=>true,
                           'models'=>['lbph'=>false,'arcface'=>false,'loading'=>true]]);
    exit;
}

// ── Stats-only AJAX endpoint (must be before any HTML output) ──────────────
if(isset($_GET['stats_only'])){
    header('Content-Type: application/json');
    if($event_id){
        $s = $conn->query("
            SELECT COUNT(*) as total,
            SUM(CASE WHEN morning_status='Present' OR afternoon_status='Present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN (morning_status='Late' OR afternoon_status='Late') AND morning_status!='Present' AND afternoon_status!='Present' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN (morning_status='Absent' OR morning_status IS NULL) AND (afternoon_status='Absent' OR afternoon_status IS NULL) THEN 1 ELSE 0 END) as absent
            FROM attendance WHERE event_id='$event_id' AND date=CURDATE()
        ")->fetch_assoc();
        echo json_encode($s);
    } else {
        echo json_encode(['total'=>0,'present'=>0,'late'=>0,'absent'=>0]);
    }
    exit;
