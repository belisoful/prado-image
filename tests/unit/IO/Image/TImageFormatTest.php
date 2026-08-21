<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\Meta\TJFIF;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TImageChunk;
use Prado\IO\Image\TRIFF;
use Prado\IO\Image\TWebP;
use Prado\IO\TStream;

class TImageFormatTest extends PHPUnit\Framework\TestCase
{
	private function gdImage(int $w, int $h)
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 10, 120, 200));
		return $im;
	}

	private function jpegBytes(int $w = 48, int $h = 32): string
	{
		$im = $this->gdImage($w, $h);
		ob_start();
		imagejpeg($im, null, 90);
		imagedestroy($im);
		return ob_get_clean();
	}

	private function pngBytes(int $w = 40, int $h = 24): string
	{
		$im = $this->gdImage($w, $h);
		ob_start();
		imagepng($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	private function webpBytes(int $w = 64, int $h = 50): string
	{
		$im = $this->gdImage($w, $h);
		ob_start();
		imagewebp($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testJpegDimensions()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes(48, 32));
		self::assertSame('JPEG', $jpeg->getFormat());
		self::assertSame(48, $jpeg->getWidth());
		self::assertSame(32, $jpeg->getHeight());
	}

	public function testJpegInvalidThrows()
	{
		self::expectException(TIOException::class);
		TJPEG::fromString('not a jpeg');
	}

	public function testJpegIPTC()
	{
		$raw = $this->jpegBytes(20, 20);
		// Build an IPTC IIM block: caption (2#120) and a keyword (2#025).
		$iim = $this->iimRecord(0x78, 'A test caption') . $this->iimRecord(0x19, 'prado');
		$withIptc = iptcembed($iim, $this->tempFile($raw));
		self::assertNotFalse($withIptc);

		$jpeg = TJPEG::fromString($withIptc);
		self::assertTrue($jpeg->hasIPTC());
		$iptc = $jpeg->getIPTC();
		self::assertInstanceOf(TIPTC::class, $iptc);
		self::assertSame('A test caption', $iptc[TIPTCTags::CaptionAbstract]);
		self::assertSame(['prado'], $iptc[TIPTCTags::Keywords]);
		self::assertSame('A test caption', $iptc['Caption-Abstract']);   // by tag name
		self::assertSame('A test caption', $iptc['Description']);          // by alt name
		self::assertTrue($iptc->contains(TIPTCTags::Keywords));
	}

	public function testJpegNoIPTC()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		self::assertFalse($jpeg->hasIPTC());
		self::assertNull($jpeg->getIPTC());
	}

	public function testPngDimensionsAndChunks()
	{
		$png = TPNG::fromString($this->pngBytes(40, 24));
		self::assertSame('PNG', $png->getFormat());
		self::assertSame(40, $png->getWidth());
		self::assertSame(24, $png->getHeight());
		self::assertNotNull($png->getChunk('IHDR'));
		self::assertNotNull($png->getChunk('IEND'));
		self::assertNull($png->getChunk('zZzZ'));
	}

	public function testPngInvalidThrows()
	{
		self::expectException(TIOException::class);
		TPNG::fromString('not a png');
	}

	public function testWebpDimensions()
	{
		$webp = TWebP::fromString($this->webpBytes(64, 50));
		self::assertSame('WebP', $webp->getFormat());
		self::assertSame(64, $webp->getWidth());
		self::assertSame(50, $webp->getHeight());
	}

	public function testRiffFormType()
	{
		$riff = TRIFF::fromString($this->webpBytes());
		self::assertSame('WEBP', $riff->getFormType());
		self::assertNotEmpty($riff->getChunks());
	}

	public function testRiffChunkMutators()
	{
		$riff = TRIFF::fromString($this->webpBytes());
		$before = count($riff->getChunks());

		// A plain RIFF places chunks freely: setChunk replaces an existing id, appends a new one.
		$riff->setChunk(new TImageChunk('XMP ', 3, 0, 'abc'));
		self::assertCount($before + 1, $riff->getChunks());
		self::assertSame('XMP ', $riff->getChunks()[$before]->getType());

		$riff->setChunk(new TImageChunk('XMP ', 2, 0, 'de'));
		self::assertCount($before + 1, $riff->getChunks());
		self::assertSame('de', $riff->getChunk('XMP ')?->getData());

		// addChunk allows the repeat, prependChunk and insertChunk place explicitly.
		$riff->addChunk(new TImageChunk('LIST', 1, 0, 'x'));
		$riff->prependChunk(new TImageChunk('JUNK', 1, 0, 'y'));
		$riff->insertChunk(new TImageChunk('INFO', 1, 0, 'z'), 2);
		$types = array_map(fn (TImageChunk $c): string => $c->getType(), $riff->getChunks());
		self::assertSame('JUNK', $types[0]);
		self::assertSame('INFO', $types[2]);
		self::assertSame('LIST', end($types));

		// An out-of-range index appends rather than failing.
		$riff->insertChunk(new TImageChunk('LAST', 1, 0, 'w'), 999);
		self::assertSame('LAST', $riff->getChunks()[count($riff->getChunks()) - 1]->getType());

		// A given ordering ranks the insertion, and an unranked id still appends.
		$ordered = TRIFF::fromString($this->webpBytes());
		$ordered->setChunkInOrder(new TImageChunk('ICCP', 1, 0, 'i'), ['ICCP', 'VP8 ', 'EXIF']);
		self::assertSame('ICCP', $ordered->getChunks()[0]->getType());
		$ordered->setChunkInOrder(new TImageChunk('nope', 1, 0, 'n'), ['ICCP', 'VP8 ', 'EXIF']);
		self::assertSame('nope', $ordered->getChunks()[count($ordered->getChunks()) - 1]->getType());

		self::assertTrue($riff->removeChunk('JUNK'));
		self::assertFalse($riff->removeChunk('JUNK'));
		self::assertInstanceOf(TRIFF::class, TRIFF::fromString($riff->toBinary()));
	}

	public function testFromStream()
	{
		$jpeg = TJPEG::fromStream(TStream::fromString($this->jpegBytes(16, 16)));
		self::assertSame(16, $jpeg->getWidth());
	}

	public function testJpegRoundTripPreservesImageAndDimensions()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes(48, 32));
		$out = $jpeg->toBinary();
		$reloaded = TJPEG::fromString($out);
		self::assertSame(48, $reloaded->getWidth());
		self::assertSame(32, $reloaded->getHeight());
		$size = getimagesizefromstring($out);   // still a decodable JPEG
		self::assertSame([48, 32], [$size[0], $size[1]]);
	}

	public function testJpegEditIptcAndSaveKeepsImage()
	{
		$iim = $this->iimRecord(0x78, 'Original');
		$withIptc = iptcembed($iim, $this->tempFile($this->jpegBytes(24, 24)));

		$jpeg = TJPEG::fromString($withIptc);
		$jpeg->getIPTC()[TIPTCTags::CaptionAbstract] = 'Edited caption';
		$out = $jpeg->toBinary();

		$reloaded = TJPEG::fromString($out);
		self::assertSame('Edited caption', $reloaded->getIPTC()[TIPTCTags::CaptionAbstract]);
		self::assertSame(24, $reloaded->getWidth());
		$size = getimagesizefromstring($out);
		self::assertSame([24, 24], [$size[0], $size[1]]);
	}

	public function testJpegAddIptcToImageWithoutOne()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		self::assertFalse($jpeg->hasIPTC());

		$iptc = new TIPTC();
		$iptc[TIPTCTags::CaptionAbstract] = 'Brand new';
		$jpeg->setIPTC($iptc);

		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertTrue($reloaded->hasIPTC());
		self::assertSame('Brand new', $reloaded->getIPTC()[TIPTCTags::CaptionAbstract]);
	}

	public function testJpegComment()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$jpeg->setComment('Hello JPEG');   // replaces gd's own COM segment
		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertSame('Hello JPEG', $reloaded->getComment());
		self::assertSame(48, $reloaded->getWidth());
	}

	public function testJpegICCProfileRoundTrip()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		self::assertFalse($jpeg->hasICCProfile());
		$profile = str_repeat('IC', 40000);   // > one APP2 chunk, forces splitting
		$jpeg->setICCProfile($profile);

		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertTrue($reloaded->hasICCProfile());
		self::assertSame($profile, $reloaded->getICCProfile());
	}

	public function testPngRoundTrip()
	{
		$png = TPNG::fromString($this->pngBytes(40, 24));
		$out = $png->toBinary();
		$size = getimagesizefromstring($out);
		self::assertSame([40, 24], [$size[0], $size[1]]);
		$reloaded = TPNG::fromString($out);
		self::assertSame(40, $reloaded->getWidth());
	}

	public function testWebpRoundTrip()
	{
		$webp = TWebP::fromString($this->webpBytes(64, 50));
		$out = $webp->toBinary();
		$reloaded = TWebP::fromString($out);
		self::assertSame(64, $reloaded->getWidth());
		self::assertSame(50, $reloaded->getHeight());
	}

	public function testJpegJfifParsedAndPreserved()
	{
		// gd JPEG carries an APP0 JFIF (units=1 ppi, density 96 by default).
		$jpeg = TJPEG::fromString($this->jpegBytes(20, 20));
		$jfif = $jpeg->getJFIF();
		self::assertInstanceOf(TJFIF::class, $jfif);
		self::assertSame(1, $jfif->getVersionMajor());

		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertInstanceOf(TJFIF::class, $reloaded->getJFIF());
		self::assertSame($jfif->getXDensity(), $reloaded->getJFIF()->getXDensity());
	}

	public function testJpegEditJfifDensity()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$jpeg->getJFIF()->setUnits(TJFIF::UNITS_PPI);
		$jpeg->getJFIF()->setXDensity(300);
		$jpeg->getJFIF()->setYDensity(300);

		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertSame(300, $reloaded->getJFIF()->getXDensity());
		self::assertSame(300, $reloaded->getJFIF()->getYDensity());
		self::assertSame(TJFIF::UNITS_PPI, $reloaded->getJFIF()->getUnits());
	}

	public function testJfifParseAndToBinaryRoundTrip()
	{
		$jfif = new TJFIF();
		$jfif->setUnits(TJFIF::UNITS_PPI);
		$jfif->setXDensity(72);
		$jfif->setYDensity(72);
		$binary = $jfif->toBinary();
		self::assertTrue(TJFIF::isJFIF($binary));

		$parsed = TJFIF::parse($binary);
		self::assertInstanceOf(TJFIF::class, $parsed);
		self::assertSame(72, $parsed->getXDensity());
		self::assertFalse($parsed->hasImage());
	}

	public function testJfifThumbnailRoundTrip()
	{
		$thumb = imagecreatetruecolor(4, 3);
		imagefilledrectangle($thumb, 0, 0, 3, 2, imagecolorallocate($thumb, 200, 100, 50));
		$jfif = new TJFIF();
		$jfif->setImage($thumb);
		imagedestroy($thumb);

		self::assertTrue($jfif->hasImage());
		self::assertSame(4, $jfif->getXThumbnail());
		self::assertSame(3, $jfif->getYThumbnail());

		$parsed = TJFIF::parse($jfif->toBinary());
		self::assertSame(4 * 3 * 3, strlen($parsed->getThumbnail()));
		$img = $parsed->getImage();
		self::assertInstanceOf(\GdImage::class, $img);
		$rgb = imagecolorat($img, 0, 0);
		self::assertSame([200, 100, 50], [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF]);
	}

	public function testJfifThumbnailOverMaxThrows()
	{
		$big = imagecreatetruecolor(300, 10);
		$jfif = new TJFIF();
		try {
			self::expectException(\Prado\Exceptions\TInvalidDataValueException::class);
			$jfif->setImage($big);
		} finally {
			imagedestroy($big);
		}
	}

	private function thumb(int $w = 8, int $h = 6)
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 10, 200, 30));
		return $im;
	}

	public function testJfxxColorThumbRoundTrip()
	{
		$thumb = $this->thumb(8, 6);
		$jfxx = new TJFXX();
		$jfxx->setImage($thumb, TJFXX::COLOR_THUMB);
		imagedestroy($thumb);
		self::assertSame(TJFXX::COLOR_THUMB, $jfxx->getFormat());
		self::assertSame(8 * 6 * 3, strlen($jfxx->getThumbnail()));

		$parsed = TJFXX::parse($jfxx->toBinary());
		self::assertInstanceOf(TJFXX::class, $parsed);
		self::assertSame(8, $parsed->getXThumbnail());
		$img = $parsed->getImage();
		$rgb = imagecolorat($img, 0, 0);
		self::assertSame([10, 200, 30], [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF]);
	}

	public function testJfxxPaletteThumbRoundTrip()
	{
		$thumb = $this->thumb(8, 6);
		$jfxx = new TJFXX();
		$jfxx->setImage($thumb, TJFXX::PALETTE_THUMB);
		imagedestroy($thumb);
		self::assertSame(TJFXX::PALETTE_THUMB, $jfxx->getFormat());
		self::assertSame(768, strlen($jfxx->getPalette()));
		self::assertSame(8 * 6, strlen($jfxx->getThumbnail()));

		$parsed = TJFXX::parse($jfxx->toBinary());
		$img = $parsed->getImage();
		self::assertInstanceOf(\GdImage::class, $img);
		$rgb = imagecolorat($img, 0, 0);   // true-color; palette quantization may shift slightly
		self::assertEqualsWithDelta(10, ($rgb >> 16) & 0xFF, 12);
		self::assertEqualsWithDelta(200, ($rgb >> 8) & 0xFF, 12);
		self::assertEqualsWithDelta(30, $rgb & 0xFF, 12);
	}

	public function testJfxxJpegThumbReadsDimensions()
	{
		$thumb = $this->thumb(20, 16);
		$jfxx = new TJFXX();
		$jfxx->setImage($thumb, TJFXX::JPEG_THUMB);
		imagedestroy($thumb);
		self::assertSame(TJFXX::JPEG_THUMB, $jfxx->getFormat());

		$parsed = TJFXX::parse($jfxx->toBinary());
		self::assertSame(20, $parsed->getXThumbnail());
		self::assertSame(16, $parsed->getYThumbnail());
		self::assertInstanceOf(\GdImage::class, $parsed->getImage());
	}

	public function testJfxxEfficiencyPicksAFormat()
	{
		$thumb = $this->thumb(16, 16);
		$jfxx = new TJFXX();
		$jfxx->setImage($thumb);   // EFFICIENCY_THUMB
		imagedestroy($thumb);
		self::assertContains($jfxx->getFormat(), [TJFXX::JPEG_THUMB, TJFXX::PALETTE_THUMB, TJFXX::COLOR_THUMB]);
		self::assertInstanceOf(TJFXX::class, TJFXX::parse($jfxx->toBinary()));
	}

	public function testJpegEmbedsAndPreservesJfxx()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes(24, 24));
		self::assertNull($jpeg->getJFXX());

		$jfxx = new TJFXX();
		$jfxx->setImage($this->thumb(8, 8), TJFXX::COLOR_THUMB);
		$jpeg->setJFXX($jfxx);

		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertInstanceOf(TJFXX::class, $reloaded->getJFXX());
		self::assertSame(TJFXX::COLOR_THUMB, $reloaded->getJFXX()->getFormat());
		self::assertSame(8, $reloaded->getJFXX()->getXThumbnail());
		self::assertSame(24, $reloaded->getWidth());   // main image intact
	}

	public function testJfxxThumbnailOverMaxThrows()
	{
		$big = imagecreatetruecolor(256, 4);
		$jfxx = new TJFXX();
		try {
			self::expectException(\Prado\Exceptions\TInvalidDataValueException::class);
			$jfxx->setImage($big, TJFXX::COLOR_THUMB);
		} finally {
			imagedestroy($big);
		}
	}

	public function testIptcParseFalseWhenNoData()
	{
		$block = 'garbage without iptc markers';
		self::assertFalse(TIPTC::parse($block));
	}

	public function testIptcRoundTripToBinary()
	{
		$iptc = new TIPTC();
		$iptc[TIPTCTags::CaptionAbstract] = 'Round trip';
		$iptc[TIPTCTags::Keywords] = ['alpha', 'beta'];
		$binary = $iptc->toBinary('UTF-8', true);   // 8BIM-wrapped

		$reparsed = TIPTC::parse($binary);
		self::assertInstanceOf(TIPTC::class, $reparsed);
		self::assertSame('Round trip', $reparsed[TIPTCTags::CaptionAbstract]);
		self::assertSame(['alpha', 'beta'], $reparsed[TIPTCTags::Keywords]);
	}

	public function testJpegMarkerHelpers()
	{
		self::assertSame('SOF0', TJPEG::markerName(TJPEG::SOF0));
		self::assertSame('APP1', TJPEG::markerName(TJPEG::APP1));
		self::assertSame('COM', TJPEG::markerName(TJPEG::COM));
		self::assertNull(TJPEG::markerName(0x99));

		// standalone markers have no length/payload
		self::assertFalse(TJPEG::markerHasLength(TJPEG::SOI));
		self::assertFalse(TJPEG::markerHasLength(TJPEG::EOI));
		self::assertFalse(TJPEG::markerHasLength(TJPEG::RST3));
		self::assertFalse(TJPEG::markerHasLength(TJPEG::TEM));
		self::assertTrue(TJPEG::markerHasLength(TJPEG::APP0));
		self::assertTrue(TJPEG::markerHasLength(TJPEG::SOF2));

		// SOFn spans C0-CF except DHT/JPG/DAC
		self::assertTrue(TJPEG::isStartOfFrameMarker(TJPEG::SOF0));
		self::assertTrue(TJPEG::isStartOfFrameMarker(TJPEG::SOF2));
		self::assertFalse(TJPEG::isStartOfFrameMarker(TJPEG::DHT));
		self::assertFalse(TJPEG::isStartOfFrameMarker(TJPEG::DAC));
		self::assertFalse(TJPEG::isStartOfFrameMarker(TJPEG::APP0));
	}

	public function testJpegSubclassMarkerHook()
	{
		// Inject an APP1 segment after SOI, then a subclass captures it via the hook.
		$raw = $this->jpegBytes(20, 20);
		$payload = "Exif\x00\x00HELLO";
		$app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
		$withApp1 = substr($raw, 0, 2) . $app1 . substr($raw, 2);

		$jpeg = TEXIFAwareJpeg::fromString($withApp1);
		self::assertSame([$payload], $jpeg->getApp1Payloads());   // hook captured it
		self::assertSame(20, $jpeg->getWidth());

		// the APP1 segment round-trips (kept as a raw segment by the parent)
		$reparsed = TEXIFAwareJpeg::fromString($jpeg->toBinary());
		self::assertSame([$payload], $reparsed->getApp1Payloads());
	}

	public function testJpegHeightFromDnlMarker()
	{
		// SOFn with height 0 (number-of-lines deferred), real height in a DNL after SOS.
		$sof = "\xFF\xC0\x00\x07\x08\x00\x00\x00\x10";     // SOF0 len7: prec8, H=0, W=16
		$sos = "\xFF\xDA\x00\x02";                          // start of scan
		$scan = 'entropydata-without-ff';                   // fake entropy (no 0xFF)
		$dnl = "\xFF\xDC\x00\x04\x00\x20";                  // DNL len4, NL=32
		$jpeg = "\xFF\xD8" . $sof . $sos . $scan . $dnl . "\xFF\xD9";

		$img = TJPEG::fromString($jpeg);
		self::assertSame(16, $img->getWidth());
		self::assertSame(32, $img->getHeight());   // resolved from the DNL marker
	}

	public function testJpegHierarchicalDhpDimensions()
	{
		// Hierarchical JPEG: DHP carries the full size; frame SOFn after a scan is smaller.
		$dhp = "\xFF\xDE\x00\x07\x08\x01\x40\x01\x00";       // DHP: H=320, W=256 (full image)
		$frame1Sof = "\xFF\xC5\x00\x07\x08\x00\x80\x00\x80"; // differential SOF5: H=128, W=128
		$sos = "\xFF\xDA\x00\x02" . 'scan1bytes';
		$frame2Sof = "\xFF\xC5\x00\x07\x08\x01\x40\x01\x00"; // a later frame SOF, after the scan
		$sos2 = "\xFF\xDA\x00\x02" . 'scan2bytes';
		$jpeg = "\xFF\xD8" . $dhp . $frame1Sof . $sos . $frame2Sof . $sos2 . "\xFF\xD9";

		$img = TJPEG::fromString($jpeg);
		// DHP is read first and is non-zero, so it wins over the later frame headers.
		self::assertSame(256, $img->getWidth());
		self::assertSame(320, $img->getHeight());
	}

	public function testJpegMultiSofnAfterScanResolvesHeight()
	{
		// No DHP; first SOFn has height 0, a later SOFn (after a scan) supplies it.
		$sof0 = "\xFF\xC0\x00\x07\x08\x00\x00\x00\x40";       // SOF0: H=0, W=64
		$sos = "\xFF\xDA\x00\x02" . 'firstscan';
		$sof2 = "\xFF\xC2\x00\x07\x08\x00\x30\x00\x40";        // SOF2 after scan: H=48, W=64
		$sos2 = "\xFF\xDA\x00\x02" . 'secondscan';
		$jpeg = "\xFF\xD8" . $sof0 . $sos . $sof2 . $sos2 . "\xFF\xD9";

		$img = TJPEG::fromString($jpeg);
		self::assertSame(64, $img->getWidth());
		self::assertSame(48, $img->getHeight());   // resolved from the post-scan SOFn
	}

	/** Builds one IPTC IIM dataset record for record 2 (application). */
	private function iimRecord(int $dataset, string $value): string
	{
		return "\x1C\x02" . chr($dataset) . pack('n', strlen($value)) . $value;
	}

	/** Writes bytes to a temp file iptcembed can read, returns its path. */
	private function tempFile(string $bytes): string
	{
		$path = tempnam(sys_get_temp_dir(), 'iptc');
		file_put_contents($path, $bytes);
		return $path;
	}
}

/**
 * A TJPEG subclass that captures APP1 payloads via the protected ingestSegment hook,
 * demonstrating that subclasses can recognize additional markers.
 */
class TEXIFAwareJpeg extends \Prado\IO\Image\TJPEG
{
	private array $_app1 = [];

	public function getApp1Payloads(): array
	{
		return $this->_app1;
	}

	protected function ingestSegment(int $marker, string $payload, array &$iccChunks): void
	{
		if ($marker === self::APP1) {
			$this->_app1[] = $payload;
		}
		parent::ingestSegment($marker, $payload, $iccChunks);
	}
}
