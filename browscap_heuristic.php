<?php

// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME']){
  header('Location: /');
  exit;
}

class browscap_heuristic{

	public function __construct(){
		$this->browser = array();
		$this->browser['Browser'] = "Default Browser";
		$this->browser['Version'] = '';
		$this->browser['Platform'] = 'unknown';
		$this->browser['CssVersion'] = 0;
		$this->browser['Crawler'] = 'false';
		$this->browser['isMobileDevice'] = 'false';
		$this->browser['isSyndicationReader'] = 'false';
	}

	/**
	 * Parses the user agent string
	 */
	public function getBrowser($_agent = '') {
		if (empty($_agent)) return $this->browser;

		// Googlebot 
		if (preg_match("#^Mozilla/\d\.\d\s\(compatible;\sGooglebot/(\d\.\d);[\s\+]+http\://www\.google\.com/bot\.html\)$#i", $_agent, $match)>0){
			$this->browser['Browser'] = "Googlebot";
			$this->browser['Version'] = $match[1];
			$this->browser['Crawler'] = 'true';

		// Yahoo!Slurp
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(compatible;\s(Yahoo\!\s([A-Z]{2})?\s?Slurp)/?(\d\.\d)?;\shttp\://help\.yahoo\.com/.*\)$#i', $_agent, $match)>0){
			$this->browser['Browser'] = $match[1];
			if (!empty($match[3])) $this->browser['Version'] = $match[3];
			$this->browser['Crawler'] = 'true';

		// BingBot
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(compatible;\sbingbot/(\d\.\d)[^a-z0-9]+http\://www\.bing\.com/bingbot\.htm.$#', $_agent, $match)>0){
			$this->browser['Browser'] = 'BingBot';
			if (!empty($match[1])) $this->browser['Browser'] .= $match[1];
			if (!empty($match[2])) $this->browser['Version'] = $match[2];
			$this->browser['Crawler'] = 'true';

		// IE 8|7|6 on Windows7|2008|Vista|XP|2003|2000
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(compatible;\sMSIE\s(\d+)(?:\.\d+)+;\s(Windows\sNT\s\d\.\d(?:;\sW[inOW]{2}64)?)(?:;\sx64)?;?(?:\sSLCC1;?|\sSV1;?|\sGTB\d;|\sTrident/\d\.\d;|\sFunWebProducts;?|\s\.NET\sCLR\s[0-9\.]+;?|\s(Media\sCenter\sPC|Tablet\sPC)\s\d\.\d;?|\sInfoPath\.\d;?)*\)$#', $_agent, $match)>0){
			$this->browser['Browser'] = 'IE';
			$this->browser['Version'] = $match[1];
			
			// Parse the OS string and update $this->browser accordingly
			$this->os_version($match[2]);

		// Firefox and other Mozilla browsers on Windows
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(Windows;\sU;\s(.+);\s([a-z]{2}(?:\-[A-Za-z]{2})?);\srv\:\d(?:\.\d+)+\)\sGecko/\d+\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)(?:\s\(.*\))?$#', $_agent, $match)>0){
			$this->browser['Browser'] = $match[3];
			$this->browser['Version'] = $match[4];
			$this->os_version($match[1]);

		// Firefox and Gecko browsers on Mac|*nix|OS/2
		} elseif (preg_match('#^Mozilla/\d\.\d\s\((Macintosh|X11|OS/2);\sU;\s(.+);\s([a-z]{2}(?:\-[A-Za-z]{2})?)(?:-mac)?;\srv\:\d(?:.\d+)+\)\sGecko/\d+\s([A-Za-z\-0-9]+)/(\d+(?:\.[0-9a-z\-\.]+))+(?:(\s\(.*\))(?:\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)))?$#', $_agent, $match)>0){
			$this->browser['Browser'] = $match[4];
			$this->browser['Version'] = $match[5];
			$os = $match[2];
			if (!empty($match[7])){ 
				$this->browser['Browser'] = $match[7];
				$this->browser['Version'] = $match[8];
				$os .= " {$match[4]} {$match[5]}";
			} elseif (!empty($match[6])) { 
				$os .= $match[6];
			}
			$this->os_version($os);

		// Safari and Webkit-based browsers on all platforms
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(([A-Za-z0-9/\.]+);\sU;?\s?(.*);\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)(?:\s([a-zA-Z0-9\./]+(?:\sMobile)?)/?[A-Z0-9]*)?\sSafari/([0-9\.]+)$#', $_agent, $match)>0){
			$this->browser['Browser'] = 'Safari';

			// Version detection
			if (!empty($match[4]))
				$this->browser['Version'] = $match[4];
			else
				$this->browser['Version'] = $match[5];

			if (preg_match("#^([a-zA-Z]+)/([0-9]+(?:[A-Za-z\.0-9]+))(\sMobile)?#", $this->browser['Version'], $match)>0){
				if ($match[1] != "Version") { //Chrome, Iron, Shiira
					$this->browser['Browser'] = $match[1];
				}
				$this->browser['Version'] = $match[2];
				if ($this->browser['Version'] == "0") $this->browser['Version'] = '';
				if (!empty($match[3])) $this->browser['Version'] = $match[3];
			}
			elseif (is_numeric($this->browser['Version'])){
				$webkit_num = intval($this->browser['Version']-0.5);
				if ($webkit_num > 533)
					$this->browser['Version'] = '5';
				elseif ($webkit_num > 525)
					$this->browser['Version'] = '4';
				elseif ($webkit_num > 419)
					$this->browser['Version'] = '3';
				elseif ($webkit_num > 312)
					$this->browser['Version'] = '2';
				elseif ($webkit_num > 85)
					$this->browser['Version'] = '1';
				else 
					$this->browser['Version'] = '';
			}

			if (empty($match[2]))
				$os = $match[1];
			else
				$os = $match[2];
			$this->os_version($os);

		// Google Chrome browser on all platforms with or without language string
		} elseif (preg_match('#^Mozilla/\d+\.\d+\s(?:[A-Za-z0-9\./]+\s)?\((?:([A-Za-z0-9/\.]+);(?:\sU;)?\s?)?([^;]*)(?:;\s[A-Za-z]{3}64)?;?\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)(?:\s([A-Za-z0-9_\-]+[^i])/([A-Za-z0-9\.]+)){1,3}(?:\sSafari/[0-9\.]+)?$#', $_agent, $match)>0){
			$this->browser['Browser'] = $match[4];
			$this->browser['Version'] = $match[5];
			if (empty($match[2]))
				$os = $match[1];
			else
				$os = $match[2];
			$this->os_version($os);
		}

		// Simple alphanumeric strings usually identify a crawler
		elseif (preg_match("#^([a-z]+[\s_]?[a-z]*)[\-/]?([0-9\.]+)*$#", $_agent, $match)>0){
			$this->browser['Browser'] = trim($match[1]);
			if (!empty($match[2]))
				$this->browser['Version'] = $match[2];
			
			if (stristr($match[1], 'mozilla') === false)
				$this->browser['Crawler'] = 'true';
		}

		return $this->browser;
	} 
	// end getBrowser
	
	/**
	 * Parses the UserAgent string to get the operating system code
	 */
	private function os_version($_os_string) {
		if (empty($_os_string)) return '';

		// Microsoft Windows
		$x64 = '';
		if (strstr($_os_string, 'WOW64') || strstr($_os_string, 'Win64') || strstr($_os_string, 'x64'))
			$x64 = ' x64';

		if (strstr($_os_string, 'Windows NT 6.2'))
			return ($this->browser['Platform'] = 'Win8'.$x64);
		if (strstr($_os_string, 'Windows NT 6.1'))
			return ($this->browser['Platform'] = 'Win7'.$x64);
		if (strstr($_os_string, 'Windows NT 6.0'))
			return ($this->browser['Platform'] = 'WinVista'.$x64);
		if (strstr($_os_string, 'Windows NT 5.2'))
			return ($this->browser['Platform'] = 'Win2003'.$x64);
		if (strstr($_os_string, 'Windows NT 5.1'))
			return ($this->browser['Platform'] = 'WinXP'.$x64);
		if (strstr($_os_string, 'Windows NT 5.0') || strstr($_os_string, 'Windows 2000'))
			return ($this->browser['Platform'] = 'Win2000'.$x64);
		if (strstr($_os_string, 'Windows ME'))
			return ($this->browser['Platform'] = 'WinME');
		if (preg_match('/Win(?:dows\s)?NT\s?([0-9\.]+)?/', $_os_string)>0)
			return ($this->browser['Platform'] = 'WinNT'.$x64);
		if (preg_match('/(?:Windows95|Windows 95|Win95|Win 95)/', $_os_string)>0)
			return ($this->browser['Platform'] = 'Win95');
		if (preg_match('/(?:Windows98|Windows 98|Win98|Win 98|Win 9x)/', $_os_string)>0)
			return ($this->browser['Platform'] = 'Win98');
		if (preg_match('/(?:WindowsCE|Windows CE|WinCE|Win CE)[^a-z0-9]+(?:.*Version\s([0-9\.]+))?/i', $_os_string)>0)
			return ($this->browser['Platform'] = 'WinCE');
		if (preg_match('/(Windows|Win)\s?3\.\d[; )\/]/', $_os_string)>0)
			return ($this->browser['Platform'] = 'Win3.x');
		if (preg_match('/(Windows|Win)[0-9; )\/]/', $_os_string)>0)
			return ($this->browser['Platform'] = 'Windows');

		// Linux/Unix
		if (preg_match('/[^a-z0-9](Android|CentOS|Debian|Fedora|Gentoo|Mandriva|PCLinuxOS|SuSE|Kanotix|Knoppix|Mandrake|pclos|Red\s?Hat|Slackware|Ubuntu|Xandros)[^a-z]/i', $_os_string, $match)>0)
			return ($this->browser['Platform'] = $match[1]);
		if (preg_match('/((?:Free|Open|Net)BSD)\s?(?:[ix]?[386]+)?\s?([0-9\.]+)?/', $_os_string, $match)>0)
			return ($this->browser['Platform'] = $match[1]);

		// Portable devices
		if ((preg_match('/\siPhone\sOS\s(\d+)?(?:_\d)*/i', $_os_string)>0) || (strpos($_os_string, 'iPad') !== false)){
			$this->browser['isMobileDevice'] = 'true';
			return ($this->browser['Platform'] = 'iPhone OSX');
		}
		if (strpos($_os_string, 'Mac OS X') !== false){
			$this->browser['isMobileDevice'] = 'true';
			return ($this->browser['Platform'] = 'macosx');
		}
		if (preg_match('/Android\s?([0-9\.]+)?/', $_os_string)>0){
			$this->browser['isMobileDevice'] = 'true';
			return ($this->browser['Platform'] = 'android');
		}
		if ((strpos($_os_string, 'BlackBerry') !== false) || (strpos($_os_string, 'RIM') !== false)){
			$this->browser['isMobileDevice'] = 'true';
			return ($this->browser['Platform'] = 'blackberry os');
		}
		if (preg_match('/SymbianOS\/([0-9\.]+)/i', $_os_string)>0){
			$this->browser['isMobileDevice'] = 'true';
			return ($this->browser['Platform'] = 'symbianos');
		}

		// Rare operating systems
		if (preg_match('/[^a-z0-9](BeOS|BePC|Zeta)[^a-z0-9]/', $_os_string)>0)
			return ($this->browser['Platform'] = 'BeOS');
		if (preg_match('/[^a-z0-9](Commodore\s?64)[^a-z0-9]/i', $_os_string)>0)
			return ($this->browser['Platform'] = 'Commodore64');
		if (preg_match('/[^a-z0-9]Darwin\/?([0-9\.]+)/i', $_os_string)>0)
			return ($this->browser['Platform'] = 'Darwin');

		return '';
	}
	// end os_version
}
// end of class declaration