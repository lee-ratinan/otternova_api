<?php

namespace App\Controllers;

use Config\ContactForm;

/**
 * The field checks the contact forms share: the public one (Contact) and each business's on otternova_mart
 * (MartMessages). Messages come from the controller's language file, $langFile, which has the keys nameRequired,
 * nameTooLong, emailInvalid, phoneInvalid, messageRequired and messageTooLong.
 */
trait ContactInputTrait
{
    /**
     * name, email and phone of the form, trimmed and checked; email lower-cased, '' when left out
     * @param array       $input
     * @param ContactForm $config
     * @param string      $langFile
     * @param bool        $emailRequired
     * @return array{0: array{name: string, email: string, phone: string}, 1: array<string, string>} The values, and field => error
     */
    private function senderFields(array $input, ContactForm $config, string $langFile, bool $emailRequired): array
    {
        $name  = $this->cleanText($input['name'] ?? null, true);
        $email = mb_strtolower($this->cleanText($input['email'] ?? null, true), 'UTF-8');
        $phone = $this->cleanText($input['phone'] ?? null, true);

        $errors = [];
        if ($name === '') {
            $errors['name'] = lang($langFile . '.nameRequired');
        } elseif (mb_strlen($name, 'UTF-8') > $config->nameMaxLength) {
            $errors['name'] = lang($langFile . '.nameTooLong', [$config->nameMaxLength]);
        }
        if (($emailRequired || $email !== '')
            && (strlen($email) > $config->emailMaxLength || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = lang($langFile . '.emailInvalid');
        }
        if ($phone !== '' && (strlen($phone) > $config->phoneMaxLength || ! preg_match('/^\+?[0-9 ().\-]+$/', $phone)
            || preg_match_all('/[0-9]/', $phone) < 6)) {
            $errors['phone'] = lang($langFile . '.phoneInvalid');
        }

        return [['name' => $name, 'email' => $email, 'phone' => $phone], $errors];
    }

    /**
     * The message of the form, trimmed and checked
     * @param array       $input
     * @param ContactForm $config
     * @param string      $langFile
     * @return array{0: string, 1: string|null} The message, and its error
     */
    private function messageField(array $input, ContactForm $config, string $langFile): array
    {
        $message = $this->cleanText($input['message'] ?? null, false);
        if ($message === '') {
            return [$message, lang($langFile . '.messageRequired')];
        }
        if (mb_strlen($message, 'UTF-8') > $config->messageMaxLength) {
            return [$message, lang($langFile . '.messageTooLong', [$config->messageMaxLength])];
        }

        return [$message, null];
    }

    /**
     * A text field as a trimmed UTF-8 string with no control characters; '' when missing, not a string or not UTF-8
     * @param mixed $value
     * @param bool  $singleLine Whitespace, line breaks included, collapses to single spaces
     * @return string
     */
    private function cleanText(mixed $value, bool $singleLine): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        if ($singleLine) {
            return trim(preg_replace(['/[\p{Cc}\p{Cf}]+/u', '/\s+/u'], [' ', ' '], $value) ?? '');
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return trim(preg_replace('/[^\P{Cc}\n\t]+/u', '', $value) ?? '');
    }
}
