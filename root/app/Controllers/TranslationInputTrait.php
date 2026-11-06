<?php

namespace App\Controllers;

/**
 * Reads the "translations" of a request body, the names of a row in other languages, for the controllers whose rows
 * have a <table>_translation table (the artifact controllers). Messages come from Language/<locale>/Translation.php.
 *
 * With one field the body gives { language_code: text }, as { "th": "เสื้อยืด" }; with more, an object per language,
 * as { "th": { "artifact_name": "เสื้อยืด", "artifact_description": "..." } }. Each language must be one of
 * listLanguages(). A language whose texts are all empty is left out; the first field is the one a language cannot do
 * without (the name), the others may be empty (null).
 */
trait TranslationInputTrait
{
    /**
     * The translations of $in, checked
     * @param mixed             $in     The value of "translations" in the body
     * @param array<string,int> $fields Column => its longest length, the required one (the name) first
     * @param string            $prefix The key of the errors, as "translations.th"
     * @return array{0: array<string, array<string, string|null>>|null, 1: array<string, string>}
     *               [language_code => [column => text], field => error]; the translations are null when $in is not
     *               an object at all
     */
    private function translationsIn(mixed $in, array $fields, string $prefix = 'translations'): array
    {
        if (! is_array($in) || ($in !== [] && array_is_list($in))) {
            return [null, [$prefix => lang('Translation.invalid')]];
        }
        helper('list');
        $languages = listLanguages();
        $columns   = array_keys($fields);
        $single    = count($fields) === 1;
        $names     = [];
        $errors    = [];
        foreach ($in as $language => $texts) {
            $key = $prefix . '.' . $language;
            if (! isset($languages[$language])) {
                $errors[$key] = lang('Translation.unknownLanguage');

                continue;
            }
            if ($single) {
                $texts = [$columns[0] => $texts];
            } elseif ($texts !== null && (! is_array($texts) || ($texts !== [] && array_is_list($texts)))) {
                $errors[$key] = lang('Translation.invalidText');

                continue;
            }

            $row = [];
            foreach ($fields as $column => $maxLength) {
                $field = $single ? $key : $key . '.' . $column;
                $text  = $texts[$column] ?? null;
                if ($text !== null && ! is_string($text)) {
                    $errors[$field] = lang('Translation.invalidText');

                    continue;
                }
                $text = trim((string) $text);
                if (mb_strlen($text) > $maxLength) {
                    $errors[$field] = lang('Translation.tooLong', [$maxLength]);
                }
                $row[$column] = $text === '' ? null : $text;
            }
            if (array_filter($row, static fn ($text) => $text !== null) === []) {
                continue; // nothing in this language
            }
            if ($row[$columns[0]] === null) {
                $errors[$key . '.' . $columns[0]] = lang('Translation.nameRequired');

                continue;
            }
            $names[$language] = $row;
        }

        return [$names, $errors];
    }
}
