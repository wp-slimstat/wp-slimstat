<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Consent;

class Ajax
{
	public static function handle()
	{
		$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
		if (!empty($remote_ip)) {
			$key        = 'slimstat_rl_' . md5($remote_ip);
			$hits_in_5s = (int) get_transient($key);
			if ($hits_in_5s >= 10) {
				exit(Utils::logError(429));
			}

			set_transient($key, $hits_in_5s + 1, 5);
		}

		if ('on' != \wp_slimstat::$settings['is_tracking']) {
			exit(Utils::logError(204));
		}

		$id = 0;

		// Use setter with validation
		\wp_slimstat::set_data_js(apply_filters('slimstat_filter_pageview_data_js', \wp_slimstat::$raw_post_array));
		$data_js   = \wp_slimstat::get_data_js();
		$stat      = \wp_slimstat::get_stat();
		$site_host = parse_url(get_site_url(), PHP_URL_HOST);

		$stat['referer'] = '';
		if (!empty($data_js['ref'])) {
			$stat['referer'] = Utils::base64UrlDecode($data_js['ref']);
			$parsed_ref = parse_url($stat['referer'], PHP_URL_HOST);
			if (false === $parsed_ref) {
				exit(Utils::logError(201));
			}
		}

		// Update stat after referer processing
		\wp_slimstat::set_stat($stat);

		if (!empty($data_js['id'])) {
			$data_js['id'] = Utils::getValueWithoutChecksum($data_js['id']);
			if (false === $data_js['id']) {
				exit(Utils::logError(101));
			}

			$stat['id'] = intval($data_js['id']);
			if ($stat['id'] < 0) {
				do_action('slimstat_track_exit_' . abs($stat['id']));
				exit(Utils::getValueWithChecksum($stat['id']));
			}

			if (empty($data_js['pos'])) {
				Session::ensureVisitId(true);
				$stat = Utils::getClientInfo($data_js, $stat);

				$stat = \SlimStat\Providers\IPHashProvider::upgradeToPii($stat);
				if (Consent::piiAllowed(true)) {
					if (!empty($GLOBALS['current_user']->ID)) {
						$stat['username'] = $GLOBALS['current_user']->data->user_login;
						$stat['email']    = $GLOBALS['current_user']->data->user_email;
						$stat['notes'][]  = 'user:'.$GLOBALS['current_user']->data->ID;
					}
					elseif (isset($_COOKIE['comment_author_'.COOKIEHASH])) {
						if (!empty($_COOKIE['comment_author_'.COOKIEHASH])) {
							$stat['username'] = sanitize_user($_COOKIE['comment_author_'.COOKIEHASH]);
						}

						if (!empty($_COOKIE['comment_author_email_'.COOKIEHASH])) {
							$stat['email'] = sanitize_email($_COOKIE['comment_author_email_'.COOKIEHASH]);
						}
					}
				}

			if (empty($stat['resolution'])) {
				$stat['dt_out'] = \wp_slimstat::date_i18n('U');
			}

			if (!empty($stat['fingerprint']) && Utils::isNewVisitor($stat['fingerprint'])) {
				$stat['notes'] = ['new:yes'];
			}

			// Update stat before storage
			\wp_slimstat::set_stat($stat);
			$id = Storage::updateRow($stat);
			}
			else {
				$event_info = [
					'position' => strip_tags(trim($data_js['pos'])),
					'id'       => $stat['id'],
					'dt'       => \wp_slimstat::date_i18n('U'),
				];
			if (!empty($data_js['no'])) {
				$event_info['notes'] = Utils::base64UrlDecode($data_js['no']);
			}

			$shouldEventBeTracked = apply_filters('slimstat_track_event_enabled', true, $event_info);
			if ($shouldEventBeTracked) {
				Storage::insertRow($event_info, $GLOBALS['wpdb']->prefix . 'slim_events');
			}

			if (!empty($data_js['res'])) {
				$resource        = Utils::base64UrlDecode($data_js['res']);
				$parsed_resource = parse_url($resource);
				if (false === $parsed_resource || empty($parsed_resource['host'])) {
					exit(Utils::logError(203));
				}

				if (!empty($parsed_resource['path']) && in_array(pathinfo($parsed_resource['path'], PATHINFO_EXTENSION), \wp_slimstat::string_to_array(\wp_slimstat::$settings['extensions_to_track']))) {
					$stat['resource']     = $parsed_resource['path'] . (empty($parsed_resource['query']) ? '' : '?' . $parsed_resource['query']);
					$stat['content_type'] = 'download';
					if (!empty($data_js['fh'])) {
						$stat['fingerprint'] = sanitize_text_field($data_js['fh']);
					}

					// Update stat before processing
					\wp_slimstat::set_stat($stat);
					$id = Processor::process();
				} elseif ($parsed_resource['host'] != $site_host) {
					$stat['outbound_resource'] = $resource;
					$stat['dt_out']             = \wp_slimstat::date_i18n('U');

					// Update stat before storage
					\wp_slimstat::set_stat($stat);
					$id = Storage::updateRow($stat);
				}
			} else {
				$stat['dt_out'] = \wp_slimstat::date_i18n('U');

				// Update stat before storage
				\wp_slimstat::set_stat($stat);
				$id = Storage::updateRow($stat);
			}
		}
	}
	else {
		$stat['resource'] = '';
		if (!empty($data_js['res'])) {
			$stat['resource'] = Utils::base64UrlDecode($data_js['res']);
			if (false === parse_url($stat['resource'])) {
				exit(Utils::logError(203));
			}
		}

		$stat = Utils::getClientInfo($data_js, $stat);
		if (!empty($data_js['ci'])) {
			$data_js['ci'] = Utils::getValueWithoutChecksum($data_js['ci']);
			if (false === $data_js['ci']) {
				exit(Utils::logError(102));
			}

			$content_info = @unserialize(Utils::base64UrlDecode($data_js['ci']));
			if (empty($content_info) || !is_array($content_info)) {
				exit(Utils::logError(103));
			}

			foreach (['content_type', 'category', 'content_id', 'author'] as $a_key) {
				if (!empty($content_info[$a_key]) && 'content_id' !== $a_key) {
					$stat[$a_key] = sanitize_text_field($content_info[$a_key]);
				} elseif (!empty($content_info[$a_key])) {
					$stat[$a_key] = absint($content_info[$a_key]);
				}
			}
		} else {
			$stat['content_type'] = 'external';
		}

		if (!empty($stat['fingerprint']) && Utils::isNewVisitor($stat['fingerprint'])) {
			$stat['notes'] = ['new:yes'];
		}

		// Update stat before processing
		\wp_slimstat::set_stat($stat);
		$id = Processor::process();
		}

	if (empty($id)) {
		exit(0);
	}

	do_action('slimstat_track_success');
	exit(Utils::getValueWithChecksum($id));
	}
}
