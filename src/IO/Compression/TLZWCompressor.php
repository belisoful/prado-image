<?php

/**
 * TLZWCompressor class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

use Prado\Exceptions\TIOException;
use Prado\IO\TStream;
use Prado\IO\Util\TBitReader;
use Prado\IO\Util\TBitWriter;

/**
 * TLZWCompressor class.
 *
 * Implements variable-width LZW compression of the kind used by GIF and TIFF.  Codes
 * start at 9 bits and grow to a 12-bit ceiling; code 256 is the clear code (resets the
 * dictionary) and code 257 is end-of-information.  The stream begins with a clear code
 * and ends with end-of-information.
 *
 * Codes are written most-significant-bit first through {@see TBitWriter}, so the encoder
 * and decoder agree as a self-consistent codec.  Neither image wire format is decoded
 * as-is: a GIF bitstream packs codes least-significant-bit first, and standard TIFF LZW
 * widens each code size one code early (the TIFF 6 "EarlyChange" off-by-one); each needs
 * its own variant path.  The filter form is {@see TLZWFilter}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see United States patent #4,558,302 (1983) by Unisys Corporation (expired 2003-2004).
 */
class TLZWCompressor implements ICompressor
{
	/** @var int The initial code width in bits. */
	public const MinCodeSize = 9;

	/** @var int The maximum code width in bits. */
	public const MaxCodeSize = 12;

	/** @var int The clear code, which resets the dictionary. */
	public const ClearCode = 256;

	/** @var int The end-of-information code. */
	public const EndOfInformation = 257;

	/** @var int The first dynamic dictionary code. */
	public const FirstCode = 258;

	/**
	 * Compresses a byte string with variable-width LZW.
	 * @param string $data The raw bytes.
	 * @return string The LZW-encoded bytes.
	 */
	public static function compress(string $data): string
	{
		$writer = new TBitWriter(TStream::fromMemory());
		$writer->writeBits(self::ClearCode, self::MinCodeSize);

		$len = strlen($data);
		if ($len === 0) {
			$writer->writeBits(self::EndOfInformation, self::MinCodeSize);
			$writer->flush();
			return self::drain($writer);
		}

		$dictionary = [];
		for ($c = 0; $c < 256; $c++) {
			$dictionary[chr($c)] = $c;
		}
		$next = self::FirstCode;
		$codeSize = self::MinCodeSize;

		$buffer = $data[0];
		for ($i = 1; $i < $len; $i++) {
			$symbol = $data[$i];
			$candidate = $buffer . $symbol;
			if (isset($dictionary[$candidate])) {
				$buffer = $candidate;
				continue;
			}
			$writer->writeBits($dictionary[$buffer], $codeSize);
			$dictionary[$candidate] = $next++;
			$buffer = $symbol;
			if ($next === (1 << $codeSize)) {
				if ($codeSize < self::MaxCodeSize) {
					$codeSize++;
				} else {
					$writer->writeBits(self::ClearCode, $codeSize);
					$dictionary = [];
					for ($c = 0; $c < 256; $c++) {
						$dictionary[chr($c)] = $c;
					}
					$next = self::FirstCode;
					$codeSize = self::MinCodeSize;
				}
			}
		}
		$writer->writeBits($dictionary[$buffer], $codeSize);
		$writer->writeBits(self::EndOfInformation, $codeSize);
		$writer->flush();
		return self::drain($writer);
	}

	/**
	 * Decompresses an LZW byte string produced by {@see compress()}.
	 * @param string $data The LZW-encoded bytes.
	 * @throws TIOException When the data contains a code beyond the dictionary (corrupt input).
	 * @return string The decoded bytes.
	 */
	public static function decompress(string $data): string
	{
		$reader = new TBitReader(TStream::fromString($data));
		$codeSize = self::MinCodeSize;
		$dictionary = self::baseDictionary();
		$next = self::FirstCode;
		$previous = null;
		$out = '';

		while (($code = $reader->readBits($codeSize)) !== false) {
			if ($code === self::EndOfInformation) {
				break;
			}
			if ($code === self::ClearCode) {
				$dictionary = self::baseDictionary();
				$next = self::FirstCode;
				$codeSize = self::MinCodeSize;
				$previous = null;
				continue;
			}
			if ($previous === null) {
				if (!isset($dictionary[$code])) {
					throw new TIOException('lzwcompressor_code_invalid', $code, $next);
				}
				$out .= $dictionary[$code];
				$previous = $code;
				continue;
			}
			if (isset($dictionary[$code])) {
				$entry = $dictionary[$code];
			} elseif ($code === $next) {
				// The one legal not-yet-defined code (the encoder's just-added entry).
				$entry = $dictionary[$previous] . $dictionary[$previous][0];
			} else {
				throw new TIOException('lzwcompressor_code_invalid', $code, $next);
			}
			$out .= $entry;
			$dictionary[$next++] = $dictionary[$previous] . $entry[0];
			$previous = $code;
			if ($next + 1 === (1 << $codeSize) && $codeSize < self::MaxCodeSize) {
				$codeSize++;
			}
		}
		return $out;
	}

	/**
	 * Builds the initial 256-entry single-byte dictionary.
	 * @return array<int, string> The code-to-string dictionary.
	 */
	private static function baseDictionary(): array
	{
		$dictionary = [];
		for ($c = 0; $c < 256; $c++) {
			$dictionary[$c] = chr($c);
		}
		return $dictionary;
	}

	/**
	 * Reads back the bytes accumulated in the writer's stream.
	 * @param TBitWriter $writer The writer whose stream holds the encoded bytes.
	 * @return string The encoded bytes.
	 */
	private static function drain(TBitWriter $writer): string
	{
		$stream = $writer->getStream();
		$stream->seek(0);
		return $stream->getContents();
	}
}
