<?php
namespace SlimStat\Reports;

if (!defined('ABSPATH')) { exit; }

/** Mask at presentation boundaries, after aggregation, so report arithmetic is unchanged. */
final class ReportPrivacy
{
    public const PERSONAL = ['ip', 'other_ip', 'hostname', 'username', 'display_name', 'email', 'user_email', 'full_name', 'first_name', 'last_name', 'fingerprint', 'vid_hash', 'user_agent', 'notes', 'event_description', 'location', 'user_registered'];

    public static function enabled(): bool
    {
        return 'on' === (\wp_slimstat::$settings['mask_personal_data'] ?? 'off') && !current_user_can('manage_options');
    }

    public static function rows(array $rows): array
    {
        if (!self::enabled()) { return $rows; }
        foreach ($rows as &$row) {
            if (!is_array($row)) { continue; }
            foreach (self::PERSONAL as $key) {
                if (!array_key_exists($key, $row)) { continue; }
                // Stable pseudonyms preserve grouping without disclosing the account name.
                $row[$key] = 'username' === $key && '' !== (string) $row[$key]
                    ? __('Visitor', 'wp-slimstat') . ' ' . substr(hash_hmac('sha256', (string) $row[$key], \wp_slimstat::$settings['secret']), 0, 12)
                    : (in_array($key, ['ip', 'other_ip', 'email', 'user_email', 'full_name', 'display_name', 'hostname'], true) && '' !== (string) $row[$key] ? __('Hidden', 'wp-slimstat') : '');
            }
            if (isset($row['ID'])) { $row['ID'] = 0; }
            foreach (['resource', 'referer', 'outbound_resource'] as $key) {
                if (isset($row[$key]) && is_string($row[$key])) { $row[$key] = \SlimStat\Tracker\Storage::redactSensitiveUrl($row[$key]); }
            }
        }
        unset($row);
        return $rows;
    }
}
