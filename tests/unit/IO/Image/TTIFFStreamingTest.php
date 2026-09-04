<?php

use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TTIFF;
use Prado\IO\Stream\TNoSeekStream;
use Prado\IO\TStream;
use Prado\Prado;

/**
 * Unit tests for the streaming (lazy) TIFF path: {@see TTIFF::fromStreamLazy()} scans the
 * metadata by seeking and keeps the strip/tile pixel data as deferred ranges into the
 * source, and {@see TTIFF::streamTo()} copies the strips straight through with their
 * offsets rewritten — a metadata edit rewrites the file without holding its pixels.
 */
class TTIFFStreamingTest extends PHPUnit\Framework\TestCase
{
	private function gd(int $w = 24, int $h = 16): \GdImage
	{
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		return $gd;
	}

	private function tiffBytes(): string
	{
		return (string) TTIFF::fromImage($this->gd())->toBinary();
	}

	public function testStreamingProducesTheSameBytesAsAWholeParseCompose()
	{
		$bytes = $this->tiffBytes();
		$lazy = TTIFF::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame([24, 16], [$lazy->getWidth(), $lazy->getHeight()]);

		$target = TStream::fromMemory();
		$written = $lazy->streamTo($target);
		$target->rewind();
		$out = $target->getContents();

		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex((string) TTIFF::fromString($bytes)->toBinary()), bin2hex($out), 'streamed == whole-parse compose');
	}

	public function testAStreamedTiffComposesByMaterializingItsDeferredStrips()
	{
		$bytes = $this->tiffBytes();
		$lazy = TTIFF::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame(
			bin2hex((string) TTIFF::fromString($bytes)->toBinary()),
			bin2hex((string) $lazy->toBinary()),
			'toBinary() materializes the deferred strips',
		);
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->tiffBytes();
		$tiff = TTIFF::fromStreamLazy(TStream::fromString($bytes));

		$profile = ICCProfileBuilder::sRgb();
		$tiff->setICCProfile($profile);

		$target = TStream::fromMemory();
		$tiff->streamTo($target);
		$target->rewind();
		$out = $target->getContents();

		$round = TTIFF::fromString($out);
		self::assertSame(bin2hex($profile), bin2hex((string) $round->getICCProfile()), 'the ICC edit landed');
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
		self::assertInstanceOf(\GdImage::class, $round->getImage(), 'the strips decode to a raster');
	}

	public function testStreamingZeroFillsWordAlignmentGaps()
	{
		// An odd-length out-of-line value (5 bytes) forces the next allocation to word-align,
		// leaving a one-byte gap the stream must zero-fill to keep the strips on their offsets.
		$t = TTIFF::fromImage($this->gd());
		$t->getEXIF()->getIfd0()->setTagValues(270, TTIFFDataType::Ascii, 'abcde');   // 5 bytes, out-of-line and odd
		$bytes = (string) $t->toBinary();

		$target = TStream::fromMemory();
		TTIFF::fromStreamLazy(TStream::fromString($bytes))->streamTo($target);
		$target->rewind();
		$out = $target->getContents();
		self::assertSame(bin2hex((string) TTIFF::fromString($bytes)->toBinary()), bin2hex($out));
	}

	public function testStreamingAFreshTiffWritesItsDirectBytes()
	{
		// No parsed EXIF document: streamTo falls back to the raw source bytes (empty here).
		$target = TStream::fromMemory();
		$written = Prado::createComponent(TTIFF::class)->streamTo($target);
		self::assertSame(0, $written);
	}

	public function testFromStreamLazyAndStreamToAcceptResources()
	{
		$bytes = $this->tiffBytes();
		$source = fopen('php://temp', 'r+b');
		fwrite($source, $bytes);
		$lazy = TTIFF::fromStreamLazy($source);   // a PHP resource source

		$target = fopen('php://temp', 'r+b');
		$lazy->streamTo($target);                  // a PHP resource target
		rewind($target);
		$out = (string) stream_get_contents($target);
		fclose($source);
		fclose($target);
		self::assertSame(bin2hex((string) TTIFF::fromString($bytes)->toBinary()), bin2hex($out));
	}

	public function testStreamToRejectsAnInvalidTarget()
	{
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		TTIFF::fromStreamLazy(TStream::fromString($this->tiffBytes()))->streamTo('not a stream');
	}

	public function testStreamToRaisesWhenTheTargetStopsAcceptingBytes()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TTIFF::fromStreamLazy(TStream::fromString($this->tiffBytes()))->streamTo(new TChunkedWriteStream(0));
	}

	public function testStreamToHonorsPartialWrites()
	{
		// A target that accepts only a few bytes per call is written in as many calls as it
		// takes, and the bytes arrive in order and complete.
		$bytes = $this->tiffBytes();
		$trickle = new TChunkedWriteStream(5);
		$written = TTIFF::fromStreamLazy(TStream::fromString($bytes))->streamTo($trickle);
		self::assertSame(strlen($trickle->buffer), $written);
		self::assertSame(bin2hex((string) TTIFF::fromString($bytes)->toBinary()), bin2hex($trickle->buffer));
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TTIFF::fromStreamLazy(new TNoSeekStream(TStream::fromString($this->tiffBytes())));
	}

	public function testFromStreamLazyRejectsBytesThatAreNotTiff()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TTIFF::fromStreamLazy(TStream::fromString('this is not a TIFF file at all'));
	}
}
