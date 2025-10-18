<?php
/**
 * Provides common helper functions for the POS system.
 */

/**
 * Log an activity to the activity_logs table.
 *
 * @param PDO    $pdo        Database connection
 * @param int    $user_id    ID of the user performing the action
 * @param string $action     A short label for the action (e.g., 'Sale', 'Purchase', 'Transfer')
 * @param string $description Detailed description of the activity
 * @param int    $branch_id  The branch ID associated with the activity
 */
function logActivity(PDO $pdo, int $user_id, string $action, string $description, int $branch_id)
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs (user_id, action, description, branch_id, created_at) VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$user_id, $action, $description, $branch_id]);
    } catch (Throwable $e) {
        // Logging should never interrupt normal application flow; errors are suppressed
    }
}