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
 * parsing factories.  {@see readFile()} and {@see fileBytes()} read a whole file within
 * the {@see TImageFile::getMaxFileSize() file ceiling}, and {@see writeFile()} is what a
 * class's `save()` writes through, so a file is replaced whole or left alone.
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
	 * one: they go to a temporary file beside the target, which is then renamed over it.
	 * The replacement keeps the target's permission bits, and a symbolic link is followed
	 * to the file it names rather than replaced.
	 *
	 * Whatever stops that leaves the original as it was and throws: a write that fails
	 * part-way (a full disk), a directory that will not take the temporary file (read-only),
	 * a rename that is refused (a file mounted on its own).  The bytes are never written
	 * over the file in place instead, because a write in place that fails has already
	 * destroyed what it was replacing; a caller who wants one all the same hands
	 * {@see writeTo()} a handle it opened itself.
	 *
	 * Only where there is no file to replace are the bytes written directly: a path with a
	 * stream-wrapper scheme, and a target that is not a regular file -- a device, a pipe, a
	 * link to nothing.
	 * @param string $path The destination file path.
	 * @param string $bytes The bytes to write.
	 * @throws TIOException When the file cannot be written, or cannot be replaced.
	 * @return int The number of bytes written.
	 */
	protected function writeFile(string $path, string $bytes): int
	{
		$target = str_contains($path, '://') ? null : self::replaceableFile($path);
		if ($target === null) {
			$written = @file_put_contents($path, $bytes);
			if ($written === false) {
				throw new TIOException('imagefile_unwritable', $path);
			}
			return $written;
		}
		// Named for nothing but chance, so a target whose own name is as long as a name may
		// be still has room for one beside it.
		$temp = dirname($target) . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(8)) . '.tmp';
		$handle = @fopen($temp, 'xb');
		if ($handle === false) {
			throw new TIOException('imagefile_no_temporary_file', $path);
		}
		$written = $this->writeHandle($handle, $bytes);
		fclose($handle);
		if ($written !== strlen($bytes)) {
			@unlink($temp);
			throw new TIOException('imagefile_unwritable', $path);
		}
		$mode = @fileperms($target);
		if ($mode !== false) {
			@chmod($temp, $mode & 0o7777);
		}
		if (!@rename($temp, $target)) {
			@unlink($temp);
			throw new TIOException('imagefile_rename_refused', $path);
		}
		return $written;
	}

	/**
	 * Resolves the file a path's bytes are to replace.
	 * @param string $path The destination file path.
	 * @return ?string The file to write beside and rename over -- a link followed to the
	 *   file it names, a path that names nothing yet as it stands -- or null when the path
	 *   is not a regular file to replace: a device, a pipe, a directory, a link to nothing.
	 */
	private static function replaceableFile(string $path): ?string
	{
		$target = realpath($path);
		if ($target === false) {
			return is_link($path) ? null : $path;
		}
		return is_file($target) ? $target : null;
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
	 * @param ?int $limit The most bytes to read from a stream, or null to read to its end.
	 *   A caller that must not hold more than a ceiling asks for one byte over it, and
	 *   getting that many is how it knows the source is longer.
	 * @throws TInvalidDataTypeException When the source is none of those.
	 * @return string The bytes.
	 */
	protected static function sourceBytes(mixed $source, ?int $limit = null): string
	{
		if (is_string($source)) {
			return $source;
		}
		if (is_resource($source)) {
			$source = TStream::fromResource($source, false);
		}
		if (!$source instanceof StreamInterface) {
			throw new TInvalidDataTypeException('streamio_source_invalid', get_debug_type($source));
		}
		if ($limit === null) {
			return $source->getContents();
		}
		// Gathered a piece at a time: asked for a length, PHP's own whole-stream read
		// allocates all of it before it reads any, however short the stream is.
		$data = '';
		while (strlen($data) < $limit) {
			$piece = $source->read(min($limit - strlen($data), 0x100000));
			if ($piece === '') {
				break;   // the end, or a stream that yields nothing: it is not waited on
			}
			$data .= $piece;
		}
		return $data;
	}

	/**
	 * Drains a byte source that is a whole file, within the
	 * {@see TImageFile::getMaxFileSize() file ceiling}: reading stops one byte past the
	 * ceiling, so a source longer than memory is refused instead of exhausting it.
	 * @param mixed $source The string, stream, or stream resource.
	 * @throws TInvalidDataTypeException When the source is none of those.
	 * @throws TIOException When the source is longer than the ceiling.
	 * @return string The bytes.
	 */
	protected static function fileBytes(mixed $source): string
	{
		$ceiling = TImageFile::getMaxFileSize();
		$bytes = static::sourceBytes($source, $ceiling === null ? null : min($ceiling, PHP_INT_MAX - 1) + 1);
		TImageFile::assertFileSize(strlen($bytes));
		return $bytes;
	}

	/**
	 * Reads a file whole, within the {@see TImageFile::getMaxFileSize() file ceiling}.
	 * @param string $path The file path.
	 * @throws TIOException When the file cannot be read, or is longer than the ceiling.
	 * @return string The bytes.
	 */
	protected static function readFile(string $path): string
	{
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			throw new TIOException('imagefile_unreadable', $path);
		}
		try {
			return static::fileBytes($handle);
		} finally {
			fclose($handle);
		}
	}
}
