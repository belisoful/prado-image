<?php

use Prado\IO\Compression\TLZWCompressor;
use Prado\IO\Compression\TLZWFilter;
use Prado\IO\Compression\TPackBitsCompressor;
use Prado\IO\Compression\TPackBitsFilter;
use Prado\IO\TStream;

class TCompressionFilterTest extends PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
		TPackBitsFilter::registerOnce();
		TLZWFilter::registerOnce();
	}

	public function testBothNamesRegistered()
	{
		self::assertTrue(TPackBitsFilter::isRegistered(TPackBitsFilter::EncodeName));
		self::assertTrue(TPackBitsFilter::isRegistered(TPackBitsFilter::DecodeName));
		self::assertTrue(TLZWFilter::isRegistered(TLZWFilter::EncodeName));
		self::assertTrue(TLZWFilter::isRegistered(TLZWFilter::DecodeName));
	}

	public function testRegisterOnceRegistersASingleNamedDirection()
	{
		TPackBitsFilter::registerOnce(TPackBitsFilter::DecodeName);
		TLZWFilter::registerOnce(TLZWFilter::EncodeName);
		self::assertTrue(TPackBitsFilter::isRegistered(TPackBitsFilter::DecodeName));
		self::assertTrue(TLZWFilter::isRegistered(TLZWFilter::EncodeName));

		// The individually registered names filter in their own direction.
		self::assertSame('ABC', $this->runFilter(TPackBitsFilter::DecodeName, chr(2) . 'ABC', 64));
		self::assertSame(TLZWCompressor::compress('ABC'), $this->runFilter(TLZWFilter::EncodeName, 'ABC', 64));
	}

	public function testPackBitsEncodeFilterMatchesCodec()
	{
		$raw = 'AAAAABCDEEEEEE' . str_repeat('Z', 50);
		$s = TStream::fromString($raw);
		$s->appendFilter(TPackBitsFilter::EncodeName, STREAM_FILTER_READ);
		self::assertSame(TPackBitsCompressor::compress($raw), $s->getContents());
		$s->close();
	}

	public function testPackBitsDecodeFilterMatchesCodec()
	{
		$raw = 'AAAAABCDEEEEEE' . str_repeat('Z', 50);
		$encoded = TPackBitsCompressor::compress($raw);
		$s = TStream::fromString($encoded);
		$s->appendFilter(TPackBitsFilter::DecodeName, STREAM_FILTER_READ);
		self::assertSame($raw, $s->getContents());
		$s->close();
	}

	public function testLZWEncodeThenDecodeFilterRoundTrip()
	{
		$raw = str_repeat('the quick brown fox ', 200) . PseudoRandomBytes::bytes(2000, 'filter-1');

		$enc = TStream::fromString($raw);
		$enc->appendFilter(TLZWFilter::EncodeName, STREAM_FILTER_READ);
		$encoded = $enc->getContents();
		$enc->close();

		self::assertSame(TLZWCompressor::compress($raw), $encoded);

		$dec = TStream::fromString($encoded);
		$dec->appendFilter(TLZWFilter::DecodeName, STREAM_FILTER_READ);
		self::assertSame($raw, $dec->getContents());
		$dec->close();
	}

	/**
	 * Runs $data through a read-filter $chunk bytes at a time, forcing the filter to be
	 * invoked over many small buckets so the incremental (unbuffered) state is exercised.
	 * @param string $name
	 * @param string $data
	 * @param int $chunk
	 */
	private function runFilter(string $name, string $data, int $chunk): string
	{
		$h = fopen('php://temp', 'r+b');
		fwrite($h, $data);
		rewind($h);
		stream_filter_append($h, $name, STREAM_FILTER_READ);
		$out = '';
		while (!feof($h)) {
			$piece = fread($h, $chunk);
			if ($piece === false) {
				break;
			}
			$out .= $piece;
		}
		fclose($h);
		return $out;
	}

	public function testPackBitsStreamingMatchesCodecAcrossChunkSizes()
	{
		$inputs = [
			'',
			'A',
			'AABCC',
			str_repeat('Q', 300),
			'AAAAABCDEEEEEE' . str_repeat('Z', 200) . PseudoRandomBytes::bytes(500, 'filter-2'),
		];
		foreach ($inputs as $raw) {
			$expected = TPackBitsCompressor::compress($raw);
			foreach ([1, 3, 7, 128] as $chunk) {
				$encoded = $this->runFilter(TPackBitsFilter::EncodeName, $raw, $chunk);
				self::assertSame($expected, $encoded, "PackBits encode chunk={$chunk}");
				$decoded = $this->runFilter(TPackBitsFilter::DecodeName, $encoded, $chunk);
				self::assertSame($raw, $decoded, "PackBits decode chunk={$chunk}");
			}
		}
	}

	public function testLZWStreamingMatchesCodecAcrossChunkSizes()
	{
		$inputs = [
			'',
			'A',
			'TOBEORNOTTOBEORTOBEORNOT',
			str_repeat('abcdefghij', 500),   // crosses 9->10->11->12 bit widths
			PseudoRandomBytes::bytes(8000, 'filter-3'),
		];
		foreach ($inputs as $raw) {
			$expected = TLZWCompressor::compress($raw);
			foreach ([1, 5, 13, 64] as $chunk) {
				$encoded = $this->runFilter(TLZWFilter::EncodeName, $raw, $chunk);
				self::assertSame($expected, $encoded, 'LZW encode chunk=' . $chunk . ' len=' . strlen($raw));
				$decoded = $this->runFilter(TLZWFilter::DecodeName, $encoded, $chunk);
				self::assertSame($raw, $decoded, 'LZW decode chunk=' . $chunk . ' len=' . strlen($raw));
			}
		}
	}

	public function testLZWFilterMatchesCodecAcrossDictionaryClears()
	{
		// 32 KB of spread values forces 9->10->11->12 growth AND the 4096-code clear/reset in
		// both directions; the filter must stay byte-identical to the codec through the reset.
		$raw = '';
		for ($i = 0; $i < 8192; $i++) {
			$raw .= pack('N', $i * 2654435761 & 0xFFFFFFFF);
		}
		$expected = TLZWCompressor::compress($raw);
		$encoded = $this->runFilter(TLZWFilter::EncodeName, $raw, 4096);
		self::assertSame($expected, $encoded, 'The filter tracks the codec through dictionary clears.');
		self::assertSame($raw, $this->runFilter(TLZWFilter::DecodeName, $encoded, 4096));
	}

	public function testLZWDecodeFilterRejectsCorruptData()
	{
		// Clear (256), 'A' (65), then the out-of-range code 300, packed 9 bits MSB-first.
		$bits = str_pad(decbin(256), 9, '0', STR_PAD_LEFT)
			. str_pad(decbin(65), 9, '0', STR_PAD_LEFT)
			. str_pad(decbin(300), 9, '0', STR_PAD_LEFT);
		$bytes = '';
		foreach (str_split(str_pad($bits, 32, '0'), 8) as $octet) {
			$bytes .= chr(bindec($octet));
		}
		$this->expectException(\Prado\Exceptions\TIOException::class);   // the corrupt code surfaces, not silent garbage
		$this->runFilter(TLZWFilter::DecodeName, $bytes, 64);
	}

	public function testLZWWriteModeFilterFlushesOnClose()
	{
		$raw = str_repeat('write mode ', 300);
		$path = tempnam(sys_get_temp_dir(), 'pradolzw');
		$h = fopen($path, 'wb');
		stream_filter_append($h, TLZWFilter::EncodeName, STREAM_FILTER_WRITE);
		foreach (str_split($raw, 100) as $chunk) {
			fwrite($h, $chunk);
		}
		fclose($h);   // the closing flush emits the final code, end-of-information, and padding

		$encoded = (string) file_get_contents($path);
		@unlink($path);
		self::assertSame(TLZWCompressor::compress($raw), $encoded, 'Write mode flushes the tail on close.');
	}

	public function testPackBitsTruncatedStreamsDecodeTolerantly()
	{
		// A literal header wanting 6 bytes with only 2 present is an incomplete packet: the two
		// bytes are unambiguously literal, so the whole-string codec recovers them exactly as
		// the streaming filter does, because both now drive the one engine.
		self::assertSame('AB', TPackBitsCompressor::decompress(chr(5) . 'AB'));
		// A repeat header with no run byte has nothing to expand, so it decodes to nothing.
		self::assertSame('', TPackBitsCompressor::decompress(chr(254)));
		// The filter recovers the complete literal and drops the trailing truncated repeat,
		// matching the codec.
		self::assertSame('AB', $this->runFilter(TPackBitsFilter::DecodeName, chr(1) . 'AB' . chr(254), 64));
	}

	public function testPackBitsRunStraddlingLiteralBoundaryMatchesCodec()
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
			$encoded = $this->runFilter(TPackBitsFilter::EncodeName, $raw, $chunk);
			self::assertSame($expected, $encoded, "PackBits encode chunk={$chunk}");
			self::assertSame($raw, $this->runFilter(TPackBitsFilter::DecodeName, $encoded, $chunk), "PackBits decode chunk={$chunk}");
		}
	}

	public function testPackBitsDecodeFilterSkipsNoOpPackets()
	{
		// 128 is the PackBits no-op header: it carries nothing and the packets around it decode.
		self::assertSame('ABC', $this->runFilter(TPackBitsFilter::DecodeName, chr(128) . chr(2) . 'ABC' . chr(128), 64));
	}

	public function testPackBitsDecodeFilterRecoversATruncatedLiteral()
	{
		// A literal header wanting six bytes with only two present is an incomplete packet: the
		// two bytes are unambiguously literal, so the filter recovers them at close after the
		// packet before it decodes — matching the whole-string codec byte for byte.
		self::assertSame('XYZAB', $this->runFilter(TPackBitsFilter::DecodeName, chr(2) . 'XYZ' . chr(5) . 'AB', 64));
	}

	public function testLZWDecodeFilterIgnoresBytesAfterEndOfInformation()
	{
		// The trailing padding is long enough to arrive in a later bucket, after the bucket
		// carrying end-of-information has already finished the decode.
		$raw = 'hello lzw';
		$trailing = TLZWCompressor::compress($raw) . str_repeat("\x00", 20000);
		self::assertSame($raw, $this->runFilter(TLZWFilter::DecodeName, $trailing, 8192));
	}

	public function testLZWDecodeFilterRejectsAnUndefinedFirstCode()
	{
		// Clear (256) then 258 as the very first data code: no dictionary entry can exist yet.
		$bits = str_pad(decbin(256), 9, '0', STR_PAD_LEFT) . str_pad(decbin(258), 9, '0', STR_PAD_LEFT);
		$bytes = '';
		foreach (str_split(str_pad($bits, 24, '0'), 8) as $octet) {
			$bytes .= chr(bindec($octet));
		}
		try {
			$this->runFilter(TLZWFilter::DecodeName, $bytes, 64);
			self::fail('A first code beyond the dictionary is rejected.');
		} catch (\Prado\Exceptions\TIOException $e) {
			self::assertSame('lzwcompressor_code_invalid', $e->getErrorCode());
		}
	}

	public function testLZWEncodeFilterPadsNothingOnAByteBoundary()
	{
		// Six distinct bytes emit six codes; with the leading clear and the closing
		// end-of-information that is eight 9-bit codes, exactly nine whole bytes, so the
		// closing flush has no partial byte to pad.
		$encoded = $this->runFilter(TLZWFilter::EncodeName, 'ABCDEF', 64);
		self::assertSame(9, strlen($encoded));
		self::assertSame(TLZWCompressor::compress('ABCDEF'), $encoded);
		self::assertSame('ABCDEF', $this->runFilter(TLZWFilter::DecodeName, $encoded, 64));
	}
}
