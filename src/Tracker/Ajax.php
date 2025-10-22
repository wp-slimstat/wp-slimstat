<?php

namespace SlimStat\Tracker;

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

		\wp_slimstat::$data_js = apply_filters('slimstat_filter_pageview_data_js', \wp_slimstat::$raw_post_array);
		$site_host              = parse_url(get_site_url(), PHP_URL_HOST);

		\wp_slimstat::$stat['referer'] = '';
		if (!empty(\wp_slimstat::$data_js['ref'])) {
			\wp_slimstat::$stat['referer'] = Utils::base64UrlDecode(\wp_slimstat::$data_js['ref']);
			$parsed_ref = parse_url(\wp_slimstat::$stat['referer'], PHP_URL_HOST);
			if (false === $parsed_ref) {
				exit(Utils::logError(201));
			}
		}

		if (!empty(\wp_slimstat::$data_js['id'])) {
			\wp_slimstat::$data_js['id'] = Utils::getValueWithoutChecksum(\wp_slimstat::$data_js['id']);
			if (false === \wp_slimstat::$data_js['id']) {
				exit(Utils::logError(101));
			}
            
			\wp_slimstat::$stat['id'] = intval(\wp_slimstat::$data_js['id']);
			if (\wp_slimstat::$stat['id'] < 0) {
				do_action('slimstat_track_exit_' . abs(\wp_slimstat::$stat['id']));
				exit(Utils::getValueWithChecksum(\wp_slimstat::$stat['id']));
			}

			if (empty(\wp_slimstat::$data_js['pos'])) {
				Session::ensureVisitId(true);
				\wp_slimstat::$stat = Utils::getClientInfo(\wp_slimstat::$data_js, \wp_slimstat::$stat);
				if (empty(\wp_slimstat::$stat['resolution'])) {
					\wp_slimstat::$stat['dt_out'] = \wp_slimstat::date_i18n('U');
				}
                
				if (!empty(\wp_slimstat::$stat['fingerprint']) && Utils::isNewVisitor(\wp_slimstat::$stat['fingerprint'])) {
					\wp_slimstat::$stat['notes'] = ['new:yes'];
				}
                
				$id = Storage::updateRow(\wp_slimstat::$stat);
			} else {
				$event_info = [
					'position' => strip_tags(trim(\wp_slimstat::$data_js['pos'])),
					'id'       => \wp_slimstat::$stat['id'],
					'dt'       => \wp_slimstat::date_i18n('U'),
				];
				if (!empty(\wp_slimstat::$data_js['no'])) {
					$event_info['notes'] = Utils::base64UrlDecode(\wp_slimstat::$data_js['no']);
				}
                
				$shouldEventBeTracked = apply_filters('slimstat_track_event_enabled', true, $event_info);
				if ($shouldEventBeTracked) {
					Storage::insertRow($event_info, $GLOBALS['wpdb']->prefix . 'slim_events');
				}
                
				if (!empty(\wp_slimstat::$data_js['res'])) {
					$resource        = Utils::base64UrlDecode(\wp_slimstat::$data_js['res']);
					$parsed_resource = parse_url($resource);
					if (false === $parsed_resource || empty($parsed_resource['host'])) {
						exit(Utils::logError(203));
					}
                    
					if (!empty($parsed_resource['path']) && in_array(pathinfo($parsed_resource['path'], PATHINFO_EXTENSION), \wp_slimstat::string_to_array(\wp_slimstat::$settings['extensions_to_track']))) {
						\wp_slimstat::$stat['resource']     = $parsed_resource['path'] . (empty($parsed_resource['query']) ? '' : '?' . $parsed_resource['query']);
						\wp_slimstat::$stat['content_type'] = 'download';
						if (!empty(\wp_slimstat::$data_js['fh'])) {
							\wp_slimstat::$stat['fingerprint'] = sanitize_text_field(\wp_slimstat::$data_js['fh']);
						}
                        
						$id = Processor::process();
					} elseif ($parsed_resource['host'] != $site_host) {
						\wp_slimstat::$stat['outbound_resource'] = $resource;
						\wp_slimstat::$stat['dt_out']             = \wp_slimstat::date_i18n('U');
						$id                                       = Storage::updateRow(\wp_slimstat::$stat);
					}
				} else {
					\wp_slimstat::$stat['dt_out'] = \wp_slimstat::date_i18n('U');
					$id                           = Storage::updateRow(\wp_slimstat::$stat);
				}
			}
		} else {
			\wp_slimstat::$stat['resource'] = '';
			if (!empty(\wp_slimstat::$data_js['res'])) {
				\wp_slimstat::$stat['resource'] = Utils::base64UrlDecode(\wp_slimstat::$data_js['res']);
				if (false === parse_url(\wp_slimstat::$stat['resource'])) {
					exit(Utils::logError(203));
				}
			}
            
			\wp_slimstat::$stat = Utils::getClientInfo(\wp_slimstat::$data_js, \wp_slimstat::$stat);
			if (!empty(\wp_slimstat::$data_js['ci'])) {
				\wp_slimstat::$data_js['ci'] = Utils::getValueWithoutChecksum(\wp_slimstat::$data_js['ci']);
				if (false === \wp_slimstat::$data_js['ci']) {
					exit(Utils::logError(102));
				}
                
				$content_info = @unserialize(Utils::base64UrlDecode(\wp_slimstat::$data_js['ci']));
				if (empty($content_info) || !is_array($content_info)) {
					exit(Utils::logError(103));
				}
                
				foreach (['content_type', 'category', 'content_id', 'author'] as $a_key) {
					if (!empty($content_info[$a_key]) && 'content_id' !== $a_key) {
						\wp_slimstat::$stat[$a_key] = sanitize_text_field($content_info[$a_key]);
					} elseif (!empty($content_info[$a_key])) {
						\wp_slimstat::$stat[$a_key] = absint($content_info[$a_key]);
					}
				}
			} else {
				\wp_slimstat::$stat['content_type'] = 'external';
			}
            
			if (!empty(\wp_slimstat::$stat['fingerprint']) && Utils::isNewVisitor(\wp_slimstat::$stat['fingerprint'])) {
				\wp_slimstat::$stat['notes'] = ['new:yes'];
			}
            
			$id = Processor::process();
		}

		if (empty($id)) {
			exit(0);
		}
        
		do_action('slimstat_track_success');
		exit(Utils::getValueWithChecksum($id));
	}
}
