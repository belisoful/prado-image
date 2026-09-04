<?php

use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TImageChunk;
use Prado\IO\Image\TPNG;
use Prado\IO\Stream\TNoSeekStream;
use Prado\IO\TStream;

/**
 * Unit tests for the streaming (lazy) PNG path: {@see TPNG::fromStreamLazy()} reads the
 * chunk framing and small metadata but keeps each `IDAT` as a deferred range into the
 * source, and {@see TPNG::streamTo()} copies those pixel chunks straight through while
 * rebuilding the metadata — so a metadata edit rewrites the file without holding its pixels.
 */
class TPNGStreamingTest extends PHPUnit\Framework\TestCase
{
	private function pngBytes(int $w = 24, int $h = 16): string
	{
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		ob_start();
		imagepng($gd);
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	public function testStreamingRoundTripIsByteFaithful()
	{
		$bytes = $this->pngBytes();
		$png = TPNG::fromStreamLazy(TStream::fromString($bytes));

		$target = TStream::fromMemory();
		$written = $png->streamTo($target);
		$target->rewind();
		$out = $target->getContents();

		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex($bytes), bin2hex($out), 'A no-edit streamed rewrite is byte-identical.');
	}

	public function testAStreamedPngComposesByMaterializingItsDeferredPixels()
	{
		$bytes = $this->pngBytes();
		$png = TPNG::fromStreamLazy(TStream::fromString($bytes));
		// toBinary() composes the whole string, materializing the deferred IDAT chunks.
		self::assertSame(bin2hex($bytes), bin2hex((string) $png->toBinary()));
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->pngBytes();
		$png = TPNG::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame([24, 16], [$png->getWidth(), $png->getHeight()]);

		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'title', 'Streamed edit');
		$png->setXMP($xmp);

		$target = TStream::fromMemory();
		$png->streamTo($target);
		$target->rewind();
		$out = $target->getContents();

		// Re-read the streamed output the whole-string way: the edit landed and the pixels survived.
		$round = TPNG::fromString($out);
		self::assertSame('Streamed edit', $round->getXMP()?->getLangAltValue(TXMP::NS_DC, 'title'));
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TPNG::fromStreamLazy(new TNoSeekStream(TStream::fromString($this->pngBytes())));
	}

	public function testFromStreamLazyRejectsBytesThatAreNotPng()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TPNG::fromStreamLazy(TStream::fromString('this is not a PNG file'));
	}

	public function testFromStreamLazyToleratesATailWithoutIend()
	{
		// Strip the 12-byte IEND so the chunk stream ends at a boundary; the lazy parse reads
		// the chunks it has and stops at end-of-stream instead of throwing.
		$png = TPNG::fromStreamLazy(TStream::fromString(substr($this->pngBytes(), 0, -12)));
		self::assertNotEmpty($png->getChunks());
	}

	public function testFromStreamLazyAcceptsAStreamResource()
	{
		$r = fopen('php://temp', 'r+b');
		fwrite($r, $this->pngBytes());
		rewind($r);
		self::assertInstanceOf(TPNG::class, TPNG::fromStreamLazy($r));   // a resource is wrapped without ownership
		fclose($r);
	}

	public function testFromStreamLazyRejectsANonStreamSource()
	{
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		TPNG::fromStreamLazy(42);
	}

	public function testStreamToAcceptsAStreamResourceTarget()
	{
		$bytes = $this->pngBytes();
		$png = TPNG::fromStreamLazy(TStream::fromString($bytes));
		$r = fopen('php://temp', 'r+b');
		$png->streamTo($r);
		rewind($r);
		self::assertSame(bin2hex($bytes), bin2hex((string) stream_get_contents($r)));
		fclose($r);
	}

	public function testStreamToRejectsANonStreamTarget()
	{
		$png = TPNG::fromStreamLazy(TStream::fromString($this->pngBytes()));
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		$png->streamTo('not a stream');
	}

	public function testCopyDeferredToRejectsALoadedChunk()
	{
		$chunk = new TImageChunk('IHDR', 3, 8, 'abc');
		$this->expectException(\RuntimeException::class);
		$chunk->copyDeferredTo(TStream::fromMemory());
	}
}
