<?php

use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\Meta\TJFIF;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPhotoshop8BIM;

/**
 * Segment-level tests for the {@see TJPEG} marker walk and the compose side: malformed
 * and unusual marker layouts, the multi-segment carriers (extended XMP, JUMBF, the
 * legacy Photoshop IPTC block), and the segment kinds whose metadata was dropped or
 * newly injected between the parse and the rewrite.
 */
class TJPEGSegmentsTest extends PHPUnit\Framework\TestCase
{
	/** @var string A start-of-frame: 8-bit precision, height 16, width 24, one component. */
	private const SOF = "\xFF\xC0\x00\x0B\x08\x00\x10\x00\x18\x01\x01\x11\x00";

	/** @var string A start-of-scan header for the one component. */
	private const SOS = "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00";

	/** Builds a minimal 24x16 JPEG, optionally with segments injected after the SOI. */
	private function minimalJpeg(string $segments = ''): string
	{
		return "\xFF\xD8" . $segments . self::SOF . self::SOS . 'scandata' . "\xFF\xD9";
	}

	/** Builds one marker segment (marker, length, payload). */
	private function segment(int $marker, string $payload): string
	{
		return chr(TJPEG::MARKER_PREFIX) . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
	}

	private function gdImage(int $w, int $h)
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 10, 120, 200));
		return $im;
	}

	/** Builds one IPTC IIM dataset record for record 2 (application). */
	private function iimRecord(int $dataset, string $value): string
	{
		return "\x1C\x02" . chr($dataset) . pack('n', strlen($value)) . $value;
	}

	private function jpegBytes(int $w = 24, int $h = 24): string
	{
		$im = $this->gdImage($w, $h);
		ob_start();
		imagejpeg($im, null, 90);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testMarkerWalkSkipsFillBytesAndStandaloneMarkers()
	{
		// Padding that is not a marker is stepped over one byte at a time...
		$padded = TJPEG::fromString("\xFF\xD8" . "\x00\x00" . substr($this->minimalJpeg(), 2));
		self::assertSame(24, $padded->getWidth());
		self::assertSame(16, $padded->getHeight());
		self::assertCount(1, $padded->getSegments());   // only the SOF was recorded
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($padded->toBinary()));

		// ...and a standalone marker (no length field, no payload) two bytes at a time.
		$standalone = TJPEG::fromString($this->minimalJpeg("\xFF\x01"));   // TEM
		self::assertSame(24, $standalone->getWidth());
		self::assertSame(16, $standalone->getHeight());
		self::assertCount(1, $standalone->getSegments());
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($standalone->toBinary()));
	}

	public function testTruncatedSegmentHeaderStopsTheWalk()
	{
		// An APP0 marker with no room for its length field: both walks stop there.
		$jpeg = TJPEG::fromString("\xFF\xD8\xFF\xE0");
		self::assertNull($jpeg->getWidth());
		self::assertNull($jpeg->getHeight());
		self::assertSame([], $jpeg->getSegments());
		self::assertSame('', $jpeg->getScan());
		self::assertSame(bin2hex("\xFF\xD8"), bin2hex($jpeg->toBinary()));
	}

	public function testUnterminatedScanRunsToEndOfFile()
	{
		// Entropy data with no following marker and no EOI: the scan is the rest of the file.
		$bytes = "\xFF\xD8" . self::SOF . self::SOS . 'scandata-without-a-marker';
		$jpeg = TJPEG::fromString($bytes);
		self::assertSame(24, $jpeg->getWidth());
		self::assertSame(16, $jpeg->getHeight());
		self::assertStringStartsWith("\xFF\xDA", $jpeg->getScan());
		self::assertSame(bin2hex($bytes), bin2hex($jpeg->toBinary()));
	}

	public function testCommentAppendedWhenNoneExists()
	{
		$jpeg = TJPEG::fromString($this->minimalJpeg());
		self::assertNull($jpeg->getComment());

		$jpeg->setComment('appended comment');
		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertSame('appended comment', $reloaded->getComment());
		self::assertSame(24, $reloaded->getWidth());   // the frame is untouched
	}

	public function testExtendedXmpFragmentTooShortIsIgnored()
	{
		// An extension segment shorter than the digest/length/offset header carries nothing.
		$payload = TJPEG::XMP_EXTENSION_IDENTIFIER . 'tooshort';
		$jpeg = TJPEG::fromString($this->minimalJpeg($this->segment(TJPEG::APP1, $payload)));
		self::assertNull($jpeg->getXmpText());
		self::assertNull($jpeg->getXMP());
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}

	public function testExtendedXmpUnparsablePacketIsIgnored()
	{
		// A well-formed extension header whose reassembled body is not an XMP packet.
		$body = 'not xml at all';
		$payload = TJPEG::XMP_EXTENSION_IDENTIFIER . str_repeat('A', 32)
			. pack('N', strlen($body)) . pack('N', 0) . $body;
		$jpeg = TJPEG::fromString($this->minimalJpeg($this->segment(TJPEG::APP1, $payload)));
		self::assertNull($jpeg->getXmpText());
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}

	public function testExtendedXmpBecomesTheMainPacketWhenThereIsNone()
	{
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'title', 'Extended Title');
		$text = $xmp->toPacketText(false);
		$half = (int) ceil(strlen($text) / 2);
		$digest = strtoupper(md5($text));
		$chunk = fn (int $offset, string $part): string => TJPEG::XMP_EXTENSION_IDENTIFIER
			. $digest . pack('N', strlen($text)) . pack('N', $offset) . $part;

		// The fragments arrive out of order and there is no main APP1 XMP segment.
		$jpeg = TJPEG::fromString($this->minimalJpeg(
			$this->segment(TJPEG::APP1, $chunk($half, substr($text, $half)))
			. $this->segment(TJPEG::APP1, $chunk(0, substr($text, 0, $half))),
		));
		self::assertSame(['Extended Title'], $jpeg->getXMP()?->getProperty(TXMP::NS_DC, 'title'));

		// It is written back as one standard XMP segment, since it now fits in one.
		$out = $jpeg->toBinary();
		self::assertStringContainsString(TJPEG::XMP_IDENTIFIER, $out);
		self::assertStringNotContainsString(TJPEG::XMP_EXTENSION_IDENTIFIER, $out);
		self::assertSame(['Extended Title'], TJPEG::fromString($out)->getXMP()?->getProperty(TXMP::NS_DC, 'title'));
	}

	public function testJumbfFragmentTooShortIsIgnored()
	{
		// An APP11 that is box-signed but shorter than the instance/sequence/box header.
		$jpeg = TJPEG::fromString($this->minimalJpeg(
			$this->segment(TJPEG::APP11, TJPEG::JUMBF_IDENTIFIER . "\x00\x01\x00\x00"),
		));
		self::assertSame([], $jpeg->getJumbfBoxes());
		self::assertSame(bin2hex($this->minimalJpeg()), bin2hex($jpeg->toBinary()));
	}

	public function testMalformedKodakMetaSegmentStaysRaw()
	{
		// The APP3 carries the Meta signature but no readable TIFF structure behind it.
		$payload = TEXIF::MetaSignature . 'garbage!!';
		$bytes = $this->minimalJpeg($this->segment(TJPEG::APP3, $payload));
		$jpeg = TJPEG::fromString($bytes);
		self::assertNull($jpeg->getMeta());

		// Kept verbatim as a raw segment, so the file still round-trips byte for byte.
		$segments = $jpeg->getSegments();
		self::assertSame(TJPEG::APP3, $segments[0]['marker']);
		self::assertSame('raw', $segments[0]['kind']);
		self::assertSame($payload, $segments[0]['payload']);
		self::assertSame(bin2hex($bytes), bin2hex($jpeg->toBinary()));
	}

	public function testLegacyPhotoshopIptcSegmentIsReadAndRewritten()
	{
		// A Photoshop 2.5 APP13: not the 3.0 IRB signature, but an IPTC 8BIM resource.
		$iim = $this->iimRecord(0x78, 'Legacy');   // 2#120 Caption-Abstract
		$payload = str_replace("Photoshop 3.0\x00", "Photoshop 2.5\x00", TPhotoshop8BIM::iptcEncode($iim));

		$jpeg = TJPEG::fromString($this->minimalJpeg($this->segment(TJPEG::APP13, $payload)));
		self::assertNull($jpeg->getPhotoshopIRB());   // no image-resource block, just the records
		self::assertTrue($jpeg->hasIPTC());
		self::assertSame('Legacy', $jpeg->getIPTC()[TIPTCTags::CaptionAbstract]);

		// The records are re-emitted from the live object as a single APP13.
		$jpeg->getIPTC()[TIPTCTags::CaptionAbstract] = 'Rewritten';
		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertSame('Rewritten', $reloaded->getIPTC()[TIPTCTags::CaptionAbstract]);
		self::assertSame(24, $reloaded->getWidth());
	}

	public function testJfxxSegmentRecomposedFromItsParsedKind()
	{
		$jfxx = new TJFXX();
		$thumb = $this->gdImage(8, 8);
		$jfxx->setImage($thumb, TJFXX::COLOR_THUMB);
		imagedestroy($thumb);

		$jpeg = TJPEG::fromString($this->jpegBytes());
		$jpeg->setJFXX($jfxx);                             // injected: no jfxx segment yet
		$parsed = TJPEG::fromString($jpeg->toBinary());    // now the segment list has one
		$again = TJPEG::fromString($parsed->toBinary());   // rewritten from the parsed kind

		self::assertSame(TJFXX::COLOR_THUMB, $again->getJFXX()?->getFormat());
		self::assertSame(8, $again->getJFXX()->getXThumbnail());
		self::assertSame(bin2hex($parsed->toBinary()), bin2hex($again->toBinary()));
	}

	public function testDroppedIccProfileEmitsNoSegment()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$jpeg->setICCProfile(str_repeat('IC', 100));
		$withProfile = TJPEG::fromString($jpeg->toBinary());
		self::assertTrue($withProfile->hasICCProfile());

		$withProfile->setICCProfile(null);   // the icc segment kind stays, its payload is gone
		$out = $withProfile->toBinary();
		self::assertStringNotContainsString('ICC_PROFILE', $out);
		self::assertFalse(TJPEG::fromString($out)->hasICCProfile());
		self::assertSame(24, TJPEG::fromString($out)->getWidth());
	}

	public function testDroppedPhotoshopIrbEmitsNoSegment()
	{
		$path = tempnam(sys_get_temp_dir(), 'irb');
		try {
			file_put_contents($path, $this->jpegBytes());
			$embedded = iptcembed($this->iimRecord(0x78, 'Original'), $path);
		} finally {
			@unlink($path);
		}
		self::assertNotFalse($embedded);

		$jpeg = TJPEG::fromString($embedded);
		self::assertNotNull($jpeg->getPhotoshopIRB());

		$jpeg->setPhotoshopIRB(null);
		$jpeg->setIPTC(null);
		$out = $jpeg->toBinary();
		self::assertStringNotContainsString('Photoshop', $out);
		self::assertNull(TJPEG::fromString($out)->getPhotoshopIRB());
		self::assertFalse(TJPEG::fromString($out)->hasIPTC());
		self::assertSame(24, TJPEG::fromString($out)->getWidth());
	}

	public function testJfifInjectedIntoAJpegWithoutApp0()
	{
		$jpeg = TJPEG::fromString($this->minimalJpeg());
		self::assertNull($jpeg->getJFIF());

		$jfif = new TJFIF();
		$jfif->setUnits(TJFIF::UNITS_PPI);
		$jfif->setXDensity(150);
		$jfif->setYDensity(150);
		$jpeg->setJFIF($jfif);

		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertInstanceOf(TJFIF::class, $reloaded->getJFIF());
		self::assertSame(150, $reloaded->getJFIF()->getXDensity());
		self::assertSame(TJFIF::UNITS_PPI, $reloaded->getJFIF()->getUnits());
		self::assertSame(24, $reloaded->getWidth());
	}
}
