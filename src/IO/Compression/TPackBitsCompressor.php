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
 * The algorithm lives in the incremental {@see TPackBitsEncoder}/{@see TPackBitsDecoder}
 * engines; {@see encoder()}/{@see decoder()} return a fresh context (like PHP's
 * {@see deflate_init()}/{@see inflate_init()}), the whole-string {@see compress()}/
 * {@see decompress()} drive one to completion, and the streaming {@see TPackBitsFilter}
 * drives the same engine bucket by bucket, so all three share one implementation.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPackBitsCompressor implements ICompressor
{
	/** @var int The maximum literal or repeat run length in one packet. */
	public const MaxRun = 128;

	/**
	 * Returns a fresh incremental encoder context (like {@see deflate_init()}).
	 * @return TPackBitsEncoder The encoder engine.
	 */
	public static function encoder(): TPackBitsEncoder
	{
		return new TPackBitsEncoder();
	}

	/**
	 * Returns a fresh incremental decoder context (like {@see inflate_init()}).
	 * @return TPackBitsDecoder The decoder engine.
	 */
	public static function decoder(): TPackBitsDecoder
	{
		return new TPackBitsDecoder();
	}

	/**
	 * Compresses a byte string with PackBits run-length encoding.
	 * @param string $data The raw bytes.
	 * @return string The PackBits-encoded bytes.
	 */
	public static function compress(string $data): string
	{
		$encoder = new TPackBitsEncoder();
		return $encoder->add($data) . $encoder->finish();
	}

	/**
	 * Decompresses a PackBits byte string.  A truncated final literal packet still yields
	 * the literal bytes it carries; a truncated repeat packet (no run byte) yields nothing.
	 * RLE carries no end marker, so this tolerance matches the format.
	 * @param string $data The PackBits-encoded bytes.
	 * @return string The decoded bytes.
	 */
	public static function decompress(string $data): string
	{
		$decoder = new TPackBitsDecoder();
		return $decoder->add($data) . $decoder->finish();
	}
}
