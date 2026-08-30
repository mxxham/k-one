<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

class NotificationService {

    /**
     * Send an email via PHPMailer.
     *
     * @param string      $to      Recipient email address
     * @param string      $subject Email subject
     * @param string      $body    Email body (HTML or plain text)
     * @param bool        $isHtml  Whether body is HTML (default true)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function sendEmail(string $to, string $subject, string $body, bool $isHtml = true): array {
        try {
            $mail = new PHPMailer(true);

            // SMTP configuration
            $mail->isSMTP();
            $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
            $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
            $mail->SMTPAuth   = defined('SMTP_USER') && SMTP_USER !== '';
            $mail->Username   = defined('SMTP_USER') ? SMTP_USER : '';
            $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';

            if (defined('SMTP_SECURE')) {
                $mail->SMTPSecure = SMTP_SECURE;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            // Sender
            $fromEmail = defined('SMTP_FROM') ? SMTP_FROM : ($mail->Username ?: 'noreply@localhost');
            $fromName  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : APP_NAME;
            $mail->setFrom($fromEmail, $fromName);

            // Recipient
            $mail->addAddress($to);

            // Content
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            if ($isHtml) {
                $mail->AltBody = strip_tags($body);
            }

            $mail->send();

            return ['success' => true, 'message' => 'Email sent successfully'];
        } catch (MailException $e) {
            error_log('[NotificationService] Email send failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Email could not be sent: ' . $mail->ErrorInfo];
        } catch (\Throwable $e) {
            error_log('[NotificationService] Email error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Email error: ' . $e->getMessage()];
        }
    }

    /**
     * Send notification for inbound received.
     *
     * @param int         $inboundId   Inbound order ID
     * @param string      $orderNumber Inbound order number
     * @param string|null $toEmail     Optional recipient email (falls back to admin)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function notifyInboundReceived(int $inboundId, string $orderNumber, ?string $toEmail = null): array {
        $subject = "Inbound Received - Order #{$orderNumber}";
        $body    = self::renderTemplate('inbound_received', [
            'inboundId'  => $inboundId,
            'orderNumber' => $orderNumber,
        ]);

        $to = $toEmail ?? self::getAdminEmail();
        if (!$to) {
            self::logNotification(null, 'inbound_received', $subject, $body, 'skipped');
            return ['success' => false, 'message' => 'No recipient email configured'];
        }

        $result = self::sendEmail($to, $subject, $body);
        self::logNotification(
            null,
            'inbound_received',
            $subject,
            $body,
            $result['success'] ? 'sent' : 'failed'
        );

        return $result;
    }

    /**
     * Send notification for outbound shipped.
     *
     * @param int         $outboundId  Outbound order ID
     * @param string      $orderNumber Outbound order number
     * @param string|null $toEmail     Optional recipient email (falls back to admin)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function notifyOutboundShipped(int $outboundId, string $orderNumber, ?string $toEmail = null): array {
        $subject = "Outbound Shipped - Order #{$orderNumber}";
        $body    = self::renderTemplate('outbound_shipped', [
            'outboundId'  => $outboundId,
            'orderNumber' => $orderNumber,
        ]);

        $to = $toEmail ?? self::getAdminEmail();
        if (!$to) {
            self::logNotification(null, 'outbound_shipped', $subject, $body, 'skipped');
            return ['success' => false, 'message' => 'No recipient email configured'];
        }

        $result = self::sendEmail($to, $subject, $body);
        self::logNotification(
            null,
            'outbound_shipped',
            $subject,
            $body,
            $result['success'] ? 'sent' : 'failed'
        );

        return $result;
    }

    /**
     * Send notification for stock take completion.
     *
     * @param int         $stocktakeId Stock take ID
     * @param string|null $toEmail     Optional recipient email (falls back to admin)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function notifyStockTakeComplete(int $stocktakeId, ?string $toEmail = null): array {
        $subject = "Stock Take Complete - #{$stocktakeId}";
        $body    = self::renderTemplate('stocktake_complete', [
            'stocktakeId' => $stocktakeId,
        ]);

        $to = $toEmail ?? self::getAdminEmail();
        if (!$to) {
            self::logNotification(null, 'stocktake_complete', $subject, $body, 'skipped');
            return ['success' => false, 'message' => 'No recipient email configured'];
        }

        $result = self::sendEmail($to, $subject, $body);
        self::logNotification(
            null,
            'stocktake_complete',
            $subject,
            $body,
            $result['success'] ? 'sent' : 'failed'
        );

        return $result;
    }

    /**
     * Get recent notification history.
     *
     * @param int $limit Maximum number of records to return
     * @return array Notification records
     */
    public static function getNotificationHistory(int $limit = 50): array {
        try {
            $db    = db();
            $stmt  = $db->prepare("SELECT * FROM notifications ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('[NotificationService] getNotificationHistory error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Log a notification record to the database.
     */
    private static function logNotification(
        ?int    $userId,
        string  $type,
        string  $title,
        string  $body,
        string  $status = 'sent'
    ): void {
        try {
            $db = db();
            self::ensureTable($db);

            $userId = $userId ?? ($_SESSION['user_id'] ?? null);

            $stmt = $db->prepare("
                INSERT INTO notifications (user_id, type, title, body, status)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $type, $title, $body, $status]);
        } catch (\Throwable $e) {
            error_log('[NotificationService] logNotification error: ' . $e->getMessage());
        }
    }

    /**
     * Ensure the notifications table exists (idempotent).
     */
    private static function ensureTable(\PDO $db): void {
        static $ensured = false;
        if ($ensured) return;

        $db->exec("CREATE TABLE IF NOT EXISTS `notifications` (
            `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) DEFAULT NULL,
            `type` VARCHAR(50) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `body` TEXT DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'sent',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_notifications_user_id` (`user_id`),
            INDEX `idx_notifications_created_at` (`created_at`),
            INDEX `idx_notifications_type` (`type`),
            INDEX `idx_notifications_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $ensured = true;
    }

    /**
     * Render an email template with variables.
     *
     * @param string $template Template name (without .php extension)
     * @param array  $data     Variables to extract into template scope
     * @return string Rendered HTML
     */
    private static function renderTemplate(string $template, array $data = []): string {
        $templatePath = __DIR__ . '/../templates/email/' . $template . '.php';

        if (!file_exists($templatePath)) {
            error_log("[NotificationService] Template not found: {$templatePath}");
            return '<p>Notification from ' . (APP_NAME ?? 'K-one') . '</p>';
        }

        // Extract variables into template scope
        extract($data, EXTR_SKIP);

        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Get admin email for fallback notifications.
     *
     * @return string|null Admin email or null if not found
     */
    private static function getAdminEmail(): ?string {
        try {
            $db   = db();
            $stmt = $db->query("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1");
            $row  = $stmt->fetch();
            return $row ? $row['email'] : null;
        } catch (\Throwable $e) {
            error_log('[NotificationService] getAdminEmail error: ' . $e->getMessage());
            return null;
        }
    }
}
