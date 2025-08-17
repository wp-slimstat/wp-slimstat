<?php

namespace SlimStat\Utils;

class MaxMindDecoder
{
    private $fileStream;

    private $pointerBase;

    // This is only used for unit testing
    private $pointerTestHack;

    private $switchByteOrder;

    private $types = [
        0  => 'extended',
        1  => 'pointer',
        2  => 'utf8_string',
        3  => 'double',
        4  => 'bytes',
        5  => 'uint16',
        6  => 'uint32',
        7  => 'map',
        8  => 'int32',
        9  => 'uint64',
        10 => 'uint128',
        11 => 'array',
        12 => 'container',
        13 => 'end_marker',
        14 => 'boolean',
        15 => 'float',
    ];

    public function __construct($fileStream, $pointerBase = 0, $pointerTestHack = false)
    {
        $this->fileStream      = $fileStream;
        $this->pointerBase     = $pointerBase;
        $this->pointerTestHack = $pointerTestHack;

        $this->switchByteOrder = $this->isPlatformLittleEndian();
    }

    /**
     * @throws InvalidDatabaseException
     */
    public function decode($offset)
    {
        [, $ctrlByte] = unpack(
            'C',
            MaxMindUtil::read($this->fileStream, $offset, 1)
        );
        $offset++;

        $type = $this->types[$ctrlByte >> 5];

        // Pointers are a special case, we don't read the next $size bytes, we
        // use the size to determine the length of the pointer and then follow
        // it.
        if ('pointer' == $type) {
            [$pointer, $offset] = $this->decodePointer($ctrlByte, $offset);

            // for unit testing
            if ($this->pointerTestHack) {
                return [$pointer];
            }

            [$result] = $this->decode($pointer);

            return [$result, $offset];
        }

        if ('extended' == $type) {
            [, $nextByte] = unpack(
                'C',
                MaxMindUtil::read($this->fileStream, $offset, 1)
            );

            $typeNum = $nextByte + 7;

            if ($typeNum < 8) {
                throw new InvalidDatabaseException(
                    'Something went horribly wrong in the decoder. An extended type '
                    . 'resolved to a type number < 8 ('
                    . $this->types[$typeNum]
                    . ')'
                );
            }

            $type = $this->types[$typeNum];
            $offset++;
        }

        [$size, $offset] = $this->sizeFromCtrlByte($ctrlByte, $offset);

        return $this->decodeByType($type, $offset, $size);
    }

    /**
     * @throws InvalidDatabaseException
     */
    private function decodeByType($type, $offset, $size): array
    {
        switch ($type) {
            case 'map':
                return $this->decodeMap($size, $offset);
            case 'array':
                return $this->decodeArray($size, $offset);
            case 'boolean':
                return [$this->decodeBoolean($size), $offset];
        }

        $newOffset = $offset + $size;
        $bytes     = MaxMindUtil::read($this->fileStream, $offset, $size);
        switch ($type) {
            case 'utf8_string':
                return [$this->decodeString($bytes), $newOffset];
            case 'double':
                $this->verifySize(8, $size);
                return [$this->decodeDouble($bytes), $newOffset];
            case 'float':
                $this->verifySize(4, $size);
                return [$this->decodeFloat($bytes), $newOffset];
            case 'bytes':
                return [$bytes, $newOffset];
            case 'uint16':
            case 'uint32':
                return [$this->decodeUint($bytes), $newOffset];
            case 'int32':
                return [$this->decodeInt32($bytes), $newOffset];
            case 'uint64':
            case 'uint128':
                return [$this->decodeBigUint($bytes, $size), $newOffset];
            default:
                throw new InvalidDatabaseException(
                    'Unknown or unexpected type: ' . $type
                );
        }
    }

    /**
     * @throws InvalidDatabaseException
     */
    private function verifySize($expected, $actual)
    {
        if ($expected != $actual) {
            throw new InvalidDatabaseException(
                "The MaxMind DB file's data section contains bad data (unknown data type or corrupt data)"
            );
        }
    }

    /**
     * @throws InvalidDatabaseException
     */
    private function decodeArray($size, $offset): array
    {
        $array = [];

        for ($i = 0; $i < $size; $i++) {
            [$value, $offset] = $this->decode($offset);
            $array[]          = $value;
        }

        return [$array, $offset];
    }

    private function decodeBoolean($size): bool
    {
        return 0 != $size;
    }

    private function decodeDouble($bits)
    {
        // XXX - Assumes IEEE 754 double on platform
        [, $double] = unpack('d', $this->maybeSwitchByteOrder($bits));
        return $double;
    }

    private function decodeFloat($bits)
    {
        // XXX - Assumes IEEE 754 floats on platform
        [, $float] = unpack('f', $this->maybeSwitchByteOrder($bits));
        return $float;
    }

    private function decodeInt32($bytes)
    {
        $bytes   = $this->zeroPadLeft($bytes, 4);
        [, $int] = unpack('l', $this->maybeSwitchByteOrder($bytes));
        return $int;
    }

    private function decodeMap($size, $offset): array
    {

        $map = [];

        for ($i = 0; $i < $size; $i++) {
            [$key, $offset]   = $this->decode($offset);
            [$value, $offset] = $this->decode($offset);
            $map[$key]        = $value;
        }

        return [$map, $offset];
    }

    private $pointerValueOffset = [
        1 => 0,
        2 => 2048,
        3 => 526336,
        4 => 0,
    ];

    /**
     * @throws InvalidDatabaseException
     */
    private function decodePointer($ctrlByte, $offset): array
    {
        $pointerSize = (($ctrlByte >> 3) & 0x3) + 1;

        $buffer = MaxMindUtil::read($this->fileStream, $offset, $pointerSize);
        $offset += $pointerSize;

        $packed = 4 == $pointerSize
            ? $buffer
            : (pack('C', $ctrlByte & 0x7)) . $buffer;

        $unpacked = $this->decodeUint($packed);
        $pointer  = $unpacked + $this->pointerBase
            + $this->pointerValueOffset[$pointerSize];

        return [$pointer, $offset];
    }

    private function decodeUint($bytes)
    {
        [, $int] = unpack('N', $this->zeroPadLeft($bytes, 4));
        return $int;
    }

    private function decodeBigUint($bytes, $byteLength)
    {
        $maxUintBytes = log(PHP_INT_MAX, 2) / 8;

        if (0 == $byteLength) {
            return 0;
        }

        $numberOfLongs = ceil($byteLength / 4);
        $paddedLength  = $numberOfLongs * 4;
        $paddedBytes   = $this->zeroPadLeft($bytes, $paddedLength);
        $unpacked      = array_merge(unpack('N' . $numberOfLongs, $paddedBytes));

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

    private function sizeFromCtrlByte($ctrlByte, $offset): array
    {
        $size        = $ctrlByte & 0x1f;
        $bytesToRead = $size < 29 ? 0 : $size - 28;
        $bytes       = MaxMindUtil::read($this->fileStream, $offset, $bytesToRead);
        $decoded     = $this->decodeUint($bytes);

        if (29 == $size) {
            $size = 29 + $decoded;
        } elseif (30 == $size) {
            $size = 285 + $decoded;
        } elseif ($size > 30) {
            $size = ($decoded & (0x0FFFFFFF >> (32 - (8 * $bytesToRead))))
                + 65821;
        }

        return [$size, $offset + $bytesToRead];
    }

    private function zeroPadLeft($content, $desiredLength): string
    {
        return str_pad($content, $desiredLength, "\x00", STR_PAD_LEFT);
    }

    private function maybeSwitchByteOrder($bytes)
    {
        return $this->switchByteOrder ? strrev($bytes) : $bytes;
    }

    private function isPlatformLittleEndian(): bool
    {
        $testint = 0x00FF;
        $packed  = pack('S', $testint);
        return $testint === current(unpack('v', $packed));
    }
}
