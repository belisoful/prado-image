<?php

use Prado\IO\Image\TPhotoshop8BIM;
use Prado\IO\Image\TPhotoshopIRB;
use Prado\IO\Image\TPhotoshopResource;
use Prado\IO\Image\TPhotoshopResourceNames;

class TPhotoshopDecodersTest extends PHPUnit\Framework\TestCase
{
	private function psUnicode(string $utf8): string
	{
		$utf16 = mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');
		return pack('N', intdiv(strlen($utf16), 2)) . $utf16;
	}

	public function testGridGuidesDecoder()
	{
		$data = pack('N', 1) . pack('N', 576) . pack('N', 576) . pack('N', 2)
			. pack('N', 320) . "\x00"    // vertical guide at 10px (32nds)
			. pack('N', 640) . "\x01";   // horizontal guide at 20px
		$resource = new TPhotoshopResource(TPhotoshopResource::GridAndGuides, $data);
		$decoded = $resource->decodeGridGuides();
		self::assertSame(1, $decoded['version']);
		self::assertSame(576, $decoded['gridHorizontal']);
		self::assertCount(2, $decoded['guides']);
		self::assertSame(10.0, $decoded['guides'][0]['location']);
		self::assertSame('vertical', $decoded['guides'][0]['direction']);
		self::assertSame('horizontal', $decoded['guides'][1]['direction']);

		self::assertNull((new TPhotoshopResource(TPhotoshopResource::GridAndGuides, 'short'))->decodeGridGuides());
	}

	public function testVersionInfoDecoder()
	{
		$data = pack('N', 1) . "\x01" . $this->psUnicode('Adobe Photoshop') . $this->psUnicode('Reader ✓') . pack('N', 3);
		$resource = new TPhotoshopResource(TPhotoshopResource::VersionInfo, $data);
		$decoded = $resource->decodeVersionInfo();
		self::assertSame(1, $decoded['version']);
		self::assertTrue($decoded['hasRealMergedData']);
		self::assertSame('Adobe Photoshop', $decoded['writer']);
		self::assertSame('Reader ✓', $decoded['reader']);
		self::assertSame(3, $decoded['fileVersion']);

		self::assertNull((new TPhotoshopResource(TPhotoshopResource::VersionInfo, "\x00"))->decodeVersionInfo());
	}

	public function testIrbThumbnailAndICCAccessors()
	{
		$jpeg = "\xFF\xD8FAKETHUMB\xFF\xD9";
		$thumbData = pack('N', 1) . pack('N', 16) . pack('N', 12) . pack('N', 48) . pack('N', 576)
			. pack('N', strlen($jpeg)) . pack('n', 24) . pack('n', 1) . $jpeg;
		$irb = new TPhotoshopIRB();
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::Thumbnail5, $thumbData));
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::ICCProfile, 'ICCBYTES'));

		self::assertSame($jpeg, $irb->getThumbnail());
		self::assertSame('ICCBYTES', $irb->getICCProfile());

		// The Photoshop 4.0 form is the fallback.
		$irb4 = new TPhotoshopIRB();
		$irb4->setResource(new TPhotoshopResource(TPhotoshopResource::Thumbnail4, $thumbData));
		self::assertSame($jpeg, $irb4->getThumbnail());

		self::assertNull((new TPhotoshopIRB())->getThumbnail());
		self::assertNull((new TPhotoshopIRB())->getICCProfile());
	}

	public function testIrbCollectionAndNames()
	{
		$irb = new TPhotoshopIRB();
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::Url, 'https://a.example'));
		$irb->setResource(new TPhotoshopResource(0x07D5, 'pathdata', 'clip'));

		self::assertTrue(TPhotoshopIRB::isIRB(TPhotoshopIRB::Signature . '8BIMxxxx'));
		self::assertTrue(TPhotoshopIRB::isIRB('8BIMxxxx'));
		self::assertFalse(TPhotoshopIRB::isIRB('not photoshop'));

		self::assertCount(2, $irb->getResources());
		$seen = [];
		foreach ($irb as $resource) {
			$seen[] = $resource->getId();
		}
		self::assertSame([TPhotoshopResource::Url, 0x07D5], $seen);

		$path = $irb->getResource(0x07D5);
		self::assertSame('Path Information', $path->getResourceName());
		self::assertNotNull($path->getDescription());
		self::assertSame('Saved working path information', TPhotoshopResourceNames::describe(0x0800));
		self::assertNotNull(TPhotoshopResourceNames::describe(TPhotoshopResource::IptcNaa));
		self::assertNull(TPhotoshopResourceNames::nameOf(0xEEEE));

		$path->setName('renamed');
		$path->setData('newdata');
		self::assertSame('renamed', $path->getName());
		self::assertSame('newdata', $path->getData());

		self::assertTrue($irb->removeResource(0x07D5));
		self::assertFalse($irb->removeResource(0x07D5));
		self::assertCount(1, $irb);
	}

	public function testResolutionAndJpegQualityDecoders()
	{
		$resolution = new TPhotoshopResource(
			TPhotoshopResource::ResolutionInfo,
			pack('N', 72 * 0x10000) . pack('n', 1) . pack('n', 2) . pack('N', 300 * 0x10000) . pack('n', 1) . pack('n', 3),
		);
		$decoded = $resolution->decodeResolutionInfo();
		self::assertEqualsWithDelta(72.0, $decoded['hRes'], 1e-9);
		self::assertEqualsWithDelta(300.0, $decoded['vRes'], 1e-9);
		self::assertSame(2, $decoded['widthUnit']);
		self::assertSame(3, $decoded['heightUnit']);

		$quality = fn (int $scale, int $format) => (new TPhotoshopResource(
			TPhotoshopResource::JpegQuality,
			pack('n', $scale) . pack('n', $format) . pack('n', 1),
		))->decodeJpegQuality();

		// The stored scale is a signed offset: 0xFFFD..0x0008 maps to quality 1..12.
		self::assertSame(12, $quality(0x0008, 0x0000)['quality']);
		self::assertSame(1, $quality(0xFFFD, 0x0000)['quality']);
		self::assertSame(3, $quality(0x0008, 0x0000)['progressiveScans']);
		self::assertSame('Standard', $quality(0x0008, 0x0000)['format']);
		self::assertSame('Optimised', $quality(0x0008, 0x0001)['format']);
		self::assertSame('Unknown (0x0202)', $quality(0x0008, 0x0202)['format']);

		// Payloads shorter than the fixed field set decode to nothing.
		self::assertNull((new TPhotoshopResource(TPhotoshopResource::ResolutionInfo, str_repeat("\x00", 15)))->decodeResolutionInfo());
		self::assertNull((new TPhotoshopResource(TPhotoshopResource::JpegQuality, "\x00\x08\x00"))->decodeJpegQuality());
		self::assertNull((new TPhotoshopResource(TPhotoshopResource::Thumbnail5, str_repeat("\x00", 27)))->decodeThumbnail());
	}

	public function testVersionInfoWithATruncatedUnicodeString()
	{
		// The writer string is complete; the reader string's length field runs past the end.
		$data = pack('N', 1) . "\x01" . $this->psUnicode('Adobe Photoshop');
		$decoded = (new TPhotoshopResource(TPhotoshopResource::VersionInfo, $data))->decodeVersionInfo();
		self::assertSame('Adobe Photoshop', $decoded['writer']);
		self::assertSame('', $decoded['reader']);
		self::assertNull($decoded['fileVersion']);
	}

	public function testLegacy8BimHelpers()
	{
		$iptcPayload = "\x1C\x02\x05\x00\x04Test";
		$wrapped = TPhotoshop8BIM::iptcEncode($iptcPayload);
		self::assertTrue(TPhotoshop8BIM::isPhotoshop($wrapped));

		// String decode narrows to the payload.
		$copy = $wrapped;
		$length = TPhotoshop8BIM::iptcDecode($copy);
		self::assertSame(strlen($iptcPayload), $length);
		self::assertSame($iptcPayload, substr($copy, 0, $length));

		// Stream decode positions at the payload.
		$stream = fopen('php://memory', 'w+b');
		fwrite($stream, $wrapped);
		rewind($stream);
		$length = TPhotoshop8BIM::iptcDecode($stream);
		self::assertSame(strlen($iptcPayload), $length);
		self::assertSame($iptcPayload, fread($stream, $length));
		fclose($stream);

		// Non-Photoshop data answers null; a bad type throws.
		$other = 'plain data';
		self::assertNull(TPhotoshop8BIM::iptcDecode($other));
		self::expectException(Prado\Exceptions\TInvalidDataTypeException::class);
		$bad = 42;
		TPhotoshop8BIM::iptcDecode($bad);
	}
}
