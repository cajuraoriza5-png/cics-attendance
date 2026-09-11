<?php
/**
 * Test Event Link Behavior
 * Verify the exact link in admin dashboard
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🔗 Testing Event Link Behavior</h2>";

// Read the admin_dashboard.php file content
$admin_dashboard_content = file_get_contents('admin_dashboard.php');

// Find the Events link
if(preg_match('/<a href="([^"]*)"[^>]*>📅 Events<\/a>/', $admin_dashboard_content, $matches)) {
    $events_link = $matches[1];
    echo "<p style='color: #51cf66;'>✅ Events link found: <strong>" . htmlspecialchars($events_link) . "</strong></p>";
    
    if($events_link === 'create_event_new.php') {
        echo "<p style='color: #51cf66;'>✅ Link is correctly pointing to create_event_new.php</p>";
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Link is pointing to: " . htmlspecialchars($events_link) . "</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Events link not found in admin_dashboard.php</p>";
}

// Check if both files exist
echo "<h3>📁 File Existence Check</h3>";
if(file_exists('admin_dashboard.php')) {
    echo "<p style='color: #51cf66;'>✅ admin_dashboard.php exists</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ admin_dashboard.php missing</p>";
}

if(file_exists('create_event_new.php')) {
    echo "<p style='color: #51cf66;'>✅ create_event_new.php exists</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ create_event_new.php missing</p>";
}

if(file_exists('manage_events.php')) {
    echo "<p style='color: #51cf66;'>✅ manage_events.php exists</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ manage_events.php missing</p>";
}

// Show the exact line containing the Events link
echo "<h3>📄 Exact Code Line</h3>";
$lines = file('admin_dashboard.php');
foreach($lines as $line_number => $line) {
    if(strpos($line, '📅 Events') !== false) {
        echo "<p style='background: #f8f9fa; padding: 10px; border-radius: 5px; font-family: monospace;'>";
        echo "Line " . ($line_number + 1) . ": " . htmlspecialchars(trim($line));
        echo "</p>";
        break;
    }
}

// Test direct access to create_event_new.php
echo "<h3>🧪 Direct Access Test</h3>";
echo "<p><a href='create_event_new.php' target='_blank' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>📅 Test Direct Access to create_event_new.php</a></p>";

echo "<h3>🔧 Solutions to Try:</h3>";
echo "<ol style='color: white;'>";
echo "<li><strong>Clear Browser Cache:</strong> Press Ctrl+F5 (Windows) or Cmd+Shift+R (Mac)</li>";
echo "<li><strong>Check Developer Tools:</strong> Press F12, go to Network tab, click Events link</li>";
echo "<li><strong>Try Incognito Mode:</strong> Open admin dashboard in private browsing</li>";
echo "<li><strong>Check for JavaScript:</strong> Look for any JavaScript that might be changing the link</li>";
echo "<li><strong>Verify Session:</strong> Make sure you're properly logged in as admin</li>";
echo "</ol>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='admin_dashboard.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👨‍💼 Go to Admin Dashboard</a>";
echo "</div>";

$conn->close();
?>
