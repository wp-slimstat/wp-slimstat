<?php

class maxmind_geolite2_connector {
	public static function get_geolocation_info( $_ip_address = '' ) {
		$maxmind_path = wp_slimstat::$upload_dir . '/maxmind.mmdb';
		$geo_output = array( 'country' => array( 'iso_code' => '' ) );

		// Is this a RFC1918 (local) IP?
		if ( wp_slimstat::is_local_ip_address( $_ip_address ) ) {
			$geo_output[ 'country' ][ 'iso_code' ] = 'xy';
		}
		else if ( file_exists( $maxmind_path ) && is_file( $maxmind_path ) ) {
			// Do we need to update our data file?
			if ( false !== ( $file_stat = stat( $maxmind_path ) ) ) {
				// Is the database more than 30 days old?
				if ( !empty( $file_stat ) && ( date( 'U' ) - $file_stat[ 'mtime' ] > 2629740 ) ) {
					add_action( 'shutdown', array( __CLASS__, 'download_maxmind_database' ) );
				}
			}

			$reader = new MaxMindReader( $maxmind_path );
			$geo_maxmind = $reader->get( $_ip_address );

			if ( !empty( $geo_maxmind ) ) {
				$geo_output = $geo_maxmind;
			}
		}
		else if ( !is_file( $maxmind_path ) ) {
			return $geo_output;
		}

		return apply_filters( 'slimstat_get_country', $geo_output, $_ip_address );
	}

	/**
	 * Downloads the MaxMind geolocation database from their repository
	 */
	public static function download_maxmind_database() {
		$maxmind_path = wp_slimstat::$upload_dir . '/maxmind.mmdb';

		// Create the folder, if it doesn't exist
		if ( !file_exists( dirname( $maxmind_path ) ) ) {
			mkdir( dirname( $maxmind_path ) );
		}

		if ( file_exists( $maxmind_path ) ) {
			if ( is_file( $maxmind_path ) ) {
				$is_deleted = @unlink( $maxmind_path );
			}
			else {
				// This should not happen, but hey...
				$is_deleted = @rmdir( $maxmind_path );
			}

			if ( !$is_deleted ) {
				return __( "The geolocation database cannot be updated. Please check your server's file permissions and try again.", 'wp-slimstat' );
			}
		}

		// Download the most recent database directly from MaxMind's repository
		if ( wp_slimstat::$settings[ 'geolocation_country' ] == 'on' ) {
			$maxmind_tmp = self::download_url( 'https://geolite.maxmind.com/download/geoip/database/GeoLite2-Country.mmdb.gz' );
		}
		else {
			$maxmind_tmp = self::download_url( 'https://geolite.maxmind.com/download/geoip/database/GeoLite2-City.mmdb.gz' );
		}

		if ( is_wp_error( $maxmind_tmp ) ) {
			return __( 'There was an error downloading the MaxMind Geolite DB:', 'wp-slimstat' ) . ' ' . $maxmind_tmp->get_error_message();
		}

		$zh = false;

		if ( !function_exists( 'gzopen' ) ) {
			if ( function_exists( 'gzopen64' ) ) {
				if ( false === ( $zh = gzopen64( $maxmind_tmp, 'rb' ) ) ) {
					return __( "There was an error opening the zipped MaxMind Geolite DB. Please check your server's file permissions and try again.", 'wp-slimstat' );
				}
			}
			else {
				return __( 'Function <code>gzopen</code> is not defined in your environment. Please ask your server administrator to install the corresponding library.', 'wp-slimstat' );
			}
		}
		else{
			if ( false === ( $zh = gzopen( $maxmind_tmp, 'rb' ) ) ) {
				return __( "There was an error opening the zipped MaxMind Geolite DB. Please check your server's file permissions and try again.", 'wp-slimstat' );
			}
		}

		if ( false === ( $fh = fopen( $maxmind_path, 'wb' ) ) ) {
			return __( "There was an error opening the MaxMind Geolite DB. Please check your server's file permissions and try again.", 'wp-slimstat' );
		}

		while ( ( $data = gzread( $zh, 4096 ) ) != false ) {
			fwrite( $fh, $data );
		}

		@gzclose( $zh );
		@fclose( $fh );

		if ( !is_file( $maxmind_path ) ) {
			// Something went wrong, maybe a folder was created instead of a regular file
			@rmdir( $maxmind_path );
			return __( 'There was an error creating the MaxMind Geolite DB.', 'wp-slimstat' );
		}

		@unlink( $maxmind_tmp );

		return '';
	}

	public static function download_url( $url ) {
		// Include the FILE API, if it's not defined
		if ( !function_exists( 'download_url' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}

		if ( !$url ) {
			return new WP_Error( 'http_no_url', __( 'The provided URL is invalid.', 'wp-slimstat' ) );
		}

		$url_filename = basename( parse_url( $url, PHP_URL_PATH ) );

		$tmpfname = wp_tempnam( $url_filename );
		if ( ! $tmpfname ) {
			return new WP_Error( 'http_no_file', __( "A temporary file could not be created. Please check your server's file permissions and try again.", 'wp-slimstat' ) );
		}

		$response = wp_safe_remote_get( $url, array( 'timeout' => 300, 'stream' => true, 'filename' => $tmpfname, 'user-agent'  => 'Slimstat Analytics/' . wp_slimstat::$version . '; ' . home_url() ) );

		if ( is_wp_error( $response ) ) {
			unlink( $tmpfname );
			return $response;
		}

		if ( 200 != wp_remote_retrieve_response_code( $response ) ){
			unlink( $tmpfname );
			return new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
		}

		return $tmpfname;
	}
}

/**
 * Instances of this class provide a reader for the MaxMind DB format. IP
 * addresses can be looked up using the <code>get</code> method.
 */
class MaxMindReader {
	private static $DATA_SECTION_SEPARATOR_SIZE = 16;
	private static $METADATA_START_MARKER = "\xAB\xCD\xEFMaxMind.com";
	private static $METADATA_START_MARKER_LENGTH = 14;
	private static $METADATA_MAX_SIZE = 131072; // 128 * 1024 = 128KB

	private $decoder;
	private $fileHandle;
	private $fileSize;
	private $ipV4Start;
	private $metadata;

	/**
	 * Constructs a MaxMindReader for the MaxMind DB format. The file passed to it must
	 * be a valid MaxMind DB file such as a GeoIp2 database file.
	 *
	 * @param string $database
	 *            the MaxMind DB file to use.
	 * @throws \InvalidArgumentException for invalid database path or unknown arguments
	 * @throws \MaxMind\Db\Reader\InvalidDatabaseException
	 *             if the database is invalid or there is an error reading
	 *             from it.
	 */
	public function __construct($database)
	{
		if (func_num_args() != 1) {
			throw new \InvalidArgumentException(
				'The constructor takes exactly one argument.'
			);
		}

		if (!is_readable($database)) {
			throw new \InvalidArgumentException(
				"The file \"$database\" does not exist or is not readable."
			);
		}
		$this->fileHandle = @fopen($database, 'rb');
		if ($this->fileHandle === false) {
			throw new \InvalidArgumentException(
				"Error opening \"$database\"."
			);
		}
		$this->fileSize = @filesize($database);
		if ($this->fileSize === false) {
			throw new \UnexpectedValueException(
				"Error determining the size of \"$database\"."
			);
		}

		$start = $this->findMetadataStart($database);
		$metadataDecoder = new MaxMindDecoder($this->fileHandle, $start);
		list($metadataArray) = $metadataDecoder->decode($start);
		$this->metadata = new MaxMindMetadata($metadataArray);
		$this->decoder = new MaxMindDecoder(
			$this->fileHandle,
			$this->metadata->searchTreeSize + self::$DATA_SECTION_SEPARATOR_SIZE
		);
	}

	/**
	 * Looks up the <code>address</code> in the MaxMind DB.
	 *
	 * @param string $ipAddress
	 *            the IP address to look up.
	 * @return array the record for the IP address.
	 * @throws \BadMethodCallException if this method is called on a closed database.
	 * @throws \InvalidArgumentException if something other than a single IP address is passed to the method.
	 * @throws InvalidDatabaseException
	 *             if the database is invalid or there is an error reading
	 *             from it.
	 */
	public function get($ipAddress)
	{
		if (func_num_args() != 1) {
			throw new \InvalidArgumentException(
				'Method takes exactly one argument.'
			);
		}

		if (!is_resource($this->fileHandle)) {
			throw new \BadMethodCallException(
				'Attempt to read from a closed MaxMind DB.'
			);
		}

		if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
			throw new \InvalidArgumentException(
				"The value \"$ipAddress\" is not a valid IP address."
			);
		}

		if ($this->metadata->ipVersion == 4 && strrpos($ipAddress, ':')) {
			throw new \InvalidArgumentException(
				"Error looking up $ipAddress. You attempted to look up an"
				. " IPv6 address in an IPv4-only database."
			);
		}
		$pointer = $this->findAddressInTree($ipAddress);
		if ($pointer == 0) {
			return null;
		}
		return $this->resolveDataPointer($pointer);
	}

	private function findAddressInTree($ipAddress)
	{
		// XXX - could simplify. Done as a byte array to ease porting
		$rawAddress = array_merge(unpack('C*', inet_pton($ipAddress)));

		$bitCount = count($rawAddress) * 8;

		// The first node of the tree is always node 0, at the beginning of the
		// value
		$node = $this->startNode($bitCount);

		for ($i = 0; $i < $bitCount; $i++) {
			if ($node >= $this->metadata->nodeCount) {
				break;
			}
			$tempBit = 0xFF & $rawAddress[$i >> 3];
			$bit = 1 & ($tempBit >> 7 - ($i % 8));

			$node = $this->readNode($node, $bit);
		}
		if ($node == $this->metadata->nodeCount) {
			// Record is empty
			return 0;
		} elseif ($node > $this->metadata->nodeCount) {
			// Record is a data pointer
			return $node;
		}
		throw new InvalidDatabaseException("Something bad happened");
	}


	private function startNode($length)
	{
		// Check if we are looking up an IPv4 address in an IPv6 tree. If this
		// is the case, we can skip over the first 96 nodes.
		if ($this->metadata->ipVersion == 6 && $length == 32) {
			return $this->ipV4StartNode();
		}
		// The first node of the tree is always node 0, at the beginning of the
		// value
		return 0;
	}

	private function ipV4StartNode()
	{
		// This is a defensive check. There is no reason to call this when you
		// have an IPv4 tree.
		if ($this->metadata->ipVersion == 4) {
			return 0;
		}

		if ($this->ipV4Start != 0) {
			return $this->ipV4Start;
		}
		$node = 0;

		for ($i = 0; $i < 96 && $node < $this->metadata->nodeCount; $i++) {
			$node = $this->readNode($node, 0);
		}
		$this->ipV4Start = $node;
		return $node;
	}

	private function readNode($nodeNumber, $index)
	{
		$baseOffset = $nodeNumber * $this->metadata->nodeByteSize;

		// XXX - probably could condense this.
		switch ($this->metadata->recordSize) {
			case 24:
				$bytes = MaxMindUtil::read($this->fileHandle, $baseOffset + $index * 3, 3);
				list(, $node) = unpack('N', "\x00" . $bytes);
				return $node;
			case 28:
				$middleByte = MaxMindUtil::read($this->fileHandle, $baseOffset + 3, 1);
				list(, $middle) = unpack('C', $middleByte);
				if ($index == 0) {
					$middle = (0xF0 & $middle) >> 4;
				} else {
					$middle = 0x0F & $middle;
				}
				$bytes = MaxMindUtil::read($this->fileHandle, $baseOffset + $index * 4, 3);
				list(, $node) = unpack('N', chr($middle) . $bytes);
				return $node;
			case 32:
				$bytes = MaxMindUtil::read($this->fileHandle, $baseOffset + $index * 4, 4);
				list(, $node) = unpack('N', $bytes);
				return $node;
			default:
				throw new InvalidDatabaseException(
					'Unknown record size: '
					. $this->metadata->recordSize
				);
		}
	}

	private function resolveDataPointer($pointer)
	{
		$resolved = $pointer - $this->metadata->nodeCount
			+ $this->metadata->searchTreeSize;
		if ($resolved > $this->fileSize) {
			throw new InvalidDatabaseException(
				"The MaxMind DB file's search tree is corrupt"
			);
		}

		list($data) = $this->decoder->decode($resolved);
		return $data;
	}

	/*
	 * This is an extremely naive but reasonably readable implementation. There
	 * are much faster algorithms (e.g., Boyer-Moore) for this if speed is ever
	 * an issue, but I suspect it won't be.
	 */
	private function findMetadataStart($filename)
	{
		$handle = $this->fileHandle;
		$fstat = fstat($handle);
		$fileSize = $fstat['size'];
		$marker = self::$METADATA_START_MARKER;
		$markerLength = self::$METADATA_START_MARKER_LENGTH;
		$metadataMaxLengthExcludingMarker
			= min(self::$METADATA_MAX_SIZE, $fileSize) - $markerLength;

		for ($i = 0; $i <= $metadataMaxLengthExcludingMarker; $i++) {
			for ($j = 0; $j < $markerLength; $j++) {
				fseek($handle, $fileSize - $i - $j - 1);
				$matchBit = fgetc($handle);
				if ($matchBit != $marker[$markerLength - $j - 1]) {
					continue 2;
				}
			}
			return $fileSize - $i;
		}
		throw new InvalidDatabaseException(
			"Error opening database file ($filename). " .
			'Is this a valid MaxMind DB file?'
		);
	}

	/**
	 * @throws \InvalidArgumentException if arguments are passed to the method.
	 * @throws \BadMethodCallException if the database has been closed.
	 * @return Metadata object for the database.
	 */
	public function metadata()
	{
		if (func_num_args()) {
			throw new \InvalidArgumentException(
				'Method takes no arguments.'
			);
		}

		// Not technically required, but this makes it consistent with
		// C extension and it allows us to change our implementation later.
		if (!is_resource($this->fileHandle)) {
			throw new \BadMethodCallException(
				'Attempt to read from a closed MaxMind DB.'
			);
		}

		return $this->metadata;
	}

	/**
	 * Closes the MaxMind DB and returns resources to the system.
	 *
	 * @throws \Exception
	 *             if an I/O error occurs.
	 */
	public function close()
	{
		if (!is_resource($this->fileHandle)) {
			throw new \BadMethodCallException(
				'Attempt to close a closed MaxMind DB.'
			);
		}
		fclose($this->fileHandle);
	}
}

class MaxMindDecoder {
	private $fileStream;
	private $pointerBase;
	// This is only used for unit testing
	private $pointerTestHack;
	private $switchByteOrder;

	private $types = array(
		0 => 'extended',
		1 => 'pointer',
		2 => 'utf8_string',
		3 => 'double',
		4 => 'bytes',
		5 => 'uint16',
		6 => 'uint32',
		7 => 'map',
		8 => 'int32',
		9 => 'uint64',
		10 => 'uint128',
		11 => 'array',
		12 => 'container',
		13 => 'end_marker',
		14 => 'boolean',
		15 => 'float',
	);

	public function __construct(
		$fileStream,
		$pointerBase = 0,
		$pointerTestHack = false
	) {
		$this->fileStream = $fileStream;
		$this->pointerBase = $pointerBase;
		$this->pointerTestHack = $pointerTestHack;

		$this->switchByteOrder = $this->isPlatformLittleEndian();
	}


	public function decode($offset)
	{
		list(, $ctrlByte) = unpack(
			'C',
			MaxMindUtil::read($this->fileStream, $offset, 1)
		);
		$offset++;

		$type = $this->types[$ctrlByte >> 5];

		// Pointers are a special case, we don't read the next $size bytes, we
		// use the size to determine the length of the pointer and then follow
		// it.
		if ($type == 'pointer') {
			list($pointer, $offset) = $this->decodePointer($ctrlByte, $offset);

			// for unit testing
			if ($this->pointerTestHack) {
				return array($pointer);
			}

			list($result) = $this->decode($pointer);

			return array($result, $offset);
		}

		if ($type == 'extended') {
			list(, $nextByte) = unpack(
				'C',
				MaxMindUtil::read($this->fileStream, $offset, 1)
			);

			$typeNum = $nextByte + 7;

			if ($typeNum < 8) {
				throw new InvalidDatabaseException(
					"Something went horribly wrong in the decoder. An extended type "
					. "resolved to a type number < 8 ("
					. $this->types[$typeNum]
					. ")"
				);
			}

			$type = $this->types[$typeNum];
			$offset++;
		}

		list($size, $offset) = $this->sizeFromCtrlByte($ctrlByte, $offset);

		return $this->decodeByType($type, $offset, $size);
	}

	private function decodeByType($type, $offset, $size)
	{
		switch ($type) {
			case 'map':
				return $this->decodeMap($size, $offset);
			case 'array':
				return $this->decodeArray($size, $offset);
			case 'boolean':
				return array($this->decodeBoolean($size), $offset);
		}

		$newOffset = $offset + $size;
		$bytes = MaxMindUtil::read($this->fileStream, $offset, $size);
		switch ($type) {
			case 'utf8_string':
				return array($this->decodeString($bytes), $newOffset);
			case 'double':
				$this->verifySize(8, $size);
				return array($this->decodeDouble($bytes), $newOffset);
			case 'float':
				$this->verifySize(4, $size);
				return array($this->decodeFloat($bytes), $newOffset);
			case 'bytes':
				return array($bytes, $newOffset);
			case 'uint16':
			case 'uint32':
				return array($this->decodeUint($bytes), $newOffset);
			case 'int32':
				return array($this->decodeInt32($bytes), $newOffset);
			case 'uint64':
			case 'uint128':
				return array($this->decodeBigUint($bytes, $size), $newOffset);
			default:
				throw new InvalidDatabaseException(
					"Unknown or unexpected type: " . $type
				);
		}
	}

	private function verifySize($expected, $actual)
	{
		if ($expected != $actual) {
			throw new InvalidDatabaseException(
				"The MaxMind DB file's data section contains bad data (unknown data type or corrupt data)"
			);
		}
	}

	private function decodeArray($size, $offset)
	{
		$array = array();

		for ($i = 0; $i < $size; $i++) {
			list($value, $offset) = $this->decode($offset);
			array_push($array, $value);
		}

		return array($array, $offset);
	}

	private function decodeBoolean($size)
	{
		return $size == 0 ? false : true;
	}

	private function decodeDouble($bits)
	{
		// XXX - Assumes IEEE 754 double on platform
		list(, $double) = unpack('d', $this->maybeSwitchByteOrder($bits));
		return $double;
	}

	private function decodeFloat($bits)
	{
		// XXX - Assumes IEEE 754 floats on platform
		list(, $float) = unpack('f', $this->maybeSwitchByteOrder($bits));
		return $float;
	}

	private function decodeInt32($bytes)
	{
		$bytes = $this->zeroPadLeft($bytes, 4);
		list(, $int) = unpack('l', $this->maybeSwitchByteOrder($bytes));
		return $int;
	}

	private function decodeMap($size, $offset)
	{

		$map = array();

		for ($i = 0; $i < $size; $i++) {
			list($key, $offset) = $this->decode($offset);
			list($value, $offset) = $this->decode($offset);
			$map[$key] = $value;
		}

		return array($map, $offset);
	}

	private $pointerValueOffset = array(
		1 => 0,
		2 => 2048,
		3 => 526336,
		4 => 0,
	);

	private function decodePointer($ctrlByte, $offset)
	{
		$pointerSize = (($ctrlByte >> 3) & 0x3) + 1;

		$buffer = MaxMindUtil::read($this->fileStream, $offset, $pointerSize);
		$offset = $offset + $pointerSize;

		$packed = $pointerSize == 4
			? $buffer
			: (pack('C', $ctrlByte & 0x7)) . $buffer;

		$unpacked = $this->decodeUint($packed);
		$pointer = $unpacked + $this->pointerBase
			+ $this->pointerValueOffset[$pointerSize];

		return array($pointer, $offset);
	}

	private function decodeUint($bytes)
	{
		list(, $int) = unpack('N', $this->zeroPadLeft($bytes, 4));
		return $int;
	}

	private function decodeBigUint($bytes, $byteLength)
	{
		$maxUintBytes = log(PHP_INT_MAX, 2) / 8;

		if ($byteLength == 0) {
			return 0;
		}

		$numberOfLongs = ceil($byteLength / 4);
		$paddedLength = $numberOfLongs * 4;
		$paddedBytes = $this->zeroPadLeft($bytes, $paddedLength);
		$unpacked = array_merge(unpack("N$numberOfLongs", $paddedBytes));

		$integer = 0;

		// 2^32
		$twoTo32 = '4294967296';

		foreach ($unpacked as $part) {
			// We only use gmp or bcmath if the final value is too big
			if ($byteLength <= $maxUintBytes) {
				$integer = ($integer << 32) + $part;
			} elseif (extension_loaded('gmp')) {
				$integer = gmp_strval(gmp_add(gmp_mul($integer, $twoTo32), $part));
			} elseif (extension_loaded('bcmath')) {
				$integer = bcadd(bcmul($integer, $twoTo32), $part);
			} else {
				throw new \RuntimeException(
					'The gmp or bcmath extension must be installed to read this database.'
				);
			}
		}
		return $integer;
	}

	private function decodeString($bytes)
	{
		// XXX - NOOP. As far as I know, the end user has to explicitly set the
		// encoding in PHP. Strings are just bytes.
		return $bytes;
	}

	private function sizeFromCtrlByte($ctrlByte, $offset)
	{
		$size = $ctrlByte & 0x1f;
		$bytesToRead = $size < 29 ? 0 : $size - 28;
		$bytes = MaxMindUtil::read($this->fileStream, $offset, $bytesToRead);
		$decoded = $this->decodeUint($bytes);

		if ($size == 29) {
			$size = 29 + $decoded;
		} elseif ($size == 30) {
			$size = 285 + $decoded;
		} elseif ($size > 30) {
			$size = ($decoded & (0x0FFFFFFF >> (32 - (8 * $bytesToRead))))
				+ 65821;
		}

		return array($size, $offset + $bytesToRead);
	}

	private function zeroPadLeft($content, $desiredLength)
	{
		return str_pad($content, $desiredLength, "\x00", STR_PAD_LEFT);
	}

	private function maybeSwitchByteOrder($bytes)
	{
		return $this->switchByteOrder ? strrev($bytes) : $bytes;
	}

	private function isPlatformLittleEndian()
	{
		$testint = 0x00FF;
		$packed = pack('S', $testint);
		return $testint === current(unpack('v', $packed));
	}
}

/**
 * This class should be thrown when unexpected data is found in the database.
 */
class InvalidDatabaseException extends \Exception
{
}

/**
 * This class provides the metadata for the MaxMind DB file.
 *
 * @property integer nodeCount This is an unsigned 32-bit integer indicating
 * the number of nodes in the search tree.
 *
 * @property integer recordSize This is an unsigned 16-bit integer. It
 * indicates the number of bits in a record in the search tree. Note that each
 * node consists of two records.
 *
 * @property integer ipVersion This is an unsigned 16-bit integer which is
 * always 4 or 6. It indicates whether the database contains IPv4 or IPv6
 * address data.
 *
 * @property string databaseType This is a string that indicates the structure
 * of each data record associated with an IP address. The actual definition of
 * these structures is left up to the database creator.
 *
 * @property array languages An array of strings, each of which is a language
 * code. A given record may contain data items that have been localized to
 * some or all of these languages. This may be undefined.
 *
 * @property integer binaryFormatMajorVersion This is an unsigned 16-bit
 * integer indicating the major version number for the database's binary
 * format.
 *
 * @property integer binaryFormatMinorVersion This is an unsigned 16-bit
 * integer indicating the minor version number for the database's binary format.
 *
 * @property integer buildEpoch This is an unsigned 64-bit integer that
 * contains the database build timestamp as a Unix epoch value.
 *
 * @property array description This key will always point to a map
 * (associative array). The keys of that map will be language codes, and the
 * values will be a description in that language as a UTF-8 string. May be
 * undefined for some databases.
 */
class MaxMindMetadata {
	private $binaryFormatMajorVersion;
	private $binaryFormatMinorVersion;
	private $buildEpoch;
	private $databaseType;
	private $description;
	private $ipVersion;
	private $languages;
	private $nodeByteSize;
	private $nodeCount;
	private $recordSize;
	private $searchTreeSize;

	public function __construct($metadata) {
		$this->binaryFormatMajorVersion =
			$metadata['binary_format_major_version'];
		$this->binaryFormatMinorVersion =
			$metadata['binary_format_minor_version'];
		$this->buildEpoch = $metadata['build_epoch'];
		$this->databaseType = $metadata['database_type'];
		$this->languages = $metadata['languages'];
		$this->description = $metadata['description'];
		$this->ipVersion = $metadata['ip_version'];
		$this->nodeCount = $metadata['node_count'];
		$this->recordSize = $metadata['record_size'];
		$this->nodeByteSize = $this->recordSize / 4;
		$this->searchTreeSize = $this->nodeCount * $this->nodeByteSize;
	}

	public function __get($var) {
		return $this->$var;
	}
}

class MaxMindUtil {
	public static function read( $stream, $offset, $numberOfBytes ) {
		if ( $numberOfBytes == 0 ) {
			return '';
		}
		if ( fseek( $stream, $offset ) == 0 ) {
			$value = fread( $stream, $numberOfBytes );

			// We check that the number of bytes read is equal to the number
			// asked for. We use ftell as getting the length of $value is
			// much slower.
			if ( ftell( $stream ) - $offset === $numberOfBytes ) {
				return $value;
			}
		}
		throw new InvalidDatabaseException(
			"The MaxMind DB file contains bad data"
		);
	}
}