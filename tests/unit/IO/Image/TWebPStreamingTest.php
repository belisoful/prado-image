<?php

use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TWebP;
use Prado\IO\Stream\TNoSeekStream;
use Prado\IO\TStream;
use Prado\Prado;

/**
 * Unit tests for the streaming (lazy) WebP path: {@see TWebP::fromStreamLazy()} reads the
 * RIFF framing and small metadata chunks but keeps each large pixel chunk as a deferred
 * range into the source, and {@see TWebP::streamTo()} copies those chunks straight through
 * while rebuilding the metadata — a metadata edit rewrites the file without its pixels.
 */
class TWebPStreamingTest extends PHPUnit\Framework\TestCase
{
	private function webpBytes(int $w = 24, int $h = 16): string
	{
		if (!function_exists('imagewebp')) {
			self::markTestSkipped('GD lacks WebP support.');
		}
		$gd = imagecreatetruecolor($w, $h);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				imagesetpixel($gd, $x, $y, imagecolorallocate($gd, ($x * 11) & 255, ($y * 17) & 255, (($x + $y) * 7) & 255));
			}
		}
		ob_start();
		imagewebp($gd);
		$bytes = (string) ob_get_clean();
		imagedestroy($gd);
		return $bytes;
	}

	/** Assembles a WEBP RIFF from `[id, data]` chunks (GD only makes plain lossy VP8). */
	private function riffWebp(array $chunks): string
	{
		$body = 'WEBP';
		foreach ($chunks as [$id, $data]) {
			$body .= $id . pack('V', strlen($data)) . $data;
			if (strlen($data) & 1) {
				$body .= "\0";
			}
		}
		return 'RIFF' . pack('V', strlen($body)) . $body;
	}

	public function testStreamingRoundTripIsByteFaithful()
	{
		$bytes = $this->webpBytes();
		$webp = TWebP::fromStreamLazy(TStream::fromString($bytes));
		$target = TStream::fromMemory();
		$written = $webp->streamTo($target);
		$target->rewind();
		$out = $target->getContents();
		self::assertSame(strlen($out), $written);
		self::assertSame(bin2hex($bytes), bin2hex($out), 'A no-edit streamed rewrite is byte-identical.');
	}

	public function testAStreamedWebPComposesByMaterializingItsDeferredPixels()
	{
		$bytes = $this->webpBytes();
		$webp = TWebP::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame(bin2hex($bytes), bin2hex((string) $webp->toBinary()));
	}

	public function testStreamingAMetadataEditKeepsThePixels()
	{
		$bytes = $this->webpBytes();
		$webp = TWebP::fromStreamLazy(TStream::fromString($bytes));
		self::assertSame([24, 16], [$webp->getWidth(), $webp->getHeight()]);

		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_DC, 'title', 'Streamed edit');
		$webp->setXMP($xmp);

		$target = TStream::fromMemory();
		$webp->streamTo($target);
		$target->rewind();
		$round = TWebP::fromString($target->getContents());
		self::assertSame('Streamed edit', $round->getXMP()?->getLangAltValue(TXMP::NS_DC, 'title'));
		self::assertSame([24, 16], [$round->getWidth(), $round->getHeight()]);
	}

	public function testLazyParseOfAWebPWithMetadataChunks()
	{
		// VP8X carries the canvas size (24x16); the odd-length ICCP exercises the loaded-chunk
		// and pad paths, and VP8 is the deferred pixel chunk.
		$vp8x = "\x00\x00\x00\x00\x17\x00\x00\x0f\x00\x00";
		$webp = $this->riffWebp([['VP8X', $vp8x], ['ICCP', 'odd12'], ['VP8 ', 'lossy-pixel-bytes']]);
		$image = TWebP::fromStreamLazy(TStream::fromString($webp));
		self::assertSame([24, 16], [$image->getWidth(), $image->getHeight()]);

		$target = TStream::fromMemory();
		$image->streamTo($target);
		$target->rewind();
		self::assertSame(bin2hex($webp), bin2hex($target->getContents()), 'metadata + deferred pixel chunk round-trip.');
	}

	public function testLazyParseOfALosslessWebP()
	{
		$vp8l = "\x2f" . pack('V', 23 | (15 << 14));   // VP8L header encoding 24x16
		$webp = $this->riffWebp([['VP8L', $vp8l]]);
		$image = TWebP::fromStreamLazy(TStream::fromString($webp));
		self::assertSame([24, 16], [$image->getWidth(), $image->getHeight()]);

		$target = TStream::fromMemory();
		$image->streamTo($target);
		$target->rewind();
		self::assertSame(bin2hex($webp), bin2hex($target->getContents()));
	}

	public function testFromStreamLazyAcceptsAStreamResource()
	{
		$r = fopen('php://temp', 'r+b');
		fwrite($r, $this->webpBytes());
		rewind($r);
		self::assertInstanceOf(TWebP::class, TWebP::fromStreamLazy($r));
		fclose($r);
	}

	public function testFromStreamLazyRejectsANonStreamSource()
	{
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		TWebP::fromStreamLazy(42);
	}

	public function testFromStreamLazyRejectsANonSeekableStream()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TWebP::fromStreamLazy(new TNoSeekStream(TStream::fromString($this->webpBytes())));
	}

	public function testFromStreamLazyRejectsBytesWithoutARiffHeader()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TWebP::fromStreamLazy(TStream::fromString('not a riff container'));
	}

	public function testFromStreamLazyRejectsANonWebpRiffForm()
	{
		$this->expectException(\Prado\Exceptions\TIOException::class);
		TWebP::fromStreamLazy(TStream::fromString('RIFF' . pack('V', 4) . 'WAVE'));
	}

	public function testStreamToAcceptsAStreamResourceTarget()
	{
		$bytes = $this->webpBytes();
		$webp = TWebP::fromStreamLazy(TStream::fromString($bytes));
		$r = fopen('php://temp', 'r+b');
		$webp->streamTo($r);
		rewind($r);
		self::assertSame(bin2hex($bytes), bin2hex((string) stream_get_contents($r)));
		fclose($r);
	}

	public function testStreamToRejectsANonStreamTarget()
	{
		$webp = TWebP::fromStreamLazy(TStream::fromString($this->webpBytes()));
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		$webp->streamTo('not a stream');
	}

	public function testStreamToOnAnUnparsedWebPFallsBackToItsBytes()
	{
		$webp = Prado::createComponent(TWebP::class);   // never parsed: _riff is null
		self::assertSame(0, $webp->streamTo(TStream::fromMemory()));
	}
}
