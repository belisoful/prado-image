<?php

/**
 * TPackBitsCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * TPackBitsCompressor class.
 *
 * Implements the PackBits run-length codec used by Apple MacPaint and TIFF.  The
 * stream is a sequence of packets, each a one-byte header followed by data:
 *
 * | Header n   | Meaning                                              |
 * |------------|------------------------------------------------------|
 * | 0..127     | The next n+1 bytes are literal.                      |
 * | 129..255   | The next single byte repeats 257-n times (2..128).   |
 * | 128        | No-op (skipped).                                     |
 *
 * {@see compress()} and {@see decompress()} are pure string transforms and round-trip
 * any byte string.  The filter form is {@see TPackBitsFilter}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPackBitsCompressor implements ICompressor
{
	/** @var int The maximum literal or repeat run length in one packet. */
	public const MaxRun = 128;

	/**
	 * Compresses a byte string with PackBits run-length encoding.
	 * @param string $data The raw bytes.
	 * @return string The PackBits-encoded bytes.
	 */
	public static function compress(string $data): string
	{
		$len = strlen($data);
		$out = '';
		$i = 0;
		while ($i < $len) {
			$run = 1;
			while ($i + $run < $len && $run < self::MaxRun && $data[$i + $run] === $data[$i]) {
				$run++;
			}
			if ($run >= 2) {
				$out .= chr(257 - $run) . $data[$i];
				$i += $run;
				continue;
			}
			$literal = '';
			while ($i < $len && strlen($literal) < self::MaxRun) {
				if ($i + 1 < $len && $data[$i] === $data[$i + 1]) {
					break;
				}
				$literal .= $data[$i];
				$i++;
			}
			$out .= chr(strlen($literal) - 1) . $literal;
		}
		return $out;
	}

	/**
	 * Decompresses a PackBits byte string.  A truncated final packet decodes to the bytes
	 * of its complete packets; RLE carries no end marker, so tolerance matches the format.
	 * @param string $data The PackBits-encoded bytes.
	 * @return string The decoded bytes.
	 */
	public static function decompress(string $data): string
	{
		$len = strlen($data);
		$out = '';
		$i = 0;
		while ($i < $len) {
			$n = ord($data[$i++]);
			if ($n === 128) {
				continue;
			}
			if ($n < 128) {
				$count = $n + 1;
				$out .= substr($data, $i, $count);
				$i += $count;
			} else {
				if ($i >= $len) {
					break;
				}
				$out .= str_repeat($data[$i], 257 - $n);
				$i++;
			}
		}
		return $out;
	}
}
