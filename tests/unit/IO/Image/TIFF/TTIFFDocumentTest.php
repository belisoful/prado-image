<?php

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TIFF\TTIFFDocument;
use Prado\IO\Image\TIFF\TTIFFIfd;
use Prado\IO\Image\TIFF\TTIFFTag;
use Prado\IO\TStream;

class TTIFFDocumentTest extends PHPUnit\Framework\TestCase
{
	private function sampleDocument(bool $bigEndian): TTIFFDocument
	{
		$tiff = new TTIFFDocument();
		$tiff->setIsBigEndian($bigEndian);
		$ifd0 = new TTIFFIfd();
		$ifd0->setTagValues(256, TTIFFDataType::ULong, [640]);
		$ifd0->setTagValues(257, TTIFFDataType::ULong, [480]);
		$ifd0->setTagValues(271, TTIFFDataType::Ascii, "PradoCam\0");
		$ifd0->setTagValues(282, TTIFFDataType::URational, [[72, 1]]);
		$exif = new TTIFFIfd();
		$exif->setTagValues(33434, TTIFFDataType::URational, [[1, 125]]);
		$exif->setTagValues(37377, TTIFFDataType::SRational, [[-7, 2]]);
		$pointer = $ifd0->setTagValues(TTIFFDocument::ExifIfdTag, TTIFFDataType::ULong, [0]);
		$pointer->setSubIfd($exif);
		$tiff->addIfd($ifd0);
		return $tiff;
	}

	public function testDataTypeSizes()
	{
		self::assertSame(1, TTIFFDataType::getSize(TTIFFDataType::UByte));
		self::assertSame(2, TTIFFDataType::getSize(TTIFFDataType::UShort));
		self::assertSame(4, TTIFFDataType::getSize(TTIFFDataType::Float));
		self::assertSame(8, TTIFFDataType::getSize(TTIFFDataType::Double));
		self::assertSame(8, TTIFFDataType::getSize(TTIFFDataType::SRational));
		self::assertFalse(TTIFFDataType::isValid(0));
		self::assertFalse(TTIFFDataType::isValid(13));
	}

	public function testDataTypePackUnpackRoundTrip()
	{
		$cases = [
			[TTIFFDataType::UByte, [0, 1, 255]],
			[TTIFFDataType::SByte, [-128, -1, 0, 127]],
			[TTIFFDataType::UShort, [0, 1, 65535]],
			[TTIFFDataType::SShort, [-32768, -1, 32767]],
			[TTIFFDataType::ULong, [0, 1, 4294967295]],
			[TTIFFDataType::SLong, [-2147483648, -1, 2147483647]],
			[TTIFFDataType::URational, [[1, 125], [4294967295, 1]]],
			[TTIFFDataType::SRational, [[-7, 2], [1, -3]]],
			[TTIFFDataType::Double, [0.0, -1.5, 1234.5678]],
		];
		foreach ($cases as [$type, $values]) {
			foreach ([true, false] as $bigEndian) {
				$packed = TTIFFDataType::pack($type, $values, $bigEndian);
				self::assertSame(TTIFFDataType::getSize($type) * count($values), strlen($packed));
				self::assertSame($values, TTIFFDataType::unpack($type, $packed, $bigEndian), "type $type");
			}
		}
		// Floats round-trip through 32-bit precision.
		foreach ([true, false] as $bigEndian) {
			$packed = TTIFFDataType::pack(TTIFFDataType::Float, [1.5, -0.25], $bigEndian);
			self::assertEqualsWithDelta([1.5, -0.25], TTIFFDataType::unpack(TTIFFDataType::Float, $packed, $bigEndian), 1e-6);
		}
		self::assertSame('abc', TTIFFDataType::pack(TTIFFDataType::Ascii, 'abc', true));
		self::assertSame("\x01\x02", TTIFFDataType::unpack(TTIFFDataType::Undefined, "\x01\x02", false));
	}

	public function testEveryKnownDataTypeIsPackable()
	{
		// Every type getSize() admits is handled: the three string types by the early
		// return, the other ten by an arm of the pack/unpack match, so no value set that
		// clears getSize() can fall through the codec.
		$samples = [
			TTIFFDataType::UByte => [1],
			TTIFFDataType::Ascii => "a\0",
			TTIFFDataType::UShort => [1],
			TTIFFDataType::ULong => [1],
			TTIFFDataType::URational => [[1, 2]],
			TTIFFDataType::SByte => [-1],
			TTIFFDataType::Undefined => "\x01\x02",
			TTIFFDataType::SShort => [-1],
			TTIFFDataType::SLong => [-1],
			TTIFFDataType::SRational => [[-1, 2]],
			TTIFFDataType::Float => [1.0],
			TTIFFDataType::Double => [1.0],
			TTIFFDataType::Utf8 => "\u{00E9}\0",
		];
		self::assertSame(array_keys(TTIFFDataType::Sizes), array_keys($samples));
		foreach ($samples as $type => $values) {
			$packed = TTIFFDataType::pack($type, $values, true);
			self::assertSame(TTIFFDataType::getSize($type) * TTIFFDataType::countOf($type, $values), strlen($packed), "type $type");
			self::assertSame($values, TTIFFDataType::unpack($type, $packed, true), "type $type");
		}
	}

	public function testComposeParseRoundTripBothOrders()
	{
		foreach ([true, false] as $bigEndian) {
			$bytes = $this->sampleDocument($bigEndian)->toBinary();
			self::assertSame($bigEndian ? 'MM' : 'II', substr($bytes, 0, 2));

			$tiff = TTIFFDocument::fromString($bytes);
			self::assertSame($bigEndian, $tiff->getIsBigEndian());
			self::assertSame([], $tiff->getWarnings());
			$ifd0 = $tiff->getIfd(0);
			self::assertSame(640, $ifd0->getTagValue(256));
			self::assertSame('PradoCam', $ifd0->getTagValue(271));
			self::assertSame([[72, 1]], $ifd0->getTag(282)->getValues());
			self::assertEqualsWithDelta(72.0, $ifd0->getTag(282)->getRational(), 1e-9);

			$exif = $ifd0->getTag(TTIFFDocument::ExifIfdTag)->getSubIfd();
			self::assertNotNull($exif);
			self::assertSame([[1, 125]], $exif->getTag(33434)->getValues());
			self::assertSame([[-7, 2]], $exif->getTag(37377)->getValues());
			self::assertEqualsWithDelta(-3.5, $exif->getTag(37377)->getRational(), 1e-9);
		}
	}

	public function testIfd1Chain()
	{
		$tiff = $this->sampleDocument(true);
		$ifd1 = new TTIFFIfd();
		$ifd1->setTagValues(513, TTIFFDataType::ULong, [1234]);
		$tiff->addIfd($ifd1);

		$reparsed = TTIFFDocument::fromString($tiff->toBinary());
		self::assertCount(2, $reparsed->getIfds());
		self::assertSame(1234, $reparsed->getIfd(1)->getTagValue(513));
		self::assertNull($reparsed->getIfd(2));
	}

	public function testPreserveOffsetPinsValueArea()
	{
		$tiff = $this->sampleDocument(false);
		$note = str_repeat("\xAB", 64);
		$noteTag = $tiff->getIfd(0)->getTag(TTIFFDocument::ExifIfdTag)->getSubIfd()
			->setTagValues(37500, TTIFFDataType::Undefined, $note);
		$noteTag->setOffset(0x200);
		$noteTag->setPreserveOffset(true);

		$bytes = $tiff->toBinary();
		self::assertSame($note, substr($bytes, 0x200, 64));

		$reparsed = TTIFFDocument::fromString($bytes);
		$reTag = $reparsed->getIfd(0)->getTag(TTIFFDocument::ExifIfdTag)->getSubIfd()->getTag(37500);
		self::assertSame($note, $reTag->getValues());
		self::assertSame(0x200, $reTag->getOffset());
	}

	public function testInlineValuesStayInline()
	{
		$tiff = new TTIFFDocument();
		$ifd = new TTIFFIfd();
		$ifd->setTagValues(305, TTIFFDataType::Ascii, "abc\0");     // exactly 4 bytes: inline
		$ifd->setTagValues(296, TTIFFDataType::UShort, [2]);
		$tiff->addIfd($ifd);
		$bytes = $tiff->toBinary();
		// header(8) + count(2) + 2 entries(24) + next(4) = 38 bytes, nothing out-of-line
		self::assertSame(38, strlen($bytes));
		$reparsed = TTIFFDocument::fromString($bytes);
		self::assertSame('abc', $reparsed->getIfd(0)->getTagValue(305));
		self::assertSame(2, $reparsed->getIfd(0)->getTagValue(296));
	}

	public function testEmptyDocumentComposesABareHeader()
	{
		// With no IFD to point at, the header's first-IFD pointer is zero and the file
		// is the eight header bytes and nothing else.
		$tiff = new TTIFFDocument();
		self::assertSame("MM\x00\x2A\x00\x00\x00\x00", $tiff->toBinary());

		$tiff->setIsBigEndian(false);
		self::assertSame("II\x2A\x00\x00\x00\x00\x00", $tiff->toBinary());

		$reparsed = TTIFFDocument::fromString($tiff->toBinary());
		self::assertSame([], $reparsed->getIfds());
		self::assertSame([], $reparsed->getWarnings());
	}

	public function testReservedSpacesAnswerLowestOffsetFirst()
	{
		// Make's ten-byte value area sits at 52 and Model's at 40, so the pins are
		// collected in tag order but must be reported in offset order.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x02"
			. "\x01\x0F\x00\x02\x00\x00\x00\x0A\x00\x00\x00\x34"   // Make, 10 Ascii at 52
			. "\x01\x10\x00\x02\x00\x00\x00\x0A\x00\x00\x00\x28"   // Model, 10 Ascii at 40
			. "\x00\x00\x00\x00"
			. "\x00\x00"                                           // 38-39 pad
			. "Scanner-1\0"                                        // 40-49
			. "\x00\x00"                                           // 50-51 pad
			. "PradoCam1\0";                                       // 52-61

		$tiff = TTIFFDocument::fromString($bytes);
		self::assertSame('PradoCam1', $tiff->getIfd(0)->getTagValue(271));
		self::assertSame([], $tiff->getReservedSpaces());   // nothing is pinned yet

		$tiff->getIfd(0)->getTag(271)->setPreserveOffset(true);
		$tiff->getIfd(0)->getTag(272)->setPreserveOffset(true);
		self::assertSame([[40, 10], [52, 10]], $tiff->getReservedSpaces());

		// The writer places everything else around exactly those ranges.
		$out = $tiff->toBinary();
		self::assertSame("Scanner-1\0", substr($out, 40, 10));
		self::assertSame("PradoCam1\0", substr($out, 52, 10));
	}

	public function testMalformedHeaderThrows()
	{
		foreach (['', 'short', "XX\x00\x2A\x00\x00\x00\x08", "MM\x00\x2B\x00\x00\x00\x08"] as $bad) {
			try {
				TTIFFDocument::fromString($bad);
				self::fail('parse accepted malformed header');
			} catch (TIOException $e) {
				self::assertSame('tiff_invalid', $e->getErrorCode());
			}
		}
	}

	public function testTolerantParseCollectsWarnings()
	{
		// Valid header; IFD with one entry whose value offset runs past the data.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x01"
			. "\x01\x0F\x00\x02\x00\x00\x00\x20\x00\x00\xFF\x00"   // Ascii count 32 at offset 0xFF00
			. "\x00\x00\x00\x00";
		$tiff = TTIFFDocument::fromString($bytes);
		self::assertNotSame([], $tiff->getWarnings());
		self::assertNull($tiff->getIfd(0)->getTag(271));
	}

	public function testDataTypeSizeRejectsAnUnknownType()
	{
		foreach ([0, 13, 128] as $type) {
			try {
				TTIFFDataType::getSize($type);
				self::fail("getSize accepted data type $type");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('tiff_datatype_invalid', $e->getErrorCode());
			}
		}
	}

	public function testParseWarnsOnALoopingIfdChain()
	{
		// IFD0 at 8 chains to IFD1 at 26, whose next pointer loops back to IFD0.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x01" . "\x01\x0F\x00\x02\x00\x00\x00\x04" . "abc\0" . "\x00\x00\x00\x1A"
			. "\x00\x01" . "\x01\x10\x00\x02\x00\x00\x00\x04" . "def\0" . "\x00\x00\x00\x08";

		$tiff = TTIFFDocument::fromString($bytes);
		self::assertCount(2, $tiff->getIfds());
		self::assertSame('abc', $tiff->getIfd(0)->getTagValue(271));
		self::assertSame('def', $tiff->getIfd(1)->getTagValue(272));
		self::assertNotEmpty(array_filter($tiff->getWarnings(), fn ($w) => str_contains($w, 'loops back to offset 8')));
	}

	public function testParseWarnsOnAnIfdOutsideTheData()
	{
		// A valid header whose first-IFD pointer addresses the very end of the data.
		$tiff = TTIFFDocument::fromString("MM\x00\x2A\x00\x00\x00\x08");
		self::assertSame([], $tiff->getIfds());
		self::assertSame(['IFD offset 8 is outside the data'], $tiff->getWarnings());
	}

	public function testParseClampsAnEntryCountBeyondTheData()
	{
		// The table declares five entries but only one fits in the data.
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x05"
			. "\x01\x0F\x00\x02\x00\x00\x00\x04" . "abc\0"
			. "\x00\x00\x00\x00";

		$tiff = TTIFFDocument::fromString($bytes);
		self::assertSame('abc', $tiff->getIfd(0)->getTagValue(271));
		self::assertCount(1, $tiff->getIfd(0)->getTags());
		self::assertSame(['IFD at 8 declares 5 entries beyond the data'], $tiff->getWarnings());
	}

	public function testParseSkipsAnUnknownDataType()
	{
		$bytes = "MM\x00\x2A\x00\x00\x00\x08"
			. "\x00\x02"
			. "\x01\x0F\x00\x63\x00\x00\x00\x04\x00\x00\x00\x00"    // tag 271, data type 99
			. "\x01\x10\x00\x02\x00\x00\x00\x04" . "def\0"
			. "\x00\x00\x00\x00";

		$tiff = TTIFFDocument::fromString($bytes);
		self::assertNull($tiff->getIfd(0)->getTag(271));
		self::assertSame('def', $tiff->getIfd(0)->getTagValue(272));
		self::assertSame(['tag 271 has unknown data type 99'], $tiff->getWarnings());
	}

	public function testMismatchedOffsetAndCountTagsAreNotCaptured()
	{
		$tiff = new TTIFFDocument();
		$ifd = new TTIFFIfd();
		$ifd->setTagValues(256, TTIFFDataType::ULong, [4]);
		$ifd->setTagValues(273, TTIFFDataType::ULong, [8, 8]);   // two strip offsets
		$ifd->setTagValues(279, TTIFFDataType::ULong, [4]);      // but one byte count
		$tiff->addIfd($ifd);

		$reparsed = TTIFFDocument::fromString($tiff->toBinary());
		self::assertNull($reparsed->getIfd(0)->getTag(273)->getExternalData());
		self::assertSame(['tag 273 has 2 offsets but 1 byte counts'], $reparsed->getWarnings());
	}

	public function testScannedMismatchedOffsetAndCountTagsAreNotDeferred()
	{
		$tiff = new TTIFFDocument();
		$ifd = new TTIFFIfd();
		$ifd->setTagValues(256, TTIFFDataType::ULong, [4]);
		$ifd->setTagValues(273, TTIFFDataType::ULong, [8, 8]);   // two strip offsets
		$ifd->setTagValues(279, TTIFFDataType::ULong, [4]);      // but one byte count
		$tiff->addIfd($ifd);

		$scanned = TTIFFDocument::scanStream(TStream::fromString($tiff->toBinary()), null, 16777216, true);
		self::assertNull($scanned->getIfd(0)->getTag(273)->getExternalData());
		self::assertSame(['tag 273 has 2 offsets but 1 byte counts'], $scanned->getWarnings());
	}

	public function testByteCountsTagIsRetypedForExternalData()
	{
		// A byte-counts tag of an inapplicable type is retyped to ULong on compose.
		$strip = str_repeat("\xC3", 40);
		$tiff = new TTIFFDocument();
		$ifd = new TTIFFIfd();
		$offsets = $ifd->setTagValues(273, TTIFFDataType::ULong, [0]);
		$ifd->setTagValues(279, TTIFFDataType::Ascii, "40\0");
		$offsets->setExternalData([$strip]);
		$tiff->addIfd($ifd);

		$reparsed = TTIFFDocument::fromString($tiff->toBinary());
		$counts = $reparsed->getIfd(0)->getTag(279);
		self::assertSame(TTIFFDataType::ULong, $counts->getType());
		self::assertSame([40], $counts->getValues());
		self::assertSame([$strip], $reparsed->getIfd(0)->getTag(273)->getExternalData());
	}

	public function testTagConvenienceAccessors()
	{
		$tag = new TTIFFTag(305, TTIFFDataType::Ascii, "Prado\0");
		self::assertSame('Prado', $tag->getValue());
		self::assertSame(6, $tag->getCount());

		$multi = new TTIFFTag(532, TTIFFDataType::ULong, [1, 2, 3]);
		self::assertSame([1, 2, 3], $multi->getValue());
		self::assertNull($multi->getRational());

		$ifd = new TTIFFIfd();
		$ifd->setTag($tag);
		self::assertTrue($ifd->hasTag(305));
		self::assertSame('Prado', $ifd->getTagValue(305));
		self::assertSame($tag, $ifd->removeTag(305));
		self::assertFalse($ifd->hasTag(305));
		self::assertNull($ifd->getTagValue(305));
	}
}
