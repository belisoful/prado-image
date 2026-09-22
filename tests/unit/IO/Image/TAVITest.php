<?php

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TAVI;
use Prado\IO\Image\TImageChunk;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TPrivacyCategory;
use Prado\IO\Image\TRIFFChunkType;
use Prado\IO\Image\TRIFFList;
use Prado\IO\TStream;

/**
 * An AVI is read and written for the carriers the format defines — the `LIST INFO` tags,
 * the `_PMX` XMP packet, and the `IDIT` timestamp — while the `LIST movi` media never
 * moves, because `idx1` and any OpenDML index address it.
 */
class TAVITest extends PHPUnit\Framework\TestCase
{
	private function chunk(string $id, string $payload): string
	{
		return $id . pack('V', strlen($payload)) . $payload . ((strlen($payload) & 1) ? "\0" : '');
	}

	private function riffList(string $listType, string ...$children): string
	{
		return $this->chunk(TRIFFChunkType::RiffList, $listType . implode('', $children));
	}

	private function riff(string $formType, string ...$chunks): string
	{
		$body = $formType . implode('', $chunks);
		return TRIFFChunkType::Riff . pack('V', strlen($body)) . $body;
	}

	/** A `MainAVIHeader`: 14 DWORDs, with the frame size in the ninth and tenth. */
	private function avih(int $width = 320, int $height = 240, int $frames = 12, int $rate = 40000): string
	{
		return pack('VVVVVVVVVVVVVV', $rate, 0, 0, 0x10, $frames, 0, 1, 0, $width, $height, 0, 0, 0, 0);
	}

	private function infoList(string ...$pairs): string
	{
		$children = '';
		foreach (array_chunk($pairs, 2) as [$id, $value]) {
			$children .= $this->chunk($id, $value . "\0");
		}
		return $this->chunk(TRIFFChunkType::RiffList, TRIFFChunkType::InfoList . $children);
	}

	/** An AVI whose leading chunks are headers, then the media, then the index. */
	private function avi(string ...$extra): string
	{
		return $this->riff(
			TAVI::FormType,
			$this->riffList(TRIFFChunkType::HeaderList, $this->chunk(TRIFFChunkType::AviHeader, $this->avih())),
			...[
				...$extra,
				$this->riffList(TRIFFChunkType::MovieList, $this->chunk('00dc', str_repeat("\x11", 64))),
				$this->chunk(TRIFFChunkType::Index, str_repeat("\x22", 16)),
			],
		);
	}

	/**
	 * An AVI whose `idx1` is correct and `movi`-relative, as a real writer's is — the shape
	 * that lets the chunks be rearranged instead of fitted into padding.  The single media
	 * chunk's header sits four bytes into the list's payload, right after the `movi` id.
	 * @param string[] $extra
	 */
	private function indexedAvi(string ...$extra): string
	{
		return $this->riff(
			TAVI::FormType,
			$this->riffList(TRIFFChunkType::HeaderList, $this->chunk(TRIFFChunkType::AviHeader, $this->avih())),
			...[
				...$extra,
				$this->riffList(TRIFFChunkType::MovieList, $this->chunk('00dc', str_repeat("\x11", 64))),
				$this->chunk(TRIFFChunkType::Index, '00dc' . pack('V', 0x10) . pack('V', 4) . pack('V', 64)),
			],
		);
	}

	/** Checks that every index entry still names the chunk it points at. */
	private function indexIsValid(string $bytes): bool
	{
		$index = TAVI::fromString($bytes)->getRIFF()->getChunk(TRIFFChunkType::Index)?->getData() ?? '';
		$movi = (int) strpos($bytes, TRIFFChunkType::MovieList);
		for ($i = 0; $i + 16 <= strlen($index); $i += 16) {
			if (substr($bytes, $movi + (int) unpack('V', substr($index, $i + 8, 4))[1], 4) !== substr($index, $i, 4)) {
				return false;
			}
		}
		return true;
	}

	/** The byte offset the media list sits at, which no metadata write may change. */
	private function moviOffset(string $bytes): int
	{
		return (int) strpos($bytes, TRIFFChunkType::MovieList);
	}

	//
	// ─── Detection and headers ───────────────────────────────────────────────
	//

	public function testDetection(): void
	{
		self::assertTrue(TAVI::isAVI($this->avi()));
		self::assertFalse(TAVI::isAVI('RIFF' . pack('V', 4) . 'WEBP'));
		self::assertFalse(TAVI::isAVI('RIFF'));
		self::assertInstanceOf(TAVI::class, TImageFile::fromString($this->avi()));
		self::assertSame('AVI', TAVI::fromString($this->avi())->getFormat());
	}

	public function testMainHeaderIsRead(): void
	{
		$avi = TAVI::fromString($this->avi());
		self::assertSame(320, $avi->getWidth());
		self::assertSame(240, $avi->getHeight());
		self::assertSame(12, $avi->getTotalFrames());
		self::assertSame(40000, $avi->getMicroSecPerFrame());
	}

	public function testAMissingOrShortMainHeaderLeavesTheFactsUnknown(): void
	{
		$noHdrl = TAVI::fromString($this->riff(TAVI::FormType, $this->chunk('JUNK', 'xxxx')));
		self::assertNull($noHdrl->getWidth());
		self::assertNull($noHdrl->getTotalFrames());

		$shortAvih = TAVI::fromString($this->riff(
			TAVI::FormType,
			$this->riffList(TRIFFChunkType::HeaderList, $this->chunk(TRIFFChunkType::AviHeader, str_repeat("\0", 12))),
		));
		self::assertNull($shortAvih->getWidth());
		self::assertNull($shortAvih->getMicroSecPerFrame());
	}

	public function testNonAviRiffIsRefused(): void
	{
		$this->expectException(TIOException::class);
		TAVI::fromString('RIFF' . pack('V', 4) . 'WAVE');
	}

	public function testUntouchedAviRewritesByteForByte(): void
	{
		$bytes = $this->avi($this->infoList('INAM', 'A Clip'), $this->chunk(TRIFFChunkType::XmpRiff, '<x:xmpmeta/>'));
		self::assertSame($bytes, TAVI::fromString($bytes)->toBinary());
	}

	public function testAFreshContainerStartsAnEmptyAviForm(): void
	{
		$avi = new TAVI();
		self::assertSame(TAVI::FormType, $avi->getRIFF()->getFormType());
		$avi->setInfoValue(TAVI::InfoName, 'Fresh');
		self::assertSame('Fresh', TAVI::fromString($avi->toBinary())->getInfoValue(TAVI::InfoName));
	}

	//
	// ─── The INFO list ───────────────────────────────────────────────────────
	//

	public function testInfoTagsAreRead(): void
	{
		$avi = TAVI::fromString($this->avi($this->infoList('INAM', 'A Clip', 'IART', 'A Director')));
		self::assertSame(['INAM' => 'A Clip', 'IART' => 'A Director'], $avi->getInfo());
		self::assertSame('A Clip', $avi->getInfoValue(TAVI::InfoName));
		self::assertNull($avi->getInfoValue(TAVI::InfoComment));
		self::assertSame([], TAVI::fromString($this->avi())->getInfo());
	}

	public function testInfoTagsAreWritten(): void
	{
		$avi = TAVI::fromString($this->avi());
		$avi->setInfoValue(TAVI::InfoName, 'Written');
		$avi->setInfoValue(TAVI::InfoArtist, 'Someone');
		$round = TAVI::fromString($avi->toBinary());
		self::assertSame(['INAM' => 'Written', 'IART' => 'Someone'], $round->getInfo());

		$round->setInfoValue(TAVI::InfoArtist, null);
		self::assertSame(['INAM' => 'Written'], TAVI::fromString($round->toBinary())->getInfo());
	}

	public function testOddLengthInfoValuesArePadded(): void
	{
		$avi = TAVI::fromString($this->avi());
		$avi->setInfo(['ICMT' => 'odd', 'INAM' => 'even']);      // 'odd' + NUL is even, 'even' + NUL is odd
		self::assertSame(['ICMT' => 'odd', 'INAM' => 'even'], TAVI::fromString($avi->toBinary())->getInfo());
	}

	public function testAnEmptyInfoArrayDropsTheList(): void
	{
		$avi = TAVI::fromString($this->avi($this->infoList('INAM', 'Gone')));
		$avi->setInfo([]);
		$round = TAVI::fromString($avi->toBinary());
		self::assertSame([], $round->getInfo());
		self::assertNull($round->getRIFF()->getList(TRIFFChunkType::InfoList));
	}

	//
	// ─── XMP and the digitization time ───────────────────────────────────────
	//

	public function testXmpRoundTrips(): void
	{
		$avi = TAVI::fromString($this->avi());
		self::assertNull($avi->getXMP());
		self::assertNull($avi->getXmpText());
		self::assertFalse($avi->hasXMP());

		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'title', 'An AVI');
		$avi->setXMP($xmp);

		$round = TAVI::fromString($avi->toBinary());
		self::assertTrue($round->hasXMP());
		self::assertSame(['An AVI'], $round->getXMP()?->getProperty(TXMP::NS_DC, 'title'), 'dc:title is a LangAlt, per TXMPSchemas');
		self::assertNotNull($round->getRIFF()->getChunk(TRIFFChunkType::XmpRiff));

		$round->setXMP(null);
		self::assertNull(TAVI::fromString($round->toBinary())->getXmpText());
	}

	public function testUnparsableXmpReadsAsAbsent(): void
	{
		$avi = TAVI::fromString($this->avi($this->chunk(TRIFFChunkType::XmpRiff, 'not xmp at all')));
		self::assertSame('not xmp at all', $avi->getXmpText());
		self::assertNull($avi->getXMP());
	}

	public function testDigitizationTimeRoundTrips(): void
	{
		$avi = TAVI::fromString($this->avi());
		self::assertNull($avi->getDigitizationTime());
		$avi->setDigitizationTime('Mon Sep 21 10:00:00 2026');
		self::assertSame('Mon Sep 21 10:00:00 2026', TAVI::fromString($avi->toBinary())->getDigitizationTime());

		$avi->setDigitizationTime(null);
		self::assertNull(TAVI::fromString($avi->toBinary())->getDigitizationTime());
	}

	//
	// ─── Carriers AVI does not have ──────────────────────────────────────────
	//

	public function testIptcIsRefusedRatherThanDropped(): void
	{
		$avi = TAVI::fromString($this->avi());
		self::assertNull($avi->getIPTC());
		self::assertFalse($avi->hasIPTC());
		$avi->setIPTC(null);    // clearing is always fine
		$this->expectException(TIOException::class);
		$avi->setIPTC(new TIPTC());
	}

	public function testIccProfileIsRefusedRatherThanDropped(): void
	{
		$avi = TAVI::fromString($this->avi());
		self::assertNull($avi->getICCProfile());
		self::assertFalse($avi->hasICCProfile());
		$avi->setICCProfile(null);
		$this->expectException(TIOException::class);
		$avi->setICCProfile('a profile');
	}

	public function testExifIsRefusedRatherThanDropped(): void
	{
		$avi = TAVI::fromString($this->avi());
		self::assertNull($avi->getEXIF());
		$this->expectException(TIOException::class);
		$avi->setEXIF(new \Prado\IO\Image\Meta\TEXIF());
	}

	//
	// ─── Placement: the media list never moves ───────────────────────────────
	//

	public function testANewMetadataChunkIsAppendedPastTheMedia(): void
	{
		$bytes = $this->avi();
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText('<x:xmpmeta>a packet</x:xmpmeta>');
		$out = $avi->toBinary();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(TRIFFChunkType::XmpRiff, $avi->getRIFF()->getChunks()[count($avi->getRIFF()->getChunks()) - 1]->getType());
	}

	public function testAChunkBeforeTheMediaIsRewrittenInPlaceAtTheSameLength(): void
	{
		$bytes = $this->avi($this->chunk(TRIFFChunkType::XmpRiff, '12345678'));
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText('abcdefgh');   // same length, so nothing shifts and no JUNK is needed
		$out = $avi->toBinary();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(strlen($bytes), strlen($out));
		self::assertStringNotContainsString(TRIFFChunkType::Junk, $out);
		self::assertSame('abcdefgh', TAVI::fromString($out)->getXmpText());
	}

	public function testASmallerChunkBeforeTheMediaKeepsItsSlot(): void
	{
		// 24 bytes of payload gives a 32-byte slot; 8 bytes of payload needs 16, leaving 16 —
		// room for a JUNK chunk of 8 payload bytes.
		$bytes = $this->avi($this->chunk(TRIFFChunkType::XmpRiff, str_repeat('x', 24)));
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText(str_repeat('y', 8));
		$out = $avi->toBinary();

		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'the media does not move');
		self::assertSame(strlen($bytes), strlen($out), 'and the file is the same length');
		$chunks = $avi->getRIFF()->getChunks();
		self::assertSame(TRIFFChunkType::XmpRiff, $chunks[1]->getType(), 'the chunk stays where it was');
		self::assertSame(TRIFFChunkType::Junk, $chunks[2]->getType(), 'and the remainder is padding');
		self::assertSame(8, $chunks[2]->getSize());
		self::assertSame(str_repeat('y', 8), TAVI::fromString($out)->getXmpText());
	}

	public function testAChunkExactlyEightBytesSmallerStillKeepsItsSlot(): void
	{
		// The boundary: freeing exactly eight bytes leaves room for an empty JUNK chunk.
		$bytes = $this->avi($this->chunk(TRIFFChunkType::XmpRiff, str_repeat('x', 16)));
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText(str_repeat('y', 8));
		$out = $avi->toBinary();

		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(strlen($bytes), strlen($out));
		$chunks = $avi->getRIFF()->getChunks();
		self::assertSame(TRIFFChunkType::Junk, $chunks[2]->getType());
		self::assertSame(0, $chunks[2]->getSize(), 'an empty JUNK chunk is exactly eight bytes');
	}

	public function testAChunkTooLittleSmallerCannotKeepItsSlot(): void
	{
		// Freeing only two bytes leaves nowhere to put a padding chunk, so it has to move.
		$bytes = $this->avi($this->chunk(TRIFFChunkType::XmpRiff, str_repeat('x', 16)));
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText(str_repeat('y', 14));
		$out = $avi->toBinary();

		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'the media still does not move');
		$chunks = $avi->getRIFF()->getChunks();
		self::assertSame(TRIFFChunkType::Junk, $chunks[1]->getType(), 'the old slot is vacated');
		self::assertSame(TRIFFChunkType::XmpRiff, $chunks[count($chunks) - 1]->getType(), 'and the chunk went to the end');
		self::assertSame(str_repeat('y', 14), TAVI::fromString($out)->getXmpText());
	}

	public function testAResizedChunkBeforeTheMediaBecomesJunkAndMovesToTheEnd(): void
	{
		$bytes = $this->avi($this->chunk(TRIFFChunkType::XmpRiff, '12345678'));
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText('a much longer packet than before');
		$out = $avi->toBinary();

		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'the media list must not move');
		$chunks = $avi->getRIFF()->getChunks();
		self::assertSame(TRIFFChunkType::Junk, $chunks[1]->getType());
		self::assertSame(8, $chunks[1]->getSize(), 'the JUNK fills exactly the old slot');
		self::assertSame(TRIFFChunkType::XmpRiff, $chunks[count($chunks) - 1]->getType());
		self::assertSame('a much longer packet than before', TAVI::fromString($out)->getXmpText());
	}

	public function testRemovingAChunkBeforeTheMediaLeavesJunkBehind(): void
	{
		$bytes = $this->avi($this->chunk(TRIFFChunkType::XmpRiff, '12345678'));
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText(null);
		$out = $avi->toBinary();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertNull(TAVI::fromString($out)->getXmpText());
		self::assertSame(TRIFFChunkType::Junk, $avi->getRIFF()->getChunks()[1]->getType());
	}

	public function testAChunkAfterTheMediaIsRewrittenWhereItIs(): void
	{
		$bytes = $this->riff(
			TAVI::FormType,
			$this->riffList(TRIFFChunkType::HeaderList, $this->chunk(TRIFFChunkType::AviHeader, $this->avih())),
			$this->riffList(TRIFFChunkType::MovieList, $this->chunk('00dc', str_repeat("\x11", 32))),
			$this->chunk(TRIFFChunkType::XmpRiff, 'short'),
		);
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText('a considerably longer packet');
		$chunks = $avi->getRIFF()->getChunks();
		self::assertCount(3, $chunks, 'nothing is appended and no JUNK is added');
		self::assertSame(TRIFFChunkType::XmpRiff, $chunks[2]->getType());
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($avi->toBinary()));

		$avi->setXmpText(null);
		self::assertCount(2, $avi->getRIFF()->getChunks(), 'and it is simply removed');
	}

	public function testWithoutMediaEveryChunkIsFreeToMove(): void
	{
		$bytes = $this->riff(TAVI::FormType, $this->chunk(TRIFFChunkType::XmpRiff, 'short'));
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText('a considerably longer packet');
		self::assertCount(1, $avi->getRIFF()->getChunks());
		self::assertSame('a considerably longer packet', TAVI::fromString($avi->toBinary())->getXmpText());
	}

	public function testTheInfoListFollowsTheSamePlacementRule(): void
	{
		$bytes = $this->avi($this->infoList('INAM', 'A Clip'));
		$avi = TAVI::fromString($bytes);
		$avi->setInfoValue(TAVI::InfoComment, 'A much longer comment than the list held');
		$out = $avi->toBinary();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(
			['INAM' => 'A Clip', 'ICMT' => 'A much longer comment than the list held'],
			TAVI::fromString($out)->getInfo(),
		);
		self::assertInstanceOf(TRIFFList::class, $avi->getRIFF()->getChunks()[count($avi->getRIFF()->getChunks()) - 1]);
	}

	public function testAGrownChunkIsWrittenIntoTheFilesOtherPadding(): void
	{
		// Padding the file already carries is space it has set aside, so filling it moves
		// nothing.  This file cannot be rearranged, so that is the only way to avoid growing.
		$bytes = $this->avi(
			$this->chunk(TRIFFChunkType::XmpRiff, 'short'),
			$this->chunk(TRIFFChunkType::Junk, str_repeat("\0", 200)),
		);
		$avi = TAVI::fromString($bytes);
		self::assertFalse($avi->getCanRearrange());

		$avi->setXmpText(str_repeat('a much longer packet ', 6));
		$out = $avi->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'the padding paid for it');
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame(str_repeat('a much longer packet ', 6), TAVI::fromString($out)->getXmpText());
	}

	public function testAdjacentPaddingIsMergedSoItCanBeUsedTogether(): void
	{
		// The vacated slot is 14 bytes and the padding beside it 56; neither can take a
		// 60-byte chunk, but the 70 bytes they make together can.
		$bytes = $this->avi(
			$this->chunk(TRIFFChunkType::XmpRiff, 'short'),
			$this->chunk(TRIFFChunkType::Junk, str_repeat("\0", 48)),
		);
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText(str_repeat('x', 52));
		$out = $avi->toBinary();

		self::assertSame(strlen($bytes), strlen($out));
		self::assertSame(str_repeat('x', 52), TAVI::fromString($out)->getXmpText());
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
	}

	public function testANewChunkAlsoLooksForPaddingFirst(): void
	{
		$bytes = $this->avi($this->chunk(TRIFFChunkType::Junk, str_repeat("\0", 200)));
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText('<x:xmpmeta/>');
		$out = $avi->toBinary();

		self::assertSame(strlen($bytes), strlen($out), 'it went into the padding, not onto the end');
		self::assertSame('<x:xmpmeta/>', TAVI::fromString($out)->getXmpText());
		self::assertSame(TRIFFChunkType::XmpRiff, $avi->getRIFF()->getChunks()[1]->getType(), 'where the padding was');
	}

	public function testPaddingTooSmallStillSendsTheChunkToTheEnd(): void
	{
		$bytes = $this->avi(
			$this->chunk(TRIFFChunkType::XmpRiff, 'short'),
			$this->chunk(TRIFFChunkType::Junk, str_repeat("\0", 8)),
		);
		$avi = TAVI::fromString($bytes);
		$avi->setXmpText(str_repeat('y', 200));
		$out = $avi->toBinary();

		self::assertGreaterThan(strlen($bytes), strlen($out));
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'the media still never moves');
		self::assertSame(TRIFFChunkType::XmpRiff, $avi->getRIFF()->getChunks()[count($avi->getRIFF()->getChunks()) - 1]->getType());
	}

	//
	// ─── Rearranging, when the file can be shown to tolerate it ──────────────
	//

	public function testAFileWithARelativeIndexMayBeRearranged(): void
	{
		$bytes = $this->indexedAvi($this->infoList('INAM', 'A Clip'));
		$avi = TAVI::fromString($bytes);
		self::assertTrue($avi->getCanRearrange());
		self::assertTrue($this->indexIsValid($bytes), 'the fixture itself is sound');

		$avi->setInfoValue(TAVI::InfoComment, str_repeat('A long comment. ', 8));
		$out = $avi->toBinary();

		self::assertGreaterThan(strlen($bytes), strlen($out), 'the file grows by what was added');
		self::assertStringNotContainsString(TRIFFChunkType::Junk, $out, 'and no padding is left behind');
		self::assertTrue($this->indexIsValid($out), 'the index still names what it points at');
		self::assertSame(str_repeat('A long comment. ', 8), TAVI::fromString($out)->getInfoValue(TAVI::InfoComment));
		self::assertSame('A Clip', TAVI::fromString($out)->getInfoValue(TAVI::InfoName));
	}

	public function testRearrangingShrinksAndRemovesWithoutPadding(): void
	{
		$bytes = $this->indexedAvi($this->infoList('INAM', str_repeat('A long title ', 4)));
		$avi = TAVI::fromString($bytes);

		$avi->setInfoValue(TAVI::InfoName, 'Short');
		$out = $avi->toBinary();
		self::assertLessThan(strlen($bytes), strlen($out), 'the file shrinks');
		self::assertStringNotContainsString(TRIFFChunkType::Junk, $out);
		self::assertTrue($this->indexIsValid($out));

		$dropped = TAVI::fromString($out);
		$dropped->setInfo([]);
		$gone = $dropped->toBinary();
		self::assertNull(TAVI::fromString($gone)->getInfoValue(TAVI::InfoName));
		self::assertStringNotContainsString(TRIFFChunkType::Junk, $gone, 'removal leaves nothing behind either');
		self::assertTrue($this->indexIsValid($gone));

		// Removing what is not there changes nothing at all.
		$absent = TAVI::fromString($gone);
		$absent->setXmpText(null);
		self::assertSame($gone, $absent->toBinary());
	}

	public function testANewChunkIsPlacedAheadOfTheMedia(): void
	{
		$avi = TAVI::fromString($this->indexedAvi());
		$avi->setXmpText('<x:xmpmeta/>');
		$types = array_map(fn ($c) => $c->getType(), $avi->getRIFF()->getChunks());
		self::assertSame(
			[TRIFFChunkType::RiffList, TRIFFChunkType::XmpRiff, TRIFFChunkType::RiffList, TRIFFChunkType::Index],
			$types,
			'metadata belongs before the media, not after the index',
		);
		self::assertTrue($this->indexIsValid($avi->toBinary()));
	}

	public function testAFileWithNoIndexAtAllMayBeRearranged(): void
	{
		$bytes = $this->riff(
			TAVI::FormType,
			$this->riffList(TRIFFChunkType::HeaderList, $this->chunk(TRIFFChunkType::AviHeader, $this->avih())),
			$this->riffList(TRIFFChunkType::MovieList, $this->chunk('00dc', 'media')),
		);
		self::assertTrue(TAVI::fromString($bytes)->getCanRearrange(), 'nothing addresses the media');
	}

	public function testAnAbsoluteIndexIsNotRearranged(): void
	{
		// The same file with an index measured from the start of the file instead.
		$relative = $this->indexedAvi();
		$absolute = str_replace(
			'00dc' . pack('V', 0x10) . pack('V', 4) . pack('V', 64),
			'00dc' . pack('V', 0x10) . pack('V', (int) strpos($relative, TRIFFChunkType::MovieList) + 4) . pack('V', 64),
			$relative,
		);
		self::assertFalse(TAVI::fromString($absolute)->getCanRearrange());
		self::assertTrue(TAVI::fromString($relative)->getCanRearrange());
	}

	public function testAnOpenDmlIndexIsNotRearranged(): void
	{
		$direct = $this->riff(
			TAVI::FormType,
			$this->riffList(
				TRIFFChunkType::HeaderList,
				$this->chunk(TRIFFChunkType::AviHeader, $this->avih()) . $this->chunk(TRIFFChunkType::OpenDmlIndex, str_repeat("\0", 32)),
			),
			$this->riffList(TRIFFChunkType::MovieList, $this->chunk('00dc', 'media')),
		);
		self::assertFalse(TAVI::fromString($direct)->getCanRearrange(), 'a super-index holds absolute positions');

		$nested = $this->riff(
			TAVI::FormType,
			$this->riffList(
				TRIFFChunkType::HeaderList,
				$this->chunk(TRIFFChunkType::AviHeader, $this->avih())
				. $this->riffList(TRIFFChunkType::StreamList, $this->chunk(TRIFFChunkType::OpenDmlIndex, str_repeat("\0", 32))),
			),
			$this->riffList(TRIFFChunkType::MovieList, $this->chunk('00dc', 'media')),
		);
		self::assertFalse(TAVI::fromString($nested)->getCanRearrange(), 'including one inside a stream list');
	}

	public function testAnUnreadableMediaListIsNotRearranged(): void
	{
		// A deferred media list has not been read, so nothing can be proven about it.
		$bytes = $this->indexedAvi();
		$lazy = TAVI::fromStreamLazy(TStream::fromString($bytes));
		self::assertFalse($lazy->getCanRearrange());

		// Nor can a file whose index is too short to hold an entry, or which has no media.
		$short = $this->riff(
			TAVI::FormType,
			$this->riffList(TRIFFChunkType::MovieList, $this->chunk('00dc', 'media')),
			$this->chunk(TRIFFChunkType::Index, 'tiny'),
		);
		self::assertFalse(TAVI::fromString($short)->getCanRearrange());
		$noMedia = $this->riff(TAVI::FormType, $this->chunk(TRIFFChunkType::Index, str_repeat("\0", 16)));
		self::assertFalse(TAVI::fromString($noMedia)->getCanRearrange());
	}

	public function testAnEmptyMediaListIsNotRearranged(): void
	{
		$bytes = $this->riff(
			TAVI::FormType,
			$this->riffList(TRIFFChunkType::MovieList),
			$this->chunk(TRIFFChunkType::Index, str_repeat("\0", 16)),
		);
		self::assertFalse(TAVI::fromString($bytes)->getCanRearrange(), 'there is no first chunk to check against');
	}

	//
	// ─── Streaming ───────────────────────────────────────────────────────────
	//

	public function testLazyReadDefersTheMediaAndRewritesFaithfully(): void
	{
		$bytes = $this->avi($this->infoList('INAM', 'Streamed'));
		$avi = TAVI::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame(320, $avi->getWidth());
		self::assertSame('Streamed', $avi->getInfoValue(TAVI::InfoName));

		$movi = $avi->getRIFF()->getChunks()[2];
		self::assertTrue($movi->getIsDeferred(), 'the media list is never materialized');
		self::assertNotInstanceOf(TRIFFList::class, $movi);

		$target = TStream::fromString('');
		$avi->streamTo($target);
		$target->seek(0);
		self::assertSame($bytes, $target->getContents());
	}

	public function testLazyReadEditsMetadataWithoutMovingTheMedia(): void
	{
		$bytes = $this->avi();
		$source = TStream::fromString($bytes);
		$avi = TAVI::fromStreamLazy($source);
		$avi->setInfoValue(TAVI::InfoArtist, 'Streamer');

		$target = TStream::fromString('');
		$avi->streamTo($target);
		$target->seek(0);
		$out = $target->getContents();
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out));
		self::assertSame('Streamer', TAVI::fromString($out)->getInfoValue(TAVI::InfoArtist));
	}

	public function testLazyReadRefusesANonAviAndANonStream(): void
	{
		$avi = TAVI::fromStreamLazy(TStream::fromString($this->avi())->detach());
		self::assertSame(320, $avi->getWidth());   // a stream resource is accepted

		try {
			TAVI::fromStreamLazy(TStream::fromString('RIFF' . pack('V', 4) . 'WAVE'));
			self::fail('a non-AVI form type must be refused');
		} catch (TIOException $e) {
		}
		$this->expectException(TInvalidDataTypeException::class);
		TAVI::fromStreamLazy('not a stream');
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	private function privateAvi(): string
	{
		return $this->avi(
			$this->infoList(
				'IART',
				'A Director',
				'ICOP',
				'(c) Someone',
				'INAM',
				'A Clip',
				'ICMT',
				'Filmed at home',
				'ICRD',
				'2026-09-21',
				'ISFT',
				'Some Editor 1.0',
			),
			$this->chunk(TRIFFChunkType::DigitizationTime, "Mon Sep 21 10:00:00 2026\0"),
		);
	}

	public function testScrubbingByCategoryIsIsolated(): void
	{
		$avi = TAVI::fromString($this->privateAvi());
		self::assertSame(2, $avi->clearPrivateData(TPrivacyCategory::Author));
		$info = $avi->getInfo();
		self::assertArrayNotHasKey('IART', $info);
		self::assertArrayNotHasKey('ICOP', $info);
		self::assertSame('A Clip', $info['INAM'], 'another category is untouched');
		self::assertSame('Some Editor 1.0', $info['ISFT']);

		$avi = TAVI::fromString($this->privateAvi());
		self::assertSame(2, $avi->clearPrivateData(TPrivacyCategory::Timestamp));
		self::assertArrayNotHasKey('ICRD', $avi->getInfo());
		self::assertNull($avi->getDigitizationTime());
		self::assertSame('A Director', $avi->getInfoValue(TAVI::InfoArtist));

		$avi = TAVI::fromString($this->privateAvi());
		self::assertSame(1, $avi->clearPrivateData(TPrivacyCategory::Software));
		self::assertArrayNotHasKey('ISFT', $avi->getInfo());
	}

	public function testScrubbingEverythingLeavesThePlayableFacts(): void
	{
		$bytes = $this->privateAvi();
		$avi = TAVI::fromString($bytes);
		self::assertGreaterThan(0, $avi->clearPrivateData());
		$out = $avi->toBinary();
		$round = TAVI::fromString($out);

		self::assertSame([], $round->getInfo());
		self::assertNull($round->getDigitizationTime());
		self::assertSame(320, $round->getWidth(), 'the video itself still describes itself');
		self::assertSame(12, $round->getTotalFrames());
		self::assertSame($this->moviOffset($bytes), $this->moviOffset($out), 'and the media never moved');

		self::assertSame(0, $round->clearPrivateData(), 'a scrub is idempotent');
	}

	public function testScrubbingAFileWithNothingPrivateChangesNothing(): void
	{
		$bytes = $this->avi();
		$avi = TAVI::fromString($bytes);
		self::assertSame(0, $avi->clearPrivateData());
		self::assertSame($bytes, $avi->toBinary());
	}

	public function testScrubbingReachesTheXmpCarrier(): void
	{
		$avi = TAVI::fromString($this->avi());
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'creator', 'A Director');
		$avi->setXMP($xmp);
		self::assertGreaterThan(0, $avi->clearPrivateData(TPrivacyCategory::Author));
		self::assertNull($avi->getXMP()?->getProperty(TXMP::NS_DC, 'creator'));
	}

	public function testChunksCanBeReplacedWholesale(): void
	{
		$avi = TAVI::fromString($this->avi());
		$riff = $avi->getRIFF();
		$riff->setChunks([new TImageChunk('JUNK', 4, 0, 'keep')]);
		self::assertCount(1, $riff->getChunks());
		self::assertSame('keep', $riff->getChunks()[0]->getData());
	}
}
