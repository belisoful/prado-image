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

	public function testFindByNameGroupScoping()
	{
		self::assertSame([TEXIFTags::GPS, 2], TEXIFTags::findByName('GPSLatitude', TEXIFTags::GPS));
		self::assertNull(TEXIFTags::findByName('GPSLatitude', TEXIFTags::EXIF));
		self::assertSame('ExposureTime', TEXIFTags::nameOf(TEXIFTags::EXIF, 33434));
	}
}
