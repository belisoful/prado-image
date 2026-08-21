<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\Meta\TJFIF;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\Image\Meta\TPictureInfo;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TPrivacyCategory;
use Prado\IO\Image\TRIFF;
use Prado\IO\Image\TRIFFChunkType;
use Prado\IO\Image\TWebP;

class TFormatEdgeTest extends PHPUnit\Framework\TestCase
{
	private function riffContainer(string $formType, array $chunks): string
	{
		$body = $formType;
		foreach ($chunks as [$id, $payload]) {
			$body .= $id . pack('V', strlen($payload)) . $payload . (strlen($payload) & 1 ? "\0" : '');
		}
		return 'RIFF' . pack('V', strlen($body)) . $body;
	}

	private function jpegBytes(): string
	{
		$im = imagecreatetruecolor(10, 8);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	private function webpBytes(int $w = 32, int $h = 24): string
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 10, 120, 200));
		ob_start();
		imagewebp($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testWebpVp8xExtendedDimensions()
	{
		// VP8X: flags(1) + reserved(3) + (width-1) and (height-1) as 24-bit LE.
		$vp8x = "\x00\x00\x00\x00" . substr(pack('V', 799), 0, 3) . substr(pack('V', 599), 0, 3);
		$webp = TWebP::fromString($this->riffContainer('WEBP', [['VP8X', $vp8x]]));
		self::assertSame(800, $webp->getWidth());
		self::assertSame(600, $webp->getHeight());
		self::assertNotNull($webp->getRIFF());
		self::assertSame('WEBP', $webp->getRIFF()->getFormType());
	}

	public function testWebpVp8lLosslessDimensions()
	{
		// VP8L: 0x2f signature then 14-bit (width-1) | (height-1) << 14, little-endian.
		$bits = (511 - 1 + 1 - 1) | ((320 - 1) << 14);   // 511x320
		$vp8l = "\x2f" . pack('V', (510 | (319 << 14)));
		$webp = TWebP::fromString($this->riffContainer('WEBP', [['VP8L', $vp8l]]));
		self::assertSame(511, $webp->getWidth());
		self::assertSame(320, $webp->getHeight());
	}

	public function testWebpRejectsWrongFormType()
	{
		self::expectException(TIOException::class);
		TWebP::fromString($this->riffContainer('WAVE', [['fmt ', "\x01\x00"]]));
	}

	public function testPngICCProfileInflates()
	{
		$profile = 'FAKE-ICC-PROFILE-' . str_repeat('x', 64);
		$iccp = "iccname\x00\x00" . gzcompress($profile);
		$png = "\x89PNG\r\n\x1a\n";
		foreach ([
			['IHDR', pack('N', 33) . pack('N', 44) . "\x08\x02\x00\x00\x00"],
			['iCCP', $iccp],
			['IEND', ''],
		] as [$type, $payload]) {
			$png .= pack('N', strlen($payload)) . $type . $payload . pack('N', crc32($type . $payload));
		}
		$parsed = TPNG::fromString($png);
		self::assertSame(33, $parsed->getWidth());
		self::assertSame(44, $parsed->getHeight());
		self::assertSame($profile, $parsed->getICCProfile());
		self::assertCount(3, $parsed->getChunks());

		// A malformed iCCP (no NUL) is ignored, not fatal.
		$bad = "\x89PNG\r\n\x1a\n" . pack('N', 4) . 'iCCP' . 'abcd' . pack('N', crc32('iCCPabcd'));
		self::assertNull(TPNG::fromString($bad)->getICCProfile());
	}

	public function testImageFileDiskIo()
	{
		$path = tempnam(sys_get_temp_dir(), 'imgio');
		$out = $path . '.out';
		try {
			file_put_contents($path, $this->jpegBytes());
			$jpeg = TJPEG::fromFile($path);
			self::assertSame(10, $jpeg->getWidth());
			self::assertSame($jpeg->toBinary(), (string) $jpeg);
			self::assertSame(bin2hex((string) file_get_contents($path)), bin2hex($jpeg->getBytes()));

			$stream = $jpeg->toStream();
			$stream->rewind();
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex($stream->getContents()));

			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save($out));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($out)));
		} finally {
			@unlink($path);
			@unlink($out);
		}

		self::expectException(TIOException::class);
		TJPEG::fromFile('/nonexistent/nope.jpg');
	}

	public function testRiffDiskAndResourceIo()
	{
		$bytes = $this->riffContainer('WAVE', [['fmt ', "\x01\x00\x02\x00"], ['data', 'PCM']]);
		$path = tempnam(sys_get_temp_dir(), 'riffio');
		try {
			file_put_contents($path, $bytes);
			$riff = TRIFF::fromFile($path);
			self::assertSame('WAVE', $riff->getFormType());
			self::assertSame('PCM', $riff->getChunk('data')->getData());

			$resource = fopen($path, 'rb');
			self::assertSame('WAVE', TRIFF::fromStream($resource)->getFormType());
			fclose($resource);
		} finally {
			@unlink($path);
		}

		self::expectException(TIOException::class);
		TRIFF::fromFile('/nonexistent/nope.wav');
	}

	public function testJfifUnitsConversionAndAccessors()
	{
		$jfif = new TJFIF();
		$jfif->setVersionMajor(1);
		$jfif->setVersionMinor(2);
		self::assertSame(1, $jfif->getVersionMajor());
		self::assertSame(2, $jfif->getVersionMinor());

		$jfif->setUnits(TJFIF::UNITS_PPI);
		$jfif->setXDensity(300);
		$jfif->setYDensity(300);
		$jfif->setUnits(TJFIF::UNITS_PPCM);   // 300 dpi -> 118 dpcm
		self::assertSame(118, $jfif->getXDensity());
		$jfif->setUnits(TJFIF::UNITS_PPI);    // and back -> 300
		self::assertSame(300, $jfif->getYDensity());
		$jfif->setUnits(99);                  // clamps to 0-2
		self::assertSame(TJFIF::UNITS_PPCM, $jfif->getUnits());

		self::assertTrue(TJFIF::isJFXX("JFXX\x00rest"));
		self::assertFalse(TJFIF::isJFXX("JFIF\x00rest"));

		// Truncated payloads are rejected, not fatal.
		self::assertFalse(TJFIF::parse("JFIF\x00\x01"));
		self::assertFalse(TJFXX::parse("JFXX"));

		$jfif->setImage(imagecreatetruecolor(2, 2));
		self::assertTrue($jfif->hasImage());
		$jfif->clearThumbnail();
		self::assertFalse($jfif->hasImage());
	}

	public function testJpegKodakMetaSegmentEndToEnd()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$meta = new TEXIF();
		$meta->setIsMeta(true);
		$meta->getIfd0()->setTagValues(0xC350, TTIFFDataType::Ascii, "KodakFW-1.0\0");
		$jpeg->setMeta($meta);

		$reparsed = TJPEG::fromString($jpeg->toBinary());
		self::assertNotNull($reparsed->getMeta());
		self::assertTrue($reparsed->getMeta()->getIsMeta());
		self::assertSame('KodakFW-1.0', $reparsed->getMeta()->getIfd0()->getTagValue(0xC350));

		$reparsed->setMeta(null);
		self::assertNull(TJPEG::fromString($reparsed->toBinary())->getMeta());
	}

	public function testJpegScanAndCommentEdges()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		self::assertStringStartsWith("\xFF\xDA", $jpeg->getScan());

		$jpeg->setComment('first');
		$jpeg->setComment('replaced');
		self::assertSame('replaced', TJPEG::fromString($jpeg->toBinary())->getComment());
		$jpeg->setComment(null);
		self::assertNull(TJPEG::fromString($jpeg->toBinary())->getComment());
	}

	public function testJpegNonPhotoshopApp13StaysRaw()
	{
		// An APP13 that is neither Photoshop-signed nor IPTC keeps its bytes verbatim.
		$bytes = $this->jpegBytes();
		$payload = 'SomeOtherVendor' . str_repeat("\xAB", 10);
		$segment = "\xFF\xED" . pack('n', strlen($payload) + 2) . $payload;
		$patched = substr($bytes, 0, 2) . $segment . substr($bytes, 2);

		$jpeg = TJPEG::fromString($patched);
		self::assertNull($jpeg->getPhotoshopIRB());
		self::assertNull($jpeg->getIPTC());
		$again = TJPEG::fromString($jpeg->toBinary());
		$found = false;
		foreach ($again->getSegments() as $seg) {
			if ($seg['marker'] === TJPEG::APP13 && $seg['payload'] === $payload) {
				$found = true;
			}
		}
		self::assertTrue($found);
	}

	public function testRiffRejectsBytesWithoutARiffHeader()
	{
		try {
			TRIFF::fromString('MThd' . pack('N', 6) . 'nota riff');
			self::fail('a non-RIFF container was accepted');
		} catch (TIOException $e) {
			self::assertSame('riff_invalid', $e->getErrorCode());
		}

		// Too short to hold the 12-byte header, even with the right signature.
		try {
			TRIFF::fromString('RIFF' . pack('V', 0));
			self::fail('a truncated RIFF header was accepted');
		} catch (TIOException $e) {
			self::assertSame('riff_invalid', $e->getErrorCode());
		}
	}

	public function testWebpMetadataSettersDoNothingBeforeParsing()
	{
		// A reader that has not loaded a container has nowhere to put a chunk; the
		// setters answer silently rather than fataling on the missing RIFF.
		$webp = new TWebP();
		self::assertNull($webp->getRIFF());

		$webp->setICCProfile('profile bytes');
		$webp->setXmpText('<x:xmpmeta/>');
		self::assertNull($webp->getICCProfile());
		self::assertNull($webp->getXmpText());
		self::assertSame('', $webp->toBinary());
	}

	public function testWebpRemovingAbsentMetadataAddsNoVp8xHeader()
	{
		// A simple lossy file has no VP8X: clearing metadata it never had must not
		// promote it to the extended format.
		$webp = TWebP::fromString($this->webpBytes(32, 24));
		self::assertNull($webp->getRIFF()->getChunk(TRIFFChunkType::Vp8Extended));

		$webp->setICCProfile(null);
		$webp->setXmpText(null);
		self::assertNull($webp->getRIFF()->getChunk(TRIFFChunkType::Vp8Extended));
		self::assertNull($webp->getICCProfile());

		$reloaded = TWebP::fromString($webp->toBinary());
		self::assertNull($reloaded->getRIFF()->getChunk(TRIFFChunkType::Vp8Extended));
		self::assertSame(32, $reloaded->getWidth());
		self::assertSame(24, $reloaded->getHeight());
	}

	public function testEncodeFailureThrowsTheCodedError()
	{
		if (!extension_loaded('imagick')) {
			self::markTestSkipped('ext-imagick is not loaded');
		}
		// An empty ImageMagick object has no pixels to write, so the encoder answers
		// false and the readers report it as the library being unable to write.
		$empty = new \Imagick();
		try {
			try {
				TJPEG::fromImage($empty);
				self::fail('an unencodable image was accepted');
			} catch (TIOException $e) {
				self::assertSame('jpeg_encode_unavailable', $e->getErrorCode());
			}
			try {
				TWebP::fromImage($empty);
				self::fail('an unencodable image was accepted');
			} catch (TIOException $e) {
				self::assertSame('webp_encode_unavailable', $e->getErrorCode());
			}

			$jpeg = TJPEG::fromString($this->jpegBytes());
			try {
				$jpeg->setImage($empty);
				self::fail('an unencodable image was accepted');
			} catch (TIOException $e) {
				self::assertSame('jpeg_encode_unavailable', $e->getErrorCode());
			}
			self::assertSame(10, $jpeg->getWidth());   // the reader is left untouched

			$webp = TWebP::fromString($this->webpBytes());
			try {
				$webp->setImage($empty);
				self::fail('an unencodable image was accepted');
			} catch (TIOException $e) {
				self::assertSame('webp_encode_unavailable', $e->getErrorCode());
			}
			self::assertSame(32, $webp->getWidth());
		} finally {
			$empty->clear();
		}
	}

	public function testSaveToAnUnwritablePathThrows()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		try {
			$jpeg->save('/no-such-directory-for-prado-image/out.jpg');
			self::fail('an unwritable path was accepted');
		} catch (TIOException $e) {
			self::assertSame('imagefile_unwritable', $e->getErrorCode());
		}
	}

	public function testScrubDropsTheJfifThumbnailAndPictureInfo()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$thumb = imagecreatetruecolor(4, 3);
		$jpeg->getJFIF()->setImage($thumb);
		imagedestroy($thumb);
		$density = $jpeg->getJFIF()->getXDensity();

		$info = new TPictureInfo();
		$info->setHeader('Type=');
		$info->setText("Type=jpeg\nCamera=X100\nSerial=12345\nTimeDate=1044582000\n");
		$jpeg->setPictureInfo($info);

		// The thumbnail goes, the density fields the JFIF exists for stay.
		self::assertSame(1, $jpeg->clearPrivateData(TPrivacyCategory::Thumbnail));
		self::assertNotNull($jpeg->getJFIF());
		self::assertFalse($jpeg->getJFIF()->hasImage());
		self::assertSame($density, $jpeg->getJFIF()->getXDensity());
		self::assertNotNull($jpeg->getPictureInfo());   // a different category

		// The APP12 block names the camera, so the camera-model flag takes all of it.
		self::assertSame(1, $jpeg->clearPrivateData(TPrivacyCategory::CameraModel));
		self::assertNull($jpeg->getPictureInfo());
		// Idempotent: a second pass over the same categories finds nothing left.
		self::assertSame(0, $jpeg->clearPrivateData(TPrivacyCategory::Thumbnail | TPrivacyCategory::CameraModel));

		$reloaded = TJPEG::fromString($jpeg->toBinary());
		self::assertFalse($reloaded->getJFIF()->hasImage());
		self::assertNull($reloaded->getPictureInfo());
		self::assertSame(10, $reloaded->getWidth());
	}

	public function testScrubCountsRecordsAContainerRefusesToWriteBack()
	{
		$iptc = new TIPTC();
		$iptc[TIPTCTags::ByLine] = 'Jane Photographer';
		$iptc[TIPTCTags::City] = 'Reykjavik';

		// A container that reads IPTC but has no carrier to write it back to: the
		// refusal is absorbed and the scrubbed fields still count as removed.
		$webp = TReadOnlyIptcWebP::fromString($this->webpBytes());
		$webp->loadIPTC($iptc);
		self::assertSame(2, $webp->clearPrivateData(TPrivacyCategory::Author | TPrivacyCategory::Location));
		self::assertFalse($iptc->contains(TIPTCTags::ByLine));
		self::assertFalse($iptc->contains(TIPTCTags::City));
	}
}

/**
 * A WebP reader that answers an IPTC record set although the container has no carrier
 * to write one back to, exercising the read-but-refuse-writes path of
 * {@see \Prado\IO\Image\TImageFile::clearPrivateData()}.
 */
class TReadOnlyIptcWebP extends TWebP
{
	private ?TIPTC $_iptc = null;

	public function getIPTC(): ?TIPTC
	{
		return $this->_iptc;
	}

	public function loadIPTC(TIPTC $iptc): void
	{
		$this->_iptc = $iptc;
	}
}
