<?php

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Sends every kind of email the API sends. Each kind is a TYPE_* constant whose subject and body
 * come from the Email language file (Email.<type>.subject / Email.<type>.body). The body goes out as
 * HTML inside Views/email/template-v1.php (whose other text is Email.template.*), with the plain
 * text as the alternative part.
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

    /** In beta, emails to these domains (lowercase, without "@") go to the tester's own address instead */
    private const BETA_REDIRECT_DOMAINS = [
        'eikin.otternova.com' => 'lee.ratinan@gmail.com',
        'tat.otternova.com'   => 'tattananan.boss@gmail.com',
    ];

    private const TEMPLATE = 'email/template-v1';

    /** Locale of the email being sent, for the template text (null: the request's locale) */
    private ?string $locale = null;

    /**
     * @param string $type One of the TYPE_* constants
     * @param string $to
     * @param array       $data   Placeholders for the subject and body
     * @param string|null $locale The recipient's language, such as their preferred_language; null for the request's
     *                            locale. See supportedLocale() for how it maps onto Config\App::$supportedLocales. A
     *                            line not translated into it yet falls back to Config\App::$defaultLocale
     * @return bool
     */
    public function send(string $type, string $to, array $data = [], ?string $locale = null): bool
    {
        if (! in_array($type, [self::TYPE_LOGIN_OTP, self::TYPE_ACCOUNT_ACTIVATION, self::TYPE_ACCOUNT_EXISTS, self::TYPE_PASSWORD_RESET, self::TYPE_BUSINESS_INVITATION], true)) {
            throw new InvalidArgumentException('Unknown email type: ' . $type);
        }

        $this->locale = $this->supportedLocale($locale);
        try {
            return $this->deliver($to, $this->line($type . '.subject', $data), $this->line($type . '.body', $data));
        } finally {
            $this->locale = null;
        }
    }

    /**
     * The full HTML email: the plain-text body as HTML paragraphs (links made clickable) inside the template,
     * with the template's own text translated.
     * @param string      $subject
     * @param string      $body   Plain text; blank lines separate paragraphs
     * @param string|null $locale As in send()
     * @return string
     */
    public function render(string $subject, string $body, ?string $locale = null): string
    {
        $previous     = $this->locale;
        $this->locale = $this->supportedLocale($locale);
        try {
            $address = $this->line('template.company_address');

            return strtr(view(self::TEMPLATE, [], ['saveData' => false]), [
                '{{lang}}'                 => esc($this->locale ?? service('request')->getLocale()),
                '{{subject}}'              => esc($subject),
                '{{email_content}}'        => $this->bodyToHtml($body),
                '{{closing_message}}'      => esc($this->line('template.closing_message')),
                '{{best_regards}}'         => esc($this->line('template.best_regards')),
                '{{team_name}}'            => esc($this->line('template.team_name')),
                '{{company_name}}'         => esc($this->line('template.company_name')),
                '{{company_address_line}}' => $address === '' ? '' : nl2br(esc($address), false) . '<br>',
                '{{contact_support}}'      => esc($this->line('template.contact_support')),
                '{{privacy_policy}}'       => esc($this->line('template.privacy_policy')),
                '{{all_rights_reserved}}'  => esc($this->line('template.all_rights_reserved')),
                '{{year}}'                 => date('Y'),
            ]);
        } finally {
            $this->locale = $previous;
        }
    }

    /**
     * A BCP 47 tag as one of Config\App::$supportedLocales: the same tag (any case), else the first one of the same
     * language (th-TH -> th, en-GB -> en-US); null when it is empty or no supported locale has its language
     * @param string|null $tag
     * @return string|null
     */
    public function supportedLocale(?string $tag): ?string
    {
        $tag = strtolower(str_replace('_', '-', trim((string) $tag)));
        if ($tag === '') {
            return null;
        }
        $supported = config('App')->supportedLocales;
        foreach ($supported as $locale) {
            if (strtolower($locale) === $tag) {
                return $locale;
            }
        }
        $language = explode('-', $tag, 2)[0];
        foreach ($supported as $locale) {
            if (strtolower(explode('-', $locale, 2)[0]) === $language) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Email.<key> in $this->locale, or in the default locale while it has no translation there yet
     * (the framework itself would fall back to "en", which has no files, and return the key)
     * @param string $key
     * @param array  $data
     * @return string
     */
    private function line(string $key, array $data = []): string
    {
        $line = lang('Email.' . $key, $data, $this->locale);
        if ($line === 'Email.' . $key) {
            $line = lang('Email.' . $key, $data, config('App')->defaultLocale);
        }

        return $line;
    }

    /**
     * Plain text to HTML paragraphs: escaped, blank lines start a new paragraph, single line breaks become <br>,
     * and http(s) links become clickable
     * @param string $body
     * @return string
     */
    private function bodyToHtml(string $body): string
    {
        $paragraphs = preg_split('/\R{2,}/', trim($body)) ?: [];
        $html       = '';
        foreach ($paragraphs as $paragraph) {
            $text  = nl2br(esc($paragraph), false);
            $text  = preg_replace('~https?://[^\s<]+~', '<a href="$0" style="color: #c97232; word-break: break-all;">$0</a>', $text);
            $html .= '<p style="margin: 0 0 16px;">' . $text . "</p>\n";
        }

        return $html;
    }

    /**
     * Sends the email for real in production, and in beta only to known testers (see recipientFor()).
     * Everywhere else, and for anyone else in beta, the log line stands in for the email.
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return bool
     */
    protected function deliver(string $to, string $subject, string $body): bool
    {
        $recipient = $this->recipientFor($to);
        if ($recipient === null) {
            log_message('info', "Email to {to}: {subject}\n{body}", ['to' => $to, 'subject' => $subject, 'body' => $body]);

            return true;
        }
        if ($recipient !== $to) {
            log_message('info', 'Email to {to} redirected to {recipient}', ['to' => $to, 'recipient' => $recipient]);
            $to = $recipient;
        }

        $email = service('email');
        $email->setFrom((string) (env('NO_REPLY_EMAIL') ?: 'no-reply@otternova.com'), 'OtterNova');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMailType('html');
        $email->setMessage($this->render($subject, $body, $this->locale));
        $email->setAltMessage($body);

        if (! $email->send(false)) {
            log_message('error', 'Email to {to} failed: {subject}', ['to' => $to, 'subject' => $subject]);

            return false;
        }
        $email->clear();
        log_message('info', 'Email sent to {to}: {subject}', ['to' => $to, 'subject' => $subject]);

        return true;
    }

    /**
     * Where an email to this address really goes, or null when it does not go out: the address itself in production;
     * in beta, the address itself for BETA_ALLOWED_EMAILS and BETA_ALLOWED_DOMAINS, or the tester's address for
     * BETA_REDIRECT_DOMAINS; nowhere in any other environment.
     * @param string $to
     * @return string|null
     */
    protected function recipientFor(string $to): ?string
    {
        if (ENVIRONMENT === 'production') {
            return $to;
        }
        if (ENVIRONMENT !== 'beta') {
            return null;
        }

        $address = strtolower(trim($to));
        if (in_array($address, self::BETA_ALLOWED_EMAILS, true)) {
            return $to;
        }
        $at = strrpos($address, '@');
        if ($at === false) {
            return null;
        }
        $domain = substr($address, $at + 1);
        if (isset(self::BETA_REDIRECT_DOMAINS[$domain])) {
            return self::BETA_REDIRECT_DOMAINS[$domain];
        }

        return in_array($domain, self::BETA_ALLOWED_DOMAINS, true) ? $to : null;
    }
}
