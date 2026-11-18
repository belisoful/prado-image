<?php

use Prado\IO\Image\TImageGraphics;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TTIFF;

class TTIFFRasterTest extends PHPUnit\Framework\TestCase
{
	private function colorImage(int $w = 40, int $h = 25): \GdImage
	{
		$im = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($im, $x, $y, (($x * 6) & 0xFF) << 16 | (($y * 9) & 0xFF) << 8 | (($x + $y) & 0xFF));
			}
		}
		return $im;
	}

	private function monoImage(int $w = 64, int $h = 16): \GdImage
	{
		$im = imagecreatetruecolor($w, $h);
		$white = imagecolorallocate($im, 255, 255, 255);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 0, 0, 0));
		imagefilledrectangle($im, 8, 2, 40, 11, $white);
		imagesetpixel($im, 63, 15, $white);
		return $im;
	}

	public function testRgbRoundTripPerCompression()
	{
		$source = $this->colorImage();
		$expected = TImageGraphics::rgbPixels($source);
		foreach ([TTIFF::CompressionNone, TTIFF::CompressionLzw, TTIFF::CompressionPackBits] as $compression) {
			$tiff = TTIFF::fromImage($source, $compression);
			self::assertSame(40, $tiff->getWidth(), "compression $compression");
			self::assertSame(25, $tiff->getHeight());
			self::assertSame($compression, $tiff->getEXIF()->getIfd0()->getTagValue(259));

			$reparsed = TTIFF::fromString($tiff->toBinary());
			$image = $reparsed->getImage();
			self::assertNotFalse($image, "compression $compression");
			self::assertSame(bin2hex($expected), bin2hex(TImageGraphics::rgbPixels($image)), "compression $compression");
		}
	}

	public function testBilevelRoundTripPerCompression()
	{
		$source = $this->monoImage();
		$expected = TImageGraphics::rgbPixels($source);
		foreach ([TTIFF::CompressionCcittRle, TTIFF::CompressionGroup3, TTIFF::CompressionGroup4] as $compression) {
			$tiff = TTIFF::fromImage($source, $compression);
			$reparsed = TTIFF::fromString($tiff->toBinary());
			self::assertSame(0, $reparsed->getEXIF()->getIfd0()->getTagValue(262));   // WhiteIsZero
			$image = $reparsed->getImage();
			self::assertNotFalse($image, "compression $compression");
			self::assertSame(bin2hex($expected), bin2hex(TImageGraphics::rgbPixels($image)), "compression $compression");
		}
	}

	public function testGroup4TiffIsCompactForText()
	{
		$source = $this->monoImage(400, 100);
		$g4 = TTIFF::fromImage($source, TTIFF::CompressionGroup4);
		$raw = TTIFF::fromImage($source, TTIFF::CompressionNone);
		self::assertLessThan(strlen($raw->toBinary()) / 4, strlen($g4->toBinary()));
	}

	public function testSetImageKeepsMetadata()
	{
		$tiff = TTIFF::fromImage($this->colorImage(), TTIFF::CompressionNone);
		$tiff->getEXIF()->setValueByName('Make', 'PradoCam');
		$tiff->setImage($this->monoImage(), TTIFF::CompressionGroup4);

		$reparsed = TTIFF::fromString($tiff->toBinary());
		self::assertSame('PradoCam', $reparsed->getEXIF()->getMake());
		self::assertSame(64, $reparsed->getWidth());
		self::assertSame(TTIFF::CompressionGroup4, $reparsed->getEXIF()->getIfd0()->getTagValue(259));
		self::assertNotFalse($reparsed->getImage());
	}

	public function testUnsupportedRasterReturnsFalse()
	{
		// Metadata-only TIFF: no strips at all.
		$exif = new Prado\IO\Image\Meta\TEXIF();
		$exif->setValueByName('Make', 'NoPixels');
		$exif->getIfd0()->setTagValues(TTIFF::WidthTag, Prado\IO\Image\TIFF\TTIFFDataType::ULong, [10]);
		$exif->getIfd0()->setTagValues(TTIFF::HeightTag, Prado\IO\Image\TIFF\TTIFFDataType::ULong, [10]);
		$tiff = TTIFF::fromString($exif->getTiff()->toBinary());
		self::assertFalse($tiff->getImage());
	}

	public function testDecodingARasterOverThePixelCeilingIsRefused()
	{
		// The file is a few hundred bytes and claims 50000 by 50000.  The decoder sizes
		// its buffers from those tags, so the claim is refused before anything is allocated.
		$image = imagecreatetruecolor(4, 4);
		$tiff = TTIFF::fromString(TTIFF::fromImage($image, TTIFF::CompressionNone)->toBinary());
		$ifd = $tiff->getEXIF()->getIfd0();
		$ifd->setTagValues(256, TTIFFDataType::ULong, [50000]);
		$ifd->setTagValues(257, TTIFFDataType::ULong, [50000]);
		$claimed = TTIFF::fromString($tiff->toBinary());
		self::assertSame(50000, $claimed->getWidth());
		try {
			$claimed->getImage();
			self::fail('a raster over the ceiling must be refused');
		} catch (TIOException $e) {
			self::assertSame('imagegraphics_raster_too_large', $e->getErrorCode());
		}

		// The ceiling is the caller's to move: an honest raster decodes at or under it.
		$honest = TTIFF::fromImage($image, TTIFF::CompressionNone);
		try {
			TImageGraphics::setMaxPixels(15);
			try {
				$honest->getImage();
				self::fail('a 4x4 raster is over a ceiling of 15 pixels');
			} catch (TIOException) {
			}
			TImageGraphics::setMaxPixels(16);
			self::assertNotFalse($honest->getImage());
		} finally {
			TImageGraphics::setMaxPixels(null);
		}
	}
}
