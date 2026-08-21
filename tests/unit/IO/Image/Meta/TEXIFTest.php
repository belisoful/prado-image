<?php

use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TEXIFTags;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TIFF\TTIFFDocument;
use Prado\IO\Image\TJPEG;
use Prado\IO\TStream;

class TEXIFTest extends PHPUnit\Framework\TestCase
{
	private function sampleExif(): TEXIF
	{
		$exif = new TEXIF();
		$exif->setValueByName('Make', 'PradoCam');
		$exif->setValueByName('Model', 'PC-2000');
		$exif->getExifIfd(true)->setTagValues(33434, TTIFFDataType::URational, [[1, 125]]);
		$exif->getExifIfd(true)->setTagValues(34855, TTIFFDataType::UShort, [400]);
		$exif->getGpsIfd(true)->setTagValues(2, TTIFFDataType::URational, [[34, 1], [3, 1], [3000, 100]]);
		return $exif;
	}

	private function jpegBytes(int $w = 32, int $h = 24): string
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 40, 90, 160));
		ob_start();
		imagejpeg($im, null, 85);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testSegmentRoundTrip()
	{
		$payload = $this->sampleExif()->toBinary();
		self::assertStringStartsWith(TEXIF::ExifSignature, $payload);

		$exif = TEXIF::fromSegment($payload);
		self::assertNotFalse($exif);
		self::assertFalse($exif->getIsMeta());
		self::assertSame('PradoCam', $exif->getMake());
		self::assertSame('PC-2000', $exif->getModel());
		self::assertSame([[1, 125]], $exif->getExifIfd()->getTag(33434)->getValues());
		self::assertSame(400, $exif->getValueByName('ISOSpeedRatings') ?? $exif->getExifIfd()->getTagValue(34855));
		self::assertSame([], $exif->getTiff()->getWarnings());
	}

	public function testFromSegmentRejectsOther()
	{
		self::assertFalse(TEXIF::fromSegment('not exif data'));
		self::assertFalse(TEXIF::isExifSegment("II*\x00"));
		self::assertTrue(TEXIF::isExifSegment(TEXIF::ExifSignature . 'x'));
		self::assertTrue(TEXIF::isExifSegment(TEXIF::MetaSignature . 'x'));
	}

	public function testNameAccessAndText()
	{
		$exif = $this->sampleExif();
		self::assertSame('PradoCam', $exif->getValueByName('Make'));
		self::assertSame([[1, 125]], $exif->getTagByName('ExposureTime')->getValues());
		self::assertSame('1/125 seconds', $exif->getTextByName('ExposureTime'));

		$exif->getIfd0()->setTagValues(274, TTIFFDataType::UShort, [1]);
		self::assertStringContainsString('No Rotation', $exif->getTextByName('Orientation'));

		self::assertSame('34° 3\' 30"', $exif->getTextByName('GPSLatitude'));

		self::assertTrue($exif->setValueByName('Artist', 'A. Photographer'));
		self::assertSame('A. Photographer', $exif->getValueByName('Artist'));
		self::assertTrue($exif->setValueByName('Artist', null));
		self::assertNull($exif->getValueByName('Artist'));
		self::assertFalse($exif->setValueByName('NotARealTagName', 1));
	}

	public function testThumbnailRoundTrip()
	{
		$thumb = $this->jpegBytes(16, 12);
		$exif = $this->sampleExif();
		$exif->setThumbnail($thumb);
		$bytes = $exif->toBinary();

		$reparsed = TEXIF::fromSegment($bytes);
		self::assertSame($thumb, $reparsed->getThumbnail());
		self::assertSame(strlen($thumb), $reparsed->getThumbnailIfd()->getTagValue(TEXIF::ThumbnailLengthTag));

		$reparsed->setThumbnail(null);
		$again = TEXIF::fromSegment($reparsed->toBinary());
		self::assertNull($again->getThumbnail());
	}

	public function testEmbeddedIptcRoundTrip()
	{
		$iptc = new TIPTC();
		$iptc[TIPTCTags::ObjectName] = 'Embedded Title';
		$exif = $this->sampleExif();
		$exif->setIPTC($iptc);

		$reparsed = TEXIF::fromSegment($exif->toBinary());
		$reIptc = $reparsed->getIPTC();
		self::assertNotNull($reIptc);
		self::assertSame('Embedded Title', $reIptc[TIPTCTags::ObjectName]);

		$reparsed->setIPTC(null);
		self::assertNull(TEXIF::fromSegment($reparsed->toBinary())->getIPTC());
	}

	public function testEmbeddedXmpRoundTrip()
	{
		$xml = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/></x:xmpmeta>';
		$exif = $this->sampleExif();
		$exif->setXmpText($xml);
		self::assertSame($xml, TEXIF::fromSegment($exif->toBinary())->getXmpText());
	}

	public function testMakernotePreservedAtOriginalOffset()
	{
		$exif = $this->sampleExif();
		$note = 'MAKER' . str_repeat("\x5A", 40);
		$exif->getExifIfd(true)->setTagValues(TEXIF::MakerNoteTag, TTIFFDataType::Undefined, $note);
		$first = TEXIF::fromSegment($exif->toBinary());
		$originalOffset = $first->getExifIfd()->getTag(TEXIF::MakerNoteTag)->getOffset();
		self::assertNotNull($originalOffset);

		// Edit an unrelated tag and rewrite: the makernote must stay at its offset.
		$first->setValueByName('Artist', 'Somebody With A Longer Name Than Before');
		$rewritten = $first->toBinary();
		self::assertSame($note, substr($rewritten, strlen(TEXIF::ExifSignature) + $originalOffset, strlen($note)));

		$second = TEXIF::fromSegment($rewritten);
		self::assertSame($originalOffset, $second->getExifIfd()->getTag(TEXIF::MakerNoteTag)->getOffset());
		self::assertSame($note, $second->getMakernoteData());
	}

	public function testMetaSegment()
	{
		$exif = new TEXIF();
		$exif->setIsMeta(true);
		$exif->getIfd0()->setTagValues(0xC350, TTIFFDataType::Ascii, "KodakFirmware\0");
		$meta = TEXIF::fromSegment($exif->toBinary());
		self::assertNotFalse($meta);
		self::assertTrue($meta->getIsMeta());
		self::assertSame('KodakFirmware', $meta->getIfd0()->getTagValue(0xC350));
		self::assertStringStartsWith(TEXIF::MetaSignature, $meta->toBinary());
	}

	public function testJpegIntegrationRoundTrip()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		self::assertNull($jpeg->getEXIF());

		$exif = $this->sampleExif();
		$exif->setThumbnail($this->jpegBytes(8, 6));
		$jpeg->setEXIF($exif);
		$xml = '<x:xmpmeta xmlns:x="adobe:ns:meta/"/>';
		$jpeg->setXmpText($xml);

		$reparsed = TJPEG::fromString($jpeg->toBinary());
		self::assertNotNull($reparsed->getEXIF());
		self::assertSame('PradoCam', $reparsed->getEXIF()->getMake());
		self::assertSame('1/125 seconds', $reparsed->getEXIF()->getTextByName('ExposureTime'));
		self::assertNotNull($reparsed->getEXIF()->getThumbnail());
		self::assertSame($xml, $reparsed->getXmpText());
		self::assertSame($reparsed->getWidth(), 32);

		// Dropping the EXIF removes the segment entirely.
		$reparsed->setEXIF(null);
		$reparsed->setXmpText(null);
		$stripped = TJPEG::fromString($reparsed->toBinary());
		self::assertNull($stripped->getEXIF());
		self::assertNull($stripped->getXmpText());
	}

	public function testTiffFileRead()
	{
		$exif = $this->sampleExif();
		$tiffBytes = $exif->getTiff()->toBinary();
		$fromTiff = TEXIF::fromTiffString($tiffBytes);
		self::assertSame('PradoCam', $fromTiff->getMake());
		// A signatureless TIFF source still composes to a valid APP1 payload.
		self::assertStringStartsWith(TEXIF::ExifSignature, $fromTiff->toSegment());
	}

	public function testExifTagsKnowledge()
	{
		self::assertSame('FNumber', TEXIFTags::nameOf(TEXIFTags::EXIF, 33437));
		self::assertSame(['GPS', 2], TEXIFTags::findByName('GPSLatitude'));
		self::assertNull(TEXIFTags::findByName('BogusTag'));
		self::assertNull(TEXIFTags::definition(TEXIFTags::TIFF, 999999));
		self::assertCount(7, TEXIFTags::Definitions);
	}

	/**
	 * Packs an IFD: entries as [tag, type, count, valueFieldBytes(4)], big-endian.
	 * @param array $entries
	 * @param int $next
	 */
	private function packIfd(array $entries, int $next): string
	{
		$out = pack('n', count($entries));
		foreach ($entries as [$tag, $type, $count, $field]) {
			$out .= pack('n', $tag) . pack('n', $type) . pack('N', $count) . str_pad($field, 4, "\0");
		}
		return $out . pack('N', $next);
	}

	public function testScanStreamAcceptsAPhpResource()
	{
		$exif = $this->sampleExif();
		$exif->setThumbnail($this->jpegBytes(8, 6));
		$exif->setSignature('');
		$tiff = $exif->toBinary();

		$resource = fopen('php://memory', 'w+b');
		fwrite($resource, $tiff);
		rewind($resource);
		try {
			$scanned = TEXIF::scanStream($resource);
			self::assertTrue($scanned->getTiff()->getIsScanned());
			self::assertSame('PradoCam', $scanned->getMake());
			self::assertSame('1/125 seconds', $scanned->getTextByName('ExposureTime'));
			self::assertSame($exif->getThumbnail(), $scanned->getThumbnail());
		} finally {
			fclose($resource);
		}
	}

	public function testScanStreamToleratesADanglingThumbnailPointer()
	{
		// IFD1 points its thumbnail far past the end of the file: the scan keeps the
		// metadata it did read and simply has no thumbnail, as the in-memory parse does.
		$ifd1Offset = 8 + 18;         // header + a one-entry IFD0
		$makeOffset = $ifd1Offset + 30;   // + a two-entry IFD1
		$ifd0 = $this->packIfd([[271, TTIFFDataType::Ascii, 9, pack('N', $makeOffset)]], $ifd1Offset);
		$ifd1 = $this->packIfd([
			[TEXIF::ThumbnailOffsetTag, TTIFFDataType::ULong, 1, pack('N', 0x00100000)],
			[TEXIF::ThumbnailLengthTag, TTIFFDataType::ULong, 1, pack('N', 4096)],
		], 0);
		$tiff = "MM\x00\x2A" . pack('N', 8) . $ifd0 . $ifd1 . "PradoCam\0";

		$scanned = TEXIF::scanStream(TStream::fromString($tiff));
		self::assertSame('PradoCam', $scanned->getMake());
		self::assertSame(4096, $scanned->getThumbnailIfd()->getTagValue(TEXIF::ThumbnailLengthTag));
		self::assertNull($scanned->getThumbnail());
	}

	public function testSubIfdPointerWithoutAChildIsNotFabricated()
	{
		// A pointer tag whose target never parsed (a dangling offset) reads as absent
		// rather than yielding an empty sub-IFD.
		$exif = new TEXIF();
		$exif->getIfd0()->setTagValues(TTIFFDocument::ExifIfdTag, TTIFFDataType::ULong, [0]);
		self::assertNotNull($exif->getIfd0()->getTag(TTIFFDocument::ExifIfdTag));
		self::assertNull($exif->getExifIfd());

		// Asking for it created only on request, reusing the existing pointer tag.
		self::assertNotNull($exif->getExifIfd(true));
		self::assertSame($exif->getExifIfd(true), $exif->getIfd0()->getTag(TTIFFDocument::ExifIfdTag)->getSubIfd());
	}

	public function testUnknownAndUnreachableTagNames()
	{
		$exif = $this->sampleExif();
		self::assertNull($exif->getTagByName('NotARealTagName'));
		self::assertNull($exif->getTextByName('NotARealTagName'));
		self::assertNull($exif->getValueByName('NotARealTagName'));

		// A known name whose group has no IFD in an EXIF block (the Kodak APP3 Meta
		// sub-records) resolves but cannot be written.
		self::assertSame('KodakSpecialEffects', TEXIFTags::findByName('DigitalEffectsName')[0]);
		self::assertFalse($exif->setValueByName('DigitalEffectsName', 'Sepia'));
		self::assertNull($exif->getTextByName('DigitalEffectsName'));
	}

	public function testInteroperabilityIfdByName()
	{
		$exif = new TEXIF();
		self::assertNull($exif->getInteropIfd());
		self::assertTrue($exif->setValueByName('InteroperabilityIndex', 'R98'));

		$reparsed = TEXIF::fromSegment($exif->toBinary());
		self::assertNotNull($reparsed->getInteropIfd());
		self::assertSame('R98', $reparsed->getValueByName('InteroperabilityIndex'));
		self::assertSame('R98', $reparsed->getTextByName('InteroperabilityIndex'));
	}

	public function testSetValueByNameArrayForms()
	{
		$exif = new TEXIF();
		// An array of pairs writes rationals; an array of integers writes longs.
		self::assertTrue($exif->setValueByName('GPSLatitude', [[34, 1], [3, 1], [3000, 100]]));
		self::assertTrue($exif->setValueByName('BitsPerSample', [8, 8, 8]));

		$reparsed = TEXIF::fromSegment($exif->toBinary());
		self::assertSame(TTIFFDataType::URational, $reparsed->getTagByName('GPSLatitude')->getType());
		self::assertSame([[34, 1], [3, 1], [3000, 100]], $reparsed->getTagByName('GPSLatitude')->getValues());
		self::assertSame('34° 3\' 30"', $reparsed->getTextByName('GPSLatitude'));
		self::assertSame(TTIFFDataType::ULong, $reparsed->getTagByName('BitsPerSample')->getType());
		self::assertSame([8, 8, 8], $reparsed->getTagByName('BitsPerSample')->getValues());
	}
}
