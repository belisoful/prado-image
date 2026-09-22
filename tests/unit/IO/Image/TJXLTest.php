<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Image\BMFF\TBMFFBox;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\Meta\JUMBF\TJUMBFBox;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TJXL;

/**
 * A JPEG XL is read and written for the boxes ISO/IEC 18181-2 defines — `Exif`, `xml `,
 * and `jumb` — over the shared {@see TBMFFBox} grammar.  A bare codestream has no boxes at
 * all, so setting metadata on one promotes it to a container rather than failing.
 */
class TJXLTest extends PHPUnit\Framework\TestCase
{
	private function box(string $type, string $payload = ''): string
	{
		return pack('N', 8 + strlen($payload)) . $type . $payload;
	}

	/** The fixed prologue of a container: the signature box, then the file-type box. */
	private function prologue(): string
	{
		return TJXL::Signature . $this->box(TJXL::FileTypeBox, TJXL::Brand . pack('N', 0) . TJXL::Brand);
	}

	/** A container file: the prologue, any extra boxes, then the codestream. */
	private function container(string ...$extra): string
	{
		return $this->prologue() . implode('', $extra) . $this->box(TJXL::CodestreamBox, 'codestream bytes');
	}

	private function bare(): string
	{
		return TJXL::CodestreamSignature . 'a bare codestream';
	}

	/** The first bytes of a real `cjxl` 64x48 file, which state the dimensions. */
	private function realCodestream(): string
	{
		return (string) hex2bin('ff0acb060013880200f000b5');
	}

	/** One part of a split codestream, behind its four-byte index. */
	private function partial(int $index, string $data, bool $last = false): string
	{
		return $this->box(TJXL::PartialCodestreamBox, pack('N', $last ? ($index | TJXL::PartialCodestreamLastFlag) : $index) . $data);
	}

	private function exif(string $artist = 'A. Photographer'): TEXIF
	{
		$exif = new TEXIF();
		$exif->setSignature('');
		$exif->setValueByName('Artist', $artist);
		return $exif;
	}

	private function xmp(string $title = 'A JXL'): TXMP
	{
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'title', $title);
		return $xmp;
	}

	//
	// ─── Detection and shapes ────────────────────────────────────────────────
	//

	public function testDetection(): void
	{
		self::assertTrue(TJXL::isJXL($this->container()));
		self::assertTrue(TJXL::isJXL($this->bare()));
		self::assertFalse(TJXL::isJXL("\xFF\xD8\xFF\xE0"));
		self::assertFalse(TJXL::isJXL('RIFF' . pack('V', 4) . 'WEBP'));
		self::assertInstanceOf(TJXL::class, TImageFile::fromString($this->container()));
		self::assertInstanceOf(TJXL::class, TImageFile::fromString($this->bare()));
		self::assertSame('JXL', TJXL::fromString($this->bare())->getFormat());
	}

	public function testTheTwoShapesAreDistinguished(): void
	{
		$bare = TJXL::fromString($this->bare());
		self::assertTrue($bare->getIsBareCodestream());
		self::assertSame([], $bare->getBoxes());

		$container = TJXL::fromString($this->container());
		self::assertFalse($container->getIsBareCodestream());
		self::assertSame(
			[TJXL::SignatureBoxType, TJXL::FileTypeBox, TJXL::CodestreamBox],
			array_map(fn ($b) => $b->getType(), $container->getBoxes()),
		);
		self::assertSame(TJXL::SignatureBoxContent, $container->getBox(TJXL::SignatureBoxType)?->getPayload());
		self::assertNull($container->getBox(TJXL::LevelBox));
	}

	public function testSomethingThatIsNeitherShapeIsRefused(): void
	{
		$this->expectException(TIOException::class);
		TJXL::fromString("\x00\x00\x00\x0Cnope" . "\x0D\x0A\x87\x0A");
	}

	public function testUntouchedFilesRewriteByteForByte(): void
	{
		foreach ([$this->bare(), $this->container(), $this->container($this->box(TJXL::XmlBox, '<x:xmpmeta/>'))] as $bytes) {
			self::assertSame($bytes, TJXL::fromString($bytes)->toBinary());
		}
	}

	public function testTrailingBytesSurviveARewrite(): void
	{
		$bytes = $this->container() . 'tail';   // four bytes cannot be a box header
		self::assertSame($bytes, TJXL::fromString($bytes)->toBinary());
	}

	//
	// ─── The codestream and its dimensions ───────────────────────────────────
	//

	public function testABareCodestreamIsItsOwnCodestream(): void
	{
		$jxl = TJXL::fromString($this->realCodestream());
		self::assertSame($this->realCodestream(), $jxl->getCodestream());
		self::assertSame(64, $jxl->getWidth());
		self::assertSame(48, $jxl->getHeight());
		self::assertSame(3, $jxl->getSizeHeader()?->getAspectRatio());
	}

	public function testAWholeCodestreamBoxGivesTheDimensions(): void
	{
		$bytes = $this->prologue() . $this->box(TJXL::CodestreamBox, $this->realCodestream());
		$jxl = TJXL::fromString($bytes);
		self::assertSame($this->realCodestream(), $jxl->getCodestream());
		self::assertSame(64, $jxl->getWidth());
		self::assertSame(48, $jxl->getHeight());
	}

	public function testASplitCodestreamIsJoinedInIndexOrder(): void
	{
		$whole = $this->realCodestream();
		$bytes = $this->prologue()
			. $this->partial(1, substr($whole, 6), true)      // deliberately out of order
			. $this->box(TJXL::JpegReconstructionBox, 'reconstruction data')
			. $this->partial(0, substr($whole, 0, 6));
		$jxl = TJXL::fromString($bytes);

		self::assertSame($whole, $jxl->getCodestream(), 'the parts are joined by index, not by position');
		self::assertSame(64, $jxl->getWidth());
		self::assertSame(48, $jxl->getHeight());
		self::assertSame($bytes, $jxl->toBinary());
	}

	public function testAFileWithNoCodestreamHasNoDimensions(): void
	{
		$jxl = TJXL::fromString($this->prologue());
		self::assertNull($jxl->getCodestream());
		self::assertNull($jxl->getSizeHeader());
		self::assertNull($jxl->getWidth());
		self::assertNull($jxl->getHeight());
	}

	public function testATooShortPartialBoxIsIgnored(): void
	{
		$bytes = $this->prologue() . $this->box(TJXL::PartialCodestreamBox, 'ab');
		self::assertNull(TJXL::fromString($bytes)->getCodestream());
	}

	public function testAnUnreadableCodestreamLeavesTheDimensionsUnknown(): void
	{
		$jxl = TJXL::fromString($this->prologue() . $this->box(TJXL::CodestreamBox, 'not a codestream'));
		self::assertSame('not a codestream', $jxl->getCodestream());
		self::assertNull($jxl->getSizeHeader());
		self::assertNull($jxl->getWidth());
	}

	public function testPromotingABareCodestreamKeepsTheDimensions(): void
	{
		$jxl = TJXL::fromString($this->realCodestream());
		$jxl->setXmpText('<x:xmpmeta/>');
		$round = TJXL::fromString($jxl->toBinary());
		self::assertSame(64, $round->getWidth());
		self::assertSame(48, $round->getHeight());
		self::assertSame($this->realCodestream(), $round->getCodestream());
	}

	//
	// ─── XMP ─────────────────────────────────────────────────────────────────
	//

	public function testXmpRoundTrips(): void
	{
		$jxl = TJXL::fromString($this->container());
		self::assertNull($jxl->getXMP());
		self::assertNull($jxl->getXmpText());
		self::assertFalse($jxl->hasXMP());

		$jxl->setXMP($this->xmp());
		$round = TJXL::fromString($jxl->toBinary());
		self::assertTrue($round->hasXMP());
		self::assertSame(['A JXL'], $round->getXMP()?->getProperty(TXMP::NS_DC, 'title'));

		$round->setXMP(null);
		self::assertNull(TJXL::fromString($round->toBinary())->getXmpText());
	}

	public function testUnparsableXmpReadsAsAbsent(): void
	{
		$jxl = TJXL::fromString($this->container($this->box(TJXL::XmlBox, 'not xmp at all')));
		self::assertSame('not xmp at all', $jxl->getXmpText());
		self::assertNull($jxl->getXMP());
	}

	//
	// ─── EXIF ────────────────────────────────────────────────────────────────
	//

	public function testExifRoundTrips(): void
	{
		$jxl = TJXL::fromString($this->container());
		self::assertNull($jxl->getEXIF());
		self::assertFalse($jxl->hasEXIF());

		$jxl->setEXIF($this->exif());
		$round = TJXL::fromString($jxl->toBinary());
		self::assertSame('A. Photographer', $round->getEXIF()?->getValueByName('Artist'));
		self::assertSame("\x00\x00\x00\x00", substr((string) $round->getBox(TJXL::ExifBox)?->getPayload(), 0, 4));

		$round->setEXIF(null);
		self::assertNull(TJXL::fromString($round->toBinary())->getEXIF());
	}

	public function testANonZeroTiffOffsetIsHonoured(): void
	{
		$tiff = $this->exif('Offset Photographer')->toBinary();
		$jxl = TJXL::fromString($this->container($this->box(TJXL::ExifBox, pack('N', 3) . 'pad' . $tiff)));
		self::assertSame('Offset Photographer', $jxl->getEXIF()?->getValueByName('Artist'));
	}

	public function testAnUnreadableExifBoxReadsAsAbsent(): void
	{
		self::assertNull(TJXL::fromString($this->container($this->box(TJXL::ExifBox, 'ab')))->getEXIF());
		self::assertNull(TJXL::fromString($this->container($this->box(TJXL::ExifBox, pack('N', 0))))->getEXIF());
		self::assertNull(TJXL::fromString($this->container($this->box(TJXL::ExifBox, pack('N', 0) . 'garbage')))->getEXIF());
	}

	//
	// ─── JUMBF ───────────────────────────────────────────────────────────────
	//

	public function testJumbfBoxesRoundTrip(): void
	{
		$jxl = TJXL::fromString($this->container());
		self::assertSame([], $jxl->getJumbfBoxes());

		$jxl->setJumbfBoxes([TJUMBFBox::xml('annotation', '<rdf:RDF/>'), TJUMBFBox::json('ld', '{"a":1}')]);
		$round = TJXL::fromString($jxl->toBinary());
		$boxes = $round->getJumbfBoxes();
		self::assertCount(2, $boxes);
		self::assertSame('annotation', $boxes[0]->getLabel());
		self::assertSame('<rdf:RDF/>', $boxes[0]->getContentData());
		self::assertSame('{"a":1}', $boxes[1]->getContentData());

		$round->setJumbfBoxes([]);
		self::assertSame([], TJXL::fromString($round->toBinary())->getJumbfBoxes());
	}

	public function testAJumbBoxIsAlwaysReadableAsASuperbox(): void
	{
		$jxl = TJXL::fromString($this->container($this->box(TJXL::JumbfBox, '')));
		self::assertCount(1, $jxl->getJumbfBoxes(), 'an empty superbox still parses');
		self::assertNull($jxl->getJumbfBoxes()[0]->getLabel());

		// Content that is not a whole child box is kept as the superbox's remainder.
		$jxl->setBoxes([new TBMFFBox(TJXL::JumbfBox, 'x')]);
		self::assertCount(1, $jxl->getJumbfBoxes());
		self::assertSame('x', $jxl->getJumbfBoxes()[0]->getRemainder());
	}

	//
	// ─── Carriers JXL does not have ──────────────────────────────────────────
	//

	public function testIptcIsRefusedRatherThanDropped(): void
	{
		$jxl = TJXL::fromString($this->container());
		self::assertNull($jxl->getIPTC());
		self::assertFalse($jxl->hasIPTC());
		$jxl->setIPTC(null);
		$this->expectException(TIOException::class);
		$jxl->setIPTC(new TIPTC());
	}

	public function testIccProfileIsRefusedRatherThanDropped(): void
	{
		$jxl = TJXL::fromString($this->container());
		self::assertNull($jxl->getICCProfile());
		self::assertFalse($jxl->hasICCProfile());
		$jxl->setICCProfile(null);
		$this->expectException(TIOException::class);
		$jxl->setICCProfile('a profile');
	}

	//
	// ─── Placement and promotion ─────────────────────────────────────────────
	//

	public function testNewBoxesArePlacedBeforeTheCodestream(): void
	{
		$jxl = TJXL::fromString($this->container());
		$jxl->setXmpText('<x:xmpmeta/>');
		$jxl->setEXIF($this->exif());   // each lands before the codestream, in the order written
		self::assertSame(
			[TJXL::SignatureBoxType, TJXL::FileTypeBox, TJXL::XmlBox, TJXL::ExifBox, TJXL::CodestreamBox],
			array_map(fn ($b) => $b->getType(), $jxl->getBoxes()),
		);
	}

	public function testAnExistingBoxIsRewrittenWhereItIs(): void
	{
		$jxl = TJXL::fromString($this->container($this->box(TJXL::XmlBox, 'old')));
		$jxl->setXmpText('a much longer packet');
		self::assertSame(
			[TJXL::SignatureBoxType, TJXL::FileTypeBox, TJXL::XmlBox, TJXL::CodestreamBox],
			array_map(fn ($b) => $b->getType(), $jxl->getBoxes()),
		);
		self::assertSame('a much longer packet', $jxl->getXmpText());
	}

	public function testWithoutACodestreamANewBoxIsAppended(): void
	{
		$jxl = TJXL::fromString($this->prologue());
		$jxl->setXmpText('<x:xmpmeta/>');
		self::assertSame(
			[TJXL::SignatureBoxType, TJXL::FileTypeBox, TJXL::XmlBox],
			array_map(fn ($b) => $b->getType(), $jxl->getBoxes()),
		);
	}

	public function testSettingMetadataPromotesABareCodestream(): void
	{
		$jxl = TJXL::fromString($this->bare());
		$jxl->setXMP($this->xmp('Promoted'));

		self::assertFalse($jxl->getIsBareCodestream());
		$bytes = $jxl->toBinary();
		self::assertStringStartsWith(TJXL::Signature, $bytes);

		$round = TJXL::fromString($bytes);
		self::assertSame(
			[TJXL::SignatureBoxType, TJXL::FileTypeBox, TJXL::XmlBox, TJXL::CodestreamBox],
			array_map(fn ($b) => $b->getType(), $round->getBoxes()),
		);
		self::assertSame(['Promoted'], $round->getXMP()?->getProperty(TXMP::NS_DC, 'title'));
		self::assertSame(
			$this->bare(),
			$round->getBox(TJXL::CodestreamBox)?->getPayload(),
			'the codestream is carried over verbatim',
		);
	}

	public function testPromotionAlsoHappensForJumbfAndIsIdempotent(): void
	{
		$jxl = TJXL::fromString($this->bare());
		$jxl->setJumbfBoxes([TJUMBFBox::xml('l', '<a/>')]);
		self::assertFalse($jxl->getIsBareCodestream());
		self::assertCount(1, $jxl->getJumbfBoxes());

		$jxl->setXmpText('<x:xmpmeta/>');   // already a container: nothing is re-promoted
		self::assertSame(
			[TJXL::SignatureBoxType, TJXL::FileTypeBox, TJXL::JumbfBox, TJXL::XmlBox, TJXL::CodestreamBox],
			array_map(fn ($b) => $b->getType(), $jxl->getBoxes()),
		);
	}

	public function testBoxesCanBeReplacedWholesale(): void
	{
		$jxl = TJXL::fromString($this->bare());
		$jxl->setBoxes([new TBMFFBox(TJXL::SignatureBoxType, TJXL::SignatureBoxContent)]);
		self::assertFalse($jxl->getIsBareCodestream());
		self::assertCount(1, $jxl->getBoxes());
	}

	//
	// ─── Brotli-compressed carriers ──────────────────────────────────────────
	//

	public function testACompressedCarrierIsReportedRatherThanMistakenForAbsence(): void
	{
		$jxl = TJXL::fromString($this->container($this->box(TJXL::BrotliBox, TJXL::XmlBox . 'brotli bytes')));
		self::assertNull($jxl->getXmpText(), 'Brotli is not decompressed here');
		self::assertTrue($jxl->getHasBrotliCarrier(TJXL::XmlBox));
		self::assertFalse($jxl->getHasBrotliCarrier(TJXL::ExifBox));
		self::assertFalse(TJXL::fromString($this->container())->getHasBrotliCarrier(TJXL::XmlBox));
	}

	public function testWritingACarrierRemovesItsCompressedStandIn(): void
	{
		$bytes = $this->container(
			$this->box(TJXL::BrotliBox, TJXL::XmlBox . 'brotli bytes'),
			$this->box(TJXL::BrotliBox, TJXL::ExifBox . 'brotli bytes'),
		);
		$jxl = TJXL::fromString($bytes);
		$jxl->setXmpText('<x:xmpmeta/>');

		self::assertFalse($jxl->getHasBrotliCarrier(TJXL::XmlBox), 'the stale compressed copy is gone');
		self::assertTrue($jxl->getHasBrotliCarrier(TJXL::ExifBox), 'and an unrelated one is left alone');
		self::assertSame(
			[TJXL::SignatureBoxType, TJXL::FileTypeBox, TJXL::BrotliBox, TJXL::XmlBox, TJXL::CodestreamBox],
			array_map(fn ($b) => $b->getType(), $jxl->getBoxes()),
		);
	}

	//
	// ─── Privacy and streaming ───────────────────────────────────────────────
	//

	public function testScrubbingReachesTheCarriers(): void
	{
		$jxl = TJXL::fromString($this->container());
		$jxl->setEXIF($this->exif());
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'creator', 'A Director');
		$jxl->setXMP($xmp);

		self::assertGreaterThan(0, $jxl->clearPrivateData());
		self::assertNull($jxl->getEXIF()?->getValueByName('Artist'));
		self::assertNull($jxl->getXMP()?->getProperty(TXMP::NS_DC, 'creator'));
		self::assertSame('codestream bytes', $jxl->getBox(TJXL::CodestreamBox)?->getPayload(), 'the picture is untouched');
	}

	public function testStreamingOut(): void
	{
		$bytes = $this->container($this->box(TJXL::XmlBox, '<x:xmpmeta/>'));
		$target = fopen('php://temp', 'r+b');
		$written = TJXL::fromString($bytes)->streamTo($target);
		rewind($target);
		self::assertSame($bytes, (string) stream_get_contents($target));
		self::assertSame(strlen($bytes), $written);
		fclose($target);
	}
}
