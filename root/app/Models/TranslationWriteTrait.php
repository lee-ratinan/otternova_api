<?php

namespace App\Models;

use App\Libraries\ActivityLog;

/**
 * Reads and writes a <table>_translation table: one row per parent row per language_code, unique on the pair, with
 * the parent's texts in that language. For the models of the artifact tables; writes go to log_activity through the
 * ActivityLog of the caller's transaction.
 */
trait TranslationWriteTrait
{
    /**
     * The translations of some parent rows
     * @param string     $table        The translation table
     * @param string     $parentColumn Its column pointing at the parent
     * @param list<int>  $parentIds
     * @param list<string> $columns    The texts to read
     * @return array<int, array<string, array<string, string|null>>> parent id => language_code => [column => text]
     */
    protected function translationsOf(string $table, string $parentColumn, array $parentIds, array $columns): array
    {
        if ($parentIds === []) {
            return [];
        }
        $rows = $this->db->table($table)
            ->select($parentColumn . ', language_code, ' . implode(', ', $columns))
            ->whereIn($parentColumn, $parentIds)
            ->orderBy('language_code')
            ->get()
            ->getResultArray();
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row[$parentColumn]][$row['language_code']] = array_intersect_key($row, array_flip($columns));
        }

        return $out;
    }

    /**
     * Translations with a single text as the API sends them: language_code => text
     * @param array<string, array<string, string|null>> $translations language_code => [column => text]
     * @param string                                    $column
     * @return array<string, string>
     */
    protected static function flatTranslations(array $translations, string $column): array
    {
        return array_map(static fn (array $row) => $row[$column], $translations);
    }

    /**
     * Insert, update or (with $replace) delete the translations of one parent row that differ from $stored
     * @param string      $table
     * @param string      $parentColumn
     * @param int         $parentId
     * @param string      $what     The parent in the log, as "category Ab3..."
     * @param array       $stored   language_code => [column => text], as translationsOf() gives them
     * @param array       $new      language_code => [column => text]
     * @param int         $actorId
     * @param ActivityLog $log
     * @param bool        $replace  Delete the stored languages that $new leaves out
     * @return void
     */
    protected function writeTranslations(string $table, string $parentColumn, int $parentId, string $what, array $stored, array $new, ?int $actorId, ActivityLog $log, bool $replace = false): void
    {
        foreach ($new as $language => $texts) {
            $key = [$parentColumn => $parentId, 'language_code' => $language];
            if (! isset($stored[$language])) {
                $row = $key + $texts;
                $this->db->table($table)->insert($row);
                $log->inserted($actorId, 'Translated ' . $what . ' into ' . $language, $table, (int) $this->db->insertID(), $row);

                continue;
            }
            $changes = ActivityLog::changes($stored[$language], $texts);
            if ($changes !== []) {
                $old = $this->db->table($table)->where($key)->get()->getRowArray();
                $this->db->table($table)->where('id', $old['id'])->update($changes);
                $log->updated($actorId, 'Changed the ' . $language . ' translation of ' . $what, $table, (int) $old['id'], $old, $changes);
            }
        }
        if ($replace) {
            foreach (array_diff_key($stored, $new) as $language => $texts) {
                $old = $this->db->table($table)->where([$parentColumn => $parentId, 'language_code' => $language])->get()->getRowArray();
                $this->db->table($table)->where('id', $old['id'])->delete();
                $log->deleted($actorId, 'Removed the ' . $language . ' translation of ' . $what, $table, (int) $old['id'], $old);
            }
        }
    }
}
