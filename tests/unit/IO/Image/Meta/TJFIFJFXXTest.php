<?php

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\Meta\TJFIF;
use Prado\IO\Image\Meta\TJFXX;

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
}
