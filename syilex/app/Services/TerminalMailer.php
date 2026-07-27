<?php

namespace App\Services;

use App\Models\MasterPosTerminal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Send email using per-terminal SMTP|Resend config (XOR).
 */
class TerminalMailer
{
    /**
     * @param  array{path: string, name: string, mime?: string}|null  $attachment
     */
    public static function send(
        MasterPosTerminal $terminal,
        string $to,
        string $subject,
        string $textBody,
        ?string $htmlBody = null,
        ?array $attachment = null,
    ): void {
        if (! $terminal->isMailConfigured()) {
            throw new RuntimeException('Email terminal belum dikonfigurasi.');
        }

        if ($terminal->mail_driver === 'resend') {
            $payload = [
                'from' => self::fromHeader($terminal),
                'to' => [$to],
                'subject' => $subject,
                'text' => $textBody,
            ];
            if ($htmlBody) {
                $payload['html'] = $htmlBody;
            }
            if ($attachment) {
                $payload['attachments'] = [[
                    'filename' => $attachment['name'],
                    'content' => base64_encode(file_get_contents($attachment['path'])),
                ]];
            }
            Http::withToken($terminal->resend_api_key)
                ->post('https://api.resend.com/emails', $payload)
                ->throw();

            return;
        }

        config(['mail.mailers.terminal-receipt' => [
            'transport' => 'smtp',
            'host' => $terminal->smtp_host,
            'port' => $terminal->smtp_port,
            'encryption' => $terminal->smtp_encryption,
            'username' => $terminal->smtp_username,
            'password' => $terminal->smtp_password,
        ]]);

        Mail::purge('terminal-receipt');
        try {
            $fromName = $terminal->mail_from_name ?: config('mail.from.name');
            $mailer = Mail::mailer('terminal-receipt');

            if ($htmlBody) {
                $mailer->html($htmlBody, function ($mail) use ($terminal, $to, $subject, $attachment, $textBody, $fromName) {
                    $mail->to($to)
                        ->from($terminal->mail_from_address, $fromName)
                        ->subject($subject)
                        ->text($textBody);
                    if ($attachment) {
                        $mail->attach($attachment['path'], [
                            'as' => $attachment['name'],
                            'mime' => $attachment['mime'] ?? 'application/pdf',
                        ]);
                    }
                });
            } else {
                $mailer->raw($textBody, function ($mail) use ($terminal, $to, $subject, $attachment, $fromName) {
                    $mail->to($to)
                        ->from($terminal->mail_from_address, $fromName)
                        ->subject($subject);
                    if ($attachment) {
                        $mail->attach($attachment['path'], [
                            'as' => $attachment['name'],
                            'mime' => $attachment['mime'] ?? 'application/pdf',
                        ]);
                    }
                });
            }
        } finally {
            Mail::purge('terminal-receipt');
        }
    }

    public static function fromHeader(MasterPosTerminal $terminal): string
    {
        return $terminal->mail_from_name
            ? "{$terminal->mail_from_name} <{$terminal->mail_from_address}>"
            : $terminal->mail_from_address;
    }
}
