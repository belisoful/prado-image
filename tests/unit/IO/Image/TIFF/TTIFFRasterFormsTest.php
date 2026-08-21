<?php

use Prado\IO\Compression\TLZWCompressor;
use Prado\IO\Compression\TPackBitsCompressor;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TIFF\TTIFFRaster;
use Prado\IO\Image\TTIFF;

/**
 * Exposes the protected block blitter so its clipping guards can be exercised directly:
 * the decoder itself never places a block outside the canvas.
 */
class TTIFFRasterBlitProbe extends TTIFFRaster
{
	/**
	 * Blits a block into a plane buffer.
	 * @param string &$plane The plane buffer.
	 * @param string $block The block's sample bytes.
	 * @param array $position The block position and geometry.
	 * @param int $width The image width.
	 * @param int $height The image height.
	 * @param int $perPlane The samples per pixel in this plane.
	 */
	public static function blitBlock(string &$plane, string $block, array $position, int $width, int $height, int $perPlane): void
	{
		static::blit($plane, $block, $position, $width, $height, $perPlane);
	}
}

/**
 * The raster forms beyond the simple stripped 8-bit case: tiles, planar separation,
 * sub-byte and 16-bit depths, reversed fill order, and the palette/CMYK/YCbCr/Lab
 * photometrics.
 */
class TTIFFRasterFormsTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Builds a TIFF from explicit IFD tags and raster blocks.
	 * @param array $tags The tag id => [type, values] map.
	 * @param int $offsetsTag The offsets tag (273 strips or 324 tiles).
	 * @param array $blocks The block payloads.
	 * @param int $countsTag
	 */
	private function buildTiff(array $tags, int $offsetsTag, array $blocks, int $countsTag): string
	{
		$exif = new TEXIF();
		$exif->setSignature('');
		$ifd = $exif->getIfd0();
		foreach ($tags as $id => [$type, $values]) {
			$ifd->setTagValues($id, $type, $values);
		}
		$offsets = $ifd->setTagValues($offsetsTag, TTIFFDataType::ULong, array_fill(0, count($blocks), 0));
		$ifd->setTagValues($countsTag, TTIFFDataType::ULong, array_map('strlen', $blocks));
		$offsets->setExternalData($blocks);
		return $exif->toBinary();
	}

	/**
	 * Builds a TIFF whose offsets and byte-counts tags disagree, so the parser captures
	 * no raster blocks at all.
	 * @param array $tags The tag id => [type, values] map.
	 * @param int $offsetsTag The offsets tag (273 strips or 324 tiles).
	 * @param int $countsTag The matching byte-counts tag.
	 * @return string The TIFF bytes.
	 */
	private function buildBlocklessTiff(array $tags, int $offsetsTag, int $countsTag): string
	{
		$exif = new TEXIF();
		$exif->setSignature('');
		$ifd = $exif->getIfd0();
		foreach ($tags as $id => [$type, $values]) {
			$ifd->setTagValues($id, $type, $values);
		}
		$ifd->setTagValues($offsetsTag, TTIFFDataType::ULong, [8, 8]);   // two offsets ...
		$ifd->setTagValues($countsTag, TTIFFDataType::ULong, [4]);       // ... but one byte count
		return $exif->toBinary();
	}

	private function baseTags(int $width, int $height, int $photometric, array $bits, int $samples): array
	{
		return [
			256 => [TTIFFDataType::ULong, [$width]],
			257 => [TTIFFDataType::ULong, [$height]],
			258 => [TTIFFDataType::UShort, $bits],
			259 => [TTIFFDataType::UShort, [1]],
			262 => [TTIFFDataType::UShort, [$photometric]],
			277 => [TTIFFDataType::UShort, [$samples]],
			278 => [TTIFFDataType::ULong, [$height]],
		];
	}

	public function testTiledRgbRaster()
	{
		// 4x4 image in four 2x2 tiles, each a solid color.
		$colors = ["\xFF\x00\x00", "\x00\xFF\x00", "\x00\x00\xFF", "\xFF\xFF\x00"];
		$tiles = array_map(fn ($c) => str_repeat($c, 4), $colors);
		$tags = $this->baseTags(4, 4, TTIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[322] = [TTIFFDataType::ULong, [2]];
		$tags[323] = [TTIFFDataType::ULong, [2]];

		$tiff = TTIFF::fromString($this->buildTiff($tags, 324, $tiles, 325));
		$image = $tiff->getImage();
		self::assertNotFalse($image);
		$rgb = TImageGraphics::rgbPixels($image);

		// Tile 0 is top-left, tile 1 top-right, tile 2 bottom-left, tile 3 bottom-right.
		self::assertSame("\xFF\x00\x00", substr($rgb, 0, 3));            // (0,0)
		self::assertSame("\x00\xFF\x00", substr($rgb, 2 * 3, 3));        // (2,0)
		self::assertSame("\x00\x00\xFF", substr($rgb, (2 * 4) * 3, 3));  // (0,2)
		self::assertSame("\xFF\xFF\x00", substr($rgb, (2 * 4 + 2) * 3, 3));
	}

	public function testTilesPaddedAtTheEdges()
	{
		// 3x3 image in 2x2 tiles: the right and bottom tiles carry padding.
		$tile = fn (string $color) => str_repeat($color, 4);
		$tiles = [$tile("\x10\x20\x30"), $tile("\x40\x50\x60"), $tile("\x70\x80\x90"), $tile("\xA0\xB0\xC0")];
		$tags = $this->baseTags(3, 3, TTIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[322] = [TTIFFDataType::ULong, [2]];
		$tags[323] = [TTIFFDataType::ULong, [2]];

		$tiff = TTIFF::fromString($this->buildTiff($tags, 324, $tiles, 325));
		$rgb = TImageGraphics::rgbPixels($tiff->getImage());
		self::assertSame(3 * 3 * 3, strlen($rgb));
		self::assertSame("\x10\x20\x30", substr($rgb, 0, 3));                // (0,0) first tile
		self::assertSame("\x40\x50\x60", substr($rgb, 2 * 3, 3));            // (2,0) second tile
		self::assertSame("\xA0\xB0\xC0", substr($rgb, (2 * 3 + 2) * 3, 3));  // (2,2) fourth tile
	}

	public function testPlanarConfigurationSeparate()
	{
		// Three separate planes, one per channel, two strips' worth of a 2x2 image.
		$width = 2;
		$height = 2;
		$red = "\xFF\xFF\x00\x00";
		$green = "\x00\xFF\x00\xFF";
		$blue = "\x00\x00\xFF\xFF";
		$tags = $this->baseTags($width, $height, TTIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[284] = [TTIFFDataType::UShort, [2]];

		$tiff = TTIFF::fromString($this->buildTiff($tags, 273, [$red, $green, $blue], 279));
		$rgb = TImageGraphics::rgbPixels($tiff->getImage());
		self::assertSame("\xFF\x00\x00", substr($rgb, 0, 3));
		self::assertSame("\xFF\xFF\x00", substr($rgb, 3, 3));
		self::assertSame("\x00\x00\xFF", substr($rgb, 6, 3));
		self::assertSame("\x00\xFF\xFF", substr($rgb, 9, 3));
	}

	public function testFourBitGrayscaleAndFillOrderTwo()
	{
		// 4-bit gray: two pixels per byte, values scaled to the 0-15 range.
		$tags = $this->baseTags(4, 1, TTIFFRaster::BlackIsZero, [4], 1);
		$tiff = TTIFF::fromString($this->buildTiff($tags, 273, ["\x0F\x80"], 279));
		$rgb = TImageGraphics::rgbPixels($tiff->getImage());
		self::assertSame("\x00\x00\x00", substr($rgb, 0, 3));    // 0 -> black
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 3, 3));    // 15 -> white
		self::assertSame("\x88\x88\x88", substr($rgb, 6, 3));    // 8 -> mid grey
		self::assertSame("\x00\x00\x00", substr($rgb, 9, 3));    // 0

		// The same rows with every byte bit-mirrored and FillOrder 2 decode alike.
		$mirror = static function (string $data): string {
			$out = '';
			foreach (str_split($data) as $byte) {
				$out .= chr(bindec(strrev(str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT))));
			}
			return $out;
		};
		$tags[266] = [TTIFFDataType::UShort, [2]];
		$reversed = TTIFF::fromString($this->buildTiff($tags, 273, [$mirror("\x0F\x80")], 279));
		self::assertSame(bin2hex($rgb), bin2hex(TImageGraphics::rgbPixels($reversed->getImage())));
	}

	public function testOneAndTwoBitDepths()
	{
		// 1-bit black-is-zero: 0b10100000 -> white, black, white, black.
		$tags = $this->baseTags(4, 1, TTIFFRaster::BlackIsZero, [1], 1);
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, ["\xA0"], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF\x00\x00\x00\xFF\xFF\xFF\x00\x00\x00", $rgb);

		// 2-bit: values 0,1,2,3 scale across the range.
		$tags = $this->baseTags(4, 1, TTIFFRaster::BlackIsZero, [2], 1);
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, ["\x1B"], 279))->getImage());
		self::assertSame("\x00", $rgb[0]);
		self::assertSame("\x55", $rgb[3]);
		self::assertSame("\xAA", $rgb[6]);
		self::assertSame("\xFF", $rgb[9]);
	}

	public function testSixteenBitSamples()
	{
		// 16-bit gray reduces to its high byte.
		$tags = $this->baseTags(2, 1, TTIFFRaster::BlackIsZero, [16], 1);
		$data = pack('n', 0xFFFF) . pack('n', 0x8000);
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, [$data], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\x80\x80\x80", substr($rgb, 3, 3));
	}

	public function testPaletteColor()
	{
		// A 4-entry palette; ColorMap values are 16-bit, reds then greens then blues.
		$map = [0xFFFF, 0x0000, 0x0000, 0x8000,   // red
			0x0000, 0xFFFF, 0x0000, 0x8000,       // green
			0x0000, 0x0000, 0xFFFF, 0x8000];      // blue
		$tags = $this->baseTags(4, 1, TTIFFRaster::Palette, [8], 1);
		$tags[320] = [TTIFFDataType::UShort, $map];
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, ["\x00\x01\x02\x03"], 279))->getImage());
		self::assertSame("\xFF\x00\x00", substr($rgb, 0, 3));
		self::assertSame("\x00\xFF\x00", substr($rgb, 3, 3));
		self::assertSame("\x00\x00\xFF", substr($rgb, 6, 3));
		self::assertSame("\x80\x80\x80", substr($rgb, 9, 3));
	}

	public function testSeparatedCmyk()
	{
		$tags = $this->baseTags(3, 1, TTIFFRaster::Separated, [8, 8, 8, 8], 4);
		$pixels = "\x00\x00\x00\x00"      // no ink -> white
			. "\xFF\x00\x00\x00"          // cyan
			. "\x00\x00\x00\xFF";         // black
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, [$pixels], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\x00\xFF\xFF", substr($rgb, 3, 3));
		self::assertSame("\x00\x00\x00", substr($rgb, 6, 3));
	}

	public function testYCbCrUnsubsampled()
	{
		$tags = $this->baseTags(2, 1, TTIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TTIFFDataType::UShort, [1, 1]];
		// Y=255 with neutral chroma is white; Y=0 neutral is black.
		$pixels = "\xFF\x80\x80" . "\x00\x80\x80";
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, [$pixels], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\x00\x00\x00", substr($rgb, 3, 3));
	}

	public function testYCbCrSubsampled()
	{
		// 2x2 subsampling: four luma samples then one shared Cb/Cr pair per unit.
		$tags = $this->baseTags(2, 2, TTIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TTIFFDataType::UShort, [2, 2]];
		$unit = "\xFF\xFF\x00\x00" . "\x80\x80";   // two white, two black, neutral chroma
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());
		self::assertSame(2 * 2 * 3, strlen($rgb));
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));       // (0,0)
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 3, 3));       // (1,0)
		self::assertSame("\x00\x00\x00", substr($rgb, 6, 3));       // (0,1)
		self::assertSame("\x00\x00\x00", substr($rgb, 9, 3));       // (1,1)
	}

	public function testCieLab()
	{
		// L*=100 with neutral a*/b* is white; L*=0 is black.
		$tags = $this->baseTags(2, 1, TTIFFRaster::CieLab, [8, 8, 8], 3);
		$pixels = "\xFF\x00\x00" . "\x00\x00\x00";
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, [$pixels], 279))->getImage());
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\x00\x00\x00", substr($rgb, 3, 3));
	}

	public function testCompressedTilesWithPredictor()
	{
		// LZW + horizontal predictor inside tiles.
		$tile = function (string $solid): string {
			$rows = str_repeat($solid, 4);
			return TLZWCompressor::compress(\Prado\IO\Compression\THorizontalPredictor::encode($rows, 2, 3));
		};
		$tiles = [$tile("\x10\x20\x30"), $tile("\x40\x50\x60"), $tile("\x70\x80\x90"), $tile("\xA0\xB0\xC0")];
		$tags = $this->baseTags(4, 4, TTIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[259] = [TTIFFDataType::UShort, [5]];
		$tags[317] = [TTIFFDataType::UShort, [2]];
		$tags[322] = [TTIFFDataType::ULong, [2]];
		$tags[323] = [TTIFFDataType::ULong, [2]];

		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 324, $tiles, 325))->getImage());
		self::assertSame("\x10\x20\x30", substr($rgb, 0, 3));
		self::assertSame("\x40\x50\x60", substr($rgb, 2 * 3, 3));
		self::assertSame("\xA0\xB0\xC0", substr($rgb, (2 * 4 + 2) * 3, 3));
	}

	public function testPackBitsStripsAndMultipleStrips()
	{
		$rows = [str_repeat("\x11\x22\x33", 4), str_repeat("\x44\x55\x66", 4)];
		$tags = $this->baseTags(4, 2, TTIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[259] = [TTIFFDataType::UShort, [32773]];
		$tags[278] = [TTIFFDataType::ULong, [1]];   // one row per strip
		$strips = array_map(fn ($r) => TPackBitsCompressor::compress($r), $rows);

		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, $strips, 279))->getImage());
		self::assertSame("\x11\x22\x33", substr($rgb, 0, 3));
		self::assertSame("\x44\x55\x66", substr($rgb, 4 * 3, 3));
	}

	public function testSubsampledYCbCrCompressedStrips()
	{
		// Two white and two black luma samples with neutral chroma, LZW- and PackBits-coded.
		$unit = "\xFF\xFF\x00\x00" . "\x80\x80";
		$strips = [
			TTIFF::CompressionLzw => TLZWCompressor::compress($unit),
			TTIFF::CompressionPackBits => TPackBitsCompressor::compress($unit),
		];
		foreach ($strips as $compression => $strip) {
			$tags = $this->baseTags(2, 2, TTIFFRaster::YCbCr, [8, 8, 8], 3);
			$tags[259] = [TTIFFDataType::UShort, [$compression]];
			$tags[530] = [TTIFFDataType::UShort, [2, 2]];
			$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, [$strip], 279))->getImage());
			self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3), "compression $compression");
			self::assertSame("\x00\x00\x00", substr($rgb, 6, 3), "compression $compression");
		}
	}

	public function testSubsampledYCbCrClipsUnitsToTheCanvas()
	{
		// 3x1 image in 2x1 units: the second unit's right luma sample lies off-canvas.
		$tags = $this->baseTags(3, 1, TTIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TTIFFDataType::UShort, [2, 1]];
		$data = "\xFF\xFF\x80\x80" . "\x00\x40\x80\x80";
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, [$data], 279))->getImage());
		self::assertSame(3 * 3, strlen($rgb));
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 0, 3));
		self::assertSame("\xFF\xFF\xFF", substr($rgb, 3, 3));
		self::assertSame("\x00\x00\x00", substr($rgb, 6, 3));   // the discarded sample was 0x40
	}

	public function testSubsampledYCbCrStopsAtTruncatedData()
	{
		// One 2x2 unit needs six bytes; five leave the whole canvas at its initial black.
		$tags = $this->baseTags(2, 2, TTIFFRaster::YCbCr, [8, 8, 8], 3);
		$tags[530] = [TTIFFDataType::UShort, [2, 2]];
		$rgb = TImageGraphics::rgbPixels(TTIFF::fromString($this->buildTiff($tags, 273, ["\xFF\xFF\xFF\xFF\x80"], 279))->getImage());
		self::assertSame(str_repeat("\x00", 2 * 2 * 3), $rgb);
	}

	public function testSubsampledYCbCrUnsupportedFormsAnswerFalse()
	{
		$subsampled = function (array $subsampling, array $overrides = []): array {
			$tags = $this->baseTags(2, 2, TTIFFRaster::YCbCr, [8, 8, 8], 3);
			$tags[530] = [TTIFFDataType::UShort, $subsampling];
			return $overrides + $tags;
		};
		$unit = "\xFF\xFF\x00\x00\x80\x80";

		// Separate planes are outside the subsampled unit layout.
		$tags = $subsampled([2, 2], [284 => [TTIFFDataType::UShort, [2]]]);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());

		// A zero subsampling factor.
		$tags = $subsampled([0, 2]);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());

		// A compression the subsampled path does not decode.
		$tags = $subsampled([2, 2], [259 => [TTIFFDataType::UShort, [TTIFF::CompressionGroup3]]]);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, [$unit], 279))->getImage());

		// No strip data at all.
		$tags = $subsampled([2, 2]);
		self::assertFalse(TTIFF::fromString($this->buildBlocklessTiff($tags, 273, 279))->getImage());
	}

	public function testZeroSizedRasterAnswersFalse()
	{
		$tags = $this->baseTags(0, 0, TTIFFRaster::BlackIsZero, [8], 1);
		$tiff = TTIFF::fromString($this->buildTiff($tags, 273, ["\x00"], 279));
		self::assertSame(0, $tiff->getWidth());
		self::assertFalse($tiff->getImage());
	}

	public function testUnsupportedGeometriesAnswerFalse()
	{
		// A bit depth outside the modeled 1/2/4/8/16.
		$tags = $this->baseTags(2, 1, TTIFFRaster::BlackIsZero, [12], 1);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, ["\x00\x00\x00"], 279))->getImage());

		// An unmodeled PlanarConfiguration.
		$tags = $this->baseTags(2, 1, TTIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[284] = [TTIFFDataType::UShort, [3]];
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, [str_repeat("\x00", 6)], 279))->getImage());

		// A fax coding on data that is not bilevel.
		$tags = $this->baseTags(2, 1, TTIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[259] = [TTIFFDataType::UShort, [TTIFF::CompressionGroup4]];
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, ["\x00"], 279))->getImage());
	}

	public function testTiledRasterWithoutTileDataAnswersFalse()
	{
		$tags = $this->baseTags(4, 4, TTIFFRaster::Rgb, [8, 8, 8], 3);
		unset($tags[278]);
		$tags[322] = [TTIFFDataType::ULong, [2]];
		$tags[323] = [TTIFFDataType::ULong, [2]];

		$tiff = TTIFF::fromString($this->buildBlocklessTiff($tags, 324, 325));
		self::assertNull($tiff->getEXIF()->getIfd0()->getTag(324)->getExternalData());
		self::assertFalse($tiff->getImage());
	}

	public function testPhotometricsMissingTheirRequiredSamplesAnswerFalse()
	{
		// Palette color without a ColorMap.
		$tags = $this->baseTags(2, 1, TTIFFRaster::Palette, [8], 1);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, ["\x00\x01"], 279))->getImage());

		// RGB with only two samples per pixel.
		$tags = $this->baseTags(2, 1, TTIFFRaster::Rgb, [8, 8], 2);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, [str_repeat("\x40", 4)], 279))->getImage());

		// Separated (CMYK) with three.
		$tags = $this->baseTags(2, 1, TTIFFRaster::Separated, [8, 8, 8], 3);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, [str_repeat("\x40", 6)], 279))->getImage());

		// CIE L*a*b* with two.
		$tags = $this->baseTags(2, 1, TTIFFRaster::CieLab, [8, 8], 2);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, [str_repeat("\x40", 4)], 279))->getImage());

		// An unknown photometric interpretation is refused rather than guessed.
		$tags = $this->baseTags(2, 1, 100, [8], 1);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, ["\x00\x01"], 279))->getImage());
	}

	public function testBlitClipsBlocksOutsideTheImage()
	{
		$plane = str_repeat("\x11", 4 * 2 * 3);   // a 4x2 RGB plane
		$untouched = $plane;

		// A block placed entirely past the right edge copies nothing.
		TTIFFRasterBlitProbe::blitBlock($plane, str_repeat("\xFF", 12), ['x' => 4, 'y' => 0, 'width' => 2, 'rows' => 2], 4, 2, 3);
		self::assertSame($untouched, $plane);

		// A block with fewer bytes than its declared rows stops when it runs out.
		TTIFFRasterBlitProbe::blitBlock($plane, str_repeat("\xFF", 12), ['x' => 0, 'y' => 0, 'width' => 4, 'rows' => 2], 4, 2, 3);
		self::assertSame(str_repeat("\xFF", 12) . str_repeat("\x11", 12), $plane);
	}

	public function testUnsupportedFormsAnswerFalse()
	{
		// Mixed sample depths are outside the model.
		$tags = $this->baseTags(2, 1, TTIFFRaster::Rgb, [8, 4, 8], 3);
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, ["\x00\x00\x00\x00\x00\x00"], 279))->getImage());

		// An unknown compression is refused rather than guessed.
		$tags = $this->baseTags(2, 1, TTIFFRaster::Rgb, [8, 8, 8], 3);
		$tags[259] = [TTIFFDataType::UShort, [34712]];   // JPEG 2000
		self::assertFalse(TTIFF::fromString($this->buildTiff($tags, 273, ['whatever'], 279))->getImage());
	}
}
