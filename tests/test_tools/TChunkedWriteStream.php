<?php

/**
 * TChunkedWriteStream class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

use Psr\Http\Message\StreamInterface;

/**
 * A write target that accepts at most a fixed number of bytes per call, so the partial-write
 * loop of a streaming writer (TStreamIOTrait::writeTo(), TTIFFDocument::streamTo()) can be
 * observed; a chunk of zero is a stream that never accepts anything.
 */
class TChunkedWriteStream implements StreamInterface
{
	public string $buffer = '';

	public int $writes = 0;

	public function __construct(private int $chunk)
	{
	}

	public function write(string $string): int
	{
		$this->writes++;
		$bytes = substr($string, 0, $this->chunk);
		$this->buffer .= $bytes;
		return strlen($bytes);
	}

	public function __toString(): string
	{
		return $this->buffer;
	}

	public function close(): void
	{
	}

	public function detach()
	{
		return null;
	}

	public function getSize(): ?int
	{
		return strlen($this->buffer);
	}

	public function tell(): int
	{
		return strlen($this->buffer);
	}

	public function eof(): bool
	{
		return true;
	}

	public function isSeekable(): bool
	{
		return false;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new RuntimeException('not seekable');
	}

	public function rewind(): void
	{
		throw new RuntimeException('not seekable');
	}

	public function isWritable(): bool
	{
		return true;
	}

	public function isReadable(): bool
	{
		return false;
	}

	public function read(int $length): string
	{
		throw new RuntimeException('not readable');
	}

	public function getContents(): string
	{
		return $this->buffer;
	}

	public function getMetadata(?string $key = null)
	{
		return $key === null ? [] : null;
	}
}
