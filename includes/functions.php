<?php
/**
 * CICS Attendance System - Utility Functions
 * Provides common functions for error handling, validation, and security
 */

// Prevent direct access
if (!defined('CICS_SYSTEM')) {
    die('Direct access denied');
}

/**
 * Secure database connection with error handling
 */
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $conn = new mysqli("localhost", "root", "", "attendance");
            if ($conn->connect_error) {
                throw new Exception("Database connection failed: " . $conn->connect_error);
            }
            $conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            error_log("Database error: " . $e->getMessage());
            return false;
        }
    }
    
    return $conn;
}

/**
 * Validate and sanitize input
 */
function validateInput($data, $type = 'string') {
    if ($data === null || $data === '') {
        return false;
    }
    
    switch ($type) {
        case 'int':
            return filter_var($data, FILTER_VALIDATE_INT);
        case 'email':
            return filter_var($data, FILTER_VALIDATE_EMAIL);
        case 'string':
            return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
        case 'alpha':
            return preg_match('/^[a-zA-Z\s]+$/', $data) ? trim($data) : false;
        case 'alphanumeric':
            return preg_match('/^[a-zA-Z0-9\s]+$/', $data) ? trim($data) : false;
        default:
            return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Check if user session is valid
 */
function validateSession($role = 'admin') {
    session_start();
    
    $session_key = $role . '_id';
    $name_key = $role . '_name';
    
    if (!isset($_SESSION[$session_key]) || !isset($_SESSION[$name_key])) {
        return false;
    }
    
    return [
        'id' => $_SESSION[$session_key],
        'name' => $_SESSION[$name_key]
    ];
}

/**
 * Log system errors
 */
function logError($message, $context = []) {
    $log_entry = date('Y-m-d H:i:s') . " - " . $message;
    if (!empty($context)) {
        $log_entry .= " | Context: " . json_encode($context);
    }
    error_log($log_entry);
    
    // Also log to file if needed
    $log_file = __DIR__ . '/../logs/system.log';
    if (is_dir(dirname($log_file))) {
        file_put_contents($log_file, $log_entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Return JSON response with proper headers
 */
function jsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

/**
 * Validate file upload
 */
function validateFileUpload($file, $allowed_types = ['jpg', 'jpeg', 'png'], $max_size = 5242880) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error'];
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File too large'];
    }
    
    // Check file type
    $file_info = pathinfo($file['name']);
    $extension = strtolower($file_info['extension']);
    
    if (!in_array($extension, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    // Validate image
    if (in_array($extension, ['jpg', 'jpeg', 'png'])) {
        $image_info = getimagesize($file['tmp_name']);
        if ($image_info === false) {
            return ['success' => false, 'message' => 'Invalid image file'];
        }
    }
    
    return ['success' => true];
}

/**
 * Check if student exists and is eligible
 */
function validateStudent($student_id, $require_face_registered = false) {
    $conn = getDBConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $student_id = intval($student_id);
    if ($student_id <= 0) {
        return ['success' => false, 'message' => 'Invalid student ID'];
    }
    
    $query = "SELECT id, first_name, last_name, face_registered FROM users WHERE id = ? AND role = 'student'";
    if ($require_face_registered) {
        $query .= " AND face_registered = 1";
    }
    
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        logError("Prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $message = $require_face_registered ? 
            'Student not found or face not registered' : 
            'Student not found';
        return ['success' => false, 'message' => $message];
    }
    
    $student = $result->fetch_assoc();
    $stmt->close();
    
    return ['success' => true, 'student' => $student];
}

/**
 * Get today's event with validation
 */
function getTodayEvent() {
    $conn = getDBConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $stmt = $conn->prepare("SELECT * FROM events WHERE event_date = CURDATE() LIMIT 1");
    if ($stmt === false) {
        logError("Prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => 'No event scheduled for today'];
    }
    
    $event = $result->fetch_assoc();
    $stmt->close();
    
    return ['success' => true, 'event' => $event];
}

/**
 * Check if student already scanned today
 */
function checkTodayAttendance($student_id, $event_id) {
    $conn = getDBConnection();
    if (!$conn) {
        return ['success' => false, 'message' => 'Database error'];
    }
    
    $stmt = $conn->prepare("
        SELECT id, morning_in, morning_status, afternoon_in, afternoon_status
        FROM attendance
        WHERE student_id = ? AND event_id = ? AND date = CURDATE()
    ");

    if ($stmt === false) {
        logError("Prepare failed: " . $conn->error);
        return ['success' => false, 'message' => 'Database error'];
    }

    $stmt->bind_param("ii", $student_id, $event_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $attendance = $result->fetch_assoc();
        $stmt->close();

        if ($attendance['morning_in'] !== null || $attendance['afternoon_in'] !== null) {
            return ['success' => false, 'message' => 'Already scanned today', 'attendance' => $attendance];
        }
    }
    
    $stmt->close();
    return ['success' => true];
}

/**
 * Create directory if it doesn't exist
 */
function ensureDirectoryExists($path) {
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) {
            logError("Failed to create directory: " . $path);
            return false;
        }
    }
    return true;
}

/**
 * Generate secure filename
 */
function generateSecureFilename($student_id, $index) {
    return $student_id . "_" . $index . ".jpg";
}

/**
 * Send email notification (placeholder for future implementation)
 */
function sendNotification($to, $subject, $message) {
    // This would be implemented with actual email sending
    logError("Email notification: $subject to $to");
    return true;
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}

/**
 * Get attendance statistics
 */
function getAttendanceStats($student_id = null, $event_id = null) {
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }
    
    $where_conditions = [];
    $params = [];
    $types = '';
    
    if ($student_id) {
        $where_conditions[] = "a.student_id = ?";
        $params[] = $student_id;
        $types .= 'i';
    }
    
    if ($event_id) {
        $where_conditions[] = "a.event_id = ?";
        $params[] = $event_id;
        $types .= 'i';
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    $query = "
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN a.morning_status='Present' OR a.afternoon_status='Present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN (a.morning_status='Late' OR a.afternoon_status='Late') AND a.morning_status!='Present' AND a.afternoon_status!='Present' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN (a.morning_status='Absent' OR a.morning_status IS NULL) AND (a.afternoon_status='Absent' OR a.afternoon_status IS NULL) THEN 1 ELSE 0 END) as absent,
            SUM(a.penalty) as total_penalty
        FROM attendance a
        JOIN events e ON a.event_id = e.id
        $where_clause
        AND e.event_date <= CURDATE()
    ";
    
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        logError("Prepare failed: " . $conn->error);
        return false;
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    
    return $stats;
}
?>
