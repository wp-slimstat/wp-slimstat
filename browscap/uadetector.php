<?php

if ( !class_exists( 'wp_slimstat' ) ) {
	die( 0 );
}

class slim_browser {
	// Browser Types:
	//		0: regular
	//		1: crawler
	//		2: mobile

	public static function get_browser( $_user_agent = '' ) {
		$browser = array( 'browser' => 'Default Browser', 'browser_version' => '', 'browser_type' => 0, 'platform' => 'unknown', 'user_agent' => $_user_agent );

		if ( empty( $_user_agent ) || strlen( $_user_agent ) <= 5 ) {
			$browser[ 'browser_type' ] = 1;
		}

		// First let's test against major browsers and search engines
		else if ( preg_match( '#\(compatible;\sGooglebot(?:([a-z\-]+)?)/(\d\.\d);[\s\+]+http\://www\.google\.com/bot\.html\)$#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'Googlebot';
			$browser[ 'browser_version' ] = $match[ 2 ];
			$browser[ 'browser_type' ] = 1;
		}
		else if ( preg_match( '#\(compatible;\s(Yahoo\!\s([A-Z]{2})?\s?Slurp)/?(\d\.\d)?;\shttp\://help\.yahoo\.com/.*\)$#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 1 ];
			if ( !empty( $match[ 3 ] ) ) {
				$browser[ 'browser_version' ] = $match[ 3 ];
			}
			$browser[ 'browser_type' ] = 1;
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\((Windows\sNT\s\d+\.\d(?:;\sW[inOW]{2}64)?)\)\sAppleWebKit\/\d+\.\d+\s\(KHTML,\slike\sGecko\)\sChrome\/[0-9\.]+\sSafari\/[0-9\.]+\sEdge\/([0-9\.]+)$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'IE';
			if ( $match[ 2 ] == '12.0' ) {
				$browser[ 'browser_version' ] = 11;
			}
			list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $match[ 1 ] );
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\((Windows\sNT\s\d\.\d(?:;\sARM|;\sW[inOW]{2}64)?)(?:;\sx64)?;?\sTrident/[0-9\.]+;(?:\s[0-9A-Za-z\.;]+;){0,}\srv\:([0-9\.]+)\)\slike\sGecko(?:,gzip\(gfe\))?$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'IE';
			$browser[ 'browser_version' ] = $match[ 2 ];
			list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $match[ 1 ] );
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\(compatible;\sMSIE\s(\d+)(?:\.\d+)+;\s(Windows\sNT\s\d\.\d(?:;\sW[inOW]{2}64)?)(?:;\sx64)?;?(?:\sSLCC1;?|\sSV1;?|\sGTB\d;|\sTrident/\d\.\d;|\sFunWebProducts;?|\s\.NET\sCLR\s[0-9\.]+;?|\s(Media\sCenter\sPC|Tablet\sPC)\s\d\.\d;?|\sInfoPath\.\d;?)*\)$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'IE';
			$browser[ 'browser_version' ] = $match[ 1 ];
			list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $match[ 2 ] );
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\((Windows\sNT\s\d\.\d;(?:\sW[inOW]{2}64;)?)\srv\:[0-9\.]+\)\sGecko/[0-9a-z]+\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)(?:\s\(.*\))?$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 2 ];
			$browser[ 'browser_version' ] = $match[ 3 ];
			list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $match[ 1 ] );
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\(Windows;\sU;\s(.+);\s([a-z]{2}(?:\-[A-Za-z]{2})?);\srv\:\d(?:\.\d+)+\)\sGecko/\d+\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)(?:\s\(.*\))?$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 3 ];
			$browser[ 'browser_version' ] = $match[ 4 ];
			list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $match[ 1 ] );
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\(compatible;\sbingbot/(\d\.\d)[^a-z0-9]+http\://www\.bing\.com/bingbot\.htm.$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'BingBot';
			
			if ( !empty( $match[ 1 ] ) ) {
				$browser[ 'browser_version' ] = $match[ 1 ];
			}
			$browser[ 'browser_type' ] = 1;
		}
		else if ( preg_match( '#^FeedBurner/(\d\.\d)\s\(http\://www\.FeedBurner\.com\)$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'FeedBurner';
			$browser[ 'browser_version' ] = $match[ 1 ];
			$browser[ 'browser_type' ] = 3;
		}
		else if ( preg_match( '#^WordPress/(?:wordpress(\-mu)\-)?(\d\.\d+)(?:\.\d+)*(?:\-[a-z]+)?(?:\;\shttp\://[a-z0-9_\.\:\/]+)?$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'Wordpress';
			if ( !empty( $match[ 1 ] ) ) {
				$browser[ 'browser' ] .= $match[ 1 ];
			}
			$browser[ 'browser_version' ] = $match[ 2 ];
			$browser[ 'browser_type' ] = 3;
		}
		else if ( preg_match( '#Opera[/ ]([0-9\.]+)#', $_user_agent, $match ) > 0 || preg_match( '#OPR[/ ]([0-9\.]+)#', $_user_agent, $match ) > 0) {
			$browser[ 'browser' ] = 'Opera';
			$browser[ 'browser_version' ] = $match[ 1 ];
		}
		else if ( preg_match( '#[^a-z](Camino|Flock|Galeon|Orca)/(\d+[\.0-9a-z]*)#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 1 ];
			$browser[ 'browser_version' ] = $match[ 2 ];
		}
		else if ( preg_match( '#(Fire(?:fox|bird))/?(\d+[\.0-9a-z]*)?#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 1 ];
			if ( !empty( $match[ 2 ] ) ) {
				$browser[ 'browser_version' ] = $match[ 2 ];
			}
		}
		else if ( preg_match( '/^Mozilla\/\d\.\d.+\srv\:(\d[\.0-9a-z]+)[^a-z0-9]+(?:Gecko\/\d+)?$/i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'Mozilla';
			if ( !empty( $match[ 1 ] ) ) {
				$browser[ 'browser_version' ] = $match[ 1 ];
			}
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\((?:([a-z]{3,}.*\s)?([a-z]{2}(?:\-[A-Za-z]{2})?)?)\)\sAppleWebKit/[0-9\.]+\+?\s\([a-z, ]*like\sGecko[a-z\; ]*\)\s([a-zA-Z0-9\./]+(?:\sMobile)?/?[A-Z0-9]*)?(\sSafari/([0-9\.]+))?$#i', $_user_agent, $match ) > 0 ) {
			if ( !empty( $match[ 3 ] ) ) {
				$version = $match[ 3 ];
			}
			else {
				$version = $match[ 5 ];
			}

			$webkit_info =self::_get_webkit_info( $browser[ 'browser' ], $version, $_user_agent );
			if ( !empty( $webkit_info ) && is_array( $webkit_info ) ) {
				$browser[ 'browser' ] = $webkit_info[ 'browser' ];
				$browser[ 'browser_version' ] = $webkit_info[ 'browser_version' ];
			}
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\(.+?\)\sAppleWebKit/[0-9\.]+\+?\s\([a-z, ]*like\sGecko[a-z\; ]*\)\s([a-zA-Z0-9\./]+(?:\sMobile)?/?[A-Z0-9]*)?(\sSafari/([0-9\.]+))?$#i', $_user_agent, $match ) > 0 ) {
			$version = 0;
			if ( !empty( $match[ 3 ] ) ) {
				$version = $match[ 3 ];
			}

			if ( !empty( $match[ 1 ] ) && stristr( $match[ 1 ], 'Version' ) === false ) {
				$webkit_info = explode( '/', $match[ 1 ] );
				$browser[ 'browser' ] = $webkit_info[ 0 ];
				$browser[ 'browser_version' ] = floatval( $webkit_info[ 1 ] );
			}
		}
		else if ( preg_match( '#^(E?Links|Lynx|(?:Emacs\-)?w3m)[^a-z0-9]+([0-9\.]+)?#i', $_user_agent, $match ) > 0 || preg_match( '#(?:^|[^a-z0-9])(ActiveWorlds|Dillo|OffByOne)[/\sv\.]*([0-9\.]+)?#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 1 ];
			if ( !empty( $match[ 2 ] ) ) {
				$browser[ 'browser_version' ] = $match[ 2 ];
			}
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\((Macintosh|X11|OS/2);\sU;\s(.+);\s([a-z]{2}(?:\-[A-Za-z]{2})?)(?:-mac)?;\srv\:\d(?:.\d+)+\)\sGecko/\d+\s([A-Za-z\-0-9]+)/(\d+(?:\.[0-9a-z\-\.]+))+(?:(\s\(.*\))(?:\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)))?$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 4 ];
			$browser[ 'browser_version' ] = $match[ 5 ];

			$os = $match[ 2 ];
			$platform = $match[ 1 ];
			if ( !empty( $match[ 7 ] ) ) {
				$browser[ 'browser' ] = $match[ 7 ];
				$browser[ 'browser_version' ] = $match[ 8 ];
				$os = $os . ' ' . $match[ 4 ] . ' ' . $match[ 5 ];
			}
			else if ( !empty( $match[ 6 ] ) ) {
				$os = $os . $match[ 6 ];
			}
			list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_os_version( $os, $_user_agent, $platform );
		}
		else if ( preg_match( '#^Mozilla/\d\.\d\s\(([A-Za-z0-9/\.]+);(?:\sU;)?\s([A-Za-z0-9_\s]+);?\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)(?:\s([a-zA-Z0-9\./]+(?:\sMobile)?)/?[A-Z0-9]*)?\sSafari/([0-9\.]+)$#', $_user_agent, $match ) > 0 || preg_match ( '#^Mozilla/\d+\.\d+\s(?:[A-Za-z0-9\./]+\s)?\((?:([A-Za-z0-9/\.]+);(?:\sU;)?\s?)?([^;]*)(?:;\s[A-Za-z]{3}64)?;?\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)\s(?:Version/([0-9\.]+))?(?:\s([A-Za-z0-9_\-]+[^i])/([A-Za-z0-9\.]+)){1,3}((?:\sSafari/[0-9\.]+)?)$#', $_user_agent, $match ) > 0) {
			$browser[ 'browser' ] = 'Safari';
			$version = !empty( $match[ 4 ] ) ? $match[4] : $match[ 5 ];

			$webkit_info =self::_get_webkit_info( $browser[ 'browser' ], $version, $_user_agent );
			if ( !empty( $webkit_info ) && is_array( $webkit_info ) ) {
				$browser[ 'browser' ] = $webkit_info[ 'browser' ];
				$browser[ 'browser_version' ] = $webkit_info[ 'browser_version' ];
			}

			$os = $match[ 1 ];
			if ( !empty( $match[ 2 ] ) ) {
				$os = $match[ 2 ];
			}
			
			if ( $match[ 1 ] == 'Windows' ) {
				list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $os );
			}
			else {
				list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_os_version( $os, $_user_agent, $match[ 1 ] );
			}
		}
		else if ( preg_match( '#^Mozilla/\d+\.\d+\s(?:[A-Za-z0-9\./]+\s)?\((?:([A-Za-z0-9/\.]+);(?:\sU;)?\s?)?([^;]*)(?:;\s[A-Za-z]{3}64)?;?\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)(?:\s([A-Za-z0-9_\-]+[^i])/([A-Za-z0-9\.]+)){1,3}((?:\sSafari/[0-9\.]+)?)$#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 4 ];
			$browser[ 'browser_version' ] = $match[ 5 ];
			
			$os = $match[ 1 ];
			if ( !empty( $match[ 2 ] ) ) {
				$os = $match[ 2 ];
			}

			if ( $match[ 1 ] == 'Windows' ) {
				list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $os );
			}
			else {
				list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_os_version( $os, $_user_agent, $match[ 1 ] );
			}
		}
		else if ( preg_match( '#Gecko/\d+\s([a-z0-9_\- ]+)/(\d+[\.0-9a-z]*)(?:$|[^a-z0-9_\-]+([a-z0-9_\- ]+)/(\d+[\.0-9a-z]*)|[^a-z0-9_\-]*\(.*\))#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 1 ];
			$browser[ 'browser_version' ] = $match[ 2 ];
			if ( !empty( $match[ 3 ] ) && stristr( $match[ 3 ], 'Firefox' ) !== false ) {
				$browser[ 'browser' ] = 'Firefox';
				$browser[ 'browser_version' ] = $match[ 4 ];
			}
		}
		else if ( preg_match( '#^(?:([a-z0-9\-\s_]{3,})\s)?Mozilla/\d\.\d\s\([a-z\;\s]+Android\s[0-9\.]+(?:\;\s([a-z]{2}(?:\-[A-Za-z]{2})?)\;)?.*Gecko\)\s([a-zA-Z0-9\./]+(?:\sMobile)?/?[A-Z0-9]*?)?(?:\sChrome/([0-9\.]+)?)(\sSafari/([0-9\.]+))?#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'Chrome';
			$browser[ 'browser_version' ] = floatval( $match[ 4 ] );
		}
		else if ( preg_match( '#^(?:([a-z0-9\-\s_]{3,})\s)?Mozilla/\d\.\d\s\([a-z\;\s]+Android\s([0-9\.]+)(?:\;\s([a-z]{2}(?:\-[A-Za-z]{2})?)\;)?.*Gecko\)\s([a-zA-Z0-9\./]+(?:\sMobile)?/?[A-Z0-9]*)?(\sSafari/([0-9\.]+))?#i', $_user_agent, $match ) > 0 ) {
			$version = !empty( $match[ 4 ] ) ? $match[4] : $match[ 6 ];
			$webkit_info = self::_get_webkit_info( $browser[ 'browser' ], $version, $_user_agent );

			if ( !empty( $webkit_info ) && is_array( $webkit_info ) ) {
				$browser[ 'browser' ] = $webkit_info[ 'browser' ];
				$browser[ 'browser_version' ] = $webkit_info[ 'browser_version' ];
			}

			$browser[ 'platform' ] = 'android';
			$browser[ 'browser_type' ] = 2;
		}
		else if ( preg_match( '#IEMobile\s(\d+)(\.\d+)*\)#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'IE Mobile';
			$browser[ 'browser_version' ] = $match[ 1 ];
			$browser[ 'platform' ] = 'wince';
			$browser[ 'browser_type' ] = 2;
		}
		else if ( preg_match( '#(Opera\s(?:Mini|Mobile))[/ ]([0-9\.]+)#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 1 ];
			$browser[ 'browser_version' ] = $match[ 2 ];
			$browser[ 'browser_type' ] = 2;
		}
		else if ( preg_match( '#(NetFront|NF\-Browser)/([0-9\.]+)#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'NetFront';
			$browser[ 'browser_version' ] = $match[ 2 ];
		}
		else if ( preg_match( '#[^a-z0-9](Bolt|Iris|Jasmine|Minimo|Novarra\-Vision|Polaris)/([0-9\.]+)#i', $_user_agent, $match ) > 0 ||  preg_match( '#(UP\.browser|SMIT\-Browser)/([0-9\.]+)#i', $_user_agent, $match ) > 0 || preg_match( '#\((jig\sbrowser).*\s([0-9\.]+)[^a-z0-9]#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 1 ];
			$browser[ 'browser_version' ] = $match[ 2 ];
		}
		else if ( preg_match( '#[^a-z]Obigo#i', $_user_agent ) > 0 ) {
			$browser[ 'browser' ] = 'Obigo';
		}
		else if ( preg_match( '#openwave(\suntrusted)?/([0-9\.]+)#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'OpenWave';
			$browser[ 'browser_version' ] = $match[ 2 ];
		}
		else if ( preg_match( '#(alcatel|amoi|blackberry|docomo\s|htc|ipaq|kindle|kwc|lge|lg\-|mobilephone|motorola|nexus\sone|nokia|PDA|Palm|Samsung|sanyo|smartphone|SonyEricsson|\st\-mobile|vodafone|zte)[/\-_\s]?((?:\d|[a-z])+\d+[a-z]*)*#i', $_user_agent, $match ) > 0 && empty( $browser[ 'browser' ] ) ) {
			$browser[ 'browser' ] = $match[ 1 ];
			$browser[ 'browser_type' ] = 2;
		}
		else if ( strstr( $_user_agent,' Gecko/' ) == false && preg_match( '#^Mozilla\/\d\.\d\s\((Windows\sNT\s\d\.\d;(?:\s[0-9A-Za-z./]+;)+)\srv\:([0-9\.]+)\)\s?(.*)#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'IE';
			$browser[ 'browser_version' ] = $match[ 2 ];
			list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $match[ 1 ] );

			if ( !empty( $match[ 3 ] ) ) {
				if ( preg_match( '#\s(AOL|America\sOnline\sBrowser)\s(\d+(\.\d+)*)#', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = 'AOL';
					$browser[ 'browser_version' ] = $match_sub[ 2 ];
				}
				else if ( preg_match( '#\s(Opera|Netscape|Crazy\sBrowser)/?\s?(\d+(?:\.\d+)*)#', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = $match_sub[ 1 ];
					$browser[ 'browser_version' ] = $match_sub[ 2 ];
				}
				else if ( preg_match( '#\s(Avant|Orca)\sBrowser;#', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = $match_sub[ 1 ];
					$browser[ 'browser_version' ] = '';
				}
				else if ( preg_match( '#Windows\sCE;\s?IEMobile\s(\d+)(\.\d+)*\)#i', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = 'IEMobile';
					$browser[ 'browser_version' ] = $match_sub[ 1 ];
					$browser[ 'platform' ] = 'wince';
					$browser[ 'browser_type' ] = 2;
				}
				else if ( preg_match( '#\s(\d+x\d+)?\;?\s?(?:WebTV|MSNTV)(?:/|\s)([0-9\.]+)*#i', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = 'MSNTV';
					$browser[ 'browser_version' ] = $match_sub[ 2 ];
				}
			}
		}
		else if ( preg_match( '#compatible(?:\;|\,|\s)+MSIE\s(\d+)(\.\d+)+(.*)#', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'IE';
			$browser[ 'browser_version' ] = $match[ 1 ];

			if ( !empty( $match[ 3 ] ) ) {
				if ( preg_match( '#\s(AOL|America\sOnline\sBrowser)\s(\d+(\.\d+)*)#', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = 'AOL';
					$browser[ 'browser_version' ] = $match_sub[ 2 ];
				}
				else if ( preg_match( '#\s(Opera|Netscape|Crazy\sBrowser)/?\s?(\d+(?:\.\d+)*)#', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = $match_sub[ 1 ];
					$browser[ 'browser_version' ] = $match_sub[ 2 ];
				}
				else if ( preg_match( '#\s(Avant|Orca)\sBrowser;#', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = $match_sub[ 1 ];
					$browser[ 'browser_version' ] = '';
				}
				else if ( preg_match( '#IEMobile[\s/](\d+\.\d+)*.*\)#i', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = 'IEMobile';
					$browser[ 'browser_version' ] = $match_sub[ 1 ];
					$browser[ 'platform' ] = 'winphone8';
					$browser[ 'browser_type' ] = 2;
				}
				else if ( preg_match( '#\s(\d+x\d+)?\;?\s?(?:WebTV|MSNTV)(?:/|\s)([0-9\.]+)*#i', $match[ 3 ], $match_sub ) > 0 ) {
					$browser[ 'browser' ] = 'MSNTV';
					$browser[ 'browser_version' ] = $match_sub[ 2 ];
				}
			}
		}
		else if ( stristr( $_user_agent, 'location.href' ) !== false || preg_match( '/(<|&lt;|&#60;|%3C)script/i', $_user_agent ) > 0 || preg_match( '/(<|&lt;|&#60;|%3C)a(\s|%20|&#32;|\+)+href/i' ,$_user_agent ) > 0 || preg_match( '/(select|update).*( |%20|%#32;|\+)from( |%20|%#32;|\+)/i', $_user_agent ) > 0 || preg_match( '/(drop|alter)(?:\s|%20|%#32;|\+)table/i', $_user_agent ) > 0 ) {
			$browser[ 'browser' ] = 'Script Injection Bot';
			$browser[ 'browser_type' ] = 1;
		}
		else if ( preg_match( '#^([a-z]+)?/?nutch\-([0-9\.]+)#i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = 'Nutch';
			if ( !empty( $match[ 1 ] ) ) {
				$browser[ 'browser' ] = $match[ 1 ];
			}
			$browser[ 'browser_version' ] = $match[ 2 ];
			$browser[ 'browser_type' ] = 1;
		}
		else if ( preg_match( '#^Mozilla/\d\.\d[^a-z0-9_\-]+(Yahoo[\-\!\s_]+[a-z]+)/?([0-9\.]+)?[^a-z0-9_\-]+.+yahoo.*\.com#i', $_user_agent, $match ) > 0 ||
					preg_match( '#^((?:[a-z]|\%20)+)\/?([0-9\.]+).*[^a-z0-9]CFNetwork\/?([0-9\.]+)#' , $_user_agent, $match ) > 0 ||
					preg_match( '/^Mozilla\/\d\.\d\s\(compatible\;\s(HTTrack|ICS)(?:\s(\d\.[a-z0-9]+))?[^a-z0-9\s]/', $_user_agent, $match ) > 0 ||
					preg_match( '#^Mozilla\/\d\.\d\s\(compatible;\s([a-z_ ]+)(?:[-/](\d+\.\d+))?;\s.?http://(?:www\.)?[a-z]+(?:[a-z\.]+)\.(?:[a-z]{2,4})/?[a-z/]*(?:\.s?html?|\.php|\.aspx?)?\)$#i', $_user_agent, $match ) > 0 ||
					preg_match( '/([a-z\_\s\.]+)[\s\/\-_]?(v?[0-9\.]+)?.*(?:http\:\/\/|www\.)(\1)\.[a-z0-9_\-]+/i', $_user_agent, $match ) > 0 ||
					preg_match( '/^([a-z\_\.]+)[\s\/\-_]?(v?[0-9\.]+)?[\s\(\+]*(?:http\:\/\/|www\.)[a-z0-9_\-]+\.[a-z0-9_\-\.]+\)?/i', $_user_agent, $match ) > 0 ||
					preg_match( '/([a-z]+[a-z0-9]{2,})[\s\/\-]?([0-9\.]+)?[^a-z]+[^0-9]*http\:.*\/(\1)[^a-z]/i', $_user_agent, $match ) > 0 ||
					preg_match( '/([a-z]+[a-z0-9]{2,})[\s\/\-]?([0-9\.]+)?.*[^a-z0-9](\1)@[a-z0-9\-_]{2,}\.[a-z0-9\-_]{2,}/i', $_user_agent, $match ) > 0 ||
					preg_match( '#^Mozilla\/\d\.\d\s\(compatible;\s([a-z_ ]+)(?:[-/](\d+\.\d+))?;\s[^a-z0-9]?([a-z0-9\.]+@[a-z0-9]+\.[a-z]{2,4})\)$#i', $_user_agent, $match ) > 0 ||
					preg_match( '/^([a-z]+)[\/\-\s_](v?[0-9\.]+)?.*[a-z0-9_\.]+(?:\@|\sat\s)[a-z0-9\-_]+(?:\.|\s?dot\s)[a-z]{2,4}[^a-z]/i', $_user_agent, $match ) > 0 ||
					preg_match( '/^([a-z\_\.]+)[\s\/\-_]?(v?[0-9\.]+)?$/i', $_user_agent, $match ) > 0 ||
					preg_match( '/^([a-z\_\.]+)[\s\/\-_]?(v?[0-9\.]+)?$/i', $_user_agent, $match ) > 0 ||
					preg_match( '#(\spowermarks)\/([0-9\.]+)#i', $_user_agent, $match ) > 0
				) {
			$browser[ 'browser' ] = $match[ 1 ];
			$browser[ 'browser_type' ] = 1;
			if ( !empty( $match[ 2 ] ) ) {
				$browser[ 'browser_version' ] = $match[ 2 ];
			}
		}
		else if ( preg_match( '#WinHTTP#i', $_user_agent ) > 0 ) {
			$browser[ 'browser' ] = 'WinHTTP';
			$browser[ 'browser_type' ] = 1;
		}
		else if ( preg_match( '/(?:http|www[a-z0-9]?)[^a-z].*[^a-z]([a-z0-9\-_]{4,}).*\.(?:com|net|org|biz|info|html?|aspx?|[a-z]{2})[^a-z0-9]+(\1[a-z_\-]+)[\/|\s|v]+([\d\.]+)/i', $_user_agent, $match ) > 0 ) {
			$browser[ 'browser' ] = $match[ 2 ];
			$browser[ 'browser_version' ] = $match[ 3 ];
			$browser[ 'browser_type' ] = 1;
		}

		if ( preg_match( '#(robot|bot[\s\-_\/\)]|bot$|blog|checker|crawl|feed|fetcher|libwww|[^\.e]link\s?|parser|reader|spider|verifier|href|https?\://|.+(?:\@|\s?at\s?)[a-z0-9_\-]+(?:\.|\s?dot\s?)|www[0-9]?\.[a-z0-9_\-]+\..+|\/.+\.(s?html?|aspx?|php5?|cgi))#i', $_user_agent ) > 0 ) {
			$browser[ 'browser_type' ] = 1;
		}

		if ( ( empty( $browser[ 'platform' ] ) || $browser[ 'platform' ] == 'unknown' ) && $browser[ 'browser_type' ] % 2 == 0 ) {
			if ( stristr( $_user_agent, 'Windows' ) !== false ) {
				list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_win_os_version( $_user_agent );
			}
			else {
				list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_os_version( $_user_agent, $_user_agent, '' );
			}

			if ( !empty( $_SERVER[ 'HTTP_UA_OS' ] ) ) {
				list( $browser[ 'platform' ], $browser[ 'browser_type' ] ) = self::_get_os_version( $_SERVER[ 'HTTP_UA_OS' ], $_user_agent );
			}
		}

		$browser[ 'browser_version' ] = floatval( $browser[ 'browser_version' ] );

		return $browser;
	}

	protected static function _get_os_version( $_os = '', $_user_agent = '', $_platform = '' ) {
		if ( empty( $_os ) || empty( $_user_agent ) ) {
			return array( 'unknown', 0 );
		}

		if( preg_match( '/(Windows|Win|NT)[0-9;\s\)\/]/', $_os ) > 0 || preg_match( '/(Windows|Win|NT)[0-9;\s\)\/]/', $_user_agent ) > 0 ) {
			return self::_get_win_os_version( $_os );
		}
		else if ( strpos( $_os, 'Intel Mac OS X' ) !== false || strpos( $_os, 'PPC Mac OS X' ) !== false ) {
			return array( 'macosx', 0 );
		}
		else if ( stristr( $_user_agent, 'iPhone' ) !== false || stristr( $_user_agent, 'iPad' ) !== false ) {
			return array( 'ios', 2 );
		}
		else if ( strpos( $_os,'Mac OS X' ) !== false ) {
			return array( 'macosx', 0 );
		}
		else if ( preg_match( '/Android\s?([0-9\.]+)?/', $_os ) > 0 ) {
			return array( 'android', 2 );
		}
		else if ( preg_match( '/[^a-z0-9](BeOS|BePC|Zeta)[^a-z0-9]/', $_os ) > 0 ) {
			return array( 'beos', 0 );
		}
		else if ( preg_match( '/[^a-z0-9](Commodore\s?64)[^a-z0-9]/i', $_os ) > 0 ) {
			return array( 'commodore64', 0 );
		}
		else if ( preg_match( '/[^a-z0-9]Darwin\/?([0-9\.]+)/i', $_os ) > 0 || preg_match( '/[^a-z0-9]Darwin[^a-z0-9]/i', $_os ) > 0 ) {
			return array( 'darwin', 0 );
		}
		else if ( preg_match( '/((?:Free|Open|Net)BSD)\s?(?:[ix]?[386]+)?\s?([0-9\.]+)?/', $_os, $match ) > 0 ) {
			return array( strtolower( $match[ 1 ] . ( !empty( $match[ 2 ] ) ? ' ' . $match[ 2 ] : '' ) ), 0 );
		}
		else if ( preg_match( '/(?:(i[0-9]{3})\s)?Linux\s*((?:i[0-9]{3})?\s*(?:[0-9]\.[0-9]{1,2}\.[0-9]{1,2})?\s*(?:[ix][0-9_]{3,})?)?(?:.+[\s\(](Android|CentOS|Debian|Fedora|Gentoo|Mandriva|PCLinuxOS|SuSE|[KX]?ubuntu)[\s\/\-\)]+(\d+[a-z0-9\.]*)?)?/i', $_os ) > 0 || preg_match( '/Linux/i', $_os ) > 0 ) {
			return array( self::_get_linux_os_version( $_os ), 0 );
		}
		else if ( preg_match( '/(Mac_PowerPC|Macintosh)/', $_os ) > 0 ) {
			return array( 'macppc', 0 );
		}
		else if ( preg_match( '/Nintendo\s(Wii|DSi?)?/i', $_os ) > 0 ) {
			return array( 'nintendo', 0 );
		}
		else if ( preg_match( '/[^a-z0-9_\-]MS\-?DOS[^a-z]([0-9\.]+)?/i', $_os ) > 0 ) {
			return array( 'ms-dos', 0 );
		}
		else if ( preg_match( '/[^a-z0-9_\-]OS\/2[^a-z0-9_\-].+Warp\s([0-9\.]+)?/i', $_os ) > 0 ) {
			return array( 'os/2', 0 );
		}
		else if ( stristr( $_os, 'PalmOS' ) !== false ) {
			return array( 'palmos', 2 );
		}
		else if ( preg_match( '/PLAYSTATION\s(\d+)/i', $_os ) > 0 ) {
			return array( 'playstation', 0 );
		}
		else if ( preg_match( '/IRIX\s*([0-9\.]+)?/i', $_os ) > 0 ) {
			return array( 'irix', 0 );
		}
		else if ( preg_match( '/SCO_SV\s([0-9\.]+)?/i', $_os ) > 0 ) {
			return array( 'unix', 0 );
		}
		else if ( preg_match( '/Solaris\s?([0-9\.]+)?/i', $_os ) > 0 ) {
			return array( 'solaris', 0 );
		}
		else if ( preg_match( '/SunOS\s?(i?[0-9\.]+)?/i', $_os ) > 0 ) {
			return array( 'sunos', 0 );
		}
		else if ( preg_match( '/SymbianOS\/([0-9\.]+)/i', $_os ) > 0 ) {
			return array( 'symbianos', 2 );
		}
		else if ( preg_match( '/[^a-z]Unixware\s(\d+(?:\.\d+)?)?/i', $_user_agent ) ) {
			return array( 'unix', 0 );
		}
		else if ( preg_match( '/\(PDA(?:.*)\)(.*)Zaurus/i', $_os ) > 0 ) {
			return array( 'zaurus', 2 );
		}
		else if ( preg_match( '/[^a-z]Unix/i', $_user_agent ) ) {
			return array( 'unix', 0 );
		}
		// else if ( preg_match( '#^Mozilla/\d\.\d\s\(([a-z0-9]+);\sU;\s(([a-z0-9]+)(?:\s([a-z0-9\.\s]+))?);#i', $_os, $match ) > 0 ) {
		// 	return array( strtolower( $match[ 3 ] ), 0 );
		// }
		else {
			$os_type = self::_get_linux_os_version( $_os );
			if ( empty( $os_type ) && preg_match( '/[^a-z0-9_\-]OS\/2[^a-z0-9_\-]/i', $_os ) > 0 ) {
				return array( 'os/2', 0 );
			}

			return array( $os_type, 0 );
		}

		if ( !empty( $_platform ) ) {
			return array( strtolower( $_platform ), 0 );
		}
		
		return array( 'unknown', 0 );
	}

	protected static function _get_linux_os_version( $_os = '' ) {
		if ( empty( $_os ) ) {
			return 'unknown';
		}

		if ( preg_match( '/[^a-z0-9](CentOS|Debian|Fedora|Gentoo|Kanotix|Knoppix|Mandrake|Mandriva|MEPIS|PCLinuxOS|Slackware|SuSE)[^a-z]/', $_os , $match ) > 0 ) {
			return strtolower( $match[ 1 ] );
		}
		else if ( preg_match( '/Red\s?Hat^[a-z]/i', $_os ) ) {
			return 'redhat';
		}
		else if ( preg_match( '#([kx]?Ubuntu)[^a-z]?(\d+[\.0-9a-z]*)?#i', $_os, $match ) > 0 ) {
			if ( stristr( $_os, 'Xandros' ) !== false ) {
				return 'xandros';
			}
			return strtolower( $match[ 1 ] );
		}
		else if ( preg_match( '/[^a-z]Linux[^a-z]/i', $_os ) ) {
			return 'linux';
		}
		
		return 'unknown';
	}

	protected static function _get_win_os_version( $_os = '' ) {
		if ( empty( $_os ) ) {
			return array( 'unknown', 0 );
		}

		if ( stristr( $_os, 'Windows NT 10.0' ) !== false ) {
			if ( stristr( $_os, 'touch' ) !== false ) {
				return array( 'wi10', 2 );
			}
			else {
				return array( 'win10', 0 );
			}
		}
		
		if ( stristr( $_os, 'Windows NT 6.3' ) !== false ) {
			if ( stristr( $_os, '; ARM' ) !== false ) {
				return array( 'winrt', 0 );
			}
			else if ( stristr( $_os, 'touch' ) !== false ) {
				return array( 'win8.1', 2 );
			}
			else {
				return array( 'win8.1', 0 );
			}
		}
		
		if ( stristr( $_os, 'Windows NT 6.2' ) !== false ) {
			if ( stristr( $_os, 'touch' ) !== false ) {
				return array( 'win8', 2 );
			}
			else {
				return array( 'win8', 0 );
			}
		}
		
		if ( stristr( $_os, 'Windows NT 6.1' ) !== false ) {
			return array( 'win7', 0 );
		}
		
		if ( stristr( $_os, 'Windows NT 6.0' ) !== false ) {
			return array( 'winvista', 0 );
		}
		
		if ( stristr( $_os, 'Windows NT 5.2' ) !== false ) {
			return array( 'win2003', 0 );
		}
		
		if ( stristr( $_os, 'Windows NT 5.1' ) !== false ) {
			return array( 'winxp', 0 );
		}
		
		if ( stristr( $_os, 'Windows NT 5.0' ) !== false || strstr( $_os, 'Windows 2000' ) !== false ) {
			return arrya( 'win2000', 0 );
		}
		
		if ( stristr( $_os, 'Windows ME' ) !== false ) {
			return array( 'winme', 0 );
		}
		
		if ( preg_match( '/Win(?:dows\s)?NT\s?([0-9\.]+)?/', $_os ) > 0 ) {
			return array( 'winnt', 0 );
		}
		
		if ( preg_match( '/(?:Windows98|Windows 98|Win98|Win 98|Win 9x)/', $_os ) > 0 ) {
			return array( 'win98', 0 );
		}

		if ( preg_match( '/(?:Windows95|Windows 95|Win95|Win 95)/', $_os ) > 0 ) {
			return array( 'win95', 0 );
		}

		if ( preg_match( '/(?:WindowsCE|Windows CE|WinCE|Win CE)[^a-z0-9]+(?:.*Version\s([0-9\.]+))?/i', $_os ) > 0 ) {
			return array( 'wince', 2 );
		}
		
		if ( preg_match( '/(Windows|Win)\s?3\.\d[; )\/]/', $_os ) > 0 ) {
			return array( 'win31', 0 );
		}

		return array( 'unknown',  0 );
	}

	protected static function _get_webkit_info( $_browser = 'Default Browser', $_version = '', $_user_agent = '' ) {
		$browser = $_browser;
		$version = $_version;

		if ( empty( $_version ) ) {
			return array( $browser, 0 );
		}

		if ( preg_match( '#^([a-zA-Z]+)/([0-9]+(?:[A-Za-z\.0-9]+))(\sMobile)?#', $_version, $match ) > 0 ) {
			if ( $match[ 1 ] != 'Version' && $match[ 1 ] != 'Mobile' ) {
				$browser = $match[ 1 ];
			}

			if ( !empty(  $match[ 2 ] ) ) {
				$version = $match[ 2 ];
			}

			if ( !empty( $match[ 3 ] ) ) {
				$version .= $match[ 3 ];
			}
		}
		else if ( preg_match( '#^(?:([0-9]+)\.){1,3}$#', $_version, $match ) > 0 ) {
			$webkit_num = (int) $match[ 1 ];
			if ( $webkit_num > 536 ) {
				$version = '6';
			}
			else if ( $webkit_num > 533 ) {
				$version = '5';
			}
			else if ( $webkit_num > 525 ) {
				$version = '4';
			}
			else if ( $webkit_num > 419 ) {
				$version = '3';
			}
			else if ( $webkit_num > 312 ) {
				$version = '2';
			}
			else if ( $webkit_num > 85 ) {
				$version = '1';
			}
			else {
				$version = '';
			}
		}

		return array( 'browser' => $browser, 'browser_version' => $version );
	}
}