<?php
// Mail Helper Functions

/**
 * Send an email using PHPMailer
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $html_message HTML message body
 * @param string $text_message Plain text message body (optional)
 * @return array ['success' => bool, 'message' => string]
 */
function send_mail($to, $subject, $html_message, $text_message = '') {
    // Check if PHPMailer is installed
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        return [
            'success' => false,
            'message' => 'PHPMailer is not installed. Run: composer require phpmailer/phpmailer'
        ];
    }

    // Load PHPMailer
    require_once __DIR__ . '/../vendor/autoload.php';
    
    // Load mail configuration
    $config = include(__DIR__ . '/mail_config.php');
    
    // Create a new PHPMailer instance
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    
    try {
        // Server settings
        $mail->SMTPDebug = $config['debug_level'];
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->SMTPSecure = $config['encryption'];
        $mail->Port       = $config['port'];
        
        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_message;
        
        if (!empty($text_message)) {
            $mail->AltBody = $text_message;
        } else {
            // Create a plain text version from HTML
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_message));
        }
        
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'Email sent successfully'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "Email could not be sent. Mailer Error: {$mail->ErrorInfo}"
        ];
    }
}

/**
 * Check if we're in a development environment
 * 
 * @return bool
 */
function is_development_environment() {
    $local_hosts = ['localhost', '127.0.0.1', '::1'];
    $local_domains = ['.local', '.test', '.dev'];
    
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    
    // Check if host is in local_hosts array
    if (in_array($host, $local_hosts)) {
        return true;
    }
    
    // Check if host ends with any of the local domains
    foreach ($local_domains as $domain) {
        if (substr($host, -strlen($domain)) === $domain) {
            return true;
        }
    }
    
    return false;
}