<?php

/**
 * TStreamIOTrait trait file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TIOException;
use Prado\IO\TStream;
use Psr\Http\Message\StreamInterface;

/**
 * TStreamIOTrait trait.
 *
 * Gives a composable metadata or container class PSR-7 stream and PHP resource IO:
 * {@see writeTo()} composes the object (via its `toBinary()`) into any writable
 * {@see StreamInterface} or stream resource, and {@see sourceBytes()} drains any
 * string, {@see StreamInterface}, or stream resource into bytes for the class's
 * parsing factories.
 *
 * Every framework stream flows through both sides: a {@see TStream}, a windowed
 * {@see \Prado\IO\Stream\TLimitStream}, or a typed {@see \Prado\IO\Stream\TBinaryStream}
 * (which is itself a PSR-7 stream decorator) — and a raw PHP resource (a
 * {@see \Prado\IO\TResource} handle) is wrapped without taking ownership, so
 * `TStream::asResource()` round-trips too.
 *
 * ```php
 * $jpeg = TJPEG::fromStream($binaryStream);          // TBinaryStream in
 * $jpeg->writeTo(fopen('php://temp', 'w+b'));        // resource out
 * $exif->writeTo(TStream::fromFile('exif.bin', 'wb'));
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
trait TStreamIOTrait
{
	/**
	 * Composes the object and writes the bytes to a stream or stream resource,
	 * honoring partial writes.
	 * @param mixed $target A writable {@see StreamInterface} or PHP stream resource.
	 * @throws TInvalidDataTypeException When the target is neither.
	 * @throws TIOException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	public function writeTo(mixed $target): int
	{
		if (is_resource($target)) {
			$target = TStream::fromResource($target, false);
		}
		if (!$target instanceof StreamInterface) {
			throw new TInvalidDataTypeException('streamio_target_invalid', get_debug_type($target));
		}
		$bytes = (string) $this->toBinary();
		$length = strlen($bytes);
		$total = 0;
		while ($total < $length) {
			$written = $target->write($total === 0 ? $bytes : substr($bytes, $total));
			if ($written < 1) {
				throw new TIOException('streamio_write_failed', $total, $length);
			}
			$total += $written;
		}
		return $total;
	}

	/**
	 * Drains a byte source: a string is returned as-is, a {@see StreamInterface} is
	 * read from its current position to the end, and a PHP stream resource is wrapped
	 * (without taking ownership) and read the same way.
	 * @param mixed $source The string, stream, or stream resource.
	 * @throws TInvalidDataTypeException When the source is none of those.
	 * @return string The bytes.
	 */
	protected static function sourceBytes(mixed $source): string
	{
		if (is_string($source)) {
			return $source;
		}
		if (is_resource($source)) {
			$source = TStream::fromResource($source, false);
		}
		if ($source instanceof StreamInterface) {
			return $source->getContents();
		}
		throw new TInvalidDataTypeException('streamio_source_invalid', get_debug_type($source));
	}
}
