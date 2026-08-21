<?php

use Prado\IO\Compression\TCCITTFaxCompressor;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TFileInfo;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TPIM;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TIFF\TTIFFDocument;
use Prado\IO\Image\TIFF\TTIFFIfd;
use Prado\IO\Image\TImageChunk;

class TSmallApiTest extends PHPUnit\Framework\TestCase
{
	public function testCcittStaticInterfaceWrappers()
	{
		$rowBytes = 8;
		$data = str_repeat("\xF0", $rowBytes) . str_repeat("\x0F", $rowBytes);
		$encoded = TCCITTFaxCompressor::compress($data, 64, TCCITTFaxCompressor::Group4);
		self::assertSame(bin2hex($data), bin2hex(TCCITTFaxCompressor::decompress($encoded, 64, TCCITTFaxCompressor::Group4)));
		self::assertSame(bin2hex(substr($data, 0, $rowBytes)), bin2hex(TCCITTFaxCompressor::decompress($encoded, 64, TCCITTFaxCompressor::Group4, 1)));

		$codec = new TCCITTFaxCompressor(64, TCCITTFaxCompressor::Group3);
		self::assertSame(64, $codec->getColumns());
		self::assertSame(TCCITTFaxCompressor::Group3, $codec->getMode());
		self::assertSame(8, $codec->getRowBytes());
	}

	public function testTiffDocumentAndIfdCollectionApi()
	{
		$tiff = new TTIFFDocument();
		$ifd0 = new TTIFFIfd();
		$ifd0->setTagValues(271, TTIFFDataType::Ascii, "A\0");
		$ifd1 = new TTIFFIfd();
		$ifd1->setTagValues(305, TTIFFDataType::Ascii, "B\0");
		$tiff->addIfd($ifd0);
		$tiff->addIfd($ifd1);

		self::assertSame($ifd1, $tiff->removeIfd(1));
		self::assertNull($tiff->removeIfd(5));
		self::assertCount(1, $tiff->getIfds());

		self::assertSame(1, count($ifd0));
		$tags = [];
		foreach ($ifd0 as $id => $tag) {
			$tags[$id] = $tag->getValue();
		}
		self::assertSame([271 => 'A'], $tags);
	}

	public function testImageChunkAccessors()
	{
		$chunk = new TImageChunk('IDAT', 5, 1234, 'bytes');
		self::assertSame('IDAT', $chunk->getType());
		self::assertSame(5, $chunk->getSize());
		self::assertSame(1234, $chunk->getOffset());
		self::assertSame('bytes', $chunk->getData());
	}

	public function testXmpDomAndBinaryAccessors()
	{
		$xmp = TXMP::blank();
		$xmp->setTitle('Dom');
		self::assertInstanceOf(DOMDocument::class, $xmp->getDom());
		self::assertSame($xmp->toPacketText(), $xmp->toBinary());
		self::assertStringContainsString('Dom', $xmp->toBinary());
	}

	public function testFileInfoFieldsDump()
	{
		$info = new TFileInfo();
		$info['title'] = 'T';
		$info['keywords'] = ['k'];
		self::assertSame(['title' => 'T', 'keywords' => ['k']], $info->getFields());
	}

	public function testIptcCommonMetadataBridges()
	{
		$iptc = new TIPTC();
		self::assertFalse($iptc->hasXMP());
		self::assertNull($iptc->getXMP());
		self::assertFalse($iptc->setXMP('ignored'));
	}

	public function testExifSignatureAndInteropCreation()
	{
		$exif = new TEXIF();
		self::assertSame(TEXIF::ExifSignature, $exif->getSignature());
		$exif->setSignature(TEXIF::MetaSignature);
		self::assertTrue($exif->getIsMeta());
		$exif->setSignature('');
		self::assertSame('', $exif->getSignature());

		self::assertNull($exif->getInteropIfd());
		$interop = $exif->getInteropIfd(true);
		self::assertNotNull($interop);
		$interop->setTagValues(1, TTIFFDataType::Ascii, "R98\0");
		$reparsed = TEXIF::fromSegment(TEXIF::ExifSignature . $exif->toBinary());
		self::assertSame('R98', $reparsed->getInteropIfd()->getTagValue(1));

		self::expectException(Prado\Exceptions\TInvalidDataValueException::class);
		$exif->setSignature('BOGUS');
	}

	public function testGraphicsLibraryEnumeration()
	{
		self::assertSame('GD', TImageGraphicsMode::GD);
		self::assertSame('Imagick', TImageGraphicsMode::Imagick);
	}

	public function testPimVersionAndTruncation()
	{
		$pim = new TPIM();
		$pim->setVersion('0250');
		self::assertSame('0250', $pim->getVersion());

		// A truncated block (signature only) parses to an empty, versioned PIM.
		$truncated = TPIM::parse(TPIM::Signature . '0300');
		self::assertNotFalse($truncated);
		self::assertSame([], $truncated->getEntries());
	}

	public function testStreamFilterNames()
	{
		self::assertNotSame('', Prado\IO\Compression\TLZWFilter::getFilterName());
		self::assertNotSame('', Prado\IO\Compression\TPackBitsFilter::getFilterName());
		self::assertNotSame('', Prado\IO\Compression\THorizontalPredictorFilter::getFilterName());
	}

	public function testExifTiffFileAndXmpBridge()
	{
		$exif = new TEXIF();
		$exif->setValueByName('Make', 'FileCam');
		$xmp = TXMP::blank();
		$xmp->setTitle('Embedded XMP');
		$exif->setXMP($xmp);

		$path = tempnam(sys_get_temp_dir(), 'exiftiff');
		try {
			file_put_contents($path, $exif->getTiff()->toBinary());
			$fromFile = TEXIF::fromTiffFile($path);
			self::assertSame('FileCam', $fromFile->getMake());
			self::assertNotNull($fromFile->getXMP());
			self::assertSame('Embedded XMP', $fromFile->getXMP()->getTitle());

			$fromFile->setXMP(null);
			self::assertNull($fromFile->getXmpText());
		} finally {
			@unlink($path);
		}

		self::expectException(Prado\Exceptions\TIOException::class);
		TEXIF::fromTiffFile('/nonexistent/nope.tif');
	}

	public function testTtiffExposesUnderlyingDocument()
	{
		$exif = new TEXIF();
		$exif->getIfd0()->setTagValues(Prado\IO\Image\TTIFF::WidthTag, TTIFFDataType::ULong, [5]);
		$exif->getIfd0()->setTagValues(Prado\IO\Image\TTIFF::HeightTag, TTIFFDataType::ULong, [5]);
		$tiff = Prado\IO\Image\TTIFF::fromString($exif->getTiff()->toBinary());
		self::assertInstanceOf(TTIFFDocument::class, $tiff->getTiff());
		self::assertSame(5, $tiff->getTiff()->getIfd(0)->getTagValue(256));
	}

	public function testGraphicsClosestPaletteIndex()
	{
		// The over-budget color mapping of the Imagick quantizer; pure arithmetic, so it
		// runs without the extension.
		$exposed = new class () extends Prado\IO\Image\TImageGraphicsImagick {
			public function closest(string $palette, int $r, int $g, int $b): int
			{
				return $this->closestPaletteIndex($palette, $r, $g, $b);
			}
		};
		$palette = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF";
		self::assertSame(0, $exposed->closest($palette, 250, 10, 10));
		self::assertSame(1, $exposed->closest($palette, 10, 250, 10));
		self::assertSame(2, $exposed->closest($palette, 40, 40, 220));
	}

	public function testTiffWriterWidensShortByteCounts()
	{
		// A UShort StripByteCounts must widen to ULong when a strip outgrows 65535.
		$strip = str_repeat("\xCD", 70000);
		$tiff = new TTIFFDocument();
		$ifd = new TTIFFIfd();
		$offsets = $ifd->setTagValues(273, TTIFFDataType::ULong, [0]);
		$ifd->setTagValues(279, TTIFFDataType::UShort, [0]);
		$offsets->setExternalData([$strip]);
		$tiff->addIfd($ifd);

		$reparsed = TTIFFDocument::fromString($tiff->toBinary());
		$counts = $reparsed->getIfd(0)->getTag(279);
		self::assertSame(TTIFFDataType::ULong, $counts->getType());
		self::assertSame([70000], $counts->getValues());
		self::assertSame([$strip], $reparsed->getIfd(0)->getTag(273)->getExternalData());
	}

	public function testTiffWriterIgnoresInvalidPin()
	{
		// A pin below the 8-byte header cannot be honored and is placed normally.
		$tiff = new TTIFFDocument();
		$ifd = new TTIFFIfd();
		$tag = $ifd->setTagValues(37500, TTIFFDataType::Undefined, str_repeat("\xEE", 32));
		$tag->setOffset(2);
		$tag->setPreserveOffset(true);
		$tiff->addIfd($ifd);

		$reparsed = TTIFFDocument::fromString($tiff->toBinary());
		self::assertSame(str_repeat("\xEE", 32), $reparsed->getIfd(0)->getTag(37500)->getValues());
		self::assertGreaterThanOrEqual(8, $reparsed->getIfd(0)->getTag(37500)->getOffset());
	}
}
