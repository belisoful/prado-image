<?php

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\Meta\TJFIF;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\TStream;

/**
 * The JFIF and JFXX APP0 thumbnails at their edges: an absent, cleared, malformed, or
 * oversized thumbnail, and the encodings {@see TJFXX} refuses rather than writing a
 * segment no reader could interpret.
 */
class TJFIFJFXXTest extends PHPUnit\Framework\TestCase
{
	/** A solid true-color image of the given size. */
	private function image(int $width = 4, int $height = 3): \GdImage
	{
		$image = imagecreatetruecolor($width, $height);
		imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 100, 50));
		return $image;
	}

	/**
	 * A true-color image whose every pixel differs, so a JPEG of it is larger than the
	 * palette and RGB forms of the same thumbnail.
	 * @param int $width
	 * @param int $height
	 */
	private function noise(int $width, int $height): \GdImage
	{
		$image = imagecreatetruecolor($width, $height);
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				imagesetpixel($image, $x, $y, imagecolorallocate(
					$image,
					($x * 97 + $y * 41) % 256,
					($x * 151 + $y * 7) % 256,
					($x * 23 + $y * 179) % 256,
				));
			}
		}
		return $image;
	}

	//
	// ─── JFIF ────────────────────────────────────────────────────────────────
	//

	public function testJfifWithoutAThumbnailBuildsNoImage()
	{
		$jfif = new TJFIF();
		self::assertFalse($jfif->hasImage());
		self::assertSame('', $jfif->getThumbnail());
		self::assertFalse($jfif->getImage());

		// A parsed thumbnail-less APP0 answers the same way.
		$parsed = TJFIF::parse($jfif->toBinary());
		self::assertInstanceOf(TJFIF::class, $parsed);
		self::assertFalse($parsed->getImage());
	}

	public function testJfifSetImageNullClearsTheThumbnail()
	{
		$jfif = new TJFIF();
		$image = $this->image();
		$jfif->setImage($image);
		imagedestroy($image);
		self::assertTrue($jfif->hasImage());
		$withThumbnail = strlen($jfif->toBinary());

		$jfif->setImage(null);
		self::assertFalse($jfif->hasImage());
		self::assertSame(0, $jfif->getXThumbnail());
		self::assertSame(0, $jfif->getYThumbnail());
		self::assertSame('', $jfif->getThumbnail());

		// The pixels are gone from the segment, not merely hidden behind the sizes.
		$binary = $jfif->toBinary();
		self::assertSame($withThumbnail - 4 * 3 * 3, strlen($binary));
		self::assertFalse(TJFIF::parse($binary)->hasImage());
	}

	public function testJfifRefusesToWriteAThumbnailShorterThanItsDimensions()
	{
		// A truncated APP0: 2x2 RGB pixels are declared but only two bytes follow.
		$payload = pack('a5CCCnnCC', TJFIF::IDENTIFIER, 1, 1, TJFIF::UNITS_PPI, 72, 72, 2, 2) . "\xFF\x00";
		$jfif = TJFIF::parse($payload);
		self::assertInstanceOf(TJFIF::class, $jfif);
		self::assertSame(2, strlen($jfif->getThumbnail()));

		// Writing it back would produce a segment whose length lies about its pixels.
		self::expectException(TInvalidDataValueException::class);
		$jfif->toBinary();
	}

	public function testJfifRefusesToWriteAThumbnailOverTheOneByteMaximum()
	{
		// The dimensions are one byte each in the segment, so 300 would be written as 44.
		// TJFIF has no dimension setter, so the guard is reached through a reported size.
		$jfif = new class () extends TJFIF {
			public function getXThumbnail(): int
			{
				return 300;
			}
		};
		self::expectException(TInvalidDataValueException::class);
		$jfif->toBinary();
	}

	public function testJfifParsesFromAStreamAsItDoesFromAString()
	{
		$jfif = new TJFIF();
		$jfif->setXDensity(300);
		$jfif->setYDensity(200);
		$image = $this->image(4, 3);
		$jfif->setImage($image);
		imagedestroy($image);
		$binary = $jfif->toBinary();

		// The stream is read to its end and parsed like the equivalent string.
		$parsed = TJFIF::parse(TStream::fromString($binary));
		self::assertInstanceOf(TJFIF::class, $parsed);
		self::assertSame(300, $parsed->getXDensity());
		self::assertSame(200, $parsed->getYDensity());
		self::assertSame([4, 3], [$parsed->getXThumbnail(), $parsed->getYThumbnail()]);
		self::assertSame(bin2hex($binary), bin2hex($parsed->toBinary()));

		// A stream that is not JFIF is refused the same way a string is.
		self::assertFalse(TJFIF::parse(TStream::fromString("JFIF\x00\x01")));

		// A raw PHP stream resource is accepted too, without taking ownership of it.
		$resource = fopen('php://temp', 'r+b');
		fwrite($resource, $binary);
		rewind($resource);
		$fromResource = TJFIF::parse($resource);
		self::assertInstanceOf(TJFIF::class, $fromResource);
		self::assertSame(bin2hex($binary), bin2hex($fromResource->toBinary()));
		self::assertTrue(is_resource($resource), 'the caller keeps its handle');
		fclose($resource);

		// Anything else is refused by type.
		self::expectException(TInvalidDataTypeException::class);
		TJFIF::parse([1, 2, 3]);
	}

	//
	// ─── JFXX ────────────────────────────────────────────────────────────────
	//

	public function testJfxxWithoutAThumbnailBuildsNoImage()
	{
		$jfxx = new TJFXX();
		self::assertFalse($jfxx->hasImage());
		self::assertFalse($jfxx->getImage());

		// An empty string is as absent as a null one.
		$jfxx->setThumbnail('');
		self::assertFalse($jfxx->hasImage());
		self::assertFalse($jfxx->getImage());
	}

	public function testJfxxSizelessThumbnailsHaveNoImage()
	{
		// A color thumbnail with pixels but no dimensions cannot be laid out.
		$jfxx = new TJFXX();
		$jfxx->setFormat(TJFXX::COLOR_THUMB);
		$jfxx->setThumbnail("\xFF\x00\x00");
		self::assertFalse($jfxx->hasImage());
		self::assertFalse($jfxx->getImage());

		// Nor can a palette thumbnail without its palette.
		$palette = new TJFXX();
		$palette->setFormat(TJFXX::PALETTE_THUMB);
		$palette->setXThumbnail(2);
		$palette->setYThumbnail(1);
		$palette->setThumbnail("\x00\x01");
		self::assertFalse($palette->hasImage());

		$palette->setPalette("\xFF\x00\x00" . "\x00\xFF\x00" . str_repeat("\0", 762));
		self::assertTrue($palette->hasImage());
		self::assertNotFalse($palette->getImage());
	}

	public function testJfxxUndecodableJpegThumbnailBuildsNoImage()
	{
		$jfxx = new TJFXX();
		$jfxx->setFormat(TJFXX::JPEG_THUMB);
		$jfxx->setThumbnail('this is not a JPEG');
		// The bytes are there, so the thumbnail is present...
		self::assertTrue($jfxx->hasImage());
		// ...but no graphics library can decode them.
		self::assertFalse($jfxx->getImage());
	}

	public function testJfxxSetImageNullClearsEveryThumbnailField()
	{
		$jfxx = new TJFXX();
		$image = $this->image(8, 6);
		self::assertTrue($jfxx->setImage($image, TJFXX::PALETTE_THUMB));
		imagedestroy($image);
		self::assertNotNull($jfxx->getPalette());

		self::assertTrue($jfxx->setImage(null));
		self::assertSame(0, $jfxx->getXThumbnail());
		self::assertSame(0, $jfxx->getYThumbnail());
		self::assertNull($jfxx->getPalette());
		self::assertNull($jfxx->getThumbnail());
		self::assertFalse($jfxx->hasImage());

		// The format is left alone, and the cleared extension still writes and re-reads.
		self::assertSame(TJFXX::PALETTE_THUMB, $jfxx->getFormat());
		$parsed = TJFXX::parse((string) $jfxx->toBinary());
		self::assertInstanceOf(TJFXX::class, $parsed);
		self::assertFalse($parsed->hasImage());
	}

	public function testJfxxSetImageRefusesAnUnknownFormat()
	{
		$jfxx = new TJFXX();
		$image = $this->image();
		self::assertFalse($jfxx->setImage($image, 0x99));
		imagedestroy($image);

		// Nothing was stored, so the caller cannot mistake the refusal for a thumbnail.
		self::assertSame(0, $jfxx->getXThumbnail());
		self::assertNull($jfxx->getThumbnail());
		self::assertFalse($jfxx->hasImage());
	}

	public function testJfxxRefusesToWriteAThumbnailOverTheOneByteMaximum()
	{
		$jfxx = new TJFXX();
		$jfxx->setFormat(TJFXX::COLOR_THUMB);
		$jfxx->setXThumbnail(300);
		$jfxx->setYThumbnail(10);
		self::expectException(TInvalidDataValueException::class);
		$jfxx->toBinary();
	}

	public function testJfxxWritesNothingForAnUnknownFormat()
	{
		$jfxx = new TJFXX();
		$image = $this->image();
		$jfxx->setImage($image, TJFXX::COLOR_THUMB);
		imagedestroy($image);
		self::assertIsString($jfxx->toBinary());

		// An encoding no reader knows is refused rather than written with a JFXX header.
		$jfxx->setFormat(0x99);
		self::assertFalse($jfxx->toBinary());
	}

	public function testJfxxParsesFromAStreamAsItDoesFromAString()
	{
		$jfxx = new TJFXX();
		$image = $this->image(4, 3);
		$jfxx->setImage($image, TJFXX::COLOR_THUMB);
		imagedestroy($image);
		$binary = (string) $jfxx->toBinary();

		$parsed = TJFXX::parse(TStream::fromString($binary));
		self::assertInstanceOf(TJFXX::class, $parsed);
		self::assertSame(TJFXX::COLOR_THUMB, $parsed->getFormat());
		self::assertSame([4, 3], [$parsed->getXThumbnail(), $parsed->getYThumbnail()]);
		self::assertSame(bin2hex($binary), bin2hex((string) $parsed->toBinary()));

		// A stream too short to be JFXX is refused the same way a string is.
		self::assertFalse(TJFXX::parse(TStream::fromString('JFXX')));

		// A raw PHP stream resource is accepted too, without taking ownership of it.
		$resource = fopen('php://temp', 'r+b');
		fwrite($resource, $binary);
		rewind($resource);
		$fromResource = TJFXX::parse($resource);
		self::assertInstanceOf(TJFXX::class, $fromResource);
		self::assertSame(bin2hex($binary), bin2hex((string) $fromResource->toBinary()));
		self::assertTrue(is_resource($resource), 'the caller keeps its handle');
		fclose($resource);

		// Anything else is refused by type.
		self::expectException(TInvalidDataTypeException::class);
		TJFXX::parse([1, 2, 3]);
	}

	public function testJfxxEfficiencyPicksThePaletteWhenItIsTheSmallestOfTheThree()
	{
		// 32x32 is 1024 pixels: the 768-byte palette plus one index per pixel is 1792
		// bytes against 3072 for RGB, and a quality-100 JPEG of pixel-by-pixel noise is
		// larger than both — so the palette form wins.
		$image = $this->noise(32, 32);
		$jfxx = new TJFXX();
		self::assertTrue($jfxx->setImage($image, TJFXX::EFFICIENCY_THUMB, 100));
		imagedestroy($image);

		self::assertSame(TJFXX::PALETTE_THUMB, $jfxx->getFormat());
		self::assertSame(768, strlen((string) $jfxx->getPalette()));
		self::assertSame(1024, strlen((string) $jfxx->getThumbnail()));

		// What was chosen is what a reader gets back.
		$parsed = TJFXX::parse((string) $jfxx->toBinary());
		self::assertInstanceOf(TJFXX::class, $parsed);
		self::assertSame(TJFXX::PALETTE_THUMB, $parsed->getFormat());
		self::assertSame([32, 32], [$parsed->getXThumbnail(), $parsed->getYThumbnail()]);
		self::assertInstanceOf(\GdImage::class, $parsed->getImage());
	}

	public function testJfxxEfficiencyPicksRgbForAThumbnailTooSmallToPayForAPalette()
	{
		// 8x8 is 64 pixels: 192 bytes of RGB against 832 for the palette form, and no
		// JPEG is that small — its markers alone cost more.
		$image = $this->image(8, 8);
		$jfxx = new TJFXX();
		self::assertTrue($jfxx->setImage($image, TJFXX::EFFICIENCY_THUMB));
		imagedestroy($image);

		self::assertSame(TJFXX::COLOR_THUMB, $jfxx->getFormat());
		self::assertNull($jfxx->getPalette());
		self::assertSame(192, strlen((string) $jfxx->getThumbnail()));

		// The same image at 64x64 is large enough for the JPEG to beat both raw forms.
		$large = $this->image(64, 64);
		$jpeg = new TJFXX();
		self::assertTrue($jpeg->setImage($large, TJFXX::EFFICIENCY_THUMB));
		imagedestroy($large);
		self::assertSame(TJFXX::JPEG_THUMB, $jpeg->getFormat());
		self::assertLessThan(64 * 64 + 768, strlen((string) $jpeg->getThumbnail()));
	}
}
