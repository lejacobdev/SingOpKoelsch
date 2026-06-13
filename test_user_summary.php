<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/config.php';

echo "Testing user-specific activity summary function...\n";

// Get all users to test with
$conn = Database::getConnection();
$result = $conn->query("SELECT user_id, name, email FROM singopkoelsch_users WHERE email IS NOT NULL AND email != '' LIMIT 5");
$users = $result->fetch_all(MYSQLI_ASSOC);

if (empty($users)) {
    echo "No users found to test with.\n";
    exit;
}

foreach ($users as $user) {
    $userId = $user['user_id'];
    $userName = $user['name'];
    $userEmail = $user['email'];

    echo "Testing for user: $userName ($userEmail) ID: $userId\n";

    try {
        $result = Database::sendUserActivitySummaryIfTime($userId, 24); // Last 24 hours
        if ($result) {
            echo "  Summary email processed successfully (sent or not time yet)\n";
        } else {
            echo "  Failed to process summary email\n";
        }
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
        error_log("User summary test error for user $userId: " . $e->getMessage());
    }

    echo "\n";
}

echo "Test completed.\n";
?>