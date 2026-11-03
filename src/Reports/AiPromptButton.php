<?php
/** @license GPL-2.0-or-later */
namespace SlimStat\Reports;

use SlimStat\Shortcodes\Shortcode;
use SlimStat\Utils\ProLinks;

if (!defined('ABSPATH')) { exit; }

/**
 * "✨ Copy AI Prompt" beside a report's Export. admin.js builds the prompt from what is on
 * screen (rows, date picker, filter list) and the site's name, tagline and address; it reaches
 * an AI tool only when the user pastes it there. Free for free reports, a Pro link for Pro
 * reports while Pro is inactive.
 */
final class AiPromptButton
{
    /** Filters that narrow a report to one visitor: the shortcode's personal columns and more. */
    private const ONE_VISITOR_FILTERS = [...Shortcode::PERSONAL_COLUMNS, 'visit_id', 'location', 'notes'];

    /**
     * @param array $report        The report's wp_slimstat_reports::$reports entry.
     * @param array $filterColumns wp_slimstat_db::$filters_normalized['columns'].
     */
    public static function html(string $reportId, array $report, array $filterColumns, bool $pro): string
    {
        // Rows about individual visitors, or a view narrowed to one, stay out of AI tools.
        $narrowed = array_intersect(self::ONE_VISITOR_FILTERS, array_keys($filterColumns));
        if (empty($report) || $narrowed || Shortcode::isStaff($reportId, $report['callback_args'] ?? [])) {
            return '';
        }

        $label = '<span aria-hidden="true">✨</span> ' . esc_html__('Copy AI Prompt', 'wp-slimstat');
        if (!$pro && in_array($reportId, Shortcode::PRO_REPORTS, true)) {
            // No purchase link for someone who cannot buy.
            return current_user_can('manage_options')
                ? '<a class="slimstat-ai-prompt slimstat-ai-prompt-locked noslimstat" href="' . esc_url(ProLinks::url('ai-prompt', $reportId, true)) . '" title="' . esc_attr__('Copy AI Prompt with Pro', 'wp-slimstat') . '">' . $label . '</a>'
                : '';
        }

        // Tooltip markup runs words together ("Solid line: current periodDashed line…").
        $about = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(str_replace('<', ' <', (string) ($report['tooltip'] ?? '')))));
        return '<button type="button" class="slimstat-ai-prompt noslimstat" data-title="' . esc_attr(wp_strip_all_tags($report['title'] ?? '')) . '" data-about="' . esc_attr($about) . '">' . $label . '</button>';
    }
}
