<?php

/**
 * TPhotoshop8BIM class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\IO\TResourceType;
use Prado\IO\TStream;

/**
 * TPhotoshop8BIM class.
 *
 * Decodes and encodes the Photoshop "8BIM" image-resource block that wraps IPTC data in
 * a JPEG APP13 segment.  {@see iptcDecode()} locates the IPTC resource (id 0x0404) within
 * a `Photoshop 3.0` block and narrows a string to just the IPTC payload, returning its
 * length; {@see iptcEncode()} wraps an IPTC payload back into an 8BIM block.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.adobe.com/devnet-apps/photoshop/fileformatashtml/#50577409_34945 Official standard
 * @see https://exiftool.org/TagNames/Photoshop.html For basic tag information
 */
class TPhotoshop8BIM
{
	public const PHOTOSHOP_HEADER = 'Photoshop';

	public const PHOTOSHOP_8BIM_TYPE = '8BIM';

	public const IPTCData = 0x0404;
	public const EXIFInfo = 0x0422;

	/**
	 * Indicates whether the data begins with a Photoshop resource header.
	 * @param resource|string &$data The data to inspect (string or stream resource).
	 * @throws TInvalidDataTypeException When the data is neither a string nor a stream.
	 * @return bool Whether the data is a Photoshop resource block.
	 */
	public static function isPhotoshop(mixed &$data): bool
	{
		if (empty($data)) {
			return false;
		}
		if (is_string($data)) {
			return strncmp($data, self::PHOTOSHOP_HEADER, strlen(self::PHOTOSHOP_HEADER)) === 0;
		}
		if (is_resource($data) && get_resource_type($data) === TResourceType::Stream) {
			$stream = TStream::fromResource($data, false);   // wrap without taking ownership
			$place = $stream->tell();
			$id = $stream->read(strlen(self::PHOTOSHOP_HEADER));
			$stream->seek($place);
			return $id === self::PHOTOSHOP_HEADER;
		}
		throw new TInvalidDataTypeException('ps8bim_not_a_stream');
	}

	/**
	 * Builds the 8BIM header that precedes an IPTC payload of the given length.
	 * @param int $length The IPTC payload length in bytes.
	 * @param string $name The resource name. Default '' (recommended for compatibility).
	 * @return string The 8BIM IPTC header.
	 */
	public static function iptcHeader(int $length, string $name = ''): string
	{
		$str = "Photoshop 3.0\0" . self::PHOTOSHOP_8BIM_TYPE . pack('n', self::IPTCData) . $name . "\0";
		if (strlen($str) & 1) {
			$str .= "\0";
		}
		return $str . pack('N', $length);
	}

	/**
	 * Wraps IPTC binary data in a Photoshop 8BIM resource block.
	 * @param string $data The IPTC data to wrap.
	 * @param string $name The resource name. Default ''.
	 * @return string The 8BIM-wrapped IPTC data.
	 */
	public static function iptcEncode(string $data, string $name = ''): string
	{
		return self::iptcHeader(strlen($data), $name) . $data;
	}

	/**
	 * Locates the IPTC resource within a Photoshop block.  For a string, it narrows the
	 * argument to the IPTC payload and returns the length; for a stream, it positions the
	 * cursor at the IPTC payload and returns the length.
	 * @param resource|string &$stream The Photoshop resource data (string or stream).
	 * @throws TInvalidDataTypeException When the data is neither a string nor a stream.
	 * @return null|false|int The IPTC payload length, null when not a Photoshop block, or false on a malformed block.
	 */
	public static function iptcDecode(mixed &$stream): false|null|int
	{
		if (is_string($stream)) {
			return self::iptcDecodeString($stream);
		}
		if (is_resource($stream) && get_resource_type($stream) === TResourceType::Stream) {
			return self::iptcDecodeStream($stream);
		}
		throw new TInvalidDataTypeException('ps8bim_not_a_stream');
	}

	/**
	 * Narrows a Photoshop string to its IPTC payload and returns the payload length.
	 * @param string &$stream The Photoshop resource string, narrowed in place to the IPTC payload.
	 * @return null|false|int The payload length, null when not Photoshop, or false on a malformed block.
	 */
	private static function iptcDecodeString(string &$stream): false|null|int
	{
		if (strncmp($stream, self::PHOTOSHOP_HEADER, strlen(self::PHOTOSHOP_HEADER)) !== 0) {
			return null;
		}
		$pos = strpos($stream, pack('n', self::IPTCData));
		if ($pos === false || $pos > 20) {
			return false;
		}
		$pos += 2;
		$npos = strpos($stream, "\0", $pos);
		if ($npos === false || ($npos - $pos) > 256) {
			return false;
		}
		$pos = $npos + 1;
		$pos += $pos & 1;
		$d = substr($stream, $pos, 4);
		if (strlen($d) !== 4) {
			return false;
		}
		$length = unpack('N', $d)[1];
		$stream = substr($stream, $pos + 4, $length);
		return $length;
	}

	/**
	 * Positions a Photoshop stream at its IPTC payload and returns the payload length.
	 * @param resource $stream The Photoshop resource stream.
	 * @return false|int The payload length, or false on a malformed block.
	 */
	private static function iptcDecodeStream(mixed $stream): false|int
	{
		$stream = TStream::fromResource($stream, false);   // wrap without taking ownership
		$startPosition = $stream->tell();
		$i = 0;
		while (!$stream->eof() && ($stream->read(1) !== "\0") && $i++ < 20) {
		}
		if ($i >= 20) {
			$stream->seek($startPosition);
			return false;
		}
		$type = $stream->read(4);
		if ($type !== self::PHOTOSHOP_8BIM_TYPE) {
			$stream->seek($startPosition);
			return false;
		}
		$tag = $stream->read(2);
		if (strlen($tag) !== 2 || unpack('n', $tag)[1] !== self::IPTCData) {
			$stream->seek($startPosition);
			return false;
		}
		$i = 0;
		while (!$stream->eof() && ($stream->read(1) !== "\0") && $i++ <= 256) {
		}
		if ($i > 256) {
			$stream->seek($startPosition);
			return false;
		}
		if (($stream->tell() - $startPosition) & 1) {
			if (strlen($stream->read(1)) !== 1) {
				$stream->seek($startPosition);
				return false;
			}
		}
		$length = $stream->read(4);
		if (!isset($length[3])) {   // fewer than 4 bytes: a truncated length field
			$stream->seek($startPosition);
			return false;
		}
		return unpack('N', $length)[1];
	}
}
