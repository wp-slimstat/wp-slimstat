<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Reports;

use SlimStat\Shortcodes\Shortcode;
use SlimStat\Utils\ProLinks;

if (!defined('ABSPATH')) { exit; }

/**
 * "✨ Copy AI Prompt" beside a report's Export. admin.js builds the prompt from the rows on
 * screen and the site's name, tagline and address; it reaches an AI tool only when the user
 * pastes it there. Free for free reports, a Pro link for Pro reports while Pro is inactive.
 */
final class AiPromptButton
{
    /**
     * @param array $report  The report's wp_slimstat_reports::$reports entry.
     * @param array $filters wp_slimstat_db::$filters_normalized.
     */
    public static function html(string $reportId, array $report, array $filters, bool $pro): string
    {
        // Rows about individual visitors, or a view narrowed to one, stay out of AI tools.
        $columns = $filters['columns'] ?? [];
        if (empty($report) || Shortcode::isStaff($reportId, $report['callback_args'] ?? [])
            || array_intersect(Shortcode::PERSONAL_COLUMNS, array_keys($columns))) {
            return '';
        }

        $label = '<span aria-hidden="true">✨</span> ' . esc_html__('Copy AI Prompt', 'wp-slimstat');
        if (!$pro && in_array($reportId, Shortcode::PRO_REPORTS, true)) {
            // No purchase link for someone who cannot buy.
            return current_user_can('manage_options')
                ? '<a class="slimstat-ai-prompt slimstat-ai-prompt-locked noslimstat" href="' . esc_url(ProLinks::url('ai-prompt', $reportId, true)) . '" title="' . esc_attr__('Copy AI Prompt with Pro', 'wp-slimstat') . '">' . $label . '</a>'
                : '';
        }

        $applied = [];
        foreach ($columns as $column => $details) {
            $applied[] = $column . ' ' . ($details[0] ?? '') . ' ' . ($details[1] ?? '');
        }
        $period = isset($filters['utime']['start'], $filters['utime']['end'])
            ? gmdate('Y-m-d', (int) $filters['utime']['start']) . ' to ' . gmdate('Y-m-d', (int) $filters['utime']['end'])
            : '';
        $data = [
            'title'   => wp_strip_all_tags($report['title'] ?? ''),
            // Tooltip markup runs words together ("Solid line: current periodDashed line…").
            'about'   => trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(str_replace('<', ' <', (string) ($report['tooltip'] ?? ''))))),
            'period'  => $period,
            'filters' => implode('; ', $applied),
        ];

        $attributes = '';
        foreach ($data as $key => $value) {
            $attributes .= ' data-' . $key . '="' . esc_attr($value) . '"';
        }
        return '<button type="button" class="slimstat-ai-prompt noslimstat"' . $attributes . '>' . $label . '</button>';
    }
}
