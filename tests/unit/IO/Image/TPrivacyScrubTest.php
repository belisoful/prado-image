<?php

use Prado\IO\Image\IPrivacyScrubbable;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TGIF;
use Prado\IO\Image\TImageChunk;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPhotoshopIRB;
use Prado\IO\Image\TPhotoshopResource;
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TPrivacyCategory;
use Prado\IO\Image\TTIFF;
use Prado\IO\Image\TWebP;

/**
 * The metadata-wide privacy scrub: TPrivacyCategory flags applied uniformly to every
 * carrier (XMP, IPTC, the Photoshop IRB — EXIF has its own suite) and fanned out by
 * every container to everything it holds.  A category must remove exactly its facts in
 * each carrier and leave the picture-describing fields, and a container scrub must reach
 * every carrier the format has.
 */
class TPrivacyScrubTest extends PHPUnit\Framework\TestCase
{
	//
	// ─── The contract ────────────────────────────────────────────────────────
	//

	public function testEveryCarrierAndContainerImplementsTheContract()
	{
		foreach ([TEXIF::class, TXMP::class, TIPTC::class, TPhotoshopIRB::class,
			TJPEG::class, TPNG::class, TTIFF::class, TWebP::class, TGIF::class] as $class) {
			self::assertContains(IPrivacyScrubbable::class, class_implements($class), "$class implements IPrivacyScrubbable");
		}
	}

	public function testCategoryBitsAreDistinctAndAllIsEveryBit()
	{
		$flags = [TPrivacyCategory::Location, TPrivacyCategory::Author, TPrivacyCategory::Description,
			TPrivacyCategory::CameraModel, TPrivacyCategory::SerialNumber, TPrivacyCategory::Timestamp,
			TPrivacyCategory::Software, TPrivacyCategory::MakerNote, TPrivacyCategory::Thumbnail,
			TPrivacyCategory::Interoperability];
		self::assertCount(count($flags), array_unique($flags));
		foreach ($flags as $flag) {
			self::assertSame(1, substr_count(decbin($flag), '1'), 'one bit per category');
			self::assertSame($flag, TPrivacyCategory::All & $flag);
		}
		self::assertSame(-1, TPrivacyCategory::All);
		self::assertSame(TPrivacyCategory::Author | TPrivacyCategory::SerialNumber | TPrivacyCategory::MakerNote, TPrivacyCategory::Identity);
		self::assertSame(TPrivacyCategory::Location | TPrivacyCategory::Timestamp | TPrivacyCategory::Software, TPrivacyCategory::Provenance);
	}

	//
	// ─── XMP ─────────────────────────────────────────────────────────────────
	//

	private function loadedXmp(): TXMP
	{
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'creator', 'Jane Doe');
		$xmp->setProperty(TXMP::NS_DC, 'rights', '(c) Jane');
		$xmp->setProperty(TXMP::NS_DC, 'description', 'At home with the kids');
		$xmp->setProperty(TXMP::NS_DC, 'subject', ['family', 'home']);
		$xmp->setProperty(TXMP::NS_XMP, 'CreateDate', '2026-01-01T12:00:00');
		$xmp->setProperty(TXMP::NS_XMP, 'CreatorTool', 'Editor 1.0');
		$xmp->setProperty(TXMP::NS_TIFF, 'Make', 'TestCam');
		$xmp->setProperty(TXMP::NS_TIFF, 'Model', 'ZZ-1');
		$xmp->setProperty(TXMP::NS_EXIF, 'GPSLatitude', '34,3.00N');
		$xmp->setProperty(TXMP::NS_EXIF, 'GPSLongitude', '118,14.40W');
		$xmp->setProperty(TXMP::NS_PHOTOSHOP, 'City', 'Los Angeles');
		$xmp->setProperty(TXMP::NS_EXIF_AUX, 'SerialNumber', 'SN-000123');
		$xmp->setProperty(TXMP::NS_MM, 'DocumentID', 'xmp.did:abc');
		$xmp->setProperty(TXMP::NS_MM, 'InstanceID', 'xmp.iid:def');
		// non-identifying picture properties that must survive
		$xmp->setProperty(TXMP::NS_EXIF, 'FNumber', '28/10');
		$xmp->setProperty(TXMP::NS_EXIF, 'ExposureTime', '1/125');
		$xmp->setProperty(TXMP::NS_TIFF, 'ImageWidth', '4000');
		return TXMP::parse($xmp->toPacketText());   // reparse: realistic
	}

	private function xmpProbes(): array
	{
		return [
			'Location' => fn (TXMP $x): bool => $x->getProperty(TXMP::NS_EXIF, 'GPSLatitude') !== null,
			'Author' => fn (TXMP $x): bool => $x->getProperty(TXMP::NS_DC, 'creator') !== null,
			'Description' => fn (TXMP $x): bool => $x->getProperty(TXMP::NS_DC, 'description') !== null,
			'CameraModel' => fn (TXMP $x): bool => $x->getProperty(TXMP::NS_TIFF, 'Make') !== null,
			'SerialNumber' => fn (TXMP $x): bool => $x->getProperty(TXMP::NS_EXIF_AUX, 'SerialNumber') !== null,
			'Timestamp' => fn (TXMP $x): bool => $x->getProperty(TXMP::NS_XMP, 'CreateDate') !== null,
			'Software' => fn (TXMP $x): bool => $x->getProperty(TXMP::NS_XMP, 'CreatorTool') !== null,
		];
	}

	public function testXmpEachFlagRemovesExactlyItsCategory()
	{
		foreach ($this->xmpProbes() as $name => $probe) {
			$xmp = $this->loadedXmp();
			$xmp->clearPrivateData(constant(TPrivacyCategory::class . '::' . $name));
			$xmp = TXMP::parse($xmp->toPacketText());
			self::assertFalse($probe($xmp), "XMP $name should be removed by its flag");
			foreach ($this->xmpProbes() as $other => $otherProbe) {
				if ($other !== $name) {
					self::assertTrue($otherProbe($xmp), "XMP $name flag must not remove $other");
				}
			}
			self::assertSame('28/10', $xmp->getProperty(TXMP::NS_EXIF, 'FNumber'), "$name keeps FNumber");
			self::assertSame('4000', $xmp->getProperty(TXMP::NS_TIFF, 'ImageWidth'), "$name keeps ImageWidth");
		}
	}

	public function testXmpAllClearsEverythingIdentifying()
	{
		$xmp = $this->loadedXmp();
		$removed = $xmp->clearPrivateData();
		self::assertGreaterThanOrEqual(14, $removed);
		$xmp = TXMP::parse($xmp->toPacketText());
		foreach ($this->xmpProbes() as $name => $probe) {
			self::assertFalse($probe($xmp), "All should remove XMP $name");
		}
		// The whole category, not just the probe property.
		foreach ([[TXMP::NS_DC, 'rights'], [TXMP::NS_DC, 'subject'], [TXMP::NS_TIFF, 'Model'],
			[TXMP::NS_EXIF, 'GPSLongitude'], [TXMP::NS_PHOTOSHOP, 'City'], [TXMP::NS_MM, 'DocumentID'],
			[TXMP::NS_MM, 'InstanceID']] as [$ns, $prop]) {
			self::assertNull($xmp->getProperty($ns, $prop), "$prop should be gone");
		}
		self::assertSame('28/10', $xmp->getProperty(TXMP::NS_EXIF, 'FNumber'));
		self::assertSame('1/125', $xmp->getProperty(TXMP::NS_EXIF, 'ExposureTime'));
		self::assertSame(0, $xmp->clearPrivateData(), 'idempotent');
		self::assertSame(0, TXMP::blank()->clearPrivateData(), 'empty packet is safe');
	}

	//
	// ─── IPTC ────────────────────────────────────────────────────────────────
	//

	private function loadedIptc(): TIPTC
	{
		$iptc = new TIPTC();
		$iptc[TIPTCTags::ByLine] = ['Jane Doe'];
		$iptc[TIPTCTags::Credit] = 'Jane Photo';
		$iptc[TIPTCTags::CopyrightNotice] = '(c) Jane';
		$iptc[TIPTCTags::City] = 'Los Angeles';
		$iptc[TIPTCTags::ProvinceState] = 'California';
		$iptc[TIPTCTags::CountryPrimaryLocationName] = 'USA';
		$iptc[TIPTCTags::CaptionAbstract] = 'At home';
		$iptc[TIPTCTags::Keywords] = ['family'];
		$iptc[TIPTCTags::Headline] = 'Birthday';
		$iptc[TIPTCTags::DateCreated] = '20260101';
		$iptc[TIPTCTags::TimeCreated] = '120000+0000';
		$iptc[TIPTCTags::OriginatingProgram] = 'Editor';
		$iptc[TIPTCTags::UniqueDocumentID] = 'doc-1';
		$iptc[TIPTCTags::ExifCameraInfo] = "II\x2A\x00camera";
		$iptc[TIPTCTags::ServiceIdentifier] = 'SVC';
		$iptc[TIPTCTags::DateSent] = '20260101';
		// non-identifying NewsPhoto fields that must survive
		$iptc[TIPTCTags::ImageOrientation] = 'L';
		$iptc[TIPTCTags::IPTCBitsPerSample] = 8;
		$bytes = $iptc->toBinary(false);
		return TIPTC::parse($bytes);   // reparse: realistic
	}

	private function iptcProbes(): array
	{
		return [
			'Location' => fn (TIPTC $i): bool => $i->contains(TIPTCTags::City),
			'Author' => fn (TIPTC $i): bool => $i->contains(TIPTCTags::ByLine),
			'Description' => fn (TIPTC $i): bool => $i->contains(TIPTCTags::CaptionAbstract),
			'CameraModel' => fn (TIPTC $i): bool => $i->contains(TIPTCTags::ExifCameraInfo),
			'SerialNumber' => fn (TIPTC $i): bool => $i->contains(TIPTCTags::UniqueDocumentID),
			'Timestamp' => fn (TIPTC $i): bool => $i->contains(TIPTCTags::DateCreated),
			'Software' => fn (TIPTC $i): bool => $i->contains(TIPTCTags::OriginatingProgram),
		];
	}

	public function testIptcEachFlagRemovesExactlyItsCategory()
	{
		foreach ($this->iptcProbes() as $name => $probe) {
			$iptc = $this->loadedIptc();
			$iptc->clearPrivateData(constant(TPrivacyCategory::class . '::' . $name));
			$bytes = $iptc->toBinary(false);
			$iptc = TIPTC::parse($bytes);
			self::assertFalse($probe($iptc), "IPTC $name should be removed by its flag");
			foreach ($this->iptcProbes() as $other => $otherProbe) {
				if ($other !== $name) {
					self::assertTrue($otherProbe($iptc), "IPTC $name flag must not remove $other");
				}
			}
			self::assertTrue($iptc->contains(TIPTCTags::ImageOrientation), "$name keeps ImageOrientation");
			self::assertTrue($iptc->contains(TIPTCTags::IPTCBitsPerSample), "$name keeps BitsPerSample");
		}
	}

	public function testIptcAllClearsEverythingAndDoesNotRegenerateTheEnvelopeNumber()
	{
		$iptc = $this->loadedIptc();
		// IIM mandates DateSent, ServiceIdentifier, and EnvelopeNumber, and toBinary()
		// refills any missing one with TODAY'S date and a fresh identifier -- which would
		// stamp a new timestamp onto a scrubbed file.  A scrub pins them to a fixed,
		// obviously synthetic sentinel instead, and the result must be stable across writes.
		self::assertTrue($iptc->contains(TIPTCTags::EnvelopeNumber));
		$iptc->clearPrivateData();
		self::assertSame(TIPTC::ScrubbedDate, $iptc[TIPTCTags::DateSent], 'DateSent is the sentinel, not today');
		self::assertSame(
			TIPTC::computeEnvelopeNumber($iptc[TIPTCTags::ServiceIdentifier], TIPTC::ScrubbedDate),
			$iptc[TIPTCTags::EnvelopeNumber],
			'the envelope number derives from the sentinel, so it is constant and reveals nothing',
		);
		self::assertNotSame(TIPTC::formatIPTCDate(), $iptc[TIPTCTags::DateSent], 'not today');

		$bytes = $iptc->toBinary(false);
		$iptc = TIPTC::parse($bytes);
		foreach ($this->iptcProbes() as $name => $probe) {
			self::assertFalse($probe($iptc), "All should remove IPTC $name");
		}
		foreach ([TIPTCTags::Credit, TIPTCTags::CopyrightNotice, TIPTCTags::ProvinceState,
			TIPTCTags::CountryPrimaryLocationName, TIPTCTags::Keywords, TIPTCTags::Headline,
			TIPTCTags::TimeCreated] as $dataset) {
			self::assertFalse($iptc->contains($dataset), "$dataset should be gone");
		}
		// The mandatory envelope stays, pinned to the sentinel, and the reparse kept it that way.
		self::assertSame('SVC', $iptc[TIPTCTags::ServiceIdentifier], 'an existing service id is kept, not re-minted');
		self::assertSame(TIPTC::ScrubbedDate, $iptc[TIPTCTags::DateSent]);
		self::assertTrue($iptc->contains(TIPTCTags::ImageOrientation));
		self::assertSame(0, $iptc->clearPrivateData(), 'idempotent');
		self::assertSame(0, (new TIPTC())->clearPrivateData(), 'empty set is safe');
	}

	public function testIptcEnvelopeDateIsReplacedNeverMinted()
	{
		// A record set whose only identifying fact is its envelope date: the replacement
		// counts, so a container knows to write the carrier back.
		$iptc = new TIPTC();
		$iptc[TIPTCTags::ServiceIdentifier] = 'SVC';
		$iptc[TIPTCTags::DateSent] = '20260101';
		$iptc[TIPTCTags::ImageOrientation] = 'L';
		$bytes = $iptc->toBinary();   // validate() derives the envelope number, as any written record set has
		$iptc = TIPTC::parse($bytes);
		self::assertTrue($iptc->contains(TIPTCTags::EnvelopeNumber));
		self::assertSame(1, $iptc->clearPrivateData(TPrivacyCategory::Timestamp), 'the date is replaced (its envelope number follows)');
		self::assertSame(TIPTC::ScrubbedDate, $iptc[TIPTCTags::DateSent]);
		self::assertSame(TIPTC::computeEnvelopeNumber('SVC', TIPTC::ScrubbedDate), $iptc[TIPTCTags::EnvelopeNumber]);
		self::assertSame(0, $iptc->clearPrivateData(TPrivacyCategory::Timestamp), 'idempotent');

		// A record set with no envelope yet is not given one: nothing is minted.
		$fresh = new TIPTC();
		$fresh[TIPTCTags::ImageOrientation] = 'L';
		self::assertSame(0, $fresh->clearPrivateData());
		self::assertFalse($fresh->contains(TIPTCTags::DateSent));
		self::assertFalse($fresh->contains(TIPTCTags::EnvelopeNumber));
	}

	//
	// ─── Photoshop IRB ───────────────────────────────────────────────────────
	//

	public function testIrbRemovesItsOwnResourcesAndRedactsTheEmbeddedIptc()
	{
		$irb = new TPhotoshopIRB();
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::Url, 'http://jane.example'));
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::CopyrightFlag, "\x01"));
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::CaptionString, 'a caption'));
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::VersionInfo, 'version bytes'));
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::Thumbnail5, 'thumb bytes'));
		$irb->setResource(new TPhotoshopResource(0x03ED, 'resolution info'));   // non-identifying
		$iptc = new TIPTC();
		$iptc[TIPTCTags::ByLine] = ['Jane'];
		$iptc[TIPTCTags::ImageOrientation] = 'L';
		$irb->setIPTC($iptc);
		$irb = TPhotoshopIRB::parse($irb->toBinary());

		// One flag at a time.
		$author = TPhotoshopIRB::parse($irb->toBinary());
		$author->clearPrivateData(TPrivacyCategory::Author);
		self::assertNull($author->getResource(TPhotoshopResource::Url));
		self::assertNull($author->getResource(TPhotoshopResource::CopyrightFlag));
		self::assertNotNull($author->getResource(TPhotoshopResource::CaptionString), 'Author does not remove the caption');
		self::assertNotNull($author->getResource(TPhotoshopResource::Thumbnail5));
		self::assertFalse($author->getIPTC()->contains(TIPTCTags::ByLine), 'the embedded IPTC by-line went with Author');
		self::assertTrue($author->getIPTC()->contains(TIPTCTags::ImageOrientation), 'the embedded IPTC is redacted, not dropped');

		$thumb = TPhotoshopIRB::parse($irb->toBinary());
		$thumb->clearPrivateData(TPrivacyCategory::Thumbnail);
		self::assertNull($thumb->getResource(TPhotoshopResource::Thumbnail5));
		self::assertNotNull($thumb->getResource(TPhotoshopResource::Url));

		// Everything.
		$removed = $irb->clearPrivateData();
		self::assertGreaterThanOrEqual(6, $removed);
		$irb = TPhotoshopIRB::parse($irb->toBinary());
		foreach ([TPhotoshopResource::Url, TPhotoshopResource::CopyrightFlag, TPhotoshopResource::CaptionString,
			TPhotoshopResource::VersionInfo, TPhotoshopResource::Thumbnail5] as $id) {
			self::assertNull($irb->getResource($id), "resource $id should be gone");
		}
		self::assertNotNull($irb->getResource(0x03ED), 'the resolution info survives');
		self::assertNotNull($irb->getIPTC());
		self::assertTrue($irb->getIPTC()->contains(TIPTCTags::ImageOrientation));
		self::assertSame(0, (new TPhotoshopIRB())->clearPrivateData(), 'empty block is safe');
	}

	//
	// ─── Containers: one call reaches every carrier ──────────────────────────
	//

	private function gdImage(): \GdImage
	{
		$image = imagecreatetruecolor(6, 4);
		imagefilledrectangle($image, 0, 0, 5, 3, imagecolorallocate($image, 10, 120, 200));
		return $image;
	}

	private function encoded(string $function): string
	{
		ob_start();
		$function($this->gdImage());
		return (string) ob_get_clean();
	}

	private function loadedExif(): TEXIF
	{
		$exif = new TEXIF();
		$exif->setSignature('');
		$exif->setValueByName('Artist', 'Jane Doe');
		$exif->setValueByName('Make', 'TestCam');
		$exif->setValueByName('FNumber', 2.8);
		$exif->setLatitude(34.05);
		$exif->setLongitude(-118.24);
		return $exif;
	}

	private function loadedContainerXmp(): TXMP
	{
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'creator', 'Jane Doe');
		$xmp->setProperty(TXMP::NS_EXIF, 'FNumber', '28/10');
		return $xmp;
	}

	private function loadedContainerIptc(): TIPTC
	{
		$iptc = new TIPTC();
		$iptc[TIPTCTags::ByLine] = ['Jane Doe'];
		$iptc[TIPTCTags::ImageOrientation] = 'L';
		return $iptc;
	}

	/**
	 * Loads every carrier a container supports, scrubs the container, reparses, and
	 * asserts each carrier was reached and its picture-describing fields survived.
	 * @param string $class The container class.
	 * @param string $bytes The bare container bytes.
	 * @param callable $extras Adds format-specific carriers to the container.
	 * @param callable $extrasGone Asserts the format-specific carriers were removed.
	 */
	private function assertContainerScrubReachesEveryCarrier(string $class, string $bytes, callable $extras, callable $extrasGone): void
	{
		$file = $class::fromString($bytes);
		if (method_exists($file, 'setEXIF')) {
			$file->setEXIF($this->loadedExif());
		} elseif ($file instanceof TTIFF) {
			$exif = $file->getEXIF();
			$exif->setValueByName('Artist', 'Jane Doe');
			$exif->setValueByName('Make', 'TestCam');
			$exif->setValueByName('FNumber', 2.8);
			$exif->setLatitude(34.05);
			$exif->setLongitude(-118.24);
		}
		$file->setXMP($this->loadedContainerXmp());
		$hasIptc = false;
		try {
			$file->setIPTC($this->loadedContainerIptc());
			$hasIptc = true;
		} catch (\Prado\Exceptions\TIOException $e) {
			// WebP and GIF define no IPTC carrier.
		}
		$extras($file);
		$file = $class::fromString($file->toBinary());   // realistic: scrub a parsed file

		$removed = $file->clearPrivateData();
		self::assertGreaterThan(0, $removed, "$class removed something");
		$round = $class::fromString($file->toBinary());

		if (method_exists($round, 'getEXIF') && $round->getEXIF() !== null) {
			self::assertNull($round->getEXIF()->getValueByName('Artist'), "$class EXIF Artist gone");
			self::assertNull($round->getEXIF()->getValueByName('Make'), "$class EXIF Make gone");
			self::assertNull($round->getEXIF()->getLatitude(), "$class EXIF GPS gone");
			self::assertNotNull($round->getEXIF()->getValueByName('FNumber'), "$class EXIF FNumber kept");
		}
		self::assertNull($round->getXMP()?->getProperty(TXMP::NS_DC, 'creator'), "$class XMP creator gone");
		self::assertSame('28/10', $round->getXMP()?->getProperty(TXMP::NS_EXIF, 'FNumber'), "$class XMP FNumber kept");
		if ($hasIptc) {
			self::assertFalse($round->getIPTC()?->contains(TIPTCTags::ByLine) ?? false, "$class IPTC by-line gone");
			self::assertTrue($round->getIPTC()?->contains(TIPTCTags::ImageOrientation) ?? false, "$class IPTC orientation kept");
		}
		$extrasGone($round);

		// A second scrub finds nothing.
		self::assertSame(0, $round->clearPrivateData(), "$class scrub is idempotent");
	}

	public function testJpegScrubReachesEveryCarrier()
	{
		$this->assertContainerScrubReachesEveryCarrier(
			TJPEG::class,
			$this->encoded('imagejpeg'),
			function (TJPEG $jpeg): void {
				$jpeg->setComment('hi Jane');
				$jfxx = new TJFXX();
				$jfxx->setImage($this->gdImage(), TJFXX::JPEG_THUMB);
				$jpeg->setJFXX($jfxx);
			},
			function (TJPEG $jpeg): void {
				self::assertNull($jpeg->getComment(), 'JPEG comment gone');
				self::assertNull($jpeg->getJFXX(), 'JFXX thumbnail gone');
			},
		);
	}

	public function testPngScrubReachesEveryCarrier()
	{
		$this->assertContainerScrubReachesEveryCarrier(
			TPNG::class,
			$this->encoded('imagepng'),
			function (TPNG $png): void {
				$png->addChunk(new TImageChunk('tEXt', 0, 0, "Author\0Jane Doe"));
				$png->addChunk(new TImageChunk('tEXt', 0, 0, "Comment\0hi Jane"));
			},
			function (TPNG $png): void {
				foreach ($png->getChunks() as $chunk) {
					if ($chunk->getType() === 'tEXt') {
						self::assertStringStartsNotWith("Author\0", $chunk->getData(), 'PNG Author tEXt gone');
						self::assertStringStartsNotWith("Comment\0", $chunk->getData(), 'PNG Comment tEXt gone');
					}
				}
			},
		);
	}

	public function testTiffScrubReachesItsLiveExif()
	{
		$this->assertContainerScrubReachesEveryCarrier(
			TTIFF::class,
			TTIFF::fromImage($this->gdImage())->toBinary(),
			fn (TTIFF $tiff) => null,
			fn (TTIFF $tiff) => null,
		);
	}

	public function testWebPScrubReachesEveryCarrier()
	{
		if (!function_exists('imagewebp')) {
			self::markTestSkipped('GD lacks WebP support.');
		}
		$this->assertContainerScrubReachesEveryCarrier(
			TWebP::class,
			$this->encoded('imagewebp'),
			fn (TWebP $webp) => null,
			fn (TWebP $webp) => null,
		);
	}

	public function testGifScrubReachesEveryCarrier()
	{
		$this->assertContainerScrubReachesEveryCarrier(
			TGIF::class,
			$this->encoded('imagegif'),
			fn (TGIF $gif) => $gif->addComment('hi Jane'),
			fn (TGIF $gif) => self::assertSame([], $gif->getComments(), 'GIF comment gone'),
		);
	}

	public function testContainerFlagIsolation()
	{
		// A single flag on a container removes that category from every carrier and
		// nothing else from any of them.
		$jpeg = TJPEG::fromString($this->encoded('imagejpeg'));
		$jpeg->setEXIF($this->loadedExif());
		$jpeg->setXMP($this->loadedContainerXmp());
		$jpeg->setIPTC($this->loadedContainerIptc());
		$jpeg->setComment('hi Jane');
		$jpeg = TJPEG::fromString($jpeg->toBinary());

		$jpeg->clearPrivateData(TPrivacyCategory::Location);
		$round = TJPEG::fromString($jpeg->toBinary());
		self::assertNull($round->getEXIF()->getLatitude(), 'Location removed EXIF GPS');
		self::assertSame('Jane Doe', $round->getEXIF()->getValueByName('Artist'), 'Location kept EXIF Artist');
		self::assertSame('Jane Doe', $round->getXMP()->getProperty(TXMP::NS_DC, 'creator'), 'Location kept XMP creator');
		self::assertTrue($round->getIPTC()->contains(TIPTCTags::ByLine), 'Location kept IPTC by-line');
		self::assertSame('hi Jane', $round->getComment(), 'Location kept the comment');

		$round->clearPrivateData(TPrivacyCategory::Author);
		$again = TJPEG::fromString($round->toBinary());
		self::assertNull($again->getEXIF()->getValueByName('Artist'));
		self::assertNull($again->getXMP()->getProperty(TXMP::NS_DC, 'creator'));
		self::assertFalse($again->getIPTC()->contains(TIPTCTags::ByLine));
		self::assertSame('hi Jane', $again->getComment(), 'Author does not remove a comment');
		self::assertSame('TestCam', $again->getEXIF()->getValueByName('Make'), 'Author does not remove the camera');
	}

	public function testContainerWithNoMetadataIsSafe()
	{
		foreach (['imagepng' => TPNG::class, 'imagegif' => TGIF::class] as $fn => $class) {
			$file = $class::fromString($this->encoded($fn));
			self::assertSame(0, $file->clearPrivateData(), "$class with no metadata removes nothing");
			self::assertSame([6, 4], [$file->getWidth(), $file->getHeight()], "$class is untouched");
		}
	}

	public function testGdEncoderCommentIsTreatedAsSoftwareMetadata()
	{
		// GD stamps a "CREATOR: gd-jpeg ..." COM comment into every JPEG it writes -- a
		// real toolchain fingerprint -- so a "bare" GD JPEG is not metadata-free, and the
		// scrub correctly removes it under Description.
		$jpeg = TJPEG::fromString($this->encoded('imagejpeg'));
		self::assertStringStartsWith('CREATOR:', (string) $jpeg->getComment());
		self::assertSame(1, $jpeg->clearPrivateData());
		self::assertNull($jpeg->getComment());
		self::assertSame(0, $jpeg->clearPrivateData(), 'and nothing is left');
	}
}
