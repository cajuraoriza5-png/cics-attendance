<?php
/**
 * Test New Fields Functionality
 * Tests middle name and phone number fields
 */

$conn = new mysqli("localhost","root","","attendance");

if($conn->connect_error){
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>🧪 Testing New Fields: Middle Name & Phone Number</h2>";

// Test 1: Check if columns exist
echo "<h3>📊 Test 1: Database Column Check</h3>";

$check_middle_name = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'middle_name'");
$check_phone = $conn->query("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'attendance' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone'");

if($check_middle_name->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Middle name column exists</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Middle name column missing</p>";
}

if($check_phone->num_rows > 0) {
    echo "<p style='color: #51cf66;'>✅ Phone number column exists</p>";
} else {
    echo "<p style='color: #ff6b6b;'>❌ Phone number column missing</p>";
}

// Test 2: Phone Number Validation
echo "<h3>📱 Test 2: Phone Number Validation</h3>";

$test_phones = [
    '639123456789' => true,  // Valid
    '639876543210' => true,  // Valid
    '63912345678' => false,  // Too short
    '6391234567890' => false, // Too long
    '09123456789' => false,  // Wrong format
    '63912345678a' => false, // Contains letter
    '639123456789 ' => false // Contains space
];

foreach($test_phones as $phone => $expected) {
    $valid = preg_match("/^63[0-9]{10}$/", $phone);
    $status = ($valid == $expected) ? '✅' : '❌';
    $color = ($valid == $expected) ? '#51cf66' : '#ff6b6b';
    echo "<p style='color: $color;'>$status Phone: $phone - Expected: " . ($expected ? 'Valid' : 'Invalid') . ", Got: " . ($valid ? 'Valid' : 'Invalid') . "</p>";
}

// Test 3: Create Test Student with New Fields
echo "<h3>👤 Test 3: Create Test Student</h3>";

$test_data = [
    'username' => 'test_middle_phone_' . time(),
    'password' => password_hash('test123', PASSWORD_DEFAULT),
    'student_id' => '2024-TEST2',
    'first_name' => 'John',
    'middle_name' => 'Doe',
    'last_name' => 'Smith',
    'age' => 20,
    'gender' => 'Male',
    'email' => 'john.smith@test.com',
    'phone' => '639123456789',
    'course' => 'BSCS',
    'year_level' => '1st Year',
    'section' => 'A'
];

$insert_test = $conn->prepare("
    INSERT INTO users(username, password, student_id, first_name, middle_name, last_name, age, gender, email, phone, course, year_level, section, face_registered, total_penalty, role)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0.00, 'student')
");

if($insert_test) {
    $insert_test->bind_param("sssssssissssss", 
        $test_data['username'], 
        $test_data['password'], 
        $test_data['student_id'], 
        $test_data['first_name'], 
        $test_data['middle_name'], 
        $test_data['last_name'], 
        $test_data['age'], 
        $test_data['gender'], 
        $test_data['email'], 
        $test_data['phone'], 
        $test_data['course'], 
        $test_data['year_level'], 
        $test_data['section']
    );
    
    if($insert_test->execute()) {
        $test_student_id = $conn->insert_id;
        echo "<p style='color: #51cf66;'>✅ Test student created successfully (ID: $test_student_id)</p>";
        
        // Test 4: Retrieve and Display Student Data
        echo "<h3>📋 Test 4: Retrieve Student Data</h3>";
        
        $retrieve_test = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $retrieve_test->bind_param("i", $test_student_id);
        $retrieve_test->execute();
        $result = $retrieve_test->get_result();
        
        if($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
            echo "<h4 style='color: #FFD700;'>Student Record:</h4>";
            echo "<p><strong>Full Name:</strong> " . htmlspecialchars($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['last_name']) . "</p>";
            echo "<p><strong>Student ID:</strong> " . htmlspecialchars($student['student_id']) . "</p>";
            echo "<p><strong>Phone:</strong> " . htmlspecialchars($student['phone']) . "</p>";
            echo "<p><strong>Email:</strong> " . htmlspecialchars($student['email']) . "</p>";
            echo "<p><strong>Course:</strong> " . htmlspecialchars($student['course']) . "</p>";
            echo "</div>";
            echo "<p style='color: #51cf66;'>✅ Student data retrieved successfully</p>";
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Failed to retrieve student data</p>";
        }
        
        // Test 5: Registration Form Simulation
        echo "<h3>📝 Test 5: Registration Form Simulation</h3>";
        
        $form_data = [
            'username' => 'form_test_' . time(),
            'first_name' => 'Jane',
            'middle_name' => 'Marie',
            'last_name' => 'Wilson',
            'student_id' => '2024-FORM1',
            'age' => 21,
            'gender' => 'Female',
            'email' => 'jane.wilson@test.com',
            'phone' => '639876543210',
            'course' => 'BSIT',
            'year_level' => '2nd Year',
            'section' => 'B',
            'password' => 'password123',
            'confirm_password' => 'password123'
        ];
        
        // Simulate form validation
        $validation_errors = [];
        
        if(strlen($form_data['username']) < 3) {
            $validation_errors[] = "Username must be at least 3 characters long";
        }
        
        if(!preg_match("/^63[0-9]{10}$/", $form_data['phone'])) {
            $validation_errors[] = "Invalid phone number format";
        }
        
        if(!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
            $validation_errors[] = "Invalid email address";
        }
        
        if(!preg_match("/^\d{4}-\d{4}[A-Z]$/", $form_data['student_id'])) {
            $validation_errors[] = "Invalid Student ID format";
        }
        
        if(empty($validation_errors)) {
            echo "<p style='color: #51cf66;'>✅ Form validation passed</p>";
            echo "<p><strong>Simulated registration data:</strong></p>";
            echo "<div style='background: rgba(0,0,0,0.8); padding: 15px; border-radius: 10px; color: white; font-size: 14px;'>";
            echo "Name: " . htmlspecialchars($form_data['first_name'] . ' ' . $form_data['middle_name'] . ' ' . $form_data['last_name']) . "<br>";
            echo "Phone: " . htmlspecialchars($form_data['phone']) . "<br>";
            echo "Email: " . htmlspecialchars($form_data['email']) . "<br>";
            echo "Student ID: " . htmlspecialchars($form_data['student_id']) . "<br>";
            echo "</div>";
        } else {
            echo "<p style='color: #ff6b6b;'>❌ Form validation failed:</p>";
            echo "<ul style='color: #ff6b6b;'>";
            foreach($validation_errors as $error) {
                echo "<li>$error</li>";
            }
            echo "</ul>";
        }
        
        // Clean up test data
        $conn->query("DELETE FROM users WHERE id = $test_student_id");
        echo "<p style='color: #74c0fc;'>🧹 Test data cleaned up</p>";
        
    } else {
        echo "<p style='color: #ff6b6b;'>❌ Failed to create test student: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: #ff6b6b;'>❌ Failed to prepare test insertion: " . $conn->error . "</p>";
}

// Test 6: Display Current Users Table Structure
echo "<h3>📊 Test 6: Current Users Table Structure</h3>";
$result = $conn->query("DESCRIBE users");

echo "<div style='background: rgba(0,0,0,0.8); padding: 20px; border-radius: 15px; color: white;'>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: rgba(255,215,0,0.2);'>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Field</th>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Type</th>";
echo "<th style='padding: 8px; border: 1px solid rgba(255,215,0,0.3);'>Null</th>";
echo "</tr>";

while($row = $result->fetch_assoc()){
    $highlight = ($row['Field'] == 'middle_name' || $row['Field'] == 'phone') ? 'style="background: rgba(255,215,0,0.1);"' : '';
    echo "<tr $highlight>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Field'] . "</td>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Type'] . "</td>";
    echo "<td style='padding: 8px; border: 1px solid rgba(255,215,0,0.1);'>" . $row['Null'] . "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</div>";

// Next Steps
echo "<div style='margin-top: 30px;'>";
echo "<h3>🚀 Ready to Test:</h3>";
echo "<a href='REGISTER.PHP' style='background: linear-gradient(45deg, #28a745, #20c997); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; margin-right: 10px; display: inline-block;'>📝 Test Registration Form</a>";
echo "<a href='student_login.php' style='background: linear-gradient(45deg, #007bff, #0056b3); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; display: inline-block;'>👤 Student Login</a>";
echo "</div>";

echo "<div style='background: rgba(255,193,7,0.1); border: 2px solid rgba(255,215,0,0.3); padding: 20px; border-radius: 15px; margin-top: 20px;'>";
echo "<h3 style='color: #FFD700;'>📋 New Features Added:</h3>";
echo "<ul style='color: white;'>";
echo "<li>✅ Middle Name field (optional)</li>";
echo "<li>✅ Phone Number field with 63+ format validation</li>";
echo "<li>✅ Phone format: 63 followed by 10 digits (e.g., 639123456789)</li>";
echo "<li>✅ Updated registration form with new fields</li>";
echo "<li>✅ Student dashboard displays full name with middle name</li>";
echo "<li>✅ Phone number displayed in student profile</li>";
echo "<li>✅ Proper validation for phone number format</li>";
echo "</ul>";
echo "</div>";

$conn->close();
?>
