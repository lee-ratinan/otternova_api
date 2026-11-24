<?php

namespace App\Libraries;

use App\Models\EmailLogModel;
use InvalidArgumentException;
use Throwable;

/**
 * Sends every kind of email the API sends. Each kind is a TYPE_* constant whose subject and body
 * come from the Email language file (Email.<type>.subject / Email.<type>.body). The body goes out as
 * HTML inside Views/email/template-v1.php (whose other text is Email.template.*), with the plain
 * text as the alternative part. A placeholder given as a table (see send()) is a table in the HTML.
 * Every email that deliver() handles is written to email_log (App\Models\EmailLogModel), sent or not.
 */
class EmailService
{
    public const TYPE_LOGIN_OTP          = 'login_otp';
    public const TYPE_ACCOUNT_ACTIVATION = 'account_activation';
    public const TYPE_ACCOUNT_EXISTS     = 'account_exists';
    public const TYPE_PASSWORD_RESET     = 'password_reset';
    public const TYPE_BUSINESS_INVITATION = 'business_invitation';
    public const TYPE_ORDER_CANCELLED      = 'order_cancelled';      // to the customer: an unpaid order was cancelled
    public const TYPE_ORDER_CANCELLED_PAID = 'order_cancelled_paid'; // to the customer: a paid order was cancelled, a refund to come
    public const TYPE_ORDER_RESCHEDULED    = 'order_rescheduled';    // to the customer: a booking moved to another time
    public const TYPE_ORDER_PLACED         = 'order_placed';         // to the customer: the order, and how to pay it
    public const TYPE_ORDER_PLACED_PAID    = 'order_placed_paid';    // to the customer: the order, paid already
    public const TYPE_ORDER_HISTORY_LINK   = 'order_history_link';   // to the customer: the link into their order history at a business

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

    /** Type of the email being sent, for email_log */
    private ?string $type = null;

    /** Locale of the email being sent, for the template text (null: the request's locale) */
    private ?string $locale = null;

    /** The tables of the email being sent, by placeholder (see send()) */
    private array $tables = [];

    /** The body of the email being sent with each table's placeholder as its token, for the HTML (null: no tables) */
    private ?string $htmlBody = null;

    /**
     * @param string $type One of the TYPE_* constants
     * @param string $to
     * @param array       $data   Placeholders for the subject and body
     * @param string|null $locale The recipient's language, such as their preferred_language; null for the request's
     *                            locale. See supportedLocale() for how it maps onto Config\App::$supportedLocales. A
     *                            line not translated into it yet falls back to Config\App::$defaultLocale
     * @param array       $tables Placeholders of $data that the HTML shows as a table instead of their plain text, each a
     *                            list of rows: ['item' => string, 'detail' => ?string, 'quantity' => int|string|null,
     *                            'amount' => string]. The placeholder should be a paragraph of its own in the body
     * @return bool
     */
    public function send(string $type, string $to, array $data = [], ?string $locale = null, array $tables = []): bool
    {
        if (! in_array($type, [self::TYPE_LOGIN_OTP, self::TYPE_ACCOUNT_ACTIVATION, self::TYPE_ACCOUNT_EXISTS, self::TYPE_PASSWORD_RESET, self::TYPE_BUSINESS_INVITATION,
            self::TYPE_ORDER_CANCELLED, self::TYPE_ORDER_CANCELLED_PAID, self::TYPE_ORDER_RESCHEDULED,
            self::TYPE_ORDER_PLACED, self::TYPE_ORDER_PLACED_PAID, self::TYPE_ORDER_HISTORY_LINK], true)) {
            throw new InvalidArgumentException('Unknown email type: ' . $type);
        }

        $this->type   = $type;
        $this->locale = $this->supportedLocale($locale);
        $this->tables = $tables;
        try {
            $tokens         = [];
            foreach (array_keys($tables) as $name) {
                $tokens[$name] = self::tableToken($name);
            }
            $this->htmlBody = $tables === [] ? null : $this->line($type . '.body', array_merge($data, $tokens));

            return $this->deliver($to, $this->line($type . '.subject', $data), $this->line($type . '.body', $data));
        } finally {
            $this->type     = null;
            $this->locale   = null;
            $this->tables   = [];
            $this->htmlBody = null;
        }
    }

    /**
     * The full HTML email: the plain-text body as HTML paragraphs (links made clickable) inside the template,
     * with the template's own text translated.
     * @param string      $subject
     * @param string      $body   Plain text; blank lines separate paragraphs
     * @param string|null $locale As in send()
     * @param array       $tables As in send(): a paragraph that is only a table's token (see tableToken()) becomes that table
     * @return string
     */
    public function render(string $subject, string $body, ?string $locale = null, array $tables = []): string
    {
        $previous     = $this->locale;
        $this->locale = $this->supportedLocale($locale);
        try {
            $address = $this->line('template.company_address');

            return strtr(view(self::TEMPLATE, [], ['saveData' => false]), [
                '{{lang}}'                 => esc($this->locale ?? service('request')->getLocale()),
                '{{subject}}'              => esc($subject),
                '{{email_content}}'        => $this->bodyToHtml($body, $tables),
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
     * What stands for a table in the body that render() is given
     * @param string $name The table's placeholder
     * @return string
     */
    public static function tableToken(string $name): string
    {
        return '[[table:' . $name . ']]';
    }

    /**
     * Plain text to HTML paragraphs: escaped, blank lines start a new paragraph, single line breaks become <br>,
     * and http(s) links become clickable; a paragraph that is only a table's token is that table
     * @param string $body
     * @param array  $tables As in send()
     * @return string
     */
    private function bodyToHtml(string $body, array $tables = []): string
    {
        $paragraphs = preg_split('/\R{2,}/', trim($body)) ?: [];
        $html       = '';
        foreach ($paragraphs as $paragraph) {
            foreach ($tables as $name => $rows) {
                if (trim($paragraph) === self::tableToken((string) $name)) {
                    $html .= $this->tableToHtml($rows);

                    continue 2;
                }
            }
            $text  = nl2br(esc($paragraph), false);
            $text  = preg_replace('~https?://[^\s<]+~', '<a href="$0" style="color: #c97232; word-break: break-all;">$0</a>', $text);
            $html .= '<p style="margin: 0 0 16px;">' . $text . "</p>\n";
        }

        return $html;
    }

    /**
     * Rows as a table the width of the content: the item (its detail below it), the quantity and the amount
     * @param array $rows As in send()
     * @return string
     */
    private function tableToHtml(array $rows): string
    {
        $cell = 'padding: 10px 0; border-bottom: 1px solid #eeeeee; vertical-align: top;';
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width: 100%; border-collapse: collapse; table-layout: auto; margin: 0 0 16px; font-size: 14px; line-height: 1.5;">' . "\n";
        foreach ($rows as $row) {
            $item   = esc((string) ($row['item'] ?? ''));
            $detail = trim((string) ($row['detail'] ?? ''));
            if ($detail !== '') {
                $item .= '<br><span style="color: #888888; font-size: 13px;">' . esc($detail) . '</span>';
            }
            $quantity = (string) ($row['quantity'] ?? '');
            $html    .= '<tr>'
                . '<td class="email-divider" style="' . $cell . ' word-break: break-word;">' . $item . '</td>'
                . '<td class="email-divider" style="' . $cell . ' padding-left: 12px; text-align: right; white-space: nowrap;">'
                . ($quantity === '' ? '' : '× ' . esc($quantity)) . '</td>'
                . '<td class="email-divider" style="' . $cell . ' padding-left: 12px; text-align: right; white-space: nowrap;">'
                . esc((string) ($row['amount'] ?? '')) . '</td>'
                . "</tr>\n";
        }

        return $html . "</table>\n";
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
            $this->logEmail($to, null, $subject, EmailLogModel::STATUS_SUPPRESSED);

            return true;
        }
        $intended = $to;
        if ($recipient !== $to) {
            log_message('info', 'Email to {to} redirected to {recipient}', ['to' => $to, 'recipient' => $recipient]);
            $to = $recipient;
        }

        $email = service('email');
        $email->setFrom((string) (env('NO_REPLY_EMAIL') ?: 'no-reply@otternova.com'), 'OtterNova');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMailType('html');
        $email->setMessage($this->render($subject, $this->htmlBody ?? $body, $this->locale, $this->tables));
        $email->setAltMessage($body);

        if (! $email->send(false)) {
            log_message('error', 'Email to {to} failed: {subject}', ['to' => $to, 'subject' => $subject]);
            $this->logEmail($intended, $to, $subject, EmailLogModel::STATUS_FAILED, $email->printDebugger([]));
            $email->clear();

            return false;
        }
        $email->clear();
        log_message('info', 'Email sent to {to}: {subject}', ['to' => $to, 'subject' => $subject]);
        $this->logEmail($intended, $to, $subject, EmailLogModel::STATUS_SENT);

        return true;
    }

    /**
     * Writes the email to email_log. A log that cannot be written never stops the email: it is reported instead.
     * @param string      $recipient   The address it was for
     * @param string|null $deliveredTo Where it really went (null: nowhere)
     * @param string      $subject
     * @param string      $status      EmailLogModel::STATUS_*
     * @param string|null $error       Why it failed
     */
    protected function logEmail(string $recipient, ?string $deliveredTo, string $subject, string $status, ?string $error = null): void
    {
        try {
            model(EmailLogModel::class)->record($this->type ?? 'unknown', $recipient, $deliveredTo, $this->locale, $subject, $status, $error);
        } catch (Throwable $e) {
            log_message('error', 'email_log not written for {to}: {message}', ['to' => $recipient, 'message' => $e->getMessage()]);
        }
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
