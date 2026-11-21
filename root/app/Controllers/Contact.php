<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\ContactFormGuard;
use App\Models\ContactMessageModel;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\ContactForm;
use Throwable;

/**
 * The public contact form of the website: no sign-in needed. Messages go to the contact_message table.
 * Its spam and bot checks are described in App\Libraries\ContactFormGuard.
 */
class Contact extends BaseController
{
    use ContactInputTrait;

    /**
     * GET /contact-form-token
     * A single-use token for one submission of the form. The page fetches it when the form is shown and sends it
     * back as form_token; it works from Config\ContactForm::$tokenMinAge seconds after this call until $tokenMaxAge.
     * @return ResponseInterface
     */
    public function formToken(): ResponseInterface
    {
        $config = config(ContactForm::class);

        return ApiResponse::success([
            'form_token' => service('contactFormGuard')->issueToken(),
            'expires_in' => $config->tokenMaxAge,
        ]);
    }

    /**
     * POST /contact { name, email, phone?, subject, message, locale, form_token, website? }
     * Stores a message from the contact form. website is the honeypot: the form hides it, so it stays empty.
     * A honeypot hit, or the same message sent again, gets the same 201 as a stored message, so a bot learns nothing.
     * @return ResponseInterface
     */
    public function submit(): ResponseInterface
    {
        $config = config(ContactForm::class);
        $guard  = service('contactFormGuard');
        $input  = $this->jsonInput();

        $honeypot = $input[$config->honeypotField] ?? '';
        if (! is_string($honeypot) || trim($honeypot) !== '') {
            return $this->received();
        }

        $token = $input['form_token'] ?? null;
        if ($guard->checkToken($token) !== ContactFormGuard::TOKEN_OK) {
            return ApiResponse::error(lang('Contact.formExpired'), 400);
        }

        [$row, $errors] = $this->validated($input, $config);
        if ($errors !== []) {
            return ApiResponse::error(lang('Contact.invalid'), 422, $errors);
        }

        $ip = $this->request->getIPAddress();
        if ($guard->isBlocked($row['email_address'], $ip)) {
            return ApiResponse::error(lang('Contact.tooManyMessages'), 429);
        }
        $guard->record($row['email_address'], $ip);
        $guard->consumeToken($token);

        if ($guard->isDuplicate($row['email_address'], $row['message'])) {
            return $this->received();
        }

        $reason = $guard->spamReason($row['name'], $row['subject'], $row['message']);
        $agent  = trim((string) $this->request->getHeaderLine('User-Agent'));
        $row += [
            'status'      => $reason === null ? ContactMessageModel::STATUS_NEW : ContactMessageModel::STATUS_SPAM,
            'spam_reason' => $reason,
            'ip_address'  => $ip,
            'user_agent'  => $agent === '' ? null : mb_substr($agent, 0, 255, 'UTF-8'),
        ];

        try {
            model(ContactMessageModel::class)->receive($row);
        } catch (Throwable $e) {
            log_message('error', 'Contact message could not be saved: {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Contact.saveFailed'), 500);
        }
        $guard->remember($row['email_address'], $row['message']);

        return $this->received();
    }

    /**
     * The fields of the form, trimmed and checked
     * @param array       $input
     * @param ContactForm $config
     * @return array{0: array<string, string|null>, 1: array<string, string>} The row to store, and field => error
     */
    private function validated(array $input, ContactForm $config): array
    {
        [$sender, $errors]  = $this->senderFields($input, $config, 'Contact', true);
        $subject            = $this->cleanText($input['subject'] ?? null, true);
        [$message, $error]  = $this->messageField($input, $config, 'Contact');
        $locale             = is_string($input['locale'] ?? null) ? $input['locale'] : '';

        if ($subject === '') {
            $errors['subject'] = lang('Contact.subjectRequired');
        } elseif (mb_strlen($subject, 'UTF-8') > $config->subjectMaxLength) {
            $errors['subject'] = lang('Contact.subjectTooLong', [$config->subjectMaxLength]);
        }
        if ($error !== null) {
            $errors['message'] = $error;
        }
        if (! in_array($locale, config(App::class)->supportedLocales, true)) {
            $errors['locale'] = lang('Contact.localeInvalid');
        }

        return [[
            'name'          => $sender['name'],
            'email_address' => $sender['email'],
            'phone_number'  => $sender['phone'] === '' ? null : $sender['phone'],
            'subject'       => $subject,
            'message'       => $message,
            'locale'        => $locale,
        ], $errors];
    }

    /**
     * @return ResponseInterface
     */
    private function received(): ResponseInterface
    {
        return ApiResponse::success(null, 201, lang('Contact.received'));
    }

    /**
     * @return array The JSON body as an array; [] when it is missing or not JSON
     */
    private function jsonInput(): array
    {
        try {
            $input = $this->request->getJSON(true);
        } catch (HTTPException) {
            return [];
        }

        return is_array($input) ? $input : [];
    }
}
