<?php

/**
 * TLZWFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

use Prado\IO\Filter\TStreamCodecFilter;

/**
 * TLZWFilter class.
 *
 * Streams variable-width LZW coding as a PHP stream filter, producing the same bytes as
 * {@see TLZWCompressor} without buffering the whole stream.  It registers under two
 * names so the direction is chosen at attach time: {@see EncodeName} compresses and
 * {@see DecodeName} decompresses.  {@see registerOnce()} registers both.
 *
 * The filter holds no algorithm of its own: it drives the same incremental
 * {@see TLZWEncoder}/{@see TLZWDecoder} engine that {@see TLZWCompressor} uses, feeding
 * each bucket to {@see \Prado\IO\Compression\IStreamCodec::add()} and flushing with
 * {@see \Prado\IO\Compression\IStreamCodec::finish()} on close, so the two forms can
 * never drift.
 *
 * ```php
 * TLZWFilter::registerOnce();
 * $s = TStream::fromString($lzwBytes);
 * $s->appendFilter(TLZWFilter::DecodeName, STREAM_FILTER_READ);
 * $raw = $s->getContents();
 * ```
 *
 * Attach in read mode: the encoder's end-of-information code is emitted when the input
 * ends, which a read reaches at end-of-stream.  In write mode the closing flush happens
 * when the stream is closed, so read the result from a reopened target.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TLZWFilter extends TStreamCodecFilter
{
	/** @var string The filter name that compresses. */
	public const EncodeName = 'prado.lzw.encode';

	/** @var string The filter name that decompresses. */
	public const DecodeName = 'prado.lzw.decode';

	/** @var IStreamCodec The engine for this instance's direction (set from the filter name). */
	private IStreamCodec $_codec;

	/**
	 * Returns the default (encode) filter name.
	 * @return string The encode filter name.
	 */
	public static function getFilterName(): string
	{
		return static::EncodeName;
	}

	/**
	 * Registers both the encode and decode filter names, each only once.
	 * @param ?string $name A specific name to register; null registers both.
	 */
	public static function registerOnce(?string $name = null): void
	{
		if ($name !== null) {
			parent::registerOnce($name);
			return;
		}
		parent::registerOnce(static::EncodeName);
		parent::registerOnce(static::DecodeName);
	}

	/**
	 * Picks the engine from the filter name when PHP creates the filter.
	 * @return bool Always true.
	 */
	public function onCreate(): bool
	{
		$this->_codec = ($this->filtername === static::DecodeName)
			? TLZWCompressor::decoder()
			: TLZWCompressor::encoder();
		return true;
	}

	/**
	 * Feeds a chunk to the engine.
	 * @param string $data The input chunk.
	 * @return string The transformed bytes produced from this chunk.
	 */
	protected function process(string $data): string
	{
		return $this->_codec->add($data);
	}

	/**
	 * Flushes the engine's trailing state when the stream closes.
	 * @return string The final bytes.
	 */
	protected function finish(): string
	{
		return $this->_codec->finish();
	}
}
