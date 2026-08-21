<?php

/**
 * TPackBitsFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

use Prado\IO\Filter\TStreamCodecFilter;

/**
 * TPackBitsFilter class.
 *
 * Streams PackBits run-length coding as a PHP stream filter, producing the same bytes
 * as {@see TPackBitsCompressor} without buffering the whole stream.  It registers under
 * two names so the direction is chosen at attach time: {@see EncodeName} compresses and
 * {@see DecodeName} decompresses.  {@see registerOnce()} registers both.
 *
 * The encoder keeps a bounded carry (a partial literal or run, at most 128 bytes); the
 * decoder keeps at most one partial packet.  Neither holds the whole stream.
 *
 * ```php
 * TPackBitsFilter::registerOnce();
 * $s = TStream::fromString($raw);
 * $s->appendFilter(TPackBitsFilter::EncodeName, STREAM_FILTER_READ);
 * $encoded = $s->getContents();
 * ```
 *
 * Attach in read mode: the encoder's final packet is emitted when the input ends, which
 * a read reaches at end-of-stream.  In write mode the closing flush happens when the
 * stream is closed, so read the result from a reopened target.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPackBitsFilter extends TStreamCodecFilter
{
	/** @var string The filter name that compresses. */
	public const EncodeName = 'prado.packbits.encode';

	/** @var string The filter name that decompresses. */
	public const DecodeName = 'prado.packbits.decode';

	/** @var bool Whether this instance decompresses (set from the filter name). */
	private bool $_decode = false;

	/** @var string The pending literal run being built (encode). */
	private string $_lit = '';

	/** @var int The byte value of the pending repeat run, or -1 when none (encode). */
	private int $_runByte = -1;

	/** @var int The length of the pending repeat run (encode). */
	private int $_runLen = 0;

	/** @var string The buffered partial packet awaiting more input (decode). */
	private string $_pending = '';

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
	 * Picks the direction from the filter name when PHP creates the filter.
	 * @return bool Always true.
	 */
	public function onCreate(): bool
	{
		$this->_decode = ($this->filtername === static::DecodeName);
		return true;
	}

	/**
	 * Encodes or decodes a chunk, carrying bounded state between chunks.
	 * @param string $data The input chunk.
	 * @return string The transformed bytes produced from this chunk.
	 */
	protected function process(string $data): string
	{
		return $this->_decode ? $this->decode($data) : $this->encode($data);
	}

	/**
	 * Emits the encoder's final pending packet.  A decoder's partial packet at close is a
	 * truncated stream and is discarded, matching {@see TPackBitsCompressor::decompress()}.
	 * @return string The final bytes.
	 */
	protected function finish(): string
	{
		if ($this->_decode) {
			return '';
		}
		if ($this->_runLen > 0) {
			return chr(257 - $this->_runLen) . chr($this->_runByte);
		}
		if ($this->_lit !== '') {
			return chr(strlen($this->_lit) - 1) . $this->_lit;
		}
		return '';
	}

	/**
	 * Feeds a chunk through the byte-by-byte run/literal state machine.
	 * @param string $data The raw chunk.
	 * @return string The PackBits packets completed by this chunk.
	 */
	private function encode(string $data): string
	{
		$out = '';
		$lit = $this->_lit;
		$runByte = $this->_runByte;
		$runLen = $this->_runLen;
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$ch = $data[$i];
			$c = ord($ch);
			if ($runLen > 0) {
				if ($c === $runByte && $runLen < 128) {
					$runLen++;
					continue;
				}
				$out .= chr(257 - $runLen) . chr($runByte);
				$runLen = 0;
				$runByte = -1;
			}
			$litLen = strlen($lit);
			if ($litLen > 0 && $c === ord($lit[$litLen - 1])) {
				if ($litLen > 1) {
					$out .= chr($litLen - 2) . substr($lit, 0, -1); // emit the literal, pull its last byte into a run
				}
				$lit = '';
				$runByte = $c;
				$runLen = 2;
				continue;
			}
			if ($litLen === 128) {                                  // literal full and this byte starts no run; flush before appending, so a run straddling the boundary still coalesces
				$out .= chr($litLen - 1) . $lit;
				$lit = '';
			}
			$lit .= $ch;
		}
		$this->_lit = $lit;
		$this->_runByte = $runByte;
		$this->_runLen = $runLen;
		return $out;
	}

	/**
	 * Decodes whole packets from the carry buffer, retaining any partial tail.
	 * @param string $data The encoded chunk.
	 * @return string The decoded bytes from complete packets.
	 */
	private function decode(string $data): string
	{
		$this->_pending .= $data;
		$out = '';
		$i = 0;
		$len = strlen($this->_pending);
		while ($i < $len) {
			$n = ord($this->_pending[$i]);
			if ($n === 128) {
				$i++;
				continue;
			}
			if ($n < 128) {
				$count = $n + 1;
				if ($i + 1 + $count > $len) {
					break; // incomplete literal; carry it
				}
				$out .= substr($this->_pending, $i + 1, $count);
				$i += 1 + $count;
			} else {
				if ($i + 2 > $len) {
					break; // need the run byte; carry it
				}
				$out .= str_repeat($this->_pending[$i + 1], 257 - $n);
				$i += 2;
			}
		}
		$this->_pending = substr($this->_pending, $i);
		return $out;
	}
}
