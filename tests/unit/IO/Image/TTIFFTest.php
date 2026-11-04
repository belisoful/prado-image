<?php

use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TIFF\TTIFFTag;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TTIFF;

class TTIFFTest extends PHPUnit\Framework\TestCase
{
	private function tiffBytes(): string
	{
		$exif = new TEXIF();
		$exif->getIfd0()->setTagValues(TTIFF::WidthTag, TTIFFDataType::ULong, [320]);
		$exif->getIfd0()->setTagValues(TTIFF::HeightTag, TTIFFDataType::ULong, [200]);
		$exif->setValueByName('Make', 'PradoCam');
		$iptc = new TIPTC();
		$iptc[TIPTCTags::ObjectName] = 'TIFF Title';
		$exif->setIPTC($iptc);
		$exif->getIfd0()->setTagValues(TTIFF::ICCTag, TTIFFDataType::Undefined, 'ICCPROFILEBYTES!');
		return $exif->getTiff()->toBinary();
	}

	private function gdTiff(): TTIFF
	{
		$image = imagecreatetruecolor(6, 4);
		imagefilledrectangle($image, 0, 0, 5, 3, imagecolorallocate($image, 10, 120, 200));
		return TTIFF::fromImage($image);
	}

	private function artistExif(): TEXIF
	{
		$exif = new TEXIF();
		$exif->setSignature('');
		$exif->setValueByName('Artist', 'Jane Doe');
		$exif->setValueByName('FNumber', 2.8);
		$exif->setLatitude(34.05);
		$exif->setLongitude(-118.24);
		// A raster tag in the given EXIF describes some other image and must not be taken.
		$exif->getIfd0()->setTagValues(TTIFF::WidthTag, TTIFFDataType::ULong, [999]);
		return $exif;
	}

	public function testSetExifMergesIntoTheTiffAndKeepsItsPixels()
	{
		$tiff = $this->gdTiff();
		$pixels = bin2hex(TImageGraphics::rgbPixels($tiff->getImage()));
		$tiff->setXmpText('<x:xmpmeta xmlns:x="adobe:ns:meta/"></x:xmpmeta>');
		$tiff->setEXIF($this->artistExif());

		$round = TTIFF::fromString($tiff->toBinary());
		self::assertSame('Jane Doe', $round->getEXIF()->getValueByName('Artist'));
		self::assertEqualsWithDelta(34.05, $round->getEXIF()->getLatitude(), 1e-6);
		self::assertNotNull($round->getEXIF()->getValueByName('FNumber'));
		self::assertSame([6, 4], [$round->getWidth(), $round->getHeight()]);
		self::assertSame($pixels, bin2hex(TImageGraphics::rgbPixels($round->getImage())));
		self::assertNotNull($round->getXmpText(), 'a carrier with its own setter stays');

		$round->setEXIF(null);
		$bare = TTIFF::fromString($round->toBinary());
		self::assertNull($bare->getEXIF()->getValueByName('Artist'));
		self::assertNull($bare->getEXIF()->getLatitude());
		self::assertNull($bare->getEXIF()->getExifIfd());
		self::assertSame([6, 4], [$bare->getWidth(), $bare->getHeight()]);
		self::assertSame($pixels, bin2hex(TImageGraphics::rgbPixels($bare->getImage())));
		self::assertNotNull($bare->getXmpText());
	}

	public function testSetExifCopiesSoTheTwoStayApart()
	{
		$exif = $this->artistExif();
		$tiff = $this->gdTiff();
		$tiff->setEXIF($exif);
		$exif->setValueByName('Artist', 'Someone Else');
		$exif->getGpsIfd()->removeTag(2);
		self::assertSame('Jane Doe', $tiff->getEXIF()->getValueByName('Artist'));
		self::assertEqualsWithDelta(34.05, $tiff->getEXIF()->getLatitude(), 1e-6);

		// The live document was edited in place, so setting it back is a no-op.
		$tiff->setEXIF($tiff->getEXIF());
		self::assertSame('Jane Doe', $tiff->getEXIF()->getValueByName('Artist'));
	}

	public function testSetExifKeepsAPinnedValueAtItsOffset()
	{
		$exif = new TEXIF();
		$exif->setSignature('');
		$note = new TTIFFTag(37500, TTIFFDataType::Undefined, str_repeat('N', 32));
		$note->setOffset(400);
		$note->setPreserveOffset(true);
		$exif->getExifIfd(true)->setTag($note);

		$tiff = $this->gdTiff();
		$tiff->setEXIF($exif);
		$copy = $tiff->getEXIF()->getExifIfd()->getTag(37500);
		self::assertNotSame($note, $copy);
		self::assertSame(400, $copy->getOffset());
		self::assertTrue($copy->getPreserveOffset());
		self::assertSame(str_repeat('N', 32), substr($tiff->toBinary(), 400, 32));
	}

	public function testSetExifOnAnEmptyTiff()
	{
		$tiff = new TTIFF();
		$tiff->setEXIF(null);
		self::assertNull($tiff->getEXIF(), 'clearing nothing does not conjure a document');

		$tiff->setEXIF($this->artistExif());
		self::assertSame('Jane Doe', $tiff->getEXIF()->getValueByName('Artist'));
		self::assertNull($tiff->getEXIF()->getIfd0()->getTag(TTIFF::WidthTag), 'no raster tag is taken');
	}

	public function testTiffFileRead()
	{
		$bytes = $this->tiffBytes();
		self::assertTrue(TTIFF::isTIFF($bytes));
		self::assertFalse(TTIFF::isTIFF('nope'));

		$tiff = TTIFF::fromString($bytes);
		self::assertSame('TIFF', $tiff->getFormat());
		self::assertSame(320, $tiff->getWidth());
		self::assertSame(200, $tiff->getHeight());
		self::assertSame('PradoCam', $tiff->getEXIF()->getMake());
		self::assertSame('TIFF Title', $tiff->getIPTC()[TIPTCTags::ObjectName]);
		self::assertSame('ICCPROFILEBYTES!', $tiff->getICCProfile());

		// Read-write: the recomposed bytes reparse identically.
		$again = TTIFF::fromString($tiff->toBinary());
		self::assertSame(320, $again->getWidth());
		self::assertSame('TIFF Title', $again->getIPTC()[TIPTCTags::ObjectName]);
		self::assertSame('ICCPROFILEBYTES!', $again->getICCProfile());
	}

	public function testUnloadedTiffComposesItsOwnBytes()
	{
		// Nothing parsed: there is no structure to recompose, so the bytes pass through.
		$tiff = new TTIFF();
		self::assertNull($tiff->getEXIF());
		self::assertNull($tiff->getTiff());
		self::assertSame('', $tiff->toBinary());
		self::assertSame([], $tiff->getReservedSpaces());
		// No IFD0 to read a raster geometry from, so there is no image to answer.
		self::assertFalse($tiff->getImage());
	}

	public function testIccProfileTypedAsByteValues()
	{
		// Writers that type tag 34675 as BYTE rather than UNDEFINED deliver the profile
		// as an integer array, which must still read back as the profile's bytes.
		$profile = 'ICC-PROFILE-AS-BYTES';
		$exif = new TEXIF();
		$exif->getIfd0()->setTagValues(TTIFF::WidthTag, TTIFFDataType::ULong, [16]);
		$exif->getIfd0()->setTagValues(TTIFF::HeightTag, TTIFFDataType::ULong, [16]);
		$exif->getIfd0()->setTagValues(TTIFF::ICCTag, TTIFFDataType::UByte, array_map('ord', str_split($profile)));

		$tiff = TTIFF::fromString($exif->getTiff()->toBinary());
		self::assertSame(TTIFFDataType::UByte, $tiff->getEXIF()->getIfd0()->getTag(TTIFF::ICCTag)->getType());
		self::assertSame($profile, $tiff->getICCProfile());
	}

	public function testSetImageOnAnUnloadedTiffBuildsTheStructure()
	{
		$image = imagecreatetruecolor(4, 3);
		imagefilledrectangle($image, 0, 0, 3, 2, imagecolorallocate($image, 0x20, 0x40, 0x60));
		imagesetpixel($image, 3, 2, imagecolorallocate($image, 0xFF, 0x00, 0x80));

		$tiff = new TTIFF();
		$tiff->setImage($image, TTIFF::CompressionNone);
		self::assertNotNull($tiff->getEXIF());
		self::assertSame('', $tiff->getEXIF()->getSignature());   // a TIFF file has no Exif\0\0 signature
		self::assertSame(4, $tiff->getWidth());
		self::assertSame(3, $tiff->getHeight());

		$reparsed = TTIFF::fromString($tiff->toBinary());
		self::assertSame(4, $reparsed->getWidth());
		self::assertSame(3, $reparsed->getHeight());
		self::assertSame(
			bin2hex(Prado\IO\Image\TImageGraphics::rgbPixels($image)),
			bin2hex(Prado\IO\Image\TImageGraphics::rgbPixels($reparsed->getImage())),
		);
	}

	public function testStripDataSurvivesMetadataRewrite()
	{
		// A minimal striped image: 4 rows in 2 strips of raw "pixel" bytes.
		$strip1 = str_repeat("\xAA", 24);
		$strip2 = str_repeat("\x55", 24);
		$exif = new TEXIF();
		$ifd0 = $exif->getIfd0();
		$ifd0->setTagValues(TTIFF::WidthTag, TTIFFDataType::ULong, [12]);
		$ifd0->setTagValues(TTIFF::HeightTag, TTIFFDataType::ULong, [4]);
		$ifd0->setTagValues(278, TTIFFDataType::ULong, [2]);   // RowsPerStrip
		$offsets = $ifd0->setTagValues(273, TTIFFDataType::ULong, [0, 0]);
		$ifd0->setTagValues(279, TTIFFDataType::ULong, [24, 24]);
		$offsets->setExternalData([$strip1, $strip2]);

		$tiff = TTIFF::fromString($exif->getTiff()->toBinary());
		$readBack = $tiff->getEXIF()->getIfd0()->getTag(273)->getExternalData();
		self::assertSame([$strip1, $strip2], $readBack);

		// Edit metadata (shifting every offset) and rewrite: strips must survive.
		$tiff->getEXIF()->setValueByName('Artist', 'Somebody With A Rather Long Name Indeed');
		$iptc = new TIPTC();
		$iptc[TIPTCTags::ObjectName] = 'Rewritten';
		$tiff->setIPTC($iptc);

		$rewritten = TTIFF::fromString($tiff->toBinary());
		self::assertSame([$strip1, $strip2], $rewritten->getEXIF()->getIfd0()->getTag(273)->getExternalData());
		self::assertSame([24, 24], $rewritten->getEXIF()->getIfd0()->getTag(279)->getValues());
		self::assertSame('Rewritten', $rewritten->getIPTC()[TIPTCTags::ObjectName]);
		self::assertSame('Somebody With A Rather Long Name Indeed', $rewritten->getEXIF()->getValueByName('Artist'));
		// The two strips landed at distinct, valid offsets.
		$reOffsets = $rewritten->getEXIF()->getIfd0()->getTag(273)->getValues();
		self::assertCount(2, array_unique($reOffsets));
	}
}
