<?php
require_once __DIR__ . '/mail_config.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function getMailer() {
    $mail = new PHPMailer(true);
    
    try {
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        error_log("Mailer configured. Host: " . SMTP_HOST . ", Username: " . SMTP_USERNAME . ", From: " . SMTP_FROM_EMAIL);
        
        return $mail;
    } catch (Exception $e) {
        error_log("Mailer configuration error: " . $e->getMessage());
        return null;
    }
}

function sendPasswordResetEmail($email, $token) {
    $mail = getMailer();
    if (!$mail) return false;
    
    try {
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'PEPO Password Reset Request';
        
        $resetLink = BASE_URL . '/reset_password.php?token=' . urlencode($token);
        
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Password Reset</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: linear-gradient(-45deg, #103a13, #064e3b, #129112, #000000); padding: 20px; border-radius: 10px 10px 0 0;">
                <h2 style="color: white; margin: 0;">PEPO Password Reset</h2>
            </div>
            <div style="background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px;">
                <p>Hello,</p>
                <p>We received a request to reset your PEPO account password. Click the button below to reset it:</p>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . $resetLink . '" style="background: #0ac347; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;">Reset Password</a>
                </div>
                <p style="font-size: 12px; color: #666;">
                    This link will expire in ' . RESET_TOKEN_EXPIRY . ' minutes.<br>
                    If you did not request this password reset, please ignore this email.
                </p>
                <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
                <p style="font-size: 11px; color: #999;">
                    PEPO - Equipment Pool Operations System
                </p>
            </div>
        </body>
        </html>
        ';
        
        $mail->AltBody = "PEPO Password Reset\n\nClick the link below to reset your password:\n$resetLink\n\nThis link will expire in " . RESET_TOKEN_EXPIRY . " minutes.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send password reset email: " . $mail->ErrorInfo);
        return false;
    }
}

function sendStatusUpdateEmail($requestId, $status, $mysqli = null) {
    if (!$mysqli) {
        require_once dirname(__DIR__) . '/config.php';
        global $mysqli;
    }
    
    $stmt = $mysqli->prepare("
        SELECT sr.id, sr.pre_repair_no, sr.property_no, sr.requested_by, sr.status, sr.admin_location, u.email 
        FROM supply_requests sr
        LEFT JOIN users u ON sr.requested_by = u.username
        WHERE sr.id = ?
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();
    
    if (!$request || empty($request['email'])) {
        error_log("No email found for supply request ID: $requestId. Request: " . json_encode($request));
        return false;
    }
    
    error_log("Sending email for request ID: $requestId, Status: $status, Email: " . $request['email'] . ", Admin Location: " . ($request['admin_location'] ?? 'N/A'));
    
    $mail = getMailer();
    if (!$mail) {
        error_log("getMailer() returned null for request ID: $requestId");
        return false;
    }
    
    try {
        $mail->addAddress($request['email']);
        $mail->isHTML(true);
        
        $statusDisplay = ucfirst($status);
        $mail->Subject = "PEPO Supply Request #$requestId - " . $statusDisplay;
        
        $icon = '';
        $bgColor = '';
        $emailBody = '';
        
        switch ($status) {
            case 'approved':        
                $icon = '✓';
                $bgColor = '#22c55e';
                $emailBody = '
                <p>Your supply request has been <strong>' . $statusDisplay . '</strong>.</p>
                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid ' . $bgColor . ';">
                    <h3 style="margin: 0 0 10px 0;">Request Details</h3>
                    <p style="margin: 5px 0;"><strong>Request ID:</strong> #' . $requestId . '</p>
                    <p style="margin: 5px 0;"><strong>Pre-Repair No:</strong> ' . htmlspecialchars($request['pre_repair_no'] ?? 'N/A') . '</p>
                    <p style="margin: 5px 0;"><strong>Property No:</strong> ' . htmlspecialchars($request['property_no'] ?? 'N/A') . '</p>
                    <p style="margin: 5px 0;"><strong>Status:</strong> <span style="color: ' . $bgColor . '; font-weight: bold;">' . $statusDisplay . '</span></p>
                </div>
                <p>Please log in to the PEPO system to view more details.</p>
                ';
                break;
            case 'complied':
                $icon = '✓✓';
                $bgColor = '#a855f7';
                $confirmLink = BASE_URL . '/confirm_received.php?id=' . $requestId;
                $emailBody = '
                <p>Your request has been fulfilled by the Supply Department and is now ready for collection.
Please visit the Supply Office during regular office hours to pick up your requested suppply. 
For more information and deliberation of you request, please contact the Supply Department directly.</p>
                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid ' . $bgColor . ';">
                    <h3 style="margin: 0 0 10px 0;">Request Details</h3>
                    <p style="margin: 5px 0;"><strong>Requested By:</strong> ' . htmlspecialchars($request['requested_by']) . '</p>
                    <p style="margin: 5px 0;"><strong>Maintenance Dept:</strong> ' . htmlspecialchars($request['admin_location'] ?? 'N/A') . '</p>
                    <p style="margin: 5px 0;"><strong>Status:</strong> <span style="color: ' . $bgColor . '; font-weight: bold;">' . $statusDisplay . '</span></p>
                    <p style="margin: 5px 0;"><strong>Pre-Repair No:</strong> ' . htmlspecialchars($request['pre_repair_no'] ?? 'N/A') . '</p>
                    <p style="margin: 5px 0;"><strong>Property No:</strong> ' . htmlspecialchars($request['property_no'] ?? 'N/A') . '</p>
                </div>
                <p>Action Required:</p>
                <p>Please click the button below once you have received your requested items to confirm completion.
</p>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . $confirmLink . '" style="background: #0ac347; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;">CONFIRM RECEIVED</a>
                </div>
                ';
                break;
            default:
                $icon = '•';
                $bgColor = '#6b7280';
                $emailBody = '
                <p>Your supply request status has been updated to <strong>' . $statusDisplay . '</strong>.</p>
                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid ' . $bgColor . ';">
                    <h3 style="margin: 0 0 10px 0;">Request Details</h3>
                    <p style="margin: 5px 0;"><strong>Request ID:</strong> #' . $requestId . '</p>
                    <p style="margin: 5px 0;"><strong>Pre-Repair No:</strong> ' . htmlspecialchars($request['pre_repair_no'] ?? 'N/A') . '</p>
                    <p style="margin: 5px 0;"><strong>Property No:</strong> ' . htmlspecialchars($request['property_no'] ?? 'N/A') . '</p>
                    <p style="margin: 5px 0;"><strong>Status:</strong> <span style="color: ' . $bgColor . '; font-weight: bold;">' . $statusDisplay . '</span></p>
                </div>
                ';
        }
        
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: linear-gradient(-45deg, #103a13, #064e3b, #129112, #000000); padding: 20px; border-radius: 10px 10px 0 0;">
                <h2 style="color: white; margin: 0;">PEPO Supply Request Update</h2>
            </div>
            <div style="background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px;">
                <p>Hello ' . htmlspecialchars($request['requested_by']) . ',</p>
                ' . $emailBody . '
                <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
                <p style="font-size: 11px; color: #999;">
                    PEPO - Equipment Pool Operations System<br>
                    This is an automated notification. Please do not reply to this email.
                </p>
            </div>
        </body>
        </html>
        ';
        
        $mail->AltBody = "PEPO Supply Request Update\n\nYour request #$requestId has been marked as $statusDisplay.\n\nPre-Repair No: " . ($request['pre_repair_no'] ?? 'N/A') . "\nProperty No: " . ($request['property_no'] ?? 'N/A');
        
        $mail->send();
        error_log("Email sent successfully to: " . $request['email'] . " for request ID: $requestId, status: $status");
        return true;
    } catch (Exception $e) {
        error_log("Failed to send status update email for request $requestId: " . $mail->ErrorInfo . " - Exception: " . $e->getMessage());
        return false;
    }
}

function sendPasswordChangeNotification($email, $username) {
    $mail = getMailer();
    if (!$mail) return false;
    
    try {
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'PEPO Password Changed';

        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: linear-gradient(-45deg, #103a13, #064e3b, #129112, #000000); padding: 20px; border-radius: 10px 10px 0 0;">
                <h2 style="color: white; margin: 0;">PEPO Password Changed</h2>
            </div>
            <div style="background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px;">
                <p>Hello ' . htmlspecialchars($username) . ',</p>
                <p>Your PEPO account password has been successfully changed.</p>
                <p>If you did not make this change, please contact your administrator immediately.</p>
                <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
                <p style="font-size: 11px; color: #999;">
                    PEPO - Equipment Pool Operations System
                </p>
            </div>
        </body>
        </html>
        ';
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send password change notification: " . $mail->ErrorInfo);
        return false;
    }
}

function sendRepairCompleteEmail($documentId, $mysqli = null) {
    $closeConnection = false;
    if (!$mysqli) {
        require_once dirname(__DIR__) . '/config.php';
        global $mysqli;
        $closeConnection = true;
    }
    
    // Use user_id to get the requester's email
    $stmt = $mysqli->prepare("
        SELECT d.id, d.pre_repair_no, d.property_no, d.officer_name, d.status, d.date_completed, d.location, d.user_id,
               u.email, u.username
        FROM documents d
        LEFT JOIN users u ON d.user_id = u.id
        WHERE d.id = ?
    ");
    $stmt->bind_param("i", $documentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $document = $result->fetch_assoc();
    $stmt->close();
    
    if (!$document) {
        error_log("Document not found for ID: $documentId");
        return false;
    }
    
    if (empty($document['email'])) {
        error_log("No email found for document ID: $documentId. User ID: " . ($document['user_id'] ?? 'N/A') . ", Officer: " . ($document['officer_name'] ?? 'N/A'));
        return false;
    }
    
    error_log("Sending email to: " . $document['email'] . " for document ID: $documentId");
    
    $mail = getMailer();
    if (!$mail) {
        error_log("getMailer() returned null for document ID: $documentId");
        return false;
    }
    
    try {
        $mail->addAddress($document['email']);
        $mail->isHTML(true);
        $mail->Subject = "PEPO Repair/Maintenance - Completed - " . htmlspecialchars($document['property_no'] ?? $document['pre_repair_no']);
        
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: linear-gradient(-45deg, #103a13, #064e3b, #129112, #000000); padding: 20px; border-radius: 10px 10px 0 0;">
                <h2 style="color: white; margin: 0;">PEPO Repair/Maintenance Completed</h2>
            </div>
            <div style="background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px;">
                <p>Hello ' . htmlspecialchars($document['username'] ?? $document['officer_name']) . ',</p>
                <p>Your repair/maintenance request has been <strong style="color: #22c55e;">COMPLETED</strong>.</p>
                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #22c55e;">
                    <h3 style="margin: 0 0 15px 0; color: #064e3b;">Request Details</h3>
                    <p style="margin: 8px 0;"><strong>Document ID:</strong> #' . $documentId . '</p>
                    <p style="margin: 8px 0;"><strong>Pre-Repair No:</strong> ' . htmlspecialchars($document['pre_repair_no'] ?? 'N/A') . '</p>
                    <p style="margin: 8px 0;"><strong>Property No:</strong> ' . htmlspecialchars($document['property_no'] ?? 'N/A') . '</p>
                    <p style="margin: 8px 0;"><strong>Location:</strong> ' . htmlspecialchars($document['location'] ?? 'N/A') . '</p>
                    <p style="margin: 8px 0;"><strong>Status:</strong> <span style="color: #22c55e; font-weight: bold;">COMPLETED</span></p>
                    <p style="margin: 8px 0;"><strong>Date Completed:</strong> ' . htmlspecialchars($document['date_completed'] ?? date('Y-m-d')) . '</p>
                </div>
                <p>Please log in to the PEPO system to view the full details and download the completed document.</p>
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . BASE_URL . '/users/view_document.php?id=' . $documentId . '" style="background: #0ac347; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;">View Document</a>
                </div>
                <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
                <p style="font-size: 11px; color: #999;">
                    PEPO - Equipment Pool Operations System<br>
                    This is an automated notification. Please do not reply to this email.
                </p>
            </div>
        </body>
        </html>
        ';
        
        $mail->AltBody = "PEPO Repair/Maintenance Completed\n\n" .
            "Your request has been COMPLETED.\n\n" .
            "Document ID: #" . $documentId . "\n" .
            "Pre-Repair No: " . ($document['pre_repair_no'] ?? 'N/A') . "\n" .
            "Property No: " . ($document['property_no'] ?? 'N/A') . "\n" .
            "Location: " . ($document['location'] ?? 'N/A') . "\n" .
            "Date Completed: " . ($document['date_completed'] ?? date('Y-m-d')) . "\n\n" .
            "Log in to the PEPO system to view details.";
        
        $mail->send();
        error_log("Repair complete email sent successfully to: " . $document['email'] . " for document ID: $documentId");
        
        if ($closeConnection) {
            $mysqli->close();
        }
        return true;
    } catch (Exception $e) {
        error_log("Failed to send repair complete email for document $documentId: " . $mail->ErrorInfo);
        if ($closeConnection) {
            $mysqli->close();
        }
        return false;
    }
}
