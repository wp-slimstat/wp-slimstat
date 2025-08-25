<?php

namespace SlimStat\Utils;

class UADetector
{
    // Browser Types:
    //		0: regular
    //		1: crawler
    //		2: mobile

    public static function get_browser($_user_agent = '')
    {
        $browser = ['browser' => 'Default Browser', 'browser_version' => '', 'browser_type' => 0, 'platform' => 'unknown', 'user_agent' => $_user_agent];

        if (empty($_user_agent) || strlen($_user_agent) <= 5) {
            $browser['browser_type'] = 1;
        } elseif (preg_match('#\(compatible;\sGooglebot(?:([a-z\-]+)?)/(\d\.\d);[\s\+]+http\://www\.google\.com/bot\.html\)$#i', $_user_agent, $match) > 0) {
            $browser['browser']         = 'Googlebot';
            $browser['browser_version'] = $match[2];
            $browser['browser_type']    = 1;
        } elseif (preg_match('#\(compatible;\s(Yahoo\!\s([A-Z]{2})?\s?Slurp)/?(\d\.\d)?;\shttp\://help\.yahoo\.com/.*\)$#i', $_user_agent, $match) > 0) {
            $browser['browser'] = $match[1];
            if (isset($match[3]) && ('' !== $match[3] && '0' !== $match[3])) {
                $browser['browser_version'] = $match[3];
            }

            $browser['browser_type'] = 1;
        } elseif (preg_match('#^Mozilla/\d\.\d\s\((Windows\sNT\s\d+\.\d(?:;\sW[inOW]{2}64)?)\)\sAppleWebKit\/\d+\.\d+\s\(KHTML,\slike\sGecko\)\sChrome\/[0-9\.]+\sSafari\/[0-9\.]+\sEdge\/([0-9\.]+)$#', $_user_agent, $match) > 0) {
            $browser['browser'] = 'IE';
            if ('12.0' == $match[2]) {
                $browser['browser_version'] = 11;
            }

            [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($match[1]);
        } elseif (preg_match('#^Mozilla/\d\.\d\s\((Windows\sNT\s\d\.\d(?:;\sARM|;\sW[inOW]{2}64)?)(?:;\sx64)?;?\sTrident/[0-9\.]+;(?:\s[0-9A-Za-z\.;]+;){0,}\srv\:([0-9\.]+)\)\slike\sGecko(?:,gzip\(gfe\))?$#', $_user_agent, $match) > 0) {
            $browser['browser']                              = 'IE';
            $browser['browser_version']                      = $match[2];
            [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($match[1]);
        } elseif (preg_match('#^Mozilla/\d\.\d\s\(compatible;\sMSIE\s(\d+)(?:\.\d+)+;\s(Windows\sNT\s\d\.\d(?:;\sW[inOW]{2}64)?)(?:;\sx64)?;?(?:\sSLCC1;?|\sSV1;?|\sGTB\d;|\sTrident/\d\.\d;|\sFunWebProducts;?|\s\.NET\sCLR\s[0-9\.]+;?|\s(Media\sCenter\sPC|Tablet\sPC)\s\d\.\d;?|\sInfoPath\.\d;?)*\)$#', $_user_agent, $match) > 0) {
            $browser['browser']                              = 'IE';
            $browser['browser_version']                      = $match[1];
            [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($match[2]);
        } elseif (preg_match('#^Mozilla/\d\.\d\s\((Windows\sNT\s\d\.\d;(?:\sW[inOW]{2}64;)?)\srv\:[0-9\.]+\)\sGecko/[0-9a-z]+\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)(?:\s\(.*\))?$#', $_user_agent, $match) > 0) {
            $browser['browser']                              = $match[2];
            $browser['browser_version']                      = $match[3];
            [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($match[1]);
        } elseif (preg_match('#^Mozilla/\d\.\d\s\(Windows;\sU;\s(.+);\s([a-z]{2}(?:\-[A-Za-z]{2})?);\srv\:\d(?:\.\d+)+\)\sGecko/\d+\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)(?:\s\(.*\))?$#', $_user_agent, $match) > 0) {
            $browser['browser']                              = $match[3];
            $browser['browser_version']                      = $match[4];
            [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($match[1]);
        } elseif (preg_match('#^Mozilla/\d\.\d\s\(compatible;\sbingbot/(\d\.\d)[^a-z0-9]+http\://www\.bing\.com/bingbot\.htm.$#', $_user_agent, $match) > 0) {
            $browser['browser'] = 'BingBot';
            if (isset($match[1]) && ('' !== $match[1] && '0' !== $match[1])) {
                $browser['browser_version'] = $match[1];
            }

            $browser['browser_type'] = 1;
        } elseif (preg_match('#^FeedBurner/(\d\.\d)\s\(http\://www\.FeedBurner\.com\)$#', $_user_agent, $match) > 0) {
            $browser['browser']         = 'FeedBurner';
            $browser['browser_version'] = $match[1];
            $browser['browser_type']    = 3;
        } elseif (preg_match('#^WordPress/(?:wordpress(\-mu)\-)?(\d\.\d+)(?:\.\d+)*(?:\-[a-z]+)?(?:\;\shttp\://[a-z0-9_\.\:\/]+)?$#', $_user_agent, $match) > 0) {
            $browser['browser'] = 'WordPress';
            if (isset($match[1]) && ('' !== $match[1] && '0' !== $match[1])) {
                $browser['browser'] .= $match[1];
            }

            $browser['browser_version'] = $match[2];
            $browser['browser_type']    = 3;
        } elseif (preg_match('#Opera[/ ]([0-9\.]+)#', $_user_agent, $match) > 0 || preg_match('#OPR[/ ]([0-9\.]+)#', $_user_agent, $match) > 0) {
            $browser['browser']         = 'Opera';
            $browser['browser_version'] = $match[1];
        } elseif (preg_match('#[^a-z](Camino|Flock|Galeon|Orca)/(\d+[\.0-9a-z]*)#', $_user_agent, $match) > 0) {
            $browser['browser']         = $match[1];
            $browser['browser_version'] = $match[2];
        } elseif (preg_match('#(Fire(?:fox|bird))/?(\d+[\.0-9a-z]*)?#', $_user_agent, $match) > 0) {
            $browser['browser'] = $match[1];
            if (isset($match[2]) && ('' !== $match[2] && '0' !== $match[2])) {
                $browser['browser_version'] = $match[2];
            }
        } elseif (preg_match('/^Mozilla\/\d\.\d.+\srv\:(\d[\.0-9a-z]+)[^a-z0-9]+(?:Gecko\/\d+)?$/i', $_user_agent, $match) > 0) {
            $browser['browser'] = 'Mozilla';
            if (isset($match[1]) && ('' !== $match[1] && '0' !== $match[1])) {
                $browser['browser_version'] = $match[1];
            }
        } elseif (preg_match('#^Mozilla/\d\.\d\s\((?:([a-z]{3,}.*\s)?([a-z]{2}(?:\-[A-Za-z]{2})?)?)\)\sAppleWebKit/[0-9\.]+\+?\s\([a-z, ]*like\sGecko[a-z\; ]*\)\s([a-zA-Z0-9\./]+(?:\sMobile)?/?[A-Z0-9]*)?(\sSafari/([0-9\.]+))?$#i', $_user_agent, $match) > 0) {
            $version     = empty($match[3]) ? $match[5] : $match[3];
            $webkit_info = self::_get_webkit_info($browser['browser'], $version, $_user_agent);
            if (!empty($webkit_info) && is_array($webkit_info)) {
                $browser['browser']         = $webkit_info['browser'];
                $browser['browser_version'] = $webkit_info['browser_version'];
            }
        } elseif (preg_match('#^Mozilla/\d\.\d\s\(.+?\)\sAppleWebKit/[0-9\.]+\+?\s\([a-z, ]*like\sGecko[a-z\; ]*\)\s([a-zA-Z0-9\./]+(?:\sMobile)?/?[A-Z0-9]*)?(\sSafari/([0-9\.]+))?$#i', $_user_agent, $match) > 0) {
            $version = 0;
            if (isset($match[3]) && ('' !== $match[3] && '0' !== $match[3])) {
                $version = $match[3];
            }

            if (isset($match[1]) && ('' !== $match[1] && '0' !== $match[1]) && false === stristr($match[1], 'Version')) {
                $webkit_info                = explode('/', $match[1]);
                $browser['browser']         = $webkit_info[0];
                $browser['browser_version'] = empty($webkit_info[1]) ? 0 : floatval($webkit_info[1]);
            }
        } elseif (preg_match('#^(E?Links|Lynx|(?:Emacs\-)?w3m)[^a-z0-9]+([0-9\.]+)?#i', $_user_agent, $match) > 0 || preg_match('#(?:^|[^a-z0-9])(ActiveWorlds|Dillo|OffByOne)[/\sv\.]*([0-9\.]+)?#i', $_user_agent, $match) > 0) {
            $browser['browser'] = $match[1];
            if (isset($match[2]) && ('' !== $match[2] && '0' !== $match[2])) {
                $browser['browser_version'] = $match[2];
            }
        } elseif (preg_match('#^Mozilla/\d\.\d\s\((Macintosh|X11|OS/2);\sU;\s(.+);\s([a-z]{2}(?:\-[A-Za-z]{2})?)(?:-mac)?;\srv\:\d(?:.\d+)+\)\sGecko/\d+\s([A-Za-z\-0-9]+)/(\d+(?:\.[0-9a-z\-\.]+))+(?:(\s\(.*\))(?:\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)))?$#', $_user_agent, $match) > 0) {
            $browser['browser']         = $match[4];
            $browser['browser_version'] = $match[5];
            $os                         = $match[2];
            $platform                   = $match[1];
            if (isset($match[7]) && ('' !== $match[7] && '0' !== $match[7])) {
                $browser['browser']         = $match[7];
                $browser['browser_version'] = $match[8];
                $os                         = $os . ' ' . $match[4] . ' ' . $match[5];
            } elseif (isset($match[6]) && ('' !== $match[6] && '0' !== $match[6])) {
                $os .= $match[6];
            }

            [$browser['platform'], $browser['browser_type']] = self::_get_os_version($os, $_user_agent, $platform);
        } elseif (preg_match('#^Mozilla/\d\.\d\s\(([A-Za-z0-9/\.]+);(?:\sU;)?\s([A-Za-z0-9_\s]+);?\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)(?:\s([a-zA-Z0-9\./]+(?:\sMobile)?)/?[A-Z0-9]*)?\sSafari/([0-9\.]+)$#', $_user_agent, $match) > 0 || preg_match('#^Mozilla/\d+\.\d+\s(?:[A-Za-z0-9\./]+\s)?\((?:([A-Za-z0-9/\.]+);(?:\sU;)?\s?)?([^;]*)(?:;\s[A-Za-z]{3}64)?;?\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)\s(?:Version/([0-9\.]+))?(?:\s([A-Za-z0-9_\-]+[^i])/([A-Za-z0-9\.]+)){1,3}((?:\sSafari/[0-9\.]+)?)$#', $_user_agent, $match) > 0) {
            $browser['browser'] = 'Safari';
            $version            = empty($match[4]) ? $match[5] : $match[4];
            $webkit_info        = self::_get_webkit_info($browser['browser'], $version, $_user_agent);
            if (!empty($webkit_info) && is_array($webkit_info)) {
                $browser['browser']         = $webkit_info['browser'];
                $browser['browser_version'] = $webkit_info['browser_version'];
            }

            $os = $match[1];
            if (isset($match[2]) && ('' !== $match[2] && '0' !== $match[2])) {
                $os = $match[2];
            }

            if ('Windows' == $match[1]) {
                [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($os);
            } else {
                [$browser['platform'], $browser['browser_type']] = self::_get_os_version($os, $_user_agent, $match[1]);
            }
        } elseif (preg_match('#^Mozilla/\d+\.\d+\s(?:[A-Za-z0-9\./]+\s)?\((?:([A-Za-z0-9/\.]+);(?:\sU;)?\s?)?([^;]*)(?:;\s[A-Za-z]{3}64)?;?\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)(?:\s([A-Za-z0-9_\-]+[^i])/([A-Za-z0-9\.]+)){1,3}((?:\sSafari/[0-9\.]+)?)$#', $_user_agent, $match) > 0) {
            $browser['browser']         = $match[4];
            $browser['browser_version'] = $match[5];
            $os                         = $match[1];
            if (isset($match[2]) && ('' !== $match[2] && '0' !== $match[2])) {
                $os = $match[2];
            }

            if ('Windows' == $match[1]) {
                [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($os);
            } else {
                [$browser['platform'], $browser['browser_type']] = self::_get_os_version($os, $_user_agent, $match[1]);
            }
        } elseif (preg_match('#Gecko/\d+\s([a-z0-9_\- ]+)/(\d+[\.0-9a-z]*)(?:$|[^a-z0-9_\-]+([a-z0-9_\- ]+)/(\d+[\.0-9a-z]*)|[^a-z0-9_\-]*\(.*\))#i', $_user_agent, $match) > 0) {
            $browser['browser']         = $match[1];
            $browser['browser_version'] = $match[2];
            if (isset($match[3]) && ('' !== $match[3] && '0' !== $match[3]) && false !== stristr($match[3], 'Firefox')) {
                $browser['browser']         = 'Firefox';
                $browser['browser_version'] = $match[4];
            }
        } elseif (preg_match('#^(?:([a-z0-9\-\s_]{3,})\s)?Mozilla/\d\.\d\s\([a-z\;\s]+Android\s[0-9\.]+(?:\;\s([a-z]{2}(?:\-[A-Za-z]{2})?)\;)?.*Gecko\)\s([a-zA-Z0-9\./]+(?:\sMobile)?/?[A-Z0-9]*?)?(?:\sChrome/([0-9\.]+)?)(\sSafari/([0-9\.]+))?#i', $_user_agent, $match) > 0) {
            $browser['browser']         = 'Chrome';
            $browser['browser_version'] = floatval($match[4]);
        } elseif (preg_match('#^(?:([a-z0-9\-\s_]{3,})\s)?Mozilla/\d\.\d\s\([a-z\;\s]+Android\s([0-9\.]+)(?:\;\s([a-z]{2}(?:\-[A-Za-z]{2})?)\;)?.*Gecko\)\s([a-zA-Z0-9\./]+(?:\sMobile)?/?[A-Z0-9]*)?(\sSafari/([0-9\.]+))?#i', $_user_agent, $match) > 0) {
            $version     = empty($match[4]) ? $match[6] : $match[4];
            $webkit_info = self::_get_webkit_info($browser['browser'], $version, $_user_agent);
            if (!empty($webkit_info) && is_array($webkit_info)) {
                $browser['browser']         = $webkit_info['browser'];
                $browser['browser_version'] = $webkit_info['browser_version'];
            }

            $browser['platform']     = 'android';
            $browser['browser_type'] = 2;
        } elseif (preg_match('#IEMobile\s(\d+)(\.\d+)*\)#i', $_user_agent, $match) > 0) {
            $browser['browser']         = 'IE Mobile';
            $browser['browser_version'] = $match[1];
            $browser['platform']        = 'wince';
            $browser['browser_type']    = 2;
        } elseif (preg_match('#(Opera\s(?:Mini|Mobile))[/ ]([0-9\.]+)#', $_user_agent, $match) > 0) {
            $browser['browser']         = $match[1];
            $browser['browser_version'] = $match[2];
            $browser['browser_type']    = 2;
        } elseif (preg_match('#(NetFront|NF\-Browser)/([0-9\.]+)#i', $_user_agent, $match) > 0) {
            $browser['browser']         = 'NetFront';
            $browser['browser_version'] = $match[2];
        } elseif (preg_match('#[^a-z0-9](Bolt|Iris|Jasmine|Minimo|Novarra\-Vision|Polaris)/([0-9\.]+)#i', $_user_agent, $match) > 0 || preg_match('#(UP\.browser|SMIT\-Browser)/([0-9\.]+)#i', $_user_agent, $match) > 0 || preg_match('#\((jig\sbrowser).*\s([0-9\.]+)[^a-z0-9]#i', $_user_agent, $match) > 0) {
            $browser['browser']         = $match[1];
            $browser['browser_version'] = $match[2];
        } elseif (preg_match('#[^a-z]Obigo#i', $_user_agent) > 0) {
            $browser['browser'] = 'Obigo';
        } elseif (preg_match('#openwave(\suntrusted)?/([0-9\.]+)#i', $_user_agent, $match) > 0) {
            $browser['browser']         = 'OpenWave';
            $browser['browser_version'] = $match[2];
        } elseif (preg_match('#(alcatel|amoi|blackberry|docomo\s|htc|ipaq|kindle|kwc|lge|lg\-|mobilephone|motorola|nexus\sone|nokia|PDA|Palm|Samsung|sanyo|smartphone|SonyEricsson|\st\-mobile|vodafone|zte)[/\-_\s]?((?:\d|[a-z])+\d+[a-z]*)*#i', $_user_agent, $match) > 0 && empty($browser['browser'])) {
            $browser['browser']      = $match[1];
            $browser['browser_type'] = 2;
        } elseif (false == strstr($_user_agent, ' Gecko/') && preg_match('#^Mozilla\/\d\.\d\s\((Windows\sNT\s\d\.\d;(?:\s[0-9A-Za-z./]+;)+)\srv\:([0-9\.]+)\)\s?(.*)#', $_user_agent, $match) > 0) {
            $browser['browser']                              = 'IE';
            $browser['browser_version']                      = $match[2];
            [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($match[1]);
            if (isset($match[3]) && ('' !== $match[3] && '0' !== $match[3])) {
                if (preg_match('#\s(AOL|America\sOnline\sBrowser)\s(\d+(\.\d+)*)#', $match[3], $match_sub) > 0) {
                    $browser['browser']         = 'AOL';
                    $browser['browser_version'] = $match_sub[2];
                } elseif (preg_match('#\s(Opera|Netscape|Crazy\sBrowser)/?\s?(\d+(?:\.\d+)*)#', $match[3], $match_sub) > 0) {
                    $browser['browser']         = $match_sub[1];
                    $browser['browser_version'] = $match_sub[2];
                } elseif (preg_match('#\s(Avant|Orca)\sBrowser;#', $match[3], $match_sub) > 0) {
                    $browser['browser']         = $match_sub[1];
                    $browser['browser_version'] = '';
                } elseif (preg_match('#Windows\sCE;\s?IEMobile\s(\d+)(\.\d+)*\)#i', $match[3], $match_sub) > 0) {
                    $browser['browser']         = 'IEMobile';
                    $browser['browser_version'] = $match_sub[1];
                    $browser['platform']        = 'wince';
                    $browser['browser_type']    = 2;
                } elseif (preg_match('#\s(\d+x\d+)?\;?\s?(?:WebTV|MSNTV)(?:/|\s)([0-9\.]+)*#i', $match[3], $match_sub) > 0) {
                    $browser['browser']         = 'MSNTV';
                    $browser['browser_version'] = $match_sub[2];
                }
            }
        } elseif (preg_match('#compatible(?:\;|\,|\s)+MSIE\s(\d+)(\.\d+)+(.*)#', $_user_agent, $match) > 0) {
            $browser['browser']         = 'IE';
            $browser['browser_version'] = $match[1];
            if (isset($match[3]) && ('' !== $match[3] && '0' !== $match[3])) {
                if (preg_match('#\s(AOL|America\sOnline\sBrowser)\s(\d+(\.\d+)*)#', $match[3], $match_sub) > 0) {
                    $browser['browser']         = 'AOL';
                    $browser['browser_version'] = $match_sub[2];
                } elseif (preg_match('#\s(Opera|Netscape|Crazy\sBrowser)/?\s?(\d+(?:\.\d+)*)#', $match[3], $match_sub) > 0) {
                    $browser['browser']         = $match_sub[1];
                    $browser['browser_version'] = $match_sub[2];
                } elseif (preg_match('#\s(Avant|Orca)\sBrowser;#', $match[3], $match_sub) > 0) {
                    $browser['browser']         = $match_sub[1];
                    $browser['browser_version'] = '';
                } elseif (preg_match('#IEMobile[\s/](\d+\.\d+)*.*\)#i', $match[3], $match_sub) > 0) {
                    $browser['browser']         = 'IEMobile';
                    $browser['browser_version'] = $match_sub[1];
                    $browser['platform']        = 'winphone8';
                    $browser['browser_type']    = 2;
                } elseif (preg_match('#\s(\d+x\d+)?\;?\s?(?:WebTV|MSNTV)(?:/|\s)([0-9\.]+)*#i', $match[3], $match_sub) > 0) {
                    $browser['browser']         = 'MSNTV';
                    $browser['browser_version'] = $match_sub[2];
                }
            }
        } elseif (false !== stristr($_user_agent, 'location.href') || preg_match('/(<|&lt;|&#60;|%3C)script/i', $_user_agent) > 0 || preg_match('/(<|&lt;|&#60;|%3C)a(\s|%20|&#32;|\+)+href/i', $_user_agent) > 0 || preg_match('/(select|update).*( |%20|%#32;|\+)from( |%20|%#32;|\+)/i', $_user_agent) > 0 || preg_match('/(drop|alter)(?:\s|%20|%#32;|\+)table/i', $_user_agent) > 0) {
            $browser['browser']      = 'Script Injection Bot';
            $browser['browser_type'] = 1;
        } elseif (preg_match('#^([a-z]+)?/?nutch\-([0-9\.]+)#i', $_user_agent, $match) > 0) {
            $browser['browser'] = 'Nutch';
            if (isset($match[1]) && ('' !== $match[1] && '0' !== $match[1])) {
                $browser['browser'] = $match[1];
            }

            $browser['browser_version'] = $match[2];
            $browser['browser_type']    = 1;
        } elseif (preg_match('#^Mozilla/\d\.\d[^a-z0-9_\-]+(Yahoo[\-\!\s_]+[a-z]+)/?([0-9\.]+)?[^a-z0-9_\-]+.+yahoo.*\.com#i', $_user_agent, $match) > 0 || preg_match('#^((?:[a-z]|\%20)+)\/?([0-9\.]+).*[^a-z0-9]CFNetwork\/?([0-9\.]+)#', $_user_agent, $match) > 0 || preg_match('/^Mozilla\/\d\.\d\s\(compatible\;\s(HTTrack|ICS)(?:\s(\d\.[a-z0-9]+))?[^a-z0-9\s]/', $_user_agent, $match) > 0 || preg_match('#^Mozilla\/\d\.\d\s\(compatible;\s([a-z_ ]+)(?:[-/](\d+\.\d+))?;\s.?https://(?:www\.)?[a-z]+(?:[a-z\.]+)\.(?:[a-z]{2,4})/?[a-z/]*(?:\.s?html?|\.php|\.aspx?)?\)$#i', $_user_agent, $match) > 0 || preg_match('/([a-z\_\s\.]+)[\s\/\-_]?(v?[0-9\.]+)?.*(?:http\:\/\/|www\.)(\1)\.[a-z0-9_\-]+/i', $_user_agent, $match) > 0 || preg_match('/^([a-z\_\.]+)[\s\/\-_]?(v?[0-9\.]+)?[\s\(\+]*(?:http\:\/\/|www\.)[a-z0-9_\-]+\.[a-z0-9_\-\.]+\)?/i', $_user_agent, $match) > 0 || preg_match('/([a-z]+[a-z0-9]{2,})[\s\/\-]?([0-9\.]+)?[^a-z]+[^0-9]*http\:.*\/(\1)[^a-z]/i', $_user_agent, $match) > 0 || preg_match('/([a-z]+[a-z0-9]{2,})[\s\/\-]?([0-9\.]+)?.*[^a-z0-9](\1)@[a-z0-9\-_]{2,}\.[a-z0-9\-_]{2,}/i', $_user_agent, $match) > 0 || preg_match('#^Mozilla\/\d\.\d\s\(compatible;\s([a-z_ ]+)(?:[-/](\d+\.\d+))?;\s[^a-z0-9]?([a-z0-9\.]+@[a-z0-9]+\.[a-z]{2,4})\)$#i', $_user_agent, $match) > 0 || preg_match('/^([a-z]+)[\/\-\s_](v?[0-9\.]+)?.*[a-z0-9_\.]+(?:\@|\sat\s)[a-z0-9\-_]+(?:\.|\s?dot\s)[a-z]{2,4}[^a-z]/i', $_user_agent, $match) > 0 || preg_match('/^([a-z\_\.]+)[\s\/\-_]?(v?[0-9\.]+)?$/i', $_user_agent, $match) > 0 || preg_match('/^([a-z\_\.]+)[\s\/\-_]?(v?[0-9\.]+)?$/i', $_user_agent, $match) > 0 || preg_match('#(\spowermarks)\/([0-9\.]+)#i', $_user_agent, $match) > 0) {
            $browser['browser']      = $match[1];
            $browser['browser_type'] = 1;
            if (isset($match[2]) && ('' !== $match[2] && '0' !== $match[2])) {
                $browser['browser_version'] = $match[2];
            }
        } elseif (preg_match('#WinHTTP#i', $_user_agent) > 0) {
            $browser['browser']      = 'WinHTTP';
            $browser['browser_type'] = 1;
        } elseif (preg_match('/(?:http|www[a-z0-9]?)[^a-z].*[^a-z]([a-z0-9\-_]{4,}).*\.(?:com|net|org|biz|info|html?|aspx?|[a-z]{2})[^a-z0-9]+(\1[a-z_\-]+)[\/|\s|v]+([\d\.]+)/i', $_user_agent, $match) > 0) {
            $browser['browser']         = $match[2];
            $browser['browser_version'] = $match[3];
            $browser['browser_type']    = 1;
        }

        if (preg_match('#(robot|bot[\s\-_\/\)]|bot$|blog|checker|crawl|feed|fetcher|libwww|[^\.e]link\s?|parser|reader|spider|verifier|href|https?\://|.+(?:\@|\s?at\s?)[a-z0-9_\-]+(?:\.|\s?dot\s?)|www[0-9]?\.[a-z0-9_\-]+\..+|\/.+\.(s?html?|aspx?|php5?|cgi))#i', $_user_agent) > 0) {
            $browser['browser_type'] = 1;
        }

        if ((empty($browser['platform']) || 'unknown' == $browser['platform']) && $browser['browser_type'] % 2 == 0) {
            if (false !== stristr($_user_agent, 'Windows')) {
                [$browser['platform'], $browser['browser_type']] = self::_get_win_os_version($_user_agent);
            } else {
                [$browser['platform'], $browser['browser_type']] = self::_get_os_version($_user_agent, $_user_agent, '');
            }

            if (!empty($_SERVER['HTTP_UA_OS'])) {
                [$browser['platform'], $browser['browser_type']] = self::_get_os_version($_SERVER['HTTP_UA_OS'], $_user_agent);
            }
        }

        $browser['browser_version'] = floatval($browser['browser_version']);

        return $browser;
    }

    protected static function _get_os_version($_os = '', $_user_agent = '', $_platform = '')
    {
        if (empty($_os) || empty($_user_agent)) {
            return ['unknown', 0];
        }

        if (preg_match('/(Windows|Win|NT)[0-9;\s\)\/]/', $_os) > 0 || preg_match('/(Windows|Win|NT)[0-9;\s\)\/]/', $_user_agent) > 0) {
            return self::_get_win_os_version($_os);
        } elseif (false !== strpos($_os, 'Intel Mac OS X') || false !== strpos($_os, 'PPC Mac OS X')) {
            return ['macosx', 0];
        } elseif (false !== stristr($_user_agent, 'iPhone') || false !== stristr($_user_agent, 'iPad')) {
            return ['ios', 2];
        } elseif (false !== strpos($_os, 'Mac OS X')) {
            return ['macosx', 0];
        } elseif (preg_match('/Android\s?([0-9\.]+)?/', $_os) > 0) {
            return ['android', 2];
        } elseif (preg_match('/[^a-z0-9](BeOS|BePC|Zeta)[^a-z0-9]/', $_os) > 0) {
            return ['beos', 0];
        } elseif (preg_match('/[^a-z0-9](Commodore\s?64)[^a-z0-9]/i', $_os) > 0) {
            return ['commodore64', 0];
        } elseif (preg_match('/[^a-z0-9]Darwin\/?([0-9\.]+)/i', $_os) > 0 || preg_match('/[^a-z0-9]Darwin[^a-z0-9]/i', $_os) > 0) {
            return ['darwin', 0];
        } elseif (preg_match('/((?:Free|Open|Net)BSD)\s?(?:[ix]?[386]+)?\s?([0-9\.]+)?/', $_os, $match) > 0) {
            return [strtolower($match[1] . (empty($match[2]) ? '' : ' ' . $match[2])), 0];
        } elseif (preg_match('/(?:(i[0-9]{3})\s)?Linux\s*((?:i[0-9]{3})?\s*(?:[0-9]\.[0-9]{1,2}\.[0-9]{1,2})?\s*(?:[ix][0-9_]{3,})?)?(?:.+[\s\(](Android|CentOS|Debian|Fedora|Gentoo|Mandriva|PCLinuxOS|SuSE|[KX]?ubuntu)[\s\/\-\)]+(\d+[a-z0-9\.]*)?)?/i', $_os) > 0 || preg_match('/Linux/i', $_os) > 0) {
            return [self::_get_linux_os_version($_os), 0];
        } elseif (preg_match('/(Mac_PowerPC|Macintosh)/', $_os) > 0) {
            return ['macppc', 0];
        } elseif (preg_match('/Nintendo\s(Wii|DSi?)?/i', $_os) > 0) {
            return ['nintendo', 0];
        } elseif (preg_match('/[^a-z0-9_\-]MS\-?DOS[^a-z]([0-9\.]+)?/i', $_os) > 0) {
            return ['ms-dos', 0];
        } elseif (preg_match('/[^a-z0-9_\-]OS\/2[^a-z0-9_\-].+Warp\s([0-9\.]+)?/i', $_os) > 0) {
            return ['os/2', 0];
        } elseif (false !== stristr($_os, 'PalmOS')) {
            return ['palmos', 2];
        } elseif (preg_match('/PLAYSTATION\s(\d+)/i', $_os) > 0) {
            return ['playstation', 0];
        } elseif (preg_match('/IRIX\s*([0-9\.]+)?/i', $_os) > 0) {
            return ['irix', 0];
        } elseif (preg_match('/SCO_SV\s([0-9\.]+)?/i', $_os) > 0) {
            return ['unix', 0];
        } elseif (preg_match('/Solaris\s?([0-9\.]+)?/i', $_os) > 0) {
            return ['solaris', 0];
        } elseif (preg_match('/SunOS\s?(i?[0-9\.]+)?/i', $_os) > 0) {
            return ['sunos', 0];
        } elseif (preg_match('/SymbianOS\/([0-9\.]+)/i', $_os) > 0) {
            return ['symbianos', 2];
        } elseif (preg_match('/[^a-z]Unixware\s(\d+(?:\.\d+)?)?/i', $_user_agent)) {
            return ['unix', 0];
        } elseif (preg_match('/\(PDA(?:.*)\)(.*)Zaurus/i', $_os) > 0) {
            return ['zaurus', 2];
        } elseif (preg_match('/[^a-z]Unix/i', $_user_agent)) {
            return ['unix', 0];
        } else {
            $os_type = self::_get_linux_os_version($_os);
            if (empty($os_type) && preg_match('/[^a-z0-9_\-]OS\/2[^a-z0-9_\-]/i', $_os) > 0) {
                return ['os/2', 0];
            }

            return [$os_type, 0];
        }

        if (!empty($_platform)) {
            return [strtolower($_platform), 0];
        }

        return ['unknown', 0];
    }

    protected static function _get_linux_os_version($_os = '')
    {
        if (empty($_os)) {
            return 'unknown';
        }

        if (preg_match('/[^a-z0-9](CentOS|Debian|Fedora|Gentoo|Kanotix|Knoppix|Mandrake|Mandriva|MEPIS|PCLinuxOS|Slackware|SuSE)[^a-z]/', $_os, $match) > 0) {
            return strtolower($match[1]);
        } elseif (preg_match('/Red\s?Hat^[a-z]/i', $_os)) {
            return 'redhat';
        } elseif (preg_match('#([kx]?Ubuntu)[^a-z]?(\d+[\.0-9a-z]*)?#i', $_os, $match) > 0) {
            if (false !== stristr($_os, 'Xandros')) {
                return 'xandros';
            }

            return strtolower($match[1]);
        } elseif (preg_match('/[^a-z]Linux[^a-z]/i', $_os)) {
            return 'linux';
        }

        return 'unknown';
    }

    protected static function _get_win_os_version($_os = '')
    {
        if (empty($_os)) {
            return ['unknown', 0];
        }

        if (false !== stristr($_os, 'Windows NT 10.0')) {
            if (false !== stristr($_os, 'touch')) {
                return ['wi10', 2];
            } else {
                return ['win10', 0];
            }
        }

        if (false !== stristr($_os, 'Windows NT 6.3')) {
            if (false !== stristr($_os, '; ARM')) {
                return ['winrt', 0];
            } elseif (false !== stristr($_os, 'touch')) {
                return ['win8.1', 2];
            } else {
                return ['win8.1', 0];
            }
        }

        if (false !== stristr($_os, 'Windows NT 6.2')) {
            if (false !== stristr($_os, 'touch')) {
                return ['win8', 2];
            } else {
                return ['win8', 0];
            }
        }

        if (false !== stristr($_os, 'Windows NT 6.1')) {
            return ['win7', 0];
        }

        if (false !== stristr($_os, 'Windows NT 6.0')) {
            return ['winvista', 0];
        }

        if (false !== stristr($_os, 'Windows NT 5.2')) {
            return ['win2003', 0];
        }

        if (false !== stristr($_os, 'Windows NT 5.1')) {
            return ['winxp', 0];
        }

        if (false !== stristr($_os, 'Windows NT 5.0') || false !== strstr($_os, 'Windows 2000')) {
            return ['win2000', 0];
        }

        if (false !== stristr($_os, 'Windows ME')) {
            return ['winme', 0];
        }

        if (preg_match('/Win(?:dows\s)?NT\s?([0-9\.]+)?/', $_os) > 0) {
            return ['winnt', 0];
        }

        if (preg_match('/(?:Windows98|Windows 98|Win98|Win 98|Win 9x)/', $_os) > 0) {
            return ['win98', 0];
        }

        if (preg_match('/(?:Windows95|Windows 95|Win95|Win 95)/', $_os) > 0) {
            return ['win95', 0];
        }

        if (preg_match('/(?:WindowsCE|Windows CE|WinCE|Win CE)[^a-z0-9]+(?:.*Version\s([0-9\.]+))?/i', $_os) > 0) {
            return ['wince', 2];
        }

        if (preg_match('/(Windows|Win)\s?3\.\d[; )\/]/', $_os) > 0) {
            return ['win31', 0];
        }

        return ['unknown', 0];
    }

    protected static function _get_webkit_info($_browser = 'Default Browser', $_version = '', $_user_agent = '')
    {
        $browser = $_browser;
        $version = $_version;

        if (empty($_version)) {
            return [$browser, 0];
        }

        if (preg_match('#^([a-zA-Z]+)/(\d+(?:[A-Za-z\.0-9]+))(\sMobile)?#', $_version, $match) > 0) {
            if ('Version' != $match[1] && 'Mobile' != $match[1]) {
                $browser = $match[1];
            }

            if (isset($match[2]) && ('' !== $match[2] && '0' !== $match[2])) {
                $version = $match[2];
            }

            if (isset($match[3]) && ('' !== $match[3] && '0' !== $match[3])) {
                $version .= $match[3];
            }
        } elseif (preg_match('#^(?:(\d+)\.){1,3}$#', $_version, $match) > 0) {
            $webkit_num = (int)$match[1];
            if ($webkit_num > 536) {
                $version = '6';
            } elseif ($webkit_num > 533) {
                $version = '5';
            } elseif ($webkit_num > 525) {
                $version = '4';
            } elseif ($webkit_num > 419) {
                $version = '3';
            } elseif ($webkit_num > 312) {
                $version = '2';
            } elseif ($webkit_num > 85) {
                $version = '1';
            } else {
                $version = '';
            }
        }

        return ['browser' => $browser, 'browser_version' => $version];
    }
}
