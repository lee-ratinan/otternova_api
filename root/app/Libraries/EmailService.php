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

    /**
     * @param string $type One of the TYPE_* constants
     * @param string $to
     * @param array  $data Placeholders for the subject and body
     * @return bool
     */
    public function send(string $type, string $to, array $data = []): bool
    {
        if (! in_array($type, [self::TYPE_LOGIN_OTP, self::TYPE_ACCOUNT_ACTIVATION, self::TYPE_ACCOUNT_EXISTS, self::TYPE_PASSWORD_RESET], true)) {
            throw new InvalidArgumentException('Unknown email type: ' . $type);
        }

        return $this->deliver($to, lang('Email.' . $type . '.subject', $data), lang('Email.' . $type . '.body', $data));
    }

    /**
     * Not connected to a mail server yet: the log line stands in for the email.
     * Replace this with service('email') once SMTP is set up.
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return bool
     */
    protected function deliver(string $to, string $subject, string $body): bool
    {
        log_message('info', "Email to {to}: {subject}\n{body}", ['to' => $to, 'subject' => $subject, 'body' => $body]);

        return true;
    }
}
