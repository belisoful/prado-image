<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Compression\THorizontalPredictor;
use Prado\IO\Compression\THorizontalPredictorFilter;
use Prado\IO\Compression\TLZWCompressor;

class THorizontalPredictorTest extends PHPUnit\Framework\TestCase
{
	protected function setUp(): void
	{
		THorizontalPredictorFilter::registerOnce();
	}

	public function testKnownSingleSampleVector()
	{
		// 'ABCD' differences to A, B-A, C-B, D-C.
		self::assertSame("\x41\x01\x01\x01", THorizontalPredictor::encode('ABCD', 4));
		self::assertSame('ABCD', THorizontalPredictor::decode("\x41\x01\x01\x01", 4));
	}

	public function testThreeSampleChannelsDifferencePerChannel()
	{
		// Two RGB pixels: (10,20,30) then (13,19,30) -> second pixel differences per channel.
		$raw = chr(10) . chr(20) . chr(30) . chr(13) . chr(19) . chr(30);
		$expected = chr(10) . chr(20) . chr(30) . chr(3) . chr(255) . chr(0);
		self::assertSame($expected, THorizontalPredictor::encode($raw, 2, 3));
		self::assertSame($raw, THorizontalPredictor::decode($expected, 2, 3));
	}

	public function testEachRowRestartsThePrediction()
	{
		// Two rows of two bytes: the third byte is a row start, so it stays verbatim.
		$raw = chr(100) . chr(101) . chr(200) . chr(201);
		$encoded = THorizontalPredictor::encode($raw, 2, 1);
		self::assertSame(chr(100) . chr(1) . chr(200) . chr(1), $encoded);
		self::assertSame($raw, THorizontalPredictor::decode($encoded, 2, 1));
	}

	public function testWrapAroundStaysModulo256()
	{
		$raw = chr(5) . chr(250);   // 250 - 5 = 245; 5 - 250 wraps
		$encoded = THorizontalPredictor::encode($raw . chr(5), 3, 1);
		self::assertSame(chr(5) . chr(245) . chr(11), $encoded);
		self::assertSame($raw . chr(5), THorizontalPredictor::decode($encoded, 3, 1));
	}

	public function testPartialTrailingRowTransforms()
	{
		$raw = 'ABCDEF' . 'GH';   // a 6-byte row then a 2-byte partial row
		$encoded = THorizontalPredictor::encode($raw, 6, 1);
		self::assertSame($raw, THorizontalPredictor::decode($encoded, 6, 1));
		self::assertSame('G', $encoded[6], 'The partial row restarts verbatim.');
	}

	public function testRandomRoundTrips()
	{
		foreach ([[64, 1], [64, 3], [17, 4]] as [$columns, $samples]) {
			$raw = PseudoRandomBytes::bytes($columns * $samples * 20 + 5, 'predictor-1');   // 20 rows and a partial
			$encoded = THorizontalPredictor::encode($raw, $columns, $samples);
			self::assertSame($raw, THorizontalPredictor::decode($encoded, $columns, $samples), "columns={$columns} samples={$samples}");
		}
	}

	public function testGeometryValidation()
	{
		$this->expectException(TIOException::class);
		THorizontalPredictor::encode('data', 0);
	}

	public function testPredictionImprovesLzwCompression()
	{
		// A smooth gradient with a per-row shift: raw rows never repeat, while the differenced
		// bytes collapse to near-constant runs, so the predictor must win under LZW.
		$image = '';
		for ($y = 0; $y < 32; $y++) {
			for ($x = 0; $x < 256; $x++) {
				$image .= chr(($x + $y * 3) & 0xFF);
			}
		}
		$rawPacked = TLZWCompressor::compress($image);
		$predictedPacked = TLZWCompressor::compress(THorizontalPredictor::encode($image, 256));
		self::assertLessThan(strlen($rawPacked), strlen($predictedPacked), 'Differencing shrinks the LZW output on a gradient.');
	}

	// ---- The streaming filter ---------------------------------------------------

	/**
	 * Runs $data through a read-filter $chunk bytes at a time with the given params.
	 * @param string $name
	 * @param string $data
	 * @param int $chunk
	 * @param array $params
	 */
	private function runFilter(string $name, string $data, int $chunk, array $params): string
	{
		$h = fopen('php://temp', 'r+b');
		fwrite($h, $data);
		rewind($h);
		stream_filter_append($h, $name, STREAM_FILTER_READ, $params);
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

	public function testFilterMatchesCodecAcrossChunkSizes()
	{
		$columns = 31;
		$samples = 3;
		$raw = PseudoRandomBytes::bytes($columns * $samples * 15 + 7, 'predictor-2');   // 15 rows and a partial tail
		$expected = THorizontalPredictor::encode($raw, $columns, $samples);
		foreach ([1, 7, 64, 8192] as $chunk) {
			$encoded = $this->runFilter(THorizontalPredictorFilter::EncodeName, $raw, $chunk, ['columns' => $columns, 'samples' => $samples]);
			self::assertSame($expected, $encoded, "encode chunk={$chunk}");
			$decoded = $this->runFilter(THorizontalPredictorFilter::DecodeName, $encoded, $chunk, ['columns' => $columns, 'samples' => $samples]);
			self::assertSame($raw, $decoded, "decode chunk={$chunk}");
		}
	}

	public function testFilterSamplesDefaultsToOne()
	{
		$raw = PseudoRandomBytes::bytes(64 * 10, 'predictor-3');
		$expected = THorizontalPredictor::encode($raw, 64, 1);
		self::assertSame($expected, $this->runFilter(THorizontalPredictorFilter::EncodeName, $raw, 128, ['columns' => 64]));
	}

	public function testFilterRejectsMissingColumns()
	{
		$h = fopen('php://temp', 'r+b');
		$filter = @stream_filter_append($h, THorizontalPredictorFilter::EncodeName, STREAM_FILTER_READ, []);
		self::assertFalse($filter, 'A filter without columns fails to attach.');
		fclose($h);
	}

	public function testFilterRejectsNonArrayParameters()
	{
		// The geometry arrives as an array of named values; a bare number is not read as a
		// column count, so the filter has no geometry and refuses to attach.
		$h = fopen('php://temp', 'r+b');
		$filter = @stream_filter_append($h, THorizontalPredictorFilter::EncodeName, STREAM_FILTER_READ, 64);
		self::assertFalse($filter, 'A scalar parameter is not a row geometry.');
		fclose($h);
	}

	public function testBothNamesRegistered()
	{
		self::assertTrue(THorizontalPredictorFilter::isRegistered(THorizontalPredictorFilter::EncodeName));
		self::assertTrue(THorizontalPredictorFilter::isRegistered(THorizontalPredictorFilter::DecodeName));
	}

	public function testRegisterOnceRegistersASingleNamedDirection()
	{
		THorizontalPredictorFilter::registerOnce(THorizontalPredictorFilter::DecodeName);
		self::assertTrue(THorizontalPredictorFilter::isRegistered(THorizontalPredictorFilter::DecodeName));

		$raw = PseudoRandomBytes::bytes(16 * 3 * 4, 'predictor-4');
		$encoded = THorizontalPredictor::encode($raw, 16, 3);
		self::assertSame($raw, $this->runFilter(THorizontalPredictorFilter::DecodeName, $encoded, 64, ['columns' => 16, 'samples' => 3]), 'The individually registered name filters.');
	}

	public function testFilterCarriesInputShorterThanARowToTheClose()
	{
		// Less than one row arrives, so no chunk completes a row and everything transforms in
		// the closing flush; the result still matches the codec's partial-row handling.
		$raw = PseudoRandomBytes::bytes(10, 'predictor-5');
		$expected = THorizontalPredictor::encode($raw, 100, 1);
		self::assertSame($expected, $this->runFilter(THorizontalPredictorFilter::EncodeName, $raw, 8192, ['columns' => 100]));
		self::assertSame($raw, $this->runFilter(THorizontalPredictorFilter::DecodeName, $expected, 8192, ['columns' => 100]));
	}
}
