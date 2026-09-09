<?php

namespace SlimStat\Migration;

use SlimStat\Schema\Schema;
use wpdb;

/** Recover physical legacy schema when no stored settings version is available. */
final class MissingSettingsRecovery
{
    public static function isFresh(wpdb $db, string $prefix): bool
    {
        return [] === self::tables($db, $prefix);
    }

    /** @return string[]|null Null means unknown, never a new installation. */
    private static function tables(wpdb $db, string $prefix): ?array
    {
        $suppressed = $db->suppress_errors(true);
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix . 'slim_') . '%';
        $tables = $db->get_col($db->prepare('SHOW TABLES LIKE %s', $pattern));
        $error = (string) $db->last_error;
        $db->suppress_errors($suppressed);

        return '' === $error && is_array($tables) ? $tables : null;
    }

    /** Called only inside the existing capability/lock/kill-switch guarded upgrade. */
    public static function repairLegacyColumns(wpdb $db, string $prefix, int $deadline = PHP_INT_MAX): bool
    {
        $tables = self::tables($db, $prefix);
        if (null === $tables) {
            return false;
        }

        // No invented old version and no DROP of historical data. Add only the legacy
        // fields that are actually absent; current optional columns remain offered.
        $repairs = [
            $prefix . 'slim_stats' => [
                'email' => Schema::addColumnSql('slim_stats', 'email', $prefix),
                'fingerprint' => Schema::addColumnSql('slim_stats', 'fingerprint', $prefix),
                'tz_offset' => Schema::addColumnSql('slim_stats', 'tz_offset', $prefix),
            ],
            $prefix . 'slim_stats_archive' => [
                'email' => Schema::addColumnSql('slim_stats_archive', 'email', $prefix),
                'fingerprint' => Schema::addColumnSql('slim_stats_archive', 'fingerprint', $prefix),
                'tz_offset' => Schema::addColumnSql('slim_stats_archive', 'tz_offset', $prefix),
            ],
        ];
        foreach ($repairs as $table => $columns) {
            if (!in_array($table, $tables, true)) {
                continue; // Schema::ensure owns creation of absent tables.
            }
            $names = self::columns($db, $table);
            if (null === $names) {
                return false;
            }
            $missing = array_diff(array_keys($columns), $names);
            foreach ($missing as $column) {
                if (time() >= $deadline) {
                    return false; // A later guarded request resumes from physical metadata.
                }
                if (false === $db->query($columns[$column])) {
                    return false;
                }
            }
            if ([] !== $missing) {
                $names = self::columns($db, $table);
                if (null === $names || [] !== array_diff(array_keys($columns), $names)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return string[]|null */
    private static function columns(wpdb $db, string $table): ?array
    {
        $suppressed = $db->suppress_errors(true);
        $found = $db->get_results(sprintf('SHOW COLUMNS FROM `%s`', $table), ARRAY_A);
        $error = (string) $db->last_error;
        $db->suppress_errors($suppressed);
        if ('' !== $error || !is_array($found) || [] === $found) {
            return null;
        }

        return array_map('strtolower', array_column($found, 'Field'));
    }
}
