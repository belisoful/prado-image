<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TTIFF;
use Prado\IO\Stream\TFreeSpaceStream;
use Prado\IO\Stream\TReservedSpaceMode;
use Prado\IO\Stream\TReservedSpaceStream;

/**
 * The private-spaces bridge: TEXIF and TTIFF hand their composed bytes to the framework's
 * reserved/free-space stream decorators, so a consumer can edit an EXIF or TIFF while the
 * maker notes — pinned at their original offsets — are protected exactly as the writer
 * protects them.
 */
class TReservedSpaceIOTest extends PHPUnit\Framework\TestCase
{
	private const MAKER = 'MAKERNOTE-PRIVATE-BYTES-1234567890';

	/**
	 * A parsed EXIF whose maker note carries a real on-disk offset (so it is pinned). A
	 * freshly built tag has no offset to preserve, which is why the source is reparsed.
	 */
	private function exifWithMakerNote(): TEXIF
	{
		$build = new TEXIF();
		$build->setValueByName('Make', 'TestCam');
		$build->getExifIfd(true)->setTagValues(TEXIF::MakerNoteTag, TTIFFDataType::Undefined, self::MAKER);
		return TEXIF::fromSegment($build->toBinary());   // reparse: pinMakernote() runs
	}

	public function testReservedSpaceLandsExactlyOnTheMakerNote()
	{
		$exif = $this->exifWithMakerNote();
		$bytes = $exif->toBinary();
		$spaces = $exif->getReservedSpaces();

		self::assertCount(1, $spaces);
		[$offset, $length] = $spaces[0];
		self::assertSame(strlen(self::MAKER), $length);
		// The reserved range indexes the composed output, past the 'Exif\0\0' signature.
		self::assertSame(self::MAKER, substr($bytes, $offset, $length));
		self::assertGreaterThanOrEqual(strlen($exif->getSignature()), $offset);

		// A built-but-never-parsed maker note has no offset to pin, so nothing is reserved.
		$fresh = new TEXIF();
		$fresh->getExifIfd(true)->setTagValues(TEXIF::MakerNoteTag, TTIFFDataType::Undefined, self::MAKER);
		$fresh->pinMakernote();
		self::assertSame([], $fresh->getReservedSpaces());
	}

	public function testSkipModeWritesThroughButPreservesTheMakerNote()
	{
		$exif = $this->exifWithMakerNote();
		$bytes = $exif->toBinary();
		[$offset, $length] = $exif->getReservedSpaces()[0];

		$stream = $exif->toReservedSpaceStream(TReservedSpaceMode::Skip);
		self::assertInstanceOf(TReservedSpaceStream::class, $stream);
		$stream->seek(0);
		// Skip consumes the whole input, laying it into the free space and jumping the
		// reserved gap (writing the remainder past it), so every byte is accounted for.
		$written = $stream->write(str_repeat('X', strlen($bytes)));
		$after = (string) $stream->getStream();

		// The maker note is byte-identical; the free bytes on either side were clobbered.
		self::assertSame(strlen($bytes), $written);
		self::assertSame(self::MAKER, substr($after, $offset, $length));
		self::assertSame('X', $after[$offset - 1], 'a free byte before the reserved region was overwritten');
		self::assertSame('X', $after[$offset + $length], 'a free byte after it was overwritten');
	}

	public function testClipModeStopsAtTheReservedBoundary()
	{
		$exif = $this->exifWithMakerNote();
		[$offset, $length] = $exif->getReservedSpaces()[0];

		// The default mode is Clip.
		$stream = $exif->toReservedSpaceStream();
		self::assertSame(TReservedSpaceMode::Clip, $stream->getMode());

		// A write reaching the reserved space stops at its boundary.
		$stream->seek($offset - 3);
		self::assertSame(3, $stream->write('ABCDEF'), 'clipped at the reserved boundary');

		// A write starting inside the reserved space handles nothing.
		$stream->seek($offset + 1);
		self::assertSame(0, $stream->write('ZZZ'));

		self::assertSame(self::MAKER, substr((string) $stream->getStream(), $offset, $length));
	}

	public function testFailModeThrowsOnAnOverlappingWrite()
	{
		$exif = $this->exifWithMakerNote();
		[$offset] = $exif->getReservedSpaces()[0];

		$stream = $exif->toReservedSpaceStream(TReservedSpaceMode::Fail);
		$stream->seek($offset - 2);
		self::expectException(TIOException::class);
		$stream->write('AAAAAA');
	}

	public function testFreeSpaceViewExcludesTheMakerNote()
	{
		$exif = $this->exifWithMakerNote();
		$bytes = $exif->toBinary();
		[, $length] = $exif->getReservedSpaces()[0];

		$stream = $exif->toFreeSpaceStream();
		self::assertInstanceOf(TFreeSpaceStream::class, $stream);
		self::assertSame(strlen($bytes) - $length, $stream->getSize());
		$contents = (string) $stream;
		self::assertStringNotContainsString(self::MAKER, $contents);
		self::assertSame(strlen($bytes) - $length, strlen($contents));
	}

	public function testEmptyWhenThereAreNoPrivateSpaces()
	{
		// An EXIF with no maker note reserves nothing; the whole stream is free.
		$exif = new TEXIF();
		$exif->setValueByName('Make', 'TestCam');
		self::assertSame([], $exif->getReservedSpaces());

		$bytes = $exif->toBinary();
		$free = $exif->toFreeSpaceStream();
		self::assertSame(strlen($bytes), $free->getSize());
		self::assertSame(bin2hex($bytes), bin2hex((string) $free));

		// A reserved-space stream with no reserved ranges writes straight through.
		$reserved = $exif->toReservedSpaceStream();
		$reserved->seek(0);
		self::assertSame(strlen($bytes), $reserved->write(str_repeat('Y', strlen($bytes))));
	}

	public function testTiffContainerBridgesToItsExif()
	{
		// A bare-TIFF (signature '') carrying a pinned maker note, loaded as a TIFF file.
		$tiffBytes = $this->exifWithMakerNote()->getTiff()->toBinary();
		$tiff = TTIFF::fromString($tiffBytes);

		$spaces = $tiff->getReservedSpaces();
		self::assertCount(1, $spaces);
		[$offset, $length] = $spaces[0];
		// No signature on a TIFF file, so the offset indexes the file directly.
		self::assertSame(self::MAKER, substr($tiff->toBinary(), $offset, $length));

		$stream = $tiff->toReservedSpaceStream(TReservedSpaceMode::Skip);
		$stream->seek(0);
		$stream->write(str_repeat('X', strlen($tiff->toBinary())));
		self::assertSame(self::MAKER, substr((string) $stream->getStream(), $offset, $length));

		self::assertInstanceOf(TFreeSpaceStream::class, $tiff->toFreeSpaceStream());
	}

	public function testTiffWithoutExifReservesNothing()
	{
		$tiff = new TTIFF();
		self::assertSame([], $tiff->getReservedSpaces());
	}
}
