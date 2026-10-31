<?php

namespace App\Models;

/**
 * Builds the field configuration shared by the Form Builder (form_helper.php, as 'fields') and the
 * Table Builder (table_helper.php, as 'columns' via toColumns() and 'filters' via toFilters()).
 * Used by models that have $table and $validationRules.
 */
trait FieldConfigurationTrait
{
    /**
     * @param string $langFile Language file: labels come from <langFile>.fields.*, enum labels from <langFile>.enum.*
     * @param array  $fields   field => extra Form/Table Builder keys (type, show_in, rows, options, orderable, ...)
     * @param array  $enums    field => [value => badge colour] for badges, or [value, ...] for plain labels;
     *                         the order here is also the order of the select options
     * @return array Keyed by field name, so a controller can unset() what it does not need
     */
    protected function buildConfiguration(string $langFile, array $fields, array $enums = []): array
    {
        $result = [];
        foreach ($fields as $field => $config) {
            // Rules come from $validationRules; the form adds permit_empty itself for optional fields
            $rules  = array_values(array_filter(
                explode('|', $this->validationRules[$field] ?? ''),
                static fn ($rule) => $rule !== '' && $rule !== 'permit_empty'
            ));
            $config = [
                'name'     => $field,
                'label'    => lang($langFile . '.fields.' . $field),
                'db'       => $this->table . '.' . $field,
                'rules'    => implode('|', $rules),
                'optional' => ! in_array('required', $rules, true),
            ] + $config;
            if (isset($enums[$field])) {
                $config['searchable'] = false; // the search box would match the codes, not the labels
                $values = array_is_list($enums[$field]) ? array_fill_keys($enums[$field], null) : $enums[$field];
                foreach ($values as $value => $colour) {
                    $label = lang($langFile . '.enum.' . $field . '.' . $value);
                    $config['options'][$value] = $label;
                    if ($colour !== null) {
                        $config['badges'][$value] = ['label' => $label, 'class' => 'bg-' . $colour];
                    }
                }
            }
            if (! empty($config['options']) && empty($config['badges'])) {
                $config['labels'] = $config['options']; // the table shows the label instead of the code
            }
            $result[$field] = $config;
        }

        return $result;
    }

    /**
     * Supported timezones as one flat select list (listSupportedTimezones() groups them by country)
     * @return array
     */
    protected static function timezoneOptions(): array
    {
        return array_merge(...array_values(listSupportedTimezones()));
    }

    /**
     * Turn getConfiguration() fields into Table Builder columns
     * (fields with badges => badge, switch => boolean, everything else => text).
     * @param array $fields
     * @return array
     */
    public static function toColumns(array $fields): array
    {
        $columns = [];
        foreach ($fields as $field) {
            $type      = ! empty($field['badges']) ? 'badge' : (($field['type'] ?? '') === 'switch' ? 'boolean' : 'text');
            $columns[] = [
                'data'       => $field['name'],
                'title'      => $field['label'],
                'db'         => $field['db'],
                'type'       => $type,
                'badges'     => $field['badges'] ?? [],
                'labels'     => $field['labels'] ?? [],
                'orderable'  => $field['orderable'] ?? true,
                'searchable' => $field['searchable'] ?? true,
            ];
        }

        return $columns;
    }

    /**
     * Turn getConfiguration() select fields into Table Builder filters on the same DB column
     * @param array $fields
     * @param array $names Fields to filter by, in display order
     * @return array
     */
    public static function toFilters(array $fields, array $names): array
    {
        $filters = [];
        foreach ($names as $name) {
            $filters[] = [
                'name'    => $name,
                'type'    => 'select',
                'label'   => $fields[$name]['label'],
                'column'  => $fields[$name]['db'],
                'options' => $fields[$name]['options'],
            ];
        }

        return $filters;
    }
}
