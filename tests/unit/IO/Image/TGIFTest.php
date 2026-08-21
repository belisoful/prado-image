<?php

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\Compression\TGIFLZWCompressor;
use Prado\IO\Image\GIF\TGIFBlockType;
use Prado\IO\Image\GIF\TGIFExtension;
use Prado\IO\Image\GIF\TGIFFrame;
use Prado\IO\Image\TGIF;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TPrivacyCategory;

class TGIFTest extends PHPUnit\Framework\TestCase
{
	/** The four colors of the global table: red, green, blue, white. */
	private const GlobalTable = "\xff\x00\x00\x00\xff\x00\x00\x00\xff\xff\xff\xff";

	/** A distinct four-color local table. */
	private const LocalTable = "\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc";

	/**
	 * Frames pixel indexes into a minimum-code-size byte plus LZW sub-blocks.
	 * @param string $indexes
	 * @param int $minCodeSize
	 */
	private function lzwBlocks(string $indexes, int $minCodeSize): string
	{
		$out = chr($minCodeSize);
		foreach (str_split(TGIFLZWCompressor::compress($indexes, $minCodeSize), 255) as $block) {
			$out .= chr(strlen($block)) . $block;
		}
		return $out . "\x00";
	}

	/**
	 * Builds a GIF89a exercising the whole standard: a global table, a loop block, a
	 * private application extension, a comment, two frames with graphic control
	 * extensions, an interlaced first frame, and a local table plus sub-rectangle on
	 * the second.
	 */
	private function richGif(): string
	{
		$gif = TGIF::Signature89a . pack('vv', 4, 1) . chr(0x80 | 0x01) . chr(2) . chr(0) . self::GlobalTable;
		$gif .= "\x21\xff\x0bNETSCAPE2.0\x03\x01" . pack('v', 7) . "\x00";
		$gif .= "\x21\xff\x0b" . TGIF::XmpIdentity . chr(4) . 'xmp!' . "\x00";
		$gif .= "\x21\xfe" . chr(11) . 'prado-image' . "\x00";
		// Frame 1: 50cs delay, restore-background disposal, transparent index 3, interlaced.
		$gif .= "\x21\xf9\x04" . chr((2 << 2) | 0x01) . pack('v', 50) . chr(3) . "\x00";
		$gif .= "\x2c" . pack('vvvv', 0, 0, 4, 1) . chr(0x40) . $this->lzwBlocks("\x00\x01\x02\x03", 2);
		// Frame 2: 10cs delay, do-not-dispose, local color table, sub-rectangle at +1+0.
		$gif .= "\x21\xf9\x04" . chr(1 << 2) . pack('v', 10) . chr(0) . "\x00";
		$gif .= "\x2c" . pack('vvvv', 1, 0, 2, 1) . chr(0x80 | 0x01) . self::LocalTable . $this->lzwBlocks("\x03\x02", 2);
		return $gif . chr(TGIFBlockType::Trailer);
	}

	/** Generates a paletted GIF in memory with GD. */
	private function gdGif(int $width = 6, int $height = 4): string
	{
		$im = imagecreate($width, $height);
		$colors = [
			imagecolorallocate($im, 255, 0, 0),
			imagecolorallocate($im, 0, 255, 0),
			imagecolorallocate($im, 0, 0, 255),
		];
		for ($y = 0; $y < $height; $y++) {
			for ($x = 0; $x < $width; $x++) {
				imagesetpixel($im, $x, $y, $colors[($x + $y) % 3]);
			}
		}
		ob_start();
		imagegif($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	//
	// ─── Parsing and byte faithfulness ───────────────────────────────────────
	//

	public function testParsesEveryPartOfTheStandard()
	{
		$gif = TGIF::fromString($this->richGif());

		self::assertSame('GIF', $gif->getFormat());
		self::assertSame(TGIF::Signature89a, $gif->getVersion());
		self::assertSame(4, $gif->getWidth());
		self::assertSame(1, $gif->getHeight());
		self::assertSame(2, $gif->getBackgroundIndex());
		self::assertSame(0, $gif->getAspectRatio());
		self::assertSame(self::GlobalTable, $gif->getGlobalColorTable());
		self::assertSame(7, $gif->getLoopCount());
		self::assertSame(['prado-image'], $gif->getComments());
		self::assertSame(2, $gif->getFrameCount());
		self::assertTrue($gif->getIsAnimated());
	}

	public function testFrameFieldsAreParsed()
	{
		$gif = TGIF::fromString($this->richGif());

		$first = $gif->getFrame(0);
		self::assertSame([0, 0, 4, 1], [$first->getLeft(), $first->getTop(), $first->getWidth(), $first->getHeight()]);
		self::assertTrue($first->getHasGraphicControl());
		self::assertSame(50, $first->getDelayTime());
		self::assertSame(TGIFFrame::DisposalRestoreBackground, $first->getDisposalMethod());
		self::assertSame(3, $first->getTransparentIndex());
		self::assertFalse($first->getUserInput());
		self::assertTrue($first->getInterlaced());
		self::assertFalse($first->getHasLocalColorTable());
		self::assertSame("\x00\x01\x02\x03", $first->getPixels());

		$second = $gif->getFrame(1);
		self::assertSame([1, 0, 2, 1], [$second->getLeft(), $second->getTop(), $second->getWidth(), $second->getHeight()]);
		self::assertSame(10, $second->getDelayTime());
		self::assertSame(TGIFFrame::DisposalNone, $second->getDisposalMethod());
		self::assertNull($second->getTransparentIndex());
		self::assertFalse($second->getInterlaced());
		self::assertTrue($second->getHasLocalColorTable());
		self::assertSame(self::LocalTable, $second->getLocalColorTable());
		self::assertSame("\x03\x02", $second->getPixels());

		self::assertNull($gif->getFrame(2));
	}

	public function testRoundTripIsByteFaithful()
	{
		$source = $this->richGif();
		self::assertSame($source, TGIF::fromString($source)->toBinary());

		// And stable across repeated cycles.
		$once = TGIF::fromString($source)->toBinary();
		self::assertSame($once, TGIF::fromString($once)->toBinary());
	}

	public function testGdWrittenGifRoundTripsByteFaithfully()
	{
		$bytes = $this->gdGif();
		$gif = TGIF::fromString($bytes);
		self::assertSame(6, $gif->getWidth());
		self::assertSame(4, $gif->getHeight());
		self::assertSame(1, $gif->getFrameCount());
		self::assertSame($bytes, $gif->toBinary());
	}

	public function testGif87aRoundTrips()
	{
		$bytes = TGIF::Signature87a . substr($this->gdGif(4, 4), 6);
		$gif = TGIF::fromString($bytes);
		self::assertSame(TGIF::Signature87a, $gif->getVersion());
		self::assertNull($gif->getLoopCount());
		self::assertSame($bytes, $gif->toBinary());
	}

	public function testTrailingBytesAfterTheTrailerArePreserved()
	{
		$bytes = $this->gdGif() . 'JUNKJUNK';
		self::assertSame($bytes, TGIF::fromString($bytes)->toBinary());
	}

	public function testSubBlockFramingIsPreserved()
	{
		// A comment deliberately split at an unusual boundary must be re-emitted
		// with the same split, not re-packed.
		$split = "\x21\xfe" . chr(3) . 'abc' . chr(2) . 'de' . "\x00";
		$bytes = substr($this->richGif(), 0, -1) . $split . chr(TGIFBlockType::Trailer);
		$gif = TGIF::fromString($bytes);
		self::assertContains('abcde', $gif->getComments());
		self::assertSame($bytes, $gif->toBinary());
	}

	//
	// ─── Application extensions ──────────────────────────────────────────────
	//

	public function testApplicationIdentityCaseIsPreserved()
	{
		$gif = TGIF::fromString($this->richGif());
		// The metadata identities are case-sensitive and must survive verbatim.
		$xmp = $gif->getApplicationExtension(TGIF::XmpIdentity);
		self::assertNotNull($xmp);
		self::assertSame('XMP DataXMP', $xmp->getApplicationIdentifier());
		self::assertSame('xmp!', $xmp->getApplicationData());
		self::assertNull($gif->getApplicationExtension('xmp dataxmp'));
		self::assertNull($gif->getApplicationExtension(TGIF::ICCIdentity));

		self::assertStringContainsString('XMP DataXMP', $gif->toBinary());
	}

	public function testExtensionAccessors()
	{
		$gif = TGIF::fromString($this->richGif());
		self::assertCount(3, $gif->getExtensions());
		self::assertCount(2, $gif->getExtensions(TGIFBlockType::ApplicationLabel));
		self::assertCount(1, $gif->getExtensions(TGIFBlockType::CommentLabel));
		self::assertCount(0, $gif->getExtensions(TGIFBlockType::PlainTextLabel));

		$comment = $gif->getExtensions(TGIFBlockType::CommentLabel)[0];
		self::assertFalse($comment->getIsApplication());
		self::assertNull($comment->getApplicationIdentifier());
		self::assertSame('', $comment->getApplicationData());
	}

	public function testExtensionDataSplitsAtTheSubBlockMaximum()
	{
		$extension = TGIFExtension::comment(str_repeat('x', 600));
		self::assertCount(3, $extension->getSubBlocks());
		self::assertSame([255, 255, 90], array_map('strlen', $extension->getSubBlocks()));
		self::assertSame(str_repeat('x', 600), $extension->getData());

		$empty = new TGIFExtension(TGIFBlockType::CommentLabel);
		$empty->setData('');
		self::assertSame([], $empty->getSubBlocks());
		self::assertSame("\x21\xfe\x00", $empty->toBinary());
	}

	public function testAddCommentAndPlainTextSurviveARoundTrip()
	{
		$gif = TGIF::fromString($this->gdGif());
		$gif->addComment('written by prado-image');
		$gif->addExtension(new TGIFExtension(TGIFBlockType::PlainTextLabel, ["\x00" . str_repeat("\x01", 11)]));

		$reread = TGIF::fromString($gif->toBinary());
		self::assertSame(['written by prado-image'], $reread->getComments());
		self::assertCount(1, $reread->getExtensions(TGIFBlockType::PlainTextLabel));
	}

	//
	// ─── Loop count ──────────────────────────────────────────────────────────
	//

	public function testLoopCountReadWriteAndRemove()
	{
		$gif = TGIF::fromString($this->gdGif());
		self::assertNull($gif->getLoopCount());

		$gif->setLoopCount(0);            // forever
		self::assertSame(0, $gif->getLoopCount());
		$reread = TGIF::fromString($gif->toBinary());
		self::assertSame(0, $reread->getLoopCount());
		// The loop block must precede the first frame.
		$blocks = $reread->getBlocks();
		self::assertInstanceOf(TGIFExtension::class, $blocks[0]);
		self::assertSame(TGIF::NetscapeIdentity, $blocks[0]->getApplicationIdentifier());

		$reread->setLoopCount(12);
		self::assertSame(12, $reread->getLoopCount());
		self::assertCount(1, $reread->getExtensions(TGIFBlockType::ApplicationLabel));

		$reread->setLoopCount(null);
		self::assertNull($reread->getLoopCount());
		self::assertCount(0, $reread->getExtensions(TGIFBlockType::ApplicationLabel));
		self::assertNull(TGIF::fromString($reread->toBinary())->getLoopCount());
	}

	public function testLoopCountEditKeepsTheExistingBlockPosition()
	{
		$gif = TGIF::fromString($this->richGif());
		$gif->setLoopCount(1);
		$reread = TGIF::fromString($gif->toBinary());
		self::assertSame(1, $reread->getLoopCount());
		self::assertCount(2, $reread->getExtensions(TGIFBlockType::ApplicationLabel));
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	public function testCommentsAreRemovedOnlyByTheDescriptionCategory()
	{
		// A comment is free text, so it goes under Description; a scrub of any other
		// category leaves the GIF's own carrier alone rather than emptying it.
		$gif = TGIF::fromString($this->richGif());
		self::assertSame(['prado-image'], $gif->getComments());

		self::assertSame(0, $gif->clearPrivateData(TPrivacyCategory::Location | TPrivacyCategory::Author));
		self::assertSame(['prado-image'], $gif->getComments());
		self::assertSame($this->richGif(), $gif->toBinary());

		self::assertSame(1, $gif->clearPrivateData(TPrivacyCategory::Description));
		self::assertSame([], $gif->getComments());
		// Only the comment went: the frames, their controls, and the other extensions stay.
		self::assertSame(2, $gif->getFrameCount());
		self::assertSame(7, $gif->getLoopCount());
		self::assertNotNull($gif->getXmpText());
	}

	//
	// ─── Pixels and interlacing ──────────────────────────────────────────────
	//

	public function testInterlaceHelpersAreInverse()
	{
		$rows = '';
		for ($y = 0; $y < 9; $y++) {
			$rows .= str_repeat(chr($y), 3);
		}
		$woven = TGIFFrame::interlace($rows, 3, 9);
		self::assertNotSame($rows, $woven);
		self::assertSame($rows, TGIFFrame::deinterlace($woven, 3, 9));
		// Pass one is rows 0 and 8, so the woven data starts with row 0 then row 8.
		self::assertSame(str_repeat(chr(0), 3) . str_repeat(chr(8), 3), substr($woven, 0, 6));
	}

	public function testInterlacingAnEmptyFrameLeavesTheDataAlone()
	{
		// A frame with no rows or no columns has no pass structure to weave: the four-pass
		// loop would silently drop the data, so both helpers hand it back untouched.
		self::assertSame('abc', TGIFFrame::interlace('abc', 0, 4));
		self::assertSame('abc', TGIFFrame::interlace('abc', 4, 0));
		self::assertSame('abc', TGIFFrame::deinterlace('abc', 0, 4));
		self::assertSame('abc', TGIFFrame::deinterlace('abc', 4, 0));
		self::assertSame('', TGIFFrame::interlace('', 0, 0));
		self::assertSame('', TGIFFrame::deinterlace('', 0, 0));
	}

	public function testFlippingInterlacePreservesPixels()
	{
		$gif = TGIF::fromString($this->gdGif(8, 8));
		$frame = $gif->getFrame(0);
		$before = $frame->getPixels();

		$frame->setInterlaced(true);
		self::assertTrue($frame->getInterlaced());
		self::assertSame($before, $frame->getPixels());

		$frame->setInterlaced(false);
		self::assertSame($before, $frame->getPixels());

		// Setting the same value again is a no-op.
		$frame->setInterlaced(false);
		self::assertSame($before, $frame->getPixels());
	}

	public function testPixelEditRoundTrips()
	{
		$gif = TGIF::fromString($this->gdGif());
		$frame = $gif->getFrame(0);
		$flooded = str_repeat(chr(1), $frame->getWidth() * $frame->getHeight());
		$frame->setPixels($flooded);

		$reread = TGIF::fromString($gif->toBinary());
		self::assertSame($flooded, $reread->getFrame(0)->getPixels());
	}

	public function testMinCodeSizeRisesToFitTheIndexes()
	{
		$frame = new TGIFFrame();
		$frame->setWidth(4);
		$frame->setHeight(1);
		self::assertSame(8, $frame->getMinCodeSize());

		$frame->setMinCodeSize(2);
		// Index 9 needs four bits, so the code size is raised rather than failing.
		$frame->setPixels("\x00\x09\x02\x03");
		self::assertSame(4, $frame->getMinCodeSize());
		self::assertSame("\x00\x09\x02\x03", $frame->getPixels());

		self::assertSame(2, TGIFFrame::minCodeSizeForPixels("\x00\x01\x03"));
		self::assertSame(3, TGIFFrame::minCodeSizeForPixels("\x00\x04"));
		self::assertSame(8, TGIFFrame::minCodeSizeForPixels("\xff"));
		self::assertSame(2, TGIFFrame::minCodeSizeForPixels(''));
	}

	public function testExplicitMinCodeSizeTooSmallIsRejected()
	{
		$frame = new TGIFFrame();
		$frame->setWidth(2);
		$frame->setHeight(1);
		self::expectException(TInvalidDataValueException::class);
		$frame->setPixels("\x00\x09", 2);
	}

	public function testExplicitMinCodeSizeAboveWhatTheIndexesNeedIsKept()
	{
		// A code size wider than the indexes require is legal — it is the palette's size,
		// not the pixels' — so an explicit one is stored as given rather than shrunk.
		$gif = TGIF::fromString($this->gdGif(4, 2));
		$frame = $gif->getFrame(0);
		$pixels = str_repeat("\x01\x00", 4);
		$frame->setPixels($pixels, 7);
		self::assertSame(7, $frame->getMinCodeSize());

		$reread = TGIF::fromString($gif->toBinary());
		self::assertSame(7, $reread->getFrame(0)->getMinCodeSize());
		self::assertSame($pixels, $reread->getFrame(0)->getPixels());
	}

	public function testPixelCountMustMatchTheFrame()
	{
		$frame = new TGIFFrame();
		$frame->setWidth(4);
		$frame->setHeight(2);
		self::expectException(TInvalidDataValueException::class);
		$frame->setPixels("\x00\x01");
	}

	public function testShortAndLongLzwDataAreNormalizedToTheFrameSize()
	{
		$frame = new TGIFFrame();
		$frame->setWidth(4);
		$frame->setHeight(1);
		$frame->setMinCodeSize(2);
		// Six pixels of data in a four-pixel frame is truncated...
		$frame->setLzwData(TGIFLZWCompressor::compress("\x01\x02\x03\x00\x01\x02", 2));
		self::assertSame("\x01\x02\x03\x00", $frame->getPixels());
		// ...and two pixels of data is zero-filled.
		$frame->setLzwData(TGIFLZWCompressor::compress("\x01\x02", 2));
		self::assertSame("\x01\x02\x00\x00", $frame->getPixels());
	}

	//
	// ─── Color tables ────────────────────────────────────────────────────────
	//

	public function testColorTablesArePaddedToAPowerOfTwo()
	{
		// Three colors pad to four entries.
		$frame = new TGIFFrame();
		$frame->setLocalColorTable("\x01\x02\x03\x04\x05\x06\x07\x08\x09");
		self::assertSame(12, strlen($frame->getLocalColorTable()));
		self::assertSame("\x00\x00\x00", substr($frame->getLocalColorTable(), 9, 3));
		self::assertSame(1, TGIFFrame::tableSizeBits($frame->getLocalColorTable()));

		self::assertSame(0, TGIFFrame::tableSizeBits(str_repeat("\0", 6)));      // 2 colors
		self::assertSame(7, TGIFFrame::tableSizeBits(str_repeat("\0", 768)));    // 256 colors
		// Four colors need two bits, eight need three; GIF never goes below two.
		self::assertSame(2, TGIFFrame::minCodeSizeFor(str_repeat("\0", 12)));
		self::assertSame(2, TGIFFrame::minCodeSizeFor(str_repeat("\0", 6)));
		self::assertSame(3, TGIFFrame::minCodeSizeFor(str_repeat("\0", 24)));
		self::assertSame(8, TGIFFrame::minCodeSizeFor(str_repeat("\0", 768)));
		self::assertSame(8, TGIFFrame::minCodeSizeFor(null));
	}

	public function testMalformedColorTablesAreRejected()
	{
		$frame = new TGIFFrame();
		self::expectException(TInvalidDataValueException::class);
		$frame->setLocalColorTable("\x01\x02");    // not whole triplets
	}

	public function testOversizedColorTableIsRejected()
	{
		$gif = TGIF::fromString($this->gdGif());
		self::expectException(TInvalidDataValueException::class);
		$gif->setGlobalColorTable(str_repeat("\0", 3 * 257));
	}

	public function testGlobalColorTableCanBeClearedAndSet()
	{
		$gif = TGIF::fromString($this->gdGif());
		self::assertNotNull($gif->getGlobalColorTable());
		$gif->setGlobalColorTable(null);
		self::assertNull($gif->getGlobalColorTable());
		// With no global table the packed field drops its flag.
		self::assertSame(0, ord($gif->toBinary()[10]) & 0x80);

		$gif->setGlobalColorTable(self::GlobalTable);
		self::assertSame(self::GlobalTable, TGIF::fromString($gif->toBinary())->getGlobalColorTable());
	}

	//
	// ─── Frame collection ────────────────────────────────────────────────────
	//

	public function testFramesCanBeAddedAndRemoved()
	{
		$gif = TGIF::fromString($this->richGif());
		self::assertSame(2, $gif->getFrameCount());

		$frame = new TGIFFrame();
		$frame->setWidth(4);
		$frame->setHeight(1);
		$frame->setDelayTime(5);
		$frame->setPixels("\x01\x01\x01\x01");
		$gif->addFrame($frame);
		self::assertSame(3, $gif->getFrameCount());

		$reread = TGIF::fromString($gif->toBinary());
		self::assertSame(3, $reread->getFrameCount());
		self::assertSame(5, $reread->getFrame(2)->getDelayTime());

		self::assertTrue($reread->removeFrame(1));
		self::assertSame(2, $reread->getFrameCount());
		// The remaining frames keep their order and their extensions survive.
		self::assertSame(50, $reread->getFrame(0)->getDelayTime());
		self::assertSame(5, $reread->getFrame(1)->getDelayTime());
		self::assertSame(['prado-image'], $reread->getComments());
		self::assertFalse($reread->removeFrame(9));
	}

	public function testGraphicControlIsImpliedByItsFields()
	{
		$frame = new TGIFFrame();
		self::assertFalse($frame->getHasGraphicControl());

		$frame->setDelayTime(10);
		self::assertTrue($frame->getHasGraphicControl());

		$other = new TGIFFrame();
		$other->setTransparentIndex(4);
		self::assertTrue($other->getHasGraphicControl());

		$third = new TGIFFrame();
		$third->setDisposalMethod(TGIFFrame::DisposalRestorePrevious);
		self::assertTrue($third->getHasGraphicControl());

		$fourth = new TGIFFrame();
		$fourth->setUserInput(true);
		self::assertTrue($fourth->getHasGraphicControl());
	}

	public function testUserInputAndReservedBitsRoundTrip()
	{
		$gif = TGIF::fromString($this->gdGif());
		$frame = $gif->getFrame(0);
		$frame->setUserInput(true);
		$frame->setGraphicControlReserved(5);
		$frame->setTransparentIndex(2);

		$reread = TGIF::fromString($gif->toBinary())->getFrame(0);
		self::assertTrue($reread->getUserInput());
		self::assertSame(5, $reread->getGraphicControlReserved());
		self::assertSame(2, $reread->getTransparentIndex());

		// Clearing transparency keeps the extension (user input still needs it) but
		// drops the flag and the index.
		$frame->setTransparentIndex(null);
		$cleared = TGIF::fromString($gif->toBinary())->getFrame(0);
		self::assertNull($cleared->getTransparentIndex());
		self::assertTrue($cleared->getHasGraphicControl());
		self::assertTrue($cleared->getUserInput());
	}

	public function testInvalidFrameFieldsAreRejected()
	{
		$frame = new TGIFFrame();
		try {
			$frame->setDisposalMethod(9);
			self::fail('an out-of-range disposal method was accepted');
		} catch (TInvalidDataValueException $e) {
		}
		try {
			$frame->setTransparentIndex(300);
			self::fail('an out-of-range transparent index was accepted');
		} catch (TInvalidDataValueException $e) {
		}
		self::expectException(TInvalidDataValueException::class);
		$frame->setMinCodeSize(1);
	}

	public function testInvalidVersionIsRejected()
	{
		$gif = TGIF::fromString($this->gdGif());
		$gif->setVersion(TGIF::Signature87a);
		self::assertSame(TGIF::Signature87a, $gif->getVersion());
		self::expectException(TInvalidDataValueException::class);
		$gif->setVersion('GIF90a');
	}

	//
	// ─── Raster conversion ───────────────────────────────────────────────────
	//

	public function testFrameRendersThroughTheGraphicsSeam()
	{
		$gif = TGIF::fromString($this->gdGif());
		$image = $gif->getFrameImage(0);
		self::assertSame(6, imagesx($image));
		self::assertSame(4, imagesy($image));

		// The palette maps index 0 to red, 1 to green, 2 to blue, laid out (x+y)%3.
		$rgb = imagecolorat($image, 0, 0);
		self::assertSame(0xFF0000, $rgb & 0xFFFFFF);
		self::assertSame(0x00FF00, imagecolorat($image, 1, 0) & 0xFFFFFF);
		self::assertSame(0x0000FF, imagecolorat($image, 2, 0) & 0xFFFFFF);
		imagedestroy($image);

		// getImage() is the first frame.
		$first = $gif->getImage();
		self::assertSame(6, imagesx($first));
		imagedestroy($first);
	}

	public function testFrameWithLocalTableRendersAgainstIt()
	{
		$gif = TGIF::fromString($this->richGif());
		self::assertSame(self::LocalTable, $gif->getFramePalette(1));
		self::assertSame(self::GlobalTable, $gif->getFramePalette(0));

		$image = $gif->getFrameImage(1);
		// Frame 2 is indexes 3 then 2 against the local table.
		self::assertSame(0xAABBCC, imagecolorat($image, 0, 0) & 0xFFFFFF);
		self::assertSame(0x778899, imagecolorat($image, 1, 0) & 0xFFFFFF);
		imagedestroy($image);
	}

	public function testSetImageQuantizesIntoTheFrame()
	{
		$source = imagecreatetruecolor(12, 6);
		for ($y = 0; $y < 6; $y++) {
			for ($x = 0; $x < 12; $x++) {
				imagesetpixel($source, $x, $y, imagecolorallocate($source, $x * 20, $y * 40, 128));
			}
		}
		$gif = TGIF::fromString($this->gdGif());
		$frame = $gif->getFrame(0);
		$frame->setImage($source);
		imagedestroy($source);

		self::assertSame(12, $frame->getWidth());
		self::assertSame(6, $frame->getHeight());
		self::assertTrue($frame->getHasLocalColorTable());
		self::assertSame(72, strlen($frame->getPixels()));

		$gif->setScreenSize(12, 6);
		$reread = TGIF::fromString($gif->toBinary());
		self::assertSame(12, $reread->getWidth());
		self::assertSame(72, strlen($reread->getFrame(0)->getPixels()));
	}

	public function testFromImageBuildsASingleFrameGif()
	{
		$source = imagecreatetruecolor(20, 10);
		for ($y = 0; $y < 10; $y++) {
			for ($x = 0; $x < 20; $x++) {
				imagesetpixel($source, $x, $y, imagecolorallocate($source, $x * 12, $y * 25, 90));
			}
		}
		$gif = TGIF::fromImage($source);
		imagedestroy($source);

		self::assertSame(20, $gif->getWidth());
		self::assertSame(10, $gif->getHeight());
		self::assertSame(1, $gif->getFrameCount());
		self::assertFalse($gif->getIsAnimated());
		self::assertNotNull($gif->getGlobalColorTable());

		$bytes = $gif->toBinary();
		self::assertSame($bytes, TGIF::fromString($bytes)->toBinary());

		// GD must accept what we produced.
		$decoded = @imagecreatefromstring($bytes);
		self::assertNotFalse($decoded);
		self::assertSame(20, imagesx($decoded));
		self::assertSame(10, imagesy($decoded));
		imagedestroy($decoded);
	}

	public function testRenderingRejectsMissingFramesAndTables()
	{
		$gif = TGIF::fromString($this->gdGif());
		try {
			$gif->getFrameImage(7);
			self::fail('an out-of-range frame index was accepted');
		} catch (TInvalidDataValueException $e) {
		}

		$gif->setGlobalColorTable(null);
		self::expectException(TInvalidDataValueException::class);
		$gif->getFrameImage(0);
	}

	public function testRenderingAnEmptyFrameIsRejected()
	{
		$frame = new TGIFFrame();
		self::expectException(TInvalidDataValueException::class);
		$frame->getImage(self::GlobalTable);
	}

	public function testAFrameTooLargeForTheLibraryIsRejected()
	{
		// The image descriptor's size fields are 16 bits, so a frame may legally declare
		// 65535x65535 — more than GD will allocate.  The renderer must say so rather than
		// hand back a broken image.  getPixels() is overridden only to keep the test from
		// materializing the four gigabytes of indexes such a frame would pad out to.
		$frame = new class () extends TGIFFrame {
			public function getPixels(): string
			{
				return "\x00\x01";
			}
		};
		$frame->setWidth(65535);
		$frame->setHeight(65535);

		self::expectException(TInvalidDataValueException::class);
		// GD warns as it declines the allocation; the refusal is the exception, not the warning.
		@$frame->getImage(self::GlobalTable, TImageGraphicsMode::GD);
	}

	//
	// ─── The composed file is decodable by other readers ─────────────────────
	//

	public function testComposedAnimationIsReadableByGd()
	{
		$bytes = $this->richGif();
		$recomposed = TGIF::fromString($bytes)->toBinary();
		$decoded = @imagecreatefromstring($recomposed);
		self::assertNotFalse($decoded, 'GD could not decode the recomposed animation');
		self::assertSame(4, imagesx($decoded));
		imagedestroy($decoded);
	}

	public function testBuiltAnimationIsReadableByImagick()
	{
		if (!extension_loaded('imagick')) {
			self::markTestSkipped('ext-imagick is not loaded.');
		}
		$gif = TGIF::fromString($this->gdGif(8, 8));
		$gif->setLoopCount(0);
		$gif->getFrame(0)->setDelayTime(50);

		$second = new TGIFFrame();
		$second->setWidth(8);
		$second->setHeight(8);
		$second->setDelayTime(25);
		$second->setDisposalMethod(TGIFFrame::DisposalRestoreBackground);
		$second->setPixels(str_repeat(chr(2), 64));
		$gif->addFrame($second);

		$imagick = new Imagick();
		$imagick->readImageBlob($gif->toBinary());
		self::assertSame(2, $imagick->getNumberImages());
		$delays = [];
		foreach ($imagick as $frame) {
			$delays[] = $frame->getImageDelay();
		}
		$imagick->clear();
		self::assertSame([50, 25], $delays);
	}

	//
	// ─── Malformed input ─────────────────────────────────────────────────────
	//

	public function testDetectionOfGifBytes()
	{
		self::assertTrue(TGIF::isGIF($this->gdGif()));
		self::assertTrue(TGIF::isGIF(TGIF::Signature87a . 'rest'));
		self::assertFalse(TGIF::isGIF('GIF88a' . 'rest'));
		self::assertFalse(TGIF::isGIF(''));
	}

	public function testRejectsNonGifData()
	{
		self::expectException(TIOException::class);
		TGIF::fromString(str_repeat("\x00", 32));
	}

	public function testRejectsTruncatedHeader()
	{
		self::expectException(TIOException::class);
		TGIF::fromString(TGIF::Signature89a . "\x01\x00");
	}

	public function testRejectsTruncatedGlobalColorTable()
	{
		// Declares a 256-entry table but supplies nothing.
		self::expectException(TIOException::class);
		TGIF::fromString(TGIF::Signature89a . pack('vv', 1, 1) . chr(0x80 | 0x07) . "\x00\x00");
	}

	public function testRejectsUnknownBlockMarker()
	{
		$bytes = substr($this->gdGif(), 0, -1) . "\x99" . chr(TGIFBlockType::Trailer);
		self::expectException(TIOException::class);
		TGIF::fromString($bytes);
	}

	public function testRejectsTruncatedImageDescriptor()
	{
		$bytes = TGIF::Signature89a . pack('vv', 4, 4) . chr(0) . "\x00\x00" . "\x2c" . "\x00\x00";
		self::expectException(TIOException::class);
		TGIF::fromString($bytes);
	}

	public function testRejectsUnterminatedSubBlockChain()
	{
		// A comment whose sub-block chain never reaches the zero terminator.
		$bytes = TGIF::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00" . "\x21\xfe" . chr(4) . 'abcd';
		self::expectException(TIOException::class);
		TGIF::fromString($bytes);
	}

	public function testRejectsSubBlockRunningPastTheEnd()
	{
		$bytes = TGIF::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00" . "\x21\xfe" . chr(200) . 'abcd';
		self::expectException(TIOException::class);
		TGIF::fromString($bytes);
	}

	public function testRejectsAnExtensionIntroducerWithNoLabel()
	{
		// The last byte of the data is an extension introducer, so the label is missing.
		$bytes = TGIF::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00" . chr(TGIFBlockType::ExtensionIntroducer);
		self::expectException(TIOException::class);
		TGIF::fromString($bytes);
	}

	public function testApplicationExtensionWithAForeignBlockSizeReadsAsSubBlocks()
	{
		// The specification fixes the identity block at 11 bytes; a writer that used
		// another size is not a raw XMP packet, so it is read through the ordinary
		// sub-block path and its framing is kept byte for byte.
		$bytes = TGIF::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00"
			. chr(TGIFBlockType::ExtensionIntroducer) . chr(TGIFBlockType::ApplicationLabel)
			. chr(5) . 'ODD01' . chr(3) . 'xyz' . "\x00"
			. chr(TGIFBlockType::Trailer);

		$gif = TGIF::fromString($bytes);
		$extension = $gif->getExtensions(TGIFBlockType::ApplicationLabel)[0];
		self::assertSame(['ODD01', 'xyz'], $extension->getSubBlocks());
		self::assertFalse($extension->getIsRaw());
		self::assertSame($bytes, $gif->toBinary());
	}

	public function testRejectsTruncatedGraphicControlExtension()
	{
		$bytes = TGIF::Signature89a . pack('vv', 1, 1) . chr(0) . "\x00\x00" . "\x21\xf9" . chr(2) . "\x00\x00" . "\x00";
		self::expectException(TIOException::class);
		TGIF::fromString($bytes);
	}

	public function testRejectsTruncatedLocalColorTable()
	{
		$bytes = TGIF::Signature89a . pack('vv', 4, 1) . chr(0) . "\x00\x00"
			. "\x2c" . pack('vvvv', 0, 0, 4, 1) . chr(0x80 | 0x07);
		self::expectException(TIOException::class);
		TGIF::fromString($bytes);
	}

	public function testRejectsMissingMinimumCodeSize()
	{
		$bytes = TGIF::Signature89a . pack('vv', 4, 1) . chr(0) . "\x00\x00"
			. "\x2c" . pack('vvvv', 0, 0, 4, 1) . chr(0);
		self::expectException(TIOException::class);
		TGIF::fromString($bytes);
	}

	//
	// ─── Stream IO ───────────────────────────────────────────────────────────
	//

	public function testStreamRoundTrip()
	{
		$bytes = $this->richGif();
		$in = fopen('php://temp', 'w+b');
		fwrite($in, $bytes);
		rewind($in);
		$gif = TGIF::fromStream($in);
		fclose($in);
		self::assertSame(2, $gif->getFrameCount());

		$out = fopen('php://temp', 'w+b');
		$written = $gif->writeTo($out);
		rewind($out);
		$composed = stream_get_contents($out);
		fclose($out);
		self::assertSame(strlen($bytes), $written);
		self::assertSame($bytes, $composed);
	}

	public function testSaveAndLoadFromFile()
	{
		$path = tempnam(sys_get_temp_dir(), 'tgif') . '.gif';
		$gif = TGIF::fromString($this->richGif());
		$gif->save($path);
		$loaded = TGIF::fromFile($path);
		unlink($path);
		self::assertSame(2, $loaded->getFrameCount());
		self::assertSame($this->richGif(), $loaded->toBinary());
	}
}
