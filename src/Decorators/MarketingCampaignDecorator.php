<?php

namespace SlimStat\Decorators;

use SlimStat\Services\Admin\FeedWindow;

class MarketingCampaignDecorator
{
    private $campaign;
    private $data;

    public function __construct(array $campaign)
    {
        $this->campaign = $campaign;
        $this->data = is_array($campaign['data'] ?? null) ? $campaign['data'] : $campaign;
    }

    private function text(string $key): string
    {
        return is_string($this->data[$key] ?? null) ? $this->data[$key] : '';
    }

    public function getTitle(): string { return $this->text('title'); }
    public function getTooltip(): string { return $this->text('tooltip'); }
    public function getUrl(): string { return \esc_url_raw(str_replace('{baseUrl}', \home_url(), $this->text('url')), ['https', 'http']); }
    public function getIcon(): string
    {
        $icon = json_decode($this->text('icon'));
        return is_string($icon) ? $icon : '';
    }

    public function getEndsAt(): string
    {
        $end = FeedWindow::timestamp($this->campaign['ends_at'] ?? null);
        return is_int($end) ? gmdate('Y-m-d\TH:i:s\Z', $end) : '';
    }

    public function getBackgroundColor(): string
    {
        $colors = ['inherit' => '#252733', 'promo' => '#b91c32', 'danger' => '#991b1b', 'info' => '#174ea6', 'warning' => '#f9cb65', 'success' => '#146c43'];
        return \sanitize_hex_color($this->text('background_color')) ?: ($colors[$this->text('background_color')] ?? '#b91c32');
    }

    public function getTextColor(): string
    {
        $default = 'warning' === $this->text('background_color') ? '#202224' : '#ffffff';
        $colors = ['dark' => '#202224', 'light' => '#ffffff', 'inherit' => $default];
        return \sanitize_hex_color($this->text('text_color')) ?: ($colors[$this->text('text_color')] ?? $default);
    }
}
