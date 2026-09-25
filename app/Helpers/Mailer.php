<?php

namespace App\Helpers;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

class Mailer
{
    public static function isSmtpConfigured(): bool
    {
        $required = [
            (string)($_ENV['MAIL_HOST'] ?? ''),
            (string)($_ENV['MAIL_PORT'] ?? ''),
            (string)($_ENV['MAIL_USERNAME'] ?? ''),
            (string)($_ENV['MAIL_PASSWORD'] ?? ''),
            (string)($_ENV['MAIL_FROM_ADDRESS'] ?? ''),
        ];

        foreach ($required as $value) {
            if ($value === '' || str_contains($value, 'your_')) {
                return false;
            }
        }

        return true;
    }

    public static function sendResetOtp(string $toEmail, string $otpCode, int $expiryMinutes): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->Port = (int)$_ENV['MAIL_PORT'];
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'];
            $mail->Password = $_ENV['MAIL_PASSWORD'];

            $encryption = strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls');
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'none') {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom(
                $_ENV['MAIL_FROM_ADDRESS'],
                $_ENV['MAIL_FROM_NAME'] ?? 'Bookly'
            );
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = 'Your Bookly OTP Code';
            $mail->Body = "
                <div style='font-family:Arial,sans-serif;line-height:1.6;'>
                    <h2 style='margin:0 0 12px;'>Password Reset OTP</h2>
                    <p>Use this OTP code to reset your Bookly account password:</p>
                    <p style='font-size:28px;font-weight:700;letter-spacing:4px;margin:12px 0;color:#f97316;'>{$otpCode}</p>
                    <p>This code will expire in {$expiryMinutes} minutes.</p>
                    <p>If you didn't request this, you can ignore this email.</p>
                </div>
            ";
            $mail->AltBody = "Your Bookly OTP code is {$otpCode}. It expires in {$expiryMinutes} minutes.";
            $mail->send();
        } catch (Exception $e) {
            throw new RuntimeException('Failed to send OTP email: ' . $mail->ErrorInfo);
        }
    }

    public static function sendRegistrationOtp(string $toEmail, string $otpCode, int $expiryMinutes): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->Port = (int)$_ENV['MAIL_PORT'];
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['MAIL_USERNAME'];
            $mail->Password = $_ENV['MAIL_PASSWORD'];

            $encryption = strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls');
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'none') {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom(
                $_ENV['MAIL_FROM_ADDRESS'],
                $_ENV['MAIL_FROM_NAME'] ?? 'Bookly'
            );
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = 'Verify your Bookly account';
            $mail->Body = "
                <div style='font-family:Arial,sans-serif;line-height:1.6;'>
                    <h2 style='margin:0 0 12px;'>Welcome to Bookly</h2>
                    <p>Use this OTP code to verify your email and activate your account:</p>
                    <p style='font-size:28px;font-weight:700;letter-spacing:4px;margin:12px 0;color:#f97316;'>{$otpCode}</p>
                    <p>This code will expire in {$expiryMinutes} minutes.</p>
                </div>
            ";
            $mail->AltBody = "Your Bookly verification OTP code is {$otpCode}. It expires in {$expiryMinutes} minutes.";
            $mail->send();
        } catch (Exception $e) {
            throw new RuntimeException('Failed to send registration OTP email: ' . $mail->ErrorInfo);
        }
    }
}
