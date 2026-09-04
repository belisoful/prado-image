<?php

use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TJPEG;
use Prado\IO\Stream\TNoSeekStream;
use Prado\IO\TStream;

/**
 * Unit tests for the streaming (lazy) JPEG path: {@see TJPEG::fromStreamLazy()} reads the
 * segments but keeps the entropy scan (SOS to end) as a deferred range into the source, and
 * {@see TJPEG::streamTo()} rebuilds the segments and copies the scan straight through — a
 * metadata edit rewrites the file without holding its pixels.
 */
class TJPEGStreamingTest extends PHPUnit\Framework\TestCase
{
	/** SOF0 for a 24x16 frame. */
	private const SOF = "\xFF\xC0\x00\x0B\x08\x00\x10\x00\x18\x01\x01\x11\x00";

	/** A minimal SOS header. */
	private const SOS = "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00";

	private function jpegBytes(int $w = 24, int $h = 16): string
	{
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		ob_start();
		imagejpeg($gd, null, 90);
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	private function minimalJpeg(string $segments = ''): string
	{
		return "\xFF\xD8" . $segments . self::SOF . self::SOS . 'scandata' . "\xFF\xD9";
	}

	public function testStreamingProducesTheSameBytesAsAWholeParseCompose()
	{
		$bytes = $this->jpegBytes();
		$lazy = TJPEG::fromStreamLazy(TStream::fromString($bytes));
		$target = TStream::fromMemory();
		$written = $lazy->streamTo($target);
		$target->rewind();
		$out = $target->getContents();
		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex((string) TJPEG::fromString($bytes)->toBinary()), bin2hex($out), 'streamed == whole-parse compose');
	}

	public function testAStreamedJpegComposesByMaterializingItsDeferredScan()
	{
		$bytes = $this->jpegBytes();
		$lazy = TJPEG::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame(bin2hex((string) TJPEG::fromString($bytes)->toBinary()), bin2hex((string) $lazy->toBinary()));
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->jpegBytes();
		$jpeg = TJPEG::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame([24, 16], [$jpeg->getWidth(), $jpeg->getHeight()]);

		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'title', 'Streamed edit');
		$jpeg->setXMP($xmp);

		$target = TStream::fromMemory();
		$jpeg->streamTo($target);
		$target->rewind();
		$round = TJPEG::fromString($target->getContents());
		self::assertSame('Streamed edit', $round->getXMP()?->getLangAltValue(TXMP::NS_DC, 'title'));
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
	}

	public function testLazyParseSkipsAStandaloneMarkerAndReadsDnl()
	{
		$dnl = "\xFF\xDC\x00\x04\x00\x10";   // DNL declaring 16 lines
		$jpeg = $this->minimalJpeg("\xFF\x01" . $dnl);
		$lazy = TJPEG::fromStreamLazy(TStream::fromString($jpeg));
		$target = TStream::fromMemory();
		$lazy->streamTo($target);
		$target->rewind();
		self::assertSame(bin2hex((string) TJPEG::fromString($jpeg)->toBinary()), bin2hex($target->getContents()));
	}

	public function testLazyParseToleratesAHeaderThatEndsBeforeTheScan()
	{
		$jpeg = "\xFF\xD8" . self::SOF;   // SOI + frame, no SOS/EOI
		$lazy = TJPEG::fromStreamLazy(TStream::fromString($jpeg));
		self::assertSame([24, 16], [$lazy->getWidth(), $lazy->getHeight()]);
		$target = TStream::fromMemory();
		$lazy->streamTo($target);
		$target->rewind();
		self::assertSame(bin2hex((string) TJPEG::fromString($jpeg)->toBinary()), bin2hex($target->getContents()));
	}

	public function testFromStreamLazyAcceptsAStreamResource()
	{
		$r = fopen('php://temp', 'r+b');
		fwrite($r, $this->jpegBytes());
		rewind($r);
		self::assertInstanceOf(TJPEG::class, TJPEG::fromStreamLazy($r));
		fclose($r);
	}

	public function testFromStreamLazyRejectsANonStreamSource()
	{
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		TJPEG::fromStreamLazy(42);
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TJPEG::fromStreamLazy(new TNoSeekStream(TStream::fromString($this->jpegBytes())));
	}

	public function testFromStreamLazyRejectsBytesThatAreNotJpeg()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TJPEG::fromStreamLazy(TStream::fromString('this is not a JPEG file'));
	}

	public function testStreamToAcceptsAStreamResourceTarget()
	{
		$bytes = $this->jpegBytes();
		$jpeg = TJPEG::fromStreamLazy(TStream::fromString($bytes));
		$r = fopen('php://temp', 'r+b');
		$jpeg->streamTo($r);
		rewind($r);
		self::assertSame(bin2hex((string) TJPEG::fromString($bytes)->toBinary()), bin2hex((string) stream_get_contents($r)));
		fclose($r);
	}

	public function testStreamToRejectsANonStreamTarget()
	{
		$jpeg = TJPEG::fromStreamLazy(TStream::fromString($this->jpegBytes()));
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		$jpeg->streamTo('not a stream');
	}
}
