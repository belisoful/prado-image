<?php

/**
 * TLZWFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

use Prado\Exceptions\TIOException;
use Prado\IO\Filter\TStreamCodecFilter;

/**
 * TLZWFilter class.
 *
 * Streams variable-width LZW coding as a PHP stream filter, producing the same bytes as
 * {@see TLZWCompressor} without buffering the whole stream.  It registers under two
 * names so the direction is chosen at attach time: {@see EncodeName} compresses and
 * {@see DecodeName} decompresses.  {@see registerOnce()} registers both.
 *
 * The only state held is the LZW dictionary (bounded at the 4096-code ceiling) and a
 * sub-byte bit accumulator; the input stream itself is never buffered.  Codes are packed
 * most-significant-bit first, matching {@see TLZWCompressor}.
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

	/** @var bool Whether this instance decompresses (set from the filter name). */
	private bool $_decode = false;

	/** @var int The pending bits, most-significant first. */
	private int $_bitBuffer = 0;

	/** @var int The count of valid bits in the bit buffer. */
	private int $_bitCount = 0;

	/** @var int The current code width in bits. */
	private int $_codeSize = TLZWCompressor::MinCodeSize;

	/** @var int The next dictionary code to assign. */
	private int $_next = TLZWCompressor::FirstCode;

	/** @var bool Whether the stream prologue (clear code) has been emitted/seen. */
	private bool $_started = false;

	/** @var array<string, int> The encoder dictionary (string to code). */
	private array $_encDict = [];

	/** @var ?string The encoder's current prefix. */
	private ?string $_w = null;

	/** @var array<int, string> The decoder dictionary (code to string). */
	private array $_decDict = [];

	/** @var ?int The decoder's previous code. */
	private ?int $_prev = null;

	/** @var bool Whether the decoder has read end-of-information. */
	private bool $_finished = false;

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
	 * Encodes or decodes a chunk, carrying the dictionary and bit state between chunks.
	 * @param string $data The input chunk.
	 * @return string The transformed bytes produced from this chunk.
	 */
	protected function process(string $data): string
	{
		return $this->_decode ? $this->decode($data) : $this->encode($data);
	}

	/**
	 * Emits the encoder's final code, end-of-information, and padding (decode carries none).
	 * @return string The final bytes.
	 */
	protected function finish(): string
	{
		if ($this->_decode) {
			return '';
		}
		$out = $this->startEncode();
		if ($this->_w !== null) {
			$this->pushBits($this->_encDict[$this->_w], $this->_codeSize);
		}
		$this->pushBits(TLZWCompressor::EndOfInformation, $this->_codeSize);
		return $out . $this->drainBytes() . $this->flushBits();
	}

	/**
	 * Encodes a chunk, emitting whole output bytes as codes complete them.
	 * @param string $data The raw chunk.
	 * @return string The encoded bytes produced from this chunk.
	 */
	private function encode(string $data): string
	{
		$out = $this->startEncode();
		// Pull state into locals and inline the bit packing; per-byte property access and method
		// calls dominate this hot loop.
		$w = $this->_w;
		$next = $this->_next;
		$codeSize = $this->_codeSize;
		$buf = $this->_bitBuffer;
		$cnt = $this->_bitCount;
		$dict = $this->_encDict;
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$c = $data[$i];
			if ($w === null) {
				$w = $c;
				continue;
			}
			$candidate = $w . $c;
			if (isset($dict[$candidate])) {
				$w = $candidate;
				continue;
			}
			$buf = ($buf << $codeSize) | $dict[$w];   // emit the code for the current prefix
			$cnt += $codeSize;
			$dict[$candidate] = $next++;
			$w = $c;
			if ($next === (1 << $codeSize)) {
				if ($codeSize < TLZWCompressor::MaxCodeSize) {
					$codeSize++;
				} else {
					$buf = ($buf << $codeSize) | TLZWCompressor::ClearCode;
					$cnt += $codeSize;
					$dict = [];
					for ($k = 0; $k < 256; $k++) {
						$dict[chr($k)] = $k;
					}
					$next = TLZWCompressor::FirstCode;
					$codeSize = TLZWCompressor::MinCodeSize;
				}
			}
			while ($cnt >= 8) {                       // drain whole bytes so the accumulator never overflows
				$cnt -= 8;
				$out .= chr(($buf >> $cnt) & 0xFF);
				$buf &= (1 << $cnt) - 1;
			}
		}
		$this->_w = $w;
		$this->_next = $next;
		$this->_codeSize = $codeSize;
		$this->_bitBuffer = $buf;
		$this->_bitCount = $cnt;
		$this->_encDict = $dict;
		return $out;
	}

	/**
	 * Emits the leading clear code and seeds the encoder dictionary, once.
	 * @return string The drained bytes of the clear-code prologue (empty after the first call).
	 */
	private function startEncode(): string
	{
		if ($this->_started) {
			return '';
		}
		$this->resetEncodeDictionary();
		$this->pushBits(TLZWCompressor::ClearCode, $this->_codeSize);
		$this->_started = true;
		return $this->drainBytes();
	}

	/**
	 * Resets the encoder dictionary, next code, and code width to their initial state.
	 */
	private function resetEncodeDictionary(): void
	{
		$this->_encDict = [];
		for ($c = 0; $c < 256; $c++) {
			$this->_encDict[chr($c)] = $c;
		}
		$this->_next = TLZWCompressor::FirstCode;
		$this->_codeSize = TLZWCompressor::MinCodeSize;
	}

	/**
	 * Decodes a chunk, reading variable-width codes as enough bits accumulate.
	 * @param string $data The encoded chunk.
	 * @throws TIOException When the data contains a code beyond the dictionary (corrupt input).
	 * @return string The decoded bytes produced from this chunk.
	 */
	private function decode(string $data): string
	{
		if (!$this->_started) {
			$this->resetDecodeDictionary();
			$this->_started = true;
		}
		if ($this->_finished) {
			return '';
		}
		// Pull state into locals and inline the bit unpacking and code expansion (the per-code
		// method call and property access dominate this hot loop).
		$out = '';
		$buf = $this->_bitBuffer;
		$cnt = $this->_bitCount;
		$codeSize = $this->_codeSize;
		$next = $this->_next;
		$prev = $this->_prev;
		$dict = $this->_decDict;
		$finished = false;
		$len = strlen($data);
		for ($i = 0; $i < $len; $i++) {
			$buf = ($buf << 8) | ord($data[$i]);
			$cnt += 8;
			while ($cnt >= $codeSize) {
				$cnt -= $codeSize;
				$code = ($buf >> $cnt) & ((1 << $codeSize) - 1);
				$buf &= (1 << $cnt) - 1;
				if ($code === TLZWCompressor::EndOfInformation) {
					$finished = true;
					break 2;
				}
				if ($code === TLZWCompressor::ClearCode) {
					$dict = [];
					for ($k = 0; $k < 256; $k++) {
						$dict[$k] = chr($k);
					}
					$next = TLZWCompressor::FirstCode;
					$codeSize = TLZWCompressor::MinCodeSize;
					$prev = null;
					continue;
				}
				if ($prev === null) {
					if (!isset($dict[$code])) {
						throw new TIOException('lzwcompressor_code_invalid', $code, $next);
					}
					$prev = $code;
					$out .= $dict[$code];
					continue;
				}
				if (isset($dict[$code])) {
					$entry = $dict[$code];
				} elseif ($code === $next) {
					// The one legal not-yet-defined code (the encoder's just-added entry).
					$entry = $dict[$prev] . $dict[$prev][0];
				} else {
					throw new TIOException('lzwcompressor_code_invalid', $code, $next);
				}
				$dict[$next++] = $dict[$prev] . $entry[0];
				$prev = $code;
				if ($next + 1 === (1 << $codeSize) && $codeSize < TLZWCompressor::MaxCodeSize) {
					$codeSize++;
				}
				$out .= $entry;
			}
		}
		$this->_bitBuffer = $buf;
		$this->_bitCount = $cnt;
		$this->_codeSize = $codeSize;
		$this->_next = $next;
		$this->_prev = $prev;
		$this->_decDict = $dict;
		if ($finished) {
			$this->_finished = true;
		}
		return $out;
	}

	/**
	 * Resets the decoder dictionary, next code, and code width to their initial state.
	 */
	private function resetDecodeDictionary(): void
	{
		$this->_decDict = [];
		for ($c = 0; $c < 256; $c++) {
			$this->_decDict[$c] = chr($c);
		}
		$this->_next = TLZWCompressor::FirstCode;
		$this->_codeSize = TLZWCompressor::MinCodeSize;
	}

	/**
	 * Appends bits to the accumulator, most-significant first.
	 * @param int $value The value to append.
	 * @param int $bits The number of bits.
	 */
	private function pushBits(int $value, int $bits): void
	{
		$this->_bitBuffer = ($this->_bitBuffer << $bits) | $value;
		$this->_bitCount += $bits;
	}

	/**
	 * Pops whole bytes from the accumulator, most-significant first.
	 * @return string The completed bytes.
	 */
	private function drainBytes(): string
	{
		$out = '';
		while ($this->_bitCount >= 8) {
			$out .= chr(($this->_bitBuffer >> ($this->_bitCount - 8)) & 0xFF);
			$this->_bitCount -= 8;
			$this->_bitBuffer &= (1 << $this->_bitCount) - 1;
		}
		return $out;
	}

	/**
	 * Emits the final partial byte, zero-padding the unused low bits.
	 * @return string The padded byte, or '' when nothing is pending.
	 */
	private function flushBits(): string
	{
		if ($this->_bitCount === 0) {
			return '';
		}
		$byte = ($this->_bitBuffer << (8 - $this->_bitCount)) & 0xFF;
		$this->_bitBuffer = 0;
		$this->_bitCount = 0;
		return chr($byte);
	}
}
