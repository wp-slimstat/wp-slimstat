<?php

namespace SlimStat\Services\Admin;

use SlimStat\Decorators\MarketingCampaignDecorator;
use SlimStat\Services\Admin\Notification\NotificationProcessor;

class MarketingCampaign extends HorizonFetcher
{
    public const STATE = 'slimstat_marketing_campaign_fetch_state';
    protected const LOCK = 'slimstat_marketing_campaign_execution_lock';
    protected const OPTION = 'wp_slimstat_marketing_campaigns';
    protected const ENDPOINT = 'marketing-campaigns';
    protected const INTERVAL = HOUR_IN_SECONDS;
    protected const RETRY_HOOK = 'slimstat_initial_marketing_campaign_fetch';
    protected const TYPE = 'promo_banner';

    protected function prepareFeed(array $feed): array
    {
        $feed['data'] = array_values(array_filter($feed['data'], static function ($item) {
            if (!is_array($item) || 'promo_banner' !== ($item['type'] ?? '') || !is_numeric($item['id'] ?? null)) return false;
            $data = $item['data'] ?? $item;
            if (!is_array($data) || empty($data['title']) || empty($data['url'])) return false;
            foreach (['title', 'url', 'icon', 'tooltip', 'background_color', 'text_color'] as $key) {
                if (isset($data[$key]) && !is_string($data[$key])) return false;
            }
            return !isset($item['tags']) || (is_array($item['tags']) && count(array_filter($item['tags'], 'is_string')) === count($item['tags']));
        }));
        return $feed;
    }

    /** Cache only. Never replace a Pro customer's license action with an upsell. */
    public static function banner(): ?MarketingCampaignDecorator
    {
        if ('on' !== (\wp_slimstat::$settings['display_notifications'] ?? 'on')
            || \wp_slimstat::pro_plugin_active() || !\current_user_can('manage_options')) return null;

        $feed = \get_option(self::OPTION, []);
        foreach (NotificationProcessor::filterNotificationsByTags($feed['data'] ?? []) as $item) {
            if ('promo_banner' !== ($item['type'] ?? '')) continue;
            $banner = new MarketingCampaignDecorator($item);
            if ($banner->getTitle() && $banner->getUrl()) return $banner;
        }
        return null;
    }
}
