<?php

use Prado\IO\Image\Meta\TEXIFTags;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TIFF\TTIFFTag;

class TEXIFInterpretTest extends PHPUnit\Framework\TestCase
{
	public function testCodedStringUserComment()
	{
		$ascii = new TTIFFTag(37510, TTIFFDataType::Undefined, "ASCII\x00\x00\x00Hello EXIF\x00");
		self::assertSame('Hello EXIF', TEXIFTags::textValue($ascii, TEXIFTags::EXIF));

		$unicode = new TTIFFTag(37510, TTIFFDataType::Undefined, "UNICODE\x00" . mb_convert_encoding('Ünïcode ✓', 'UTF-16BE', 'UTF-8'));
		self::assertSame('Ünïcode ✓', TEXIFTags::textValue($unicode, TEXIFTags::EXIF));

		$unknown = new TTIFFTag(37510, TTIFFDataType::Undefined, "\x00\x00\x00\x00\x00\x00\x00\x00raw text\x00");
		self::assertStringContainsString('raw text', (string) TEXIFTags::textValue($unknown, TEXIFTags::EXIF));
	}

	public function testCodedStringUnicodeByteOrderIsNamedNotGuessed()
	{
		// 'UTF-16' without a byte-order mark is big-endian by the Unicode default, and
		// that is what encodeCodedString() writes -- but iconv's bare 'UTF-16' resolves
		// the default per platform (libiconv big-endian, glibc little-endian), so an
		// unmarked comment must decode the same way everywhere.
		$text = 'Ünïcode ✓';
		$be = new TTIFFTag(37510, TTIFFDataType::Undefined, "UNICODE\0" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
		self::assertSame($text, TEXIFTags::textValue($be, TEXIFTags::EXIF));

		// A writer that left a mark is believed, in either order.
		$markedBe = new TTIFFTag(37510, TTIFFDataType::Undefined, "UNICODE\0" . "\xFE\xFF" . mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'));
		self::assertSame($text, TEXIFTags::textValue($markedBe, TEXIFTags::EXIF));

		$markedLe = new TTIFFTag(37510, TTIFFDataType::Undefined, "UNICODE\0" . "\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));
		self::assertSame($text, TEXIFTags::textValue($markedLe, TEXIFTags::EXIF));

		// The round trip through this library's own writer holds.
		self::assertSame($text, TEXIFTags::decodeCodedString(TEXIFTags::encodeCodedString($text, 'UNICODE')));
	}

	public function testComponentsConfiguration()
	{
		$tag = new TTIFFTag(37121, TTIFFDataType::Undefined, "\x01\x02\x03\x00");
		self::assertSame('Y Cb Cr -', TEXIFTags::textValue($tag, TEXIFTags::EXIF));

		$rgb = new TTIFFTag(37121, TTIFFDataType::Undefined, "\x04\x05\x06\x00");
		self::assertSame('R G B -', TEXIFTags::textValue($rgb, TEXIFTags::EXIF));
	}

	public function testYCbCrSubSampling()
	{
		$tag = new TTIFFTag(530, TTIFFDataType::UShort, [2, 2]);
		self::assertSame('YCbCr 4:2:0', TEXIFTags::textValue($tag, TEXIFTags::TIFF));
		$tag = new TTIFFTag(530, TTIFFDataType::UShort, [2, 1]);
		self::assertSame('YCbCr 4:2:2', TEXIFTags::textValue($tag, TEXIFTags::TIFF));
		$odd = new TTIFFTag(530, TTIFFDataType::UShort, [3, 3]);
		self::assertSame('3,3', TEXIFTags::textValue($odd, TEXIFTags::TIFF));
	}

	public function testCfaPattern()
	{
		// 2x2 RGGB grid: columns(2) rows(2) then the color indices.
		$tag = new TTIFFTag(41730, TTIFFDataType::Undefined, "\x00\x02\x00\x02\x00\x01\x01\x02");
		self::assertSame('RG / GB', TEXIFTags::textValue($tag, TEXIFTags::EXIF));

		$short = new TTIFFTag(41730, TTIFFDataType::Undefined, "\x00");
		self::assertNull(TEXIFTags::textValue($short, TEXIFTags::EXIF));
	}

	public function testNumericFormsAndUnits()
	{
		// A reciprocal rational renders as a fraction with units.
		$exposure = new TTIFFTag(33434, TTIFFDataType::URational, [[1, 60]]);
		self::assertSame('1/60 seconds', TEXIFTags::textValue($exposure, TEXIFTags::EXIF));

		// A plain quotient renders decimal (FNumber has no units).
		$fnumber = new TTIFFTag(33437, TTIFFDataType::URational, [[71, 10]]);
		self::assertSame('7.1', TEXIFTags::textValue($fnumber, TEXIFTags::EXIF));

		// Division by zero keeps the raw fraction.
		$broken = new TTIFFTag(33437, TTIFFDataType::URational, [[5, 0]]);
		self::assertSame('5/0', TEXIFTags::textValue($broken, TEXIFTags::EXIF));

		// Multi-value integers join.
		$bits = new TTIFFTag(258, TTIFFDataType::UShort, [8, 8, 8]);
		self::assertStringContainsString('8, 8, 8', (string) TEXIFTags::textValue($bits, TEXIFTags::TIFF));
	}

	public function testLookupUnknownValueAndStringTrim()
	{
		$orientation = new TTIFFTag(274, TTIFFDataType::UShort, [99]);
		self::assertSame('Unknown (99)', TEXIFTags::textValue($orientation, TEXIFTags::TIFF));

		$make = new TTIFFTag(271, TTIFFDataType::Ascii, "  PradoCam \0\0");
		self::assertSame('PradoCam', TEXIFTags::textValue($make, TEXIFTags::TIFF));
	}

	public function testGpsTimestampAndAltitudeStyleValues()
	{
		$time = new TTIFFTag(7, TTIFFDataType::URational, [[14, 1], [30, 1], [5, 1]]);
		self::assertSame('14:30:05', TEXIFTags::textValue($time, TEXIFTags::GPS));

		// A non-composite GPS rational falls back to numeric text with its units.
		$altitude = new TTIFFTag(6, TTIFFDataType::URational, [[125, 10]]);
		self::assertSame('12.5 Metres with respect to Altitude Reference', TEXIFTags::textValue($altitude, TEXIFTags::GPS));
	}

	public function testPointerTagsHaveNoText()
	{
		$pointer = new TTIFFTag(34665, TTIFFDataType::ULong, [1234]);
		self::assertNull(TEXIFTags::textValue($pointer, TEXIFTags::TIFF));
		$makernote = new TTIFFTag(37500, TTIFFDataType::Undefined, 'opaque');
		self::assertNull(TEXIFTags::textValue($makernote, TEXIFTags::EXIF));
	}

	public function testCodedStringJisAndTruncatedUnicode()
	{
		// The JIS (ISO-2022-JP) charset of the coded-string form round trips.
		$coded = TEXIFTags::encodeCodedString('日本語', 'JIS');
		self::assertStringStartsWith("JIS\0\0\0\0\0", $coded);
		self::assertSame("\x1B\x24\x42", substr($coded, 8, 3));   // the JIS X 0208 escape
		self::assertSame('日本語', TEXIFTags::decodeCodedString($coded));

		$tag = new TTIFFTag(37510, TTIFFDataType::Undefined, $coded);
		self::assertSame('日本語', TEXIFTags::textValue($tag, TEXIFTags::EXIF));

		// A UNICODE payload truncated mid-character decodes to nothing rather than
		// raising: neither the BOM-sensing nor the big-endian pass can convert it.
		$odd = new TTIFFTag(37510, TTIFFDataType::Undefined, "UNICODE\0" . "\x00A\x00B\x00");
		self::assertSame('', TEXIFTags::textValue($odd, TEXIFTags::EXIF));
	}

	public function testNumericTextOfByteStringValues()
	{
		// A numeric tag a camera wrote as Undefined bytes renders its byte values.
		$width = new TTIFFTag(256, TTIFFDataType::Undefined, "\x01\x02\xFF");
		self::assertSame('1 2 255 pixels', TEXIFTags::textValue($width, TEXIFTags::TIFF));

		$empty = new TTIFFTag(256, TTIFFDataType::Undefined, '');
		self::assertSame(' pixels', TEXIFTags::textValue($empty, TEXIFTags::TIFF));
	}

	public function testCfaPatternGridShorterThanItsDimensions()
	{
		// 4x4 declared but only one colour byte present: no partial grid is invented.
		$tag = new TTIFFTag(41730, TTIFFDataType::Undefined, "\x00\x04\x00\x04\x00");
		self::assertNull(TEXIFTags::textValue($tag, TEXIFTags::EXIF));
	}

	public function testJpegInterchangeFormatHasNoNumericText()
	{
		// The IFD1 thumbnail pointer names its payload instead of printing an offset.
		$tag = new TTIFFTag(513, TTIFFDataType::ULong, [1024]);
		self::assertSame('JPEG thumbnail data', TEXIFTags::textValue($tag, TEXIFTags::TIFF));
	}

	public function testGpsCompositeFallbacks()
	{
		// A coordinate with a zero-denominator second falls back to the raw fractions.
		$broken = new TTIFFTag(2, TTIFFDataType::URational, [[34, 1], [3, 1], [30, 0]]);
		self::assertSame('34, 3, 30/0 (Degrees Minutes Seconds North or South)', TEXIFTags::textValue($broken, TEXIFTags::GPS));

		// A three-rational GPS tag that is not a coordinate or the timestamp keeps its
		// numeric rendering (GPSDestLatitude has no composite decoder).
		$dest = new TTIFFTag(20, TTIFFDataType::URational, [[10, 1], [20, 1], [30, 1]]);
		self::assertSame('10, 20, 30 (Degrees Minutes Seconds North or South)', TEXIFTags::textValue($dest, TEXIFTags::GPS));
	}

	public function testSpecialDecodersWithTheOtherValueShape()
	{
		// The same four tags a camera may write either as Undefined bytes or as a
		// numeric array; each decoder reads the shape it is given.
		$components = new TTIFFTag(37121, TTIFFDataType::UByte, [1, 2, 3, 0]);
		self::assertSame('Y Cb Cr -', TEXIFTags::textValue($components, TEXIFTags::EXIF));

		$cfa = new TTIFFTag(41730, TTIFFDataType::UByte, [0, 2, 0, 2, 0, 1, 1, 2]);
		self::assertSame('RG / GB', TEXIFTags::textValue($cfa, TEXIFTags::EXIF));

		// The subsampling pair is a numeric pair by definition: bytes carry no pair.
		$subSampling = new TTIFFTag(530, TTIFFDataType::Undefined, "\x00\x02\x00\x01");
		self::assertSame('', TEXIFTags::textValue($subSampling, TEXIFTags::TIFF));

		// And the learning block is a byte string: an array form carries no sets.
		$learning = new TTIFFTag(37511, TTIFFDataType::UByte, [0, 1, 0, 0, 0, 2]);
		self::assertNull(TEXIFTags::textValue($learning, TEXIFTags::EXIF));
	}

	public function testStringTagWrittenWithANumericType()
	{
		// A text tag a camera wrote with a numeric type still renders: the values join
		// rather than being dropped for having the wrong shape.
		$make = new TTIFFTag(271, TTIFFDataType::UShort, [12, 34]);
		self::assertSame('1234', TEXIFTags::textValue($make, TEXIFTags::TIFF));
	}

	public function testSignedRationalCoordinate()
	{
		// A coordinate written with the signed rational type decodes the same way the
		// spec's unsigned form does.
		$signed = new TTIFFTag(2, TTIFFDataType::SRational, [[34, 1], [3, 1], [3000, 100]]);
		self::assertSame('34° 3\' 30"', TEXIFTags::textValue($signed, TEXIFTags::GPS));

		// A signed rational that is not three long has no composite form.
		$pair = new TTIFFTag(2, TTIFFDataType::SRational, [[34, 1], [3, 1]]);
		self::assertStringNotContainsString('°', (string) TEXIFTags::textValue($pair, TEXIFTags::GPS));

		// Nor has a GPS tag that is no rational at all: GPSVersionID is four bytes.
		$version = new TTIFFTag(0, TTIFFDataType::UByte, [2, 3, 0, 0]);
		self::assertStringStartsWith('2, 3, 0, 0', (string) TEXIFTags::textValue($version, TEXIFTags::GPS));
	}

	public function testCodedStringThatCannotBeConverted()
	{
		// Text ending mid-character cannot be converted at all (iconv answers false
		// whether or not it is asked to ignore): the charset marker is written with an
		// empty payload rather than raising.
		self::assertSame("UNICODE\0", TEXIFTags::encodeCodedString("abc\xE2", 'UNICODE'));
		self::assertSame("JIS\0\0\0\0\0", TEXIFTags::encodeCodedString("abc\xE2", 'JIS'));

		// And a JIS payload that stops inside an escape sequence passes through as the
		// stored bytes instead of decoding to nothing.
		$coded = "JIS\0\0\0\0\0" . "\x1B\x24";
		self::assertSame("\x1B\x24", TEXIFTags::decodeCodedString($coded));
		$tag = new TTIFFTag(37510, TTIFFDataType::Undefined, $coded);
		self::assertSame("\x1B\x24", TEXIFTags::textValue($tag, TEXIFTags::EXIF));
	}

	public function testFindByNameGroupScoping()
	{
		self::assertSame([TEXIFTags::GPS, 2], TEXIFTags::findByName('GPSLatitude', TEXIFTags::GPS));
		self::assertNull(TEXIFTags::findByName('GPSLatitude', TEXIFTags::EXIF));
		self::assertSame('ExposureTime', TEXIFTags::nameOf(TEXIFTags::EXIF, 33434));
	}
}
