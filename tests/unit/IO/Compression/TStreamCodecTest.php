<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Compression\IStreamCodec;
use Prado\IO\Compression\THorizontalPredictor;
use Prado\IO\Compression\TLZWCompressor;
use Prado\IO\Compression\TPackBitsCompressor;

/**
 * Unit tests for the incremental {@see IStreamCodec} engines behind the LZW and PackBits
 * compressors: {@see TLZWCompressor::encoder()}/{@see TLZWCompressor::decoder()} and the
 * PackBits equivalents, modeled on PHP's {@see deflate_init()}/{@see inflate_init()}.  The
 * engines are the one implementation the whole-string {@see TLZWCompressor}/
 * {@see TPackBitsCompressor} and the streaming {@see \Prado\IO\Compression\TLZWFilter}/
 * {@see \Prado\IO\Compression\TPackBitsFilter} share; these tests drive them directly, so
 * a divergence surfaces here rather than only through a wrapper.
 */
class TStreamCodecTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Drives a codec $chunk bytes at a time, so its incremental state is exercised across
	 * chunk boundaries.
	 * @param IStreamCodec $codec
	 * @param string $data
	 * @param int $chunk
	 */
	private function drive(IStreamCodec $codec, string $data, int $chunk): string
	{
		$out = '';
		if ($data !== '') {
			foreach (str_split($data, $chunk) as $piece) {
				$out .= $codec->add($piece);
			}
		}
		return $out . $codec->finish();
	}

	public function testWholeStringIsAddThenFinish()
	{
		$raw = 'AAAAABCDEEEEEE' . str_repeat('Z', 50);
		$enc = TLZWCompressor::encoder();
		self::assertSame(TLZWCompressor::compress($raw), $enc->add($raw) . $enc->finish());

		$pb = TPackBitsCompressor::encoder();
		self::assertSame(TPackBitsCompressor::compress($raw), $pb->add($raw) . $pb->finish());
	}

	public function testPackBitsStreamingMatchesWholeStringAcrossChunkSizes()
	{
		$inputs = [
			'',
			'A',
			'AABCC',
			str_repeat('Q', 300),
			'AAAAABCDEEEEEE' . str_repeat('Z', 200) . PseudoRandomBytes::bytes(500, 'codec-1'),
		];
		foreach ($inputs as $raw) {
			$expected = TPackBitsCompressor::compress($raw);
			foreach ([1, 3, 7, 128] as $chunk) {
				$encoded = $this->drive(TPackBitsCompressor::encoder(), $raw, $chunk);
				self::assertSame($expected, $encoded, "PackBits encode chunk={$chunk}");
				$decoded = $this->drive(TPackBitsCompressor::decoder(), $encoded, $chunk);
				self::assertSame($raw, $decoded, "PackBits decode chunk={$chunk}");
			}
		}
	}

	public function testLZWStreamingMatchesWholeStringAcrossChunkSizes()
	{
		$inputs = [
			'',
			'A',
			'TOBEORNOTTOBEORTOBEORNOT',
			str_repeat('abcdefghij', 500),   // crosses 9->10->11->12 bit widths
			PseudoRandomBytes::bytes(8000, 'codec-2'),
		];
		foreach ($inputs as $raw) {
			$expected = TLZWCompressor::compress($raw);
			foreach ([1, 5, 13, 64] as $chunk) {
				$encoded = $this->drive(TLZWCompressor::encoder(), $raw, $chunk);
				self::assertSame($expected, $encoded, 'LZW encode chunk=' . $chunk . ' len=' . strlen($raw));
				$decoded = $this->drive(TLZWCompressor::decoder(), $encoded, $chunk);
				self::assertSame($raw, $decoded, 'LZW decode chunk=' . $chunk . ' len=' . strlen($raw));
			}
		}
	}

	public function testLZWStreamingMatchesAcrossDictionaryClears()
	{
		// 32 KB of spread values forces 9->10->11->12 growth AND the 4096-code clear/reset in
		// both directions; the incremental engine must stay byte-identical through the reset.
		$raw = '';
		for ($i = 0; $i < 8192; $i++) {
			$raw .= pack('N', $i * 2654435761 & 0xFFFFFFFF);
		}
		$expected = TLZWCompressor::compress($raw);
		self::assertSame($expected, $this->drive(TLZWCompressor::encoder(), $raw, 4096));
		self::assertSame($raw, $this->drive(TLZWCompressor::decoder(), $expected, 4096));
	}

	public function testLZWDecoderRejectsCorruptData()
	{
		// Clear (256), 'A' (65), then the out-of-range code 300, packed 9 bits MSB-first.
		$bits = str_pad(decbin(256), 9, '0', STR_PAD_LEFT)
			. str_pad(decbin(65), 9, '0', STR_PAD_LEFT)
			. str_pad(decbin(300), 9, '0', STR_PAD_LEFT);
		$bytes = '';
		foreach (str_split(str_pad($bits, 32, '0'), 8) as $octet) {
			$bytes .= chr(bindec($octet));
		}
		$this->expectException(TIOException::class);
		$this->drive(TLZWCompressor::decoder(), $bytes, 64);
	}

	public function testLZWDecoderRejectsAnUndefinedFirstCode()
	{
		// Clear (256) then 258 as the very first data code: no dictionary entry can exist yet.
		$bits = str_pad(decbin(256), 9, '0', STR_PAD_LEFT) . str_pad(decbin(258), 9, '0', STR_PAD_LEFT);
		$bytes = '';
		foreach (str_split(str_pad($bits, 24, '0'), 8) as $octet) {
			$bytes .= chr(bindec($octet));
		}
		$this->expectException(TIOException::class);
		$this->drive(TLZWCompressor::decoder(), $bytes, 64);
	}

	public function testLZWDecoderIgnoresBytesAfterEndOfInformation()
	{
		// The trailing padding is long enough to arrive in a later chunk, after the chunk
		// carrying end-of-information has already finished the decode.
		$raw = 'hello lzw';
		$trailing = TLZWCompressor::compress($raw) . str_repeat("\x00", 20000);
		self::assertSame($raw, $this->drive(TLZWCompressor::decoder(), $trailing, 8192));
	}

	public function testLZWEncodePadsNothingOnAByteBoundary()
	{
		// Six distinct bytes emit six codes; with the leading clear and the closing
		// end-of-information that is eight 9-bit codes, exactly nine whole bytes, so the
		// closing flush has no partial byte to pad.
		$encoded = $this->drive(TLZWCompressor::encoder(), 'ABCDEF', 64);
		self::assertSame(9, strlen($encoded));
		self::assertSame(TLZWCompressor::compress('ABCDEF'), $encoded);
		self::assertSame('ABCDEF', $this->drive(TLZWCompressor::decoder(), $encoded, 64));
	}

	public function testPackBitsDecoderRecoversATruncatedLiteralTail()
	{
		// A literal header wanting six bytes with only two present is an incomplete packet: the
		// two bytes are unambiguously literal, so they are recovered at finish() after the packet
		// before it decodes — no recoverable byte of a truncated stream is lost.
		self::assertSame('XYZAB', $this->drive(TPackBitsCompressor::decoder(), chr(2) . 'XYZ' . chr(5) . 'AB', 64));
		// A truncated repeat packet has no run byte to expand, so it yields nothing beyond the
		// complete packet before it.
		self::assertSame('XYZ', $this->drive(TPackBitsCompressor::decoder(), chr(2) . 'XYZ' . chr(254), 64));
	}

	public function testPackBitsDecoderSkipsNoOpPackets()
	{
		// 128 is the PackBits no-op header: it carries nothing and the packets around it decode.
		self::assertSame('ABC', $this->drive(TPackBitsCompressor::decoder(), chr(128) . chr(2) . 'ABC' . chr(128), 64));
	}

	public function testPackBitsRunStraddlingLiteralBoundary()
	{
		// 127 distinct bytes fill a literal to 128 with the first byte of a pair; the 128-byte
		// flush must not split the pair, so it still coalesces into a run (regression).
		$raw = '';
		for ($i = 0; $i < 127; $i++) {
			$raw .= chr($i);
		}
		$raw .= chr(200) . chr(200);
		$expected = TPackBitsCompressor::compress($raw);
		foreach ([1, 64, 128, 8192] as $chunk) {
			self::assertSame($expected, $this->drive(TPackBitsCompressor::encoder(), $raw, $chunk), "encode chunk={$chunk}");
			self::assertSame($raw, $this->drive(TPackBitsCompressor::decoder(), $expected, $chunk), "decode chunk={$chunk}");
		}
	}

	public function testHorizontalPredictorStreamingMatchesWholeStringAcrossChunkSizes()
	{
		// A 4-pixel RGB row is 12 bytes; the last row here is a deliberate partial (7 bytes),
		// which the engine must still transform at finish() rather than drop.
		$columns = 4;
		$samples = 3;
		$raw = PseudoRandomBytes::bytes($columns * $samples * 5 + 7, 'hp-codec');
		foreach ([1, 5, 12, 64] as $chunk) {
			$encoded = $this->drive(THorizontalPredictor::encoder($columns, $samples), $raw, $chunk);
			self::assertSame(THorizontalPredictor::encode($raw, $columns, $samples), $encoded, "HP encode chunk={$chunk}");
			$decoded = $this->drive(THorizontalPredictor::decoder($columns, $samples), $encoded, $chunk);
			self::assertSame($raw, $decoded, "HP decode chunk={$chunk}");
		}
	}

	public function testHorizontalPredictorEngineRejectsNonPositiveGeometry()
	{
		// The filter validates geometry before building an engine, so the engine's own guard
		// is only reachable when a caller constructs it directly.
		$this->expectException(TIOException::class);
		THorizontalPredictor::encoder(0, 1);
	}
}
