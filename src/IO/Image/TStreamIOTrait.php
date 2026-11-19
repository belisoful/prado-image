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
 * parsing factories.  {@see writeFile()} is what a class's `save()` writes through, so a
 * file is replaced whole or left alone.
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
	 * Writes bytes to a file so that it holds the old content or the new and never part of
	 * one: they go to a temporary file beside the target, which is then renamed over it.  A
	 * write that fails part-way -- a full disk -- therefore leaves the original as it was
	 * and throws.  The replacement keeps the target's permission bits, and a symbolic link
	 * is followed to the file it names rather than replaced.
	 *
	 * Where a rename is not available the bytes are written in place, as a plain write
	 * would: a path with a stream-wrapper scheme, a target that is not a regular file (a
	 * device, a pipe, a link to nothing), and a directory that takes neither the temporary
	 * file nor the rename (read-only, or a file mounted on its own).
	 * @param string $path The destination file path.
	 * @param string $bytes The bytes to write.
	 * @throws TIOException When the file cannot be written.
	 * @return int The number of bytes written.
	 */
	protected function writeFile(string $path, string $bytes): int
	{
		$written = str_contains($path, '://') ? null : $this->replaceFile($path, $bytes);
		$written ??= @file_put_contents($path, $bytes);
		if ($written === false) {
			throw new TIOException('imagefile_unwritable', $path);
		}
		return $written;
	}

	/**
	 * Writes bytes beside a file and renames them over it.
	 * @param string $path The destination file path.
	 * @param string $bytes The bytes to write.
	 * @return null|false|int The number of bytes written, false when the write stopped
	 *   short, or null when the file cannot be replaced this way and nothing was changed.
	 */
	private function replaceFile(string $path, string $bytes): null|false|int
	{
		$target = realpath($path);
		if ($target === false) {
			if (is_link($path)) {
				return null;
			}
			$target = $path;
		} elseif (!is_file($target)) {
			return null;
		}
		$temp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
		$handle = @fopen($temp, 'xb');
		if ($handle === false) {
			return null;
		}
		$written = $this->writeHandle($handle, $bytes);
		fclose($handle);
		if ($written !== strlen($bytes)) {
			@unlink($temp);
			return false;
		}
		$mode = @fileperms($target);
		if ($mode !== false) {
			@chmod($temp, $mode & 0o7777);
		}
		if (!@rename($temp, $target)) {
			@unlink($temp);
			return null;
		}
		return $written;
	}

	/**
	 * Writes bytes to an open file.  A seam: a test overrides it to stand in for a disk
	 * that fills part-way through a write, which no test can arrange for real.
	 * @param resource $handle The open file.
	 * @param string $bytes The bytes to write.
	 * @return false|int The number of bytes written, or false on failure.
	 */
	protected function writeHandle($handle, string $bytes): false|int
	{
		return fwrite($handle, $bytes);
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
