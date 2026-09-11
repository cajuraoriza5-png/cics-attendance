<?php
/**
 * Create Sample Event Banners
 * Generates example banner photos for testing
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>📸 Creating Sample Event Banners</h2>";

// Sample banner data with base64 encoded simple images
$sample_banners = [
    [
        'event_name' => 'Morning Assembly',
        'filename' => 'banner_morning_assembly.jpg',
        'description' => 'Morning assembly banner with school colors'
    ],
    [
        'event_name' => 'Afternoon Workshop', 
        'filename' => 'banner_afternoon_workshop.jpg',
        'description' => 'Technical workshop banner'
    ],
    [
        'event_name' => 'Special Guest Lecture',
        'filename' => 'banner_special_lecture.jpg', 
        'description' => 'Guest lecture event banner'
    ],
    [
        'event_name' => 'Exam Period',
        'filename' => 'banner_exam_period.jpg',
        'description' => 'Examination period banner'
    ],
    [
        'event_name' => 'School Activity',
        'filename' => 'banner_school_activity.jpg',
        'description' => 'School activity banner'
    ]
];

// Create event_banners directory if it doesn't exist
if(!is_dir('event_banners')) {
    mkdir('event_banners', 0755, true);
    echo "<p style='color: #51cf66;'>✅ Event banners directory created</p>";
} else {
    echo "<p style='color: #74c0fc;'>✅ Event banners directory exists</p>";
}

// Generate simple color-based banner images
$created_count = 0;
foreach($sample_banners as $banner) {
    $filepath = 'event_banners/' . $banner['filename'];
    
    if(!file_exists($filepath)) {
        // Create a simple 800x400 banner with different colors
        $width = 800;
        $height = 400;
        $image = imagecreatetruecolor($width, $height);
        
        // Different color schemes for different events
        $colors = [
            [255, 215, 0],   // Gold
            [0, 123, 255],   // Blue  
            [40, 167, 69],   // Green
            [220, 53, 69],   // Red
            [108, 117, 125]  // Gray
        ];
        
        $color_index = array_rand($colors);
        $bg_color = imagecolorallocate($image, $colors[$color_index][0], $colors[$color_index][1], $colors[$color_index][2]);
        $text_color = imagecolorallocate($image, 255, 255, 255);
        $border_color = imagecolorallocate($image, 0, 0, 0);
        
        // Fill background
        imagefilledrectangle($image, 0, 0, $width, $height, $bg_color);
        
        // Add border
        imagerectangle($image, 0, 0, $width-1, $height-1, $border_color);
        
        // Add text
        $font_size = 5;
        $text = $banner['event_name'];
        $text_width = imagefontwidth($font_size) * strlen($text);
        $text_height = imagefontheight($font_size);
        $x = ($width - $text_width) / 2;
        $y = ($height - $text_height) / 2;
        
        imagestring($image, $font_size, $x, $y, $text, $text_color);
        
        // Add "SAMPLE" watermark
        $sample_text = "SAMPLE BANNER";
        $sample_width = imagefontwidth(2) * strlen($sample_text);
        $sample_x = ($width - $sample_width) / 2;
        $sample_y = $height - 30;
        imagestring($image, 2, $sample_x, $sample_y, $sample_text, $text_color);
        
        // Save the image
        if(imagejpeg($image, $filepath, 90)) {
            echo "<p style='color: #51cf66;'>✅ Created: " . htmlspecialchars($banner['filename']) . " - " . htmlspecialchars($banner['description']) . "</p>";
            $created_count++;
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Failed to create: " . htmlspecialchars($banner['filename']) . "</p>";
        }
        
        imagedestroy($image);
    } else {
        echo "<p style='color: #74c0fc;'>ℹ️ Already exists: " . htmlspecialchars($banner['filename']) . "</p>";
    }
}

// Update existing events with sample banners
echo "<h3>🔄 Updating Events with Sample Banners</h3>";
$update_count = 0;

$events_query = $conn->query("SELECT id, event_name FROM events WHERE event_banner IS NULL OR event_banner = '' LIMIT 5");
while($event = $events_query->fetch_assoc()) {
    // Find matching banner
    $matching_banner = null;
    foreach($sample_banners as $banner) {
        if(stripos($event['event_name'], strtolower(str_replace([' ', 'banner_'], '', $banner['event_name']))) !== false) {
            $matching_banner = $banner['filename'];
            break;
        }
    }
    
    // If no match, assign random banner
    if(!$matching_banner) {
        $matching_banner = $sample_banners[array_rand($sample_banners)]['filename'];
    }
    
    $update_stmt = $conn->prepare("UPDATE events SET event_banner = ? WHERE id = ?");
    $update_stmt->bind_param("si", $matching_banner, $event['id']);
    
    if($update_stmt->execute()) {
        echo "<p style='color: #51cf66;'>✅ Updated event: " . htmlspecialchars($event['event_name']) . " with banner: " . htmlspecialchars($matching_banner) . "</p>";
        $update_count++;
    }
}

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<h3>📊 Summary:</h3>";
echo "<p style='color: #51cf66;'>Created $created_count sample banners</p>";
echo "<p style='color: #51cf66;'>Updated $update_count events with banners</p>";
echo "</div>";

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='student_dashboard.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 View in Student Dashboard</a>";
echo "<a href='create_event_new.php' style='background: linear-gradient(45deg, #FFD700, #FFA500); color: black; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-left: 10px; display: inline-block;'>📅 Create Event</a>";
echo "</div>";

$conn->close();
?>
