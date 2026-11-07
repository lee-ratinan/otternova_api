<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Sends every kind of email the API sends. Each kind is a TYPE_* constant whose subject and body
 * come from the Email language file (Email.<type>.subject / Email.<type>.body).
 */
class EmailService
{
    public const TYPE_LOGIN_OTP          = 'login_otp';
    public const TYPE_ACCOUNT_ACTIVATION = 'account_activation';
    public const TYPE_ACCOUNT_EXISTS     = 'account_exists';
    public const TYPE_PASSWORD_RESET     = 'password_reset';
    public const TYPE_BUSINESS_INVITATION = 'business_invitation';

    /** In beta, only these domains (lowercase, without "@") get real emails */
    private const BETA_ALLOWED_DOMAINS = ['otternova.com'];

    /** In beta, these known testers (lowercase) also get real emails */
    private const BETA_ALLOWED_EMAILS = [
        'lee.ratinan@gmail.com',
        'tattananan.boss@gmail.com',
    ];

    /**
     * @param string $type One of the TYPE_* constants
     * @param string $to
     * @param array  $data Placeholders for the subject and body
     * @return bool
     */
    public function send(string $type, string $to, array $data = []): bool
    {
        if (! in_array($type, [self::TYPE_LOGIN_OTP, self::TYPE_ACCOUNT_ACTIVATION, self::TYPE_ACCOUNT_EXISTS, self::TYPE_PASSWORD_RESET, self::TYPE_BUSINESS_INVITATION], true)) {
            throw new InvalidArgumentException('Unknown email type: ' . $type);
        }

        return $this->deliver($to, lang('Email.' . $type . '.subject', $data), lang('Email.' . $type . '.body', $data));
    }

    /**
     * Sends the email for real in production, and in beta only to known testers (see canSendTo()).
     * Everywhere else, and for anyone else in beta, the log line stands in for the email.
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return bool
     */
    protected function deliver(string $to, string $subject, string $body): bool
    {
        if (! $this->canSendTo($to)) {
            log_message('info', "Email to {to}: {subject}\n{body}", ['to' => $to, 'subject' => $subject, 'body' => $body]);

            return true;
        }

        $email = service('email');
        $email->setFrom((string) (env('NO_REPLY_EMAIL') ?: 'no-reply@otternova.com'), 'OtterNova');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($body);

        if (! $email->send(false)) {
            log_message('error', 'Email to {to} failed: {subject}', ['to' => $to, 'subject' => $subject]);

            return false;
        }
        $email->clear();
        log_message('info', 'Email sent to {to}: {subject}', ['to' => $to, 'subject' => $subject]);

        return true;
    }

    /**
     * Whether an email to this address really goes out: anyone in production; in beta, only
     * BETA_ALLOWED_DOMAINS and BETA_ALLOWED_EMAILS; nobody in any other environment.
     * @param string $to
     * @return bool
     */
    protected function canSendTo(string $to): bool
    {
        if (ENVIRONMENT === 'production') {
            return true;
        }
        if (ENVIRONMENT !== 'beta') {
            return false;
        }

        $to = strtolower(trim($to));
        if (in_array($to, self::BETA_ALLOWED_EMAILS, true)) {
            return true;
        }
        $at = strrpos($to, '@');

        return $at !== false && in_array(substr($to, $at + 1), self::BETA_ALLOWED_DOMAINS, true);
    }
}
