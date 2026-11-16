<?php

namespace SlimStat\Services\Admin;

/** Horizon dates always include a timezone. Legacy dates are display metadata only. */
final class FeedWindow
{
    /** @return int|false|null Null is an absent boundary; false is an invalid one. */
    public static function timestamp($value)
    {
        if (null === $value || '' === $value) return null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            return $errors && ($errors['warning_count'] || $errors['error_count']) ? false : $date->getTimestamp();
        } catch (\Exception $error) {
            return false;
        }
    }

    public static function contains(array $item, ?int $now = null): bool
    {
        $start = self::timestamp($item['starts_at'] ?? null);
        $end = self::timestamp($item['ends_at'] ?? null);
        $now = $now ?? time();
        return false !== $start && false !== $end
            && (null === $start || $start <= $now)
            && (null === $end || $now < $end);
    }
}
