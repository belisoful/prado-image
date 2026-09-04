<?php

use Prado\IO\Image\GIF\TGIFFrame;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TGIF;
use Prado\IO\Stream\TNoSeekStream;
use Prado\IO\TStream;

/**
 * Unit tests for the streaming (lazy) GIF path: {@see TGIF::fromStreamLazy()} reads the
 * block structure but keeps each frame's LZW image-data run as a deferred range into the
 * source, and {@see TGIF::streamTo()} copies those runs straight through while rebuilding
 * the rest — a metadata edit rewrites the file without holding its pixels.
 */
class TGIFStreamingTest extends PHPUnit\Framework\TestCase
{
	private function gifBytes(int $w = 24, int $h = 16): string
	{
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		ob_start();
		imagegif($gd);
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	/** A GIF carrying a comment, a NETSCAPE loop extension, and a raw XMP application block. */
	private function richGif(): string
	{
		$gif = TGIF::fromString($this->gifBytes());
		$gif->addComment('a streamed comment');
		$gif->setLoopCount(3);
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'title', 'Streamed edit');
		$gif->setXMP($xmp);
		return (string) $gif->toBinary();
	}

	/** A 4x4 GIF header with a 2-colour global table. */
	private function craftedHeader(): string
	{
		return "GIF89a" . pack('v', 4) . pack('v', 4) . chr(0x80) . chr(0) . chr(0) . "\x00\x00\x00\xFF\xFF\xFF";
	}

	public function testStreamingRoundTripIsByteFaithful()
	{
		$bytes = $this->richGif();
		$gif = TGIF::fromStreamLazy(TStream::fromString($bytes));
		$target = TStream::fromMemory();
		$written = $gif->streamTo($target);
		$target->rewind();
		$out = $target->getContents();
		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex($bytes), bin2hex($out), 'A no-edit streamed rewrite is byte-identical.');
	}

	public function testAStreamedGifComposesByMaterializingItsDeferredPixels()
	{
		$bytes = $this->richGif();
		$gif = TGIF::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame(bin2hex($bytes), bin2hex((string) $gif->toBinary()));
		self::assertNotEmpty($gif->getFrames()[0]->getDataSubBlocks());
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->gifBytes();
		$gif = TGIF::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame([24, 16], [$gif->getWidth(), $gif->getHeight()]);

		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'title', 'Streamed edit');
		$gif->setXMP($xmp);

		$target = TStream::fromMemory();
		$gif->streamTo($target);
		$target->rewind();
		$round = TGIF::fromString($target->getContents());
		self::assertSame('Streamed edit', $round->getXMP()?->getLangAltValue(TXMP::NS_DC, 'title'));
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
	}

	public function testLazyParseHandlesLocalTablesGraphicControlEmptyExtensionsAndTrailingBytes()
	{
		$emptyComment = "\x21\xFE\x00";
		$gce = "\x21\xF9\x04\x00\x0A\x00\x00\x00";
		$frame = "\x2C" . pack('vvvv', 0, 0, 4, 4) . chr(0x80)
			. "\x00\x00\x00\xFF\xFF\xFF"
			. "\x02" . "\x02XX" . "\x00";
		$gif = $this->craftedHeader() . $emptyComment . $gce . $frame . "\x3B" . 'trailing';

		$lazy = TGIF::fromStreamLazy(TStream::fromString($gif));
		$target = TStream::fromMemory();
		$lazy->streamTo($target);
		$target->rewind();
		self::assertSame(bin2hex((string) TGIF::fromString($gif)->toBinary()), bin2hex($target->getContents()));
	}

	public function testLazyParseRejectsAnUnexpectedBlockMarker()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TGIF::fromStreamLazy(TStream::fromString($this->craftedHeader() . "\x99"));
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TGIF::fromStreamLazy(new TNoSeekStream(TStream::fromString($this->gifBytes())));
	}

	public function testFromStreamLazyRejectsBytesThatAreNotGif()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TGIF::fromStreamLazy(TStream::fromString('this is not a GIF at all'));
	}

	public function testFromStreamLazyAcceptsAStreamResource()
	{
		$r = fopen('php://temp', 'r+b');
		fwrite($r, $this->gifBytes());
		rewind($r);
		self::assertInstanceOf(TGIF::class, TGIF::fromStreamLazy($r));
		fclose($r);
	}

	public function testFromStreamLazyRejectsANonStreamSource()
	{
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		TGIF::fromStreamLazy(42);
	}

	public function testStreamToAcceptsAStreamResourceTarget()
	{
		$bytes = $this->gifBytes();
		$gif = TGIF::fromStreamLazy(TStream::fromString($bytes));
		$r = fopen('php://temp', 'r+b');
		$gif->streamTo($r);
		rewind($r);
		self::assertSame(bin2hex($bytes), bin2hex((string) stream_get_contents($r)));
		fclose($r);
	}

	public function testStreamToRejectsANonStreamTarget()
	{
		$gif = TGIF::fromStreamLazy(TStream::fromString($this->gifBytes()));
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		$gif->streamTo('not a stream');
	}

	public function testCopyDeferredToRejectsALoadedFrame()
	{
		$frame = new TGIFFrame();
		$this->expectException(\RuntimeException::class);
		$frame->copyDeferredTo(TStream::fromMemory());
	}
}
