<?php

/**
 * TImageChunk class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image;

use Prado\IO\Stream\TLimitStream;
use Prado\IO\Util\TStreamHelper;
use Prado\TComponent;
use Psr\Http\Message\StreamInterface;

/**
 * TImageChunk class.
 *
 * Describes one chunk of a chunked image container (a PNG chunk or a RIFF chunk).  It
 * records the four-character {@see getType() type}, the payload {@see getSize() size},
 * the byte {@see getOffset() offset} of the payload within the file, and the payload
 * {@see getData() bytes}.
 *
 * A chunk read by a streaming (lazy) parse may keep its bytes as a deferred range into the
 * still-open source instead of loading them, so a large payload (a PNG `IDAT`) is copied
 * straight through with {@see copyDeferredTo()} rather than materialized; {@see getData()}
 * materializes the payload on demand through a {@see TLimitStream}, and {@see setData()}
 * loads it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TImageChunk extends TComponent
{
	/** @var string The four-character chunk type. */
	private string $_type;

	/** @var int The payload size in bytes. */
	private int $_size;

	/** @var int The byte offset of the payload within the file. */
	private int $_offset;

	/** @var string The payload bytes (materialized; a deferred chunk fills this on demand). */
	private string $_data;

	/** @var ?StreamInterface The still-open source of a deferred chunk, or null when loaded. */
	private ?StreamInterface $_source = null;

	/** @var int The byte offset of the whole on-disk chunk within the source (deferred only). */
	private int $_wholeOffset = 0;

	/** @var int The length of the whole on-disk chunk (header + payload + any trailer). */
	private int $_wholeLength = 0;

	/**
	 * @param string $type The four-character chunk type.
	 * @param int $size The payload size in bytes.
	 * @param int $offset The byte offset of the payload within the file.
	 * @param string $data The payload bytes.
	 */
	public function __construct(string $type, int $size, int $offset, string $data)
	{
		$this->_type = $type;
		$this->_size = $size;
		$this->_offset = $offset;
		$this->_data = $data;
		parent::__construct();
	}

	/**
	 * Builds a chunk whose payload is deferred to a range in a still-open source, for a
	 * streaming parse that reads the framing but not the bytes.
	 * @param string $type The four-character chunk type.
	 * @param int $size The payload size in bytes.
	 * @param int $offset The byte offset of the payload within the source.
	 * @param StreamInterface $source The still-open, seekable source.
	 * @param int $wholeOffset The byte offset of the whole on-disk chunk within the source.
	 * @param int $wholeLength The length of the whole on-disk chunk (header + payload + trailer).
	 * @return self The deferred chunk.
	 */
	public static function deferred(string $type, int $size, int $offset, StreamInterface $source, int $wholeOffset, int $wholeLength): self
	{
		$chunk = new self($type, $size, $offset, '');
		$chunk->_source = $source;
		$chunk->_wholeOffset = $wholeOffset;
		$chunk->_wholeLength = $wholeLength;
		return $chunk;
	}

	/**
	 * Indicates whether the chunk's payload is deferred to its source rather than loaded.
	 * @return bool Whether the chunk is deferred.
	 */
	public function getIsDeferred(): bool
	{
		return $this->_source !== null;
	}

	/**
	 * Copies the whole on-disk chunk (header + payload + trailer) straight from the source
	 * to a target in bounded memory, for a streaming writer.
	 * @param StreamInterface $target The stream to write to.
	 * @throws \RuntimeException When the chunk is not deferred.
	 * @return int The number of bytes copied.
	 */
	public function copyDeferredTo(StreamInterface $target): int
	{
		if ($this->_source === null) {
			throw new \RuntimeException('The chunk is not deferred; its bytes are already loaded.');
		}
		return TStreamHelper::copyRange($this->_source, $this->_wholeOffset, $this->_wholeLength, $target);
	}

	/**
	 * Returns the four-character chunk type.
	 * @return string The chunk type.
	 */
	public function getType(): string
	{
		return $this->_type;
	}

	/**
	 * Returns the payload size in bytes.
	 * @return int The payload size.
	 */
	public function getSize(): int
	{
		return $this->_size;
	}

	/**
	 * Returns the byte offset of the payload within the file.
	 * @return int The payload offset.
	 */
	public function getOffset(): int
	{
		return $this->_offset;
	}

	/**
	 * Returns the payload bytes, materializing a deferred chunk's payload from its source
	 * through a {@see TLimitStream} window on demand.
	 * @return string The payload.
	 */
	public function getData(): string
	{
		if ($this->_source !== null) {
			return (new TLimitStream($this->_source, $this->_size, $this->_offset))->getContents();
		}
		return $this->_data;
	}

	/**
	 * Sets the payload bytes, updating the recorded size and loading the chunk (a deferred
	 * range is dropped, since the payload is now held directly).
	 * @param string $value The payload.
	 */
	public function setData(string $value): void
	{
		$this->_data = $value;
		$this->_size = strlen($value);
		$this->_source = null;
	}
}
