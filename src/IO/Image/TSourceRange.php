<?php

/**
 * TSourceRange class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\IO\Stream\TLimitStream;
use Prado\IO\TStream;
use Prado\IO\Util\TStreamHelper;
use Prado\TComponent;
use Psr\Http\Message\StreamInterface;

/**
 * TSourceRange class.
 *
 * A deferred byte payload: a `[offset, length]` window into a still-open, seekable source
 * stream, standing in for a large region — a TIFF strip, a PNG `IDAT`, a JPEG entropy scan
 * — that a streaming reader parsed past without loading.  {@see writeTo()} copies the
 * window straight to a target in bounded memory (via {@see TStreamHelper::copyRange()}), so
 * a container can rewrite its metadata and pass a payload far larger than memory through
 * from source to target untouched.  {@see read()} materializes it, for the whole-string path.
 *
 * The source stream must stay open and seekable for the life of the range.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TSourceRange extends TComponent
{
	/** @var StreamInterface The seekable source stream. */
	private StreamInterface $_source;

	/** @var int The absolute byte offset of the payload in the source. */
	private int $_offset;

	/** @var int The payload length in bytes. */
	private int $_length;

	/**
	 * @param StreamInterface $source The seekable source stream.
	 * @param int $offset The absolute byte offset of the payload in the source.
	 * @param int $length The payload length in bytes.
	 */
	public function __construct(StreamInterface $source, int $offset, int $length)
	{
		$this->_source = $source;
		$this->_offset = $offset;
		$this->_length = $length;
	}

	/**
	 * Returns the payload length in bytes.
	 * @return int The length.
	 */
	public function getLength(): int
	{
		return $this->_length;
	}

	/**
	 * Copies the payload from the source to a target in bounded memory.
	 * @param mixed $target A writable {@see StreamInterface} or PHP stream resource.
	 * @throws TInvalidDataTypeException When the target is neither.
	 * @return int The number of bytes written (equal to {@see getLength()}).
	 */
	public function writeTo(mixed $target): int
	{
		if (is_resource($target)) {
			$target = TStream::fromResource($target, false);
		}
		if (!$target instanceof StreamInterface) {
			throw new TInvalidDataTypeException('streamio_target_invalid', get_debug_type($target));
		}
		return TStreamHelper::copyRange($this->_source, $this->_offset, $this->_length, $target);
	}

	/**
	 * Materializes the payload into a string.  This defeats the streaming, so it is for the
	 * whole-string path (a caller that asked a streamed container for its bytes).
	 * @return string The payload bytes.
	 */
	public function read(): string
	{
		return (new TLimitStream($this->_source, $this->_length, $this->_offset))->getContents();
	}
}
