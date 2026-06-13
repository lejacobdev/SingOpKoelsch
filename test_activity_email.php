<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/config.php';

// We need to be in an admin context or bypass protection for testing
// For simplicity, let's just call the function directly
// Note: This assumes the script is run in a context where database connection works

echo "Testing activity summary email function...\n";

try {
    $result = Database::sendActivitySummaryEmail(24); // Last 24 hours
    if ($result) {
        echo "Email sent successfully (or no activity to report).\n";
    } else {
        echo "Failed to send email.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    error_log("Activity email test error: " . $e->getMessage());
}
?>