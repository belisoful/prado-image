<?php

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\Meta\TPictureInfo;
use Prado\IO\Image\Meta\TPIM;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TIFF\TTIFFDocument;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPhotoshopIRB;
use Prado\IO\Image\TPhotoshopResource;
use Prado\IO\Image\TTIFF;
use Prado\IO\Stream\TBinaryStream;
use Prado\IO\Stream\TLimitStream;
use Prado\IO\TStream;
use Psr\Http\Message\StreamInterface;

class TStreamIOTest extends PHPUnit\Framework\TestCase
{
	private function jpegBytes(): string
	{
		$im = imagecreatetruecolor(12, 9);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	private function sampleExif(): TEXIF
	{
		$exif = new TEXIF();
		$exif->setValueByName('Make', 'PradoCam');
		$exif->getExifIfd(true)->setTagValues(33434, TTIFFDataType::URational, [[1, 250]]);
		return $exif;
	}

	public function testJpegFromStreamVariants()
	{
		$bytes = $this->jpegBytes();

		// TStream, TBinaryStream decorator, and a raw PHP resource all parse alike.
		$fromTStream = TJPEG::fromStream(TStream::fromString($bytes));
		$fromBinary = TJPEG::fromStream(new TBinaryStream(TStream::fromString($bytes)));
		$resource = fopen('php://memory', 'w+b');
		fwrite($resource, $bytes);
		$fromResource = TJPEG::fromStream($resource);
		fclose($resource);

		foreach ([$fromTStream, $fromBinary, $fromResource] as $jpeg) {
			self::assertSame(12, $jpeg->getWidth());
			self::assertSame(9, $jpeg->getHeight());
		}
	}

	public function testJpegWriteToStreamAndResource()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$expected = $jpeg->toBinary();

		$stream = TStream::fromString('');
		self::assertSame(strlen($expected), $jpeg->writeTo($stream));
		$stream->rewind();
		self::assertSame(bin2hex($expected), bin2hex($stream->getContents()));

		$resource = fopen('php://memory', 'w+b');
		self::assertSame(strlen($expected), $jpeg->writeTo($resource));
		rewind($resource);
		self::assertSame(bin2hex($expected), bin2hex(stream_get_contents($resource)));
		fclose($resource);
	}

	public function testTiffRoundTripThroughStreams()
	{
		$exif = $this->sampleExif();
		$tiff = TTIFF::fromString($exif->getTiff()->toBinary());

		$stream = TStream::fromMemory('w+b');
		$tiff->writeTo($stream);
		$stream->rewind();
		$reparsed = TTIFF::fromStream($stream);
		self::assertSame('PradoCam', $reparsed->getEXIF()->getMake());
	}

	public function testTiffDocumentWindowedStream()
	{
		// The document reads from the stream's current position, so a TLimitStream
		// window inside a larger buffer scopes the parse.
		$tiffBytes = $this->sampleExif()->getTiff()->toBinary();
		$buffer = 'JUNKPREFIX--' . $tiffBytes . '--TRAILING';
		$window = new TLimitStream(TStream::fromString($buffer), strlen($tiffBytes), 12);

		$document = TTIFFDocument::fromStream($window);
		self::assertSame('PradoCam', $document->getIfd(0)->getTagValue(271));
	}

	public function testExifFromStreamAutoDetectsForm()
	{
		$exif = $this->sampleExif();

		$segment = TEXIF::fromStream(TStream::fromString($exif->toBinary()));   // Exif signature
		self::assertSame('PradoCam', $segment->getMake());

		$bare = TEXIF::fromStream(TStream::fromString($exif->getTiff()->toBinary()));   // bare TIFF
		self::assertSame('PradoCam', $bare->getMake());

		$written = TStream::fromString('');
		$exif->writeTo($written);
		$written->rewind();
		self::assertSame('PradoCam', TEXIF::fromStream($written)->getMake());
	}

	public function testMetadataClassesStreamRoundTrips()
	{
		$irb = new TPhotoshopIRB();
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::Url, 'https://stream.example'));
		$stream = TStream::fromString('');
		$irb->writeTo($stream);
		$stream->rewind();
		$reIrb = TPhotoshopIRB::fromStream($stream);
		self::assertSame('https://stream.example', $reIrb->getResource(TPhotoshopResource::Url)->decodeText());

		$xmp = TXMP::blank();
		$xmp->setTitle('Streamed');
		$stream = TStream::fromString('');
		$xmp->writeTo($stream);
		$stream->rewind();
		self::assertSame('Streamed', TXMP::fromStream($stream)->getTitle());

		$pim = new TPIM();
		$pim->setEntry(0x0009, 7);
		$stream = TStream::fromString('');
		$pim->writeTo($stream);
		$stream->rewind();
		self::assertSame(7, TPIM::fromStream($stream)->getEntryValue(0x0009));

		$info = new TPictureInfo();
		$info->setHeader('[picture info]');
		$info->setText("\r\nMode=Fine\r\n[end]");
		$stream = TStream::fromString('');
		$info->writeTo($stream);
		$stream->rewind();
		self::assertSame(['Mode' => 'Fine'], TPictureInfo::fromStream($stream)->getFields());

		$iptc = new TIPTC();
		$iptc[TIPTCTags::ObjectName] = 'Stream Title';
		$stream = TStream::fromString('');
		$iptc->writeTo($stream);
		$stream->rewind();
		$reIptc = TIPTC::parse($stream);
		self::assertSame('Stream Title', $reIptc[TIPTCTags::ObjectName]);
	}

	public function testBinaryStreamAsWriteTarget()
	{
		// A TBinaryStream is a PSR-7 decorator, so it serves as a write target too.
		$exif = $this->sampleExif();
		$inner = TStream::fromMemory('w+b');
		$binary = new TBinaryStream($inner);
		$exif->writeTo($binary);
		$inner->rewind();
		self::assertSame('PradoCam', TEXIF::fromStream($inner)->getMake());
	}

	public function testWriteToHonorsPartialWritesAndStalls()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$expected = $jpeg->toBinary();

		// A stream taking only a few bytes per call is written in as many calls as it
		// takes, and the bytes arrive in order.
		$trickle = new TChunkedWriteStream(5);
		self::assertSame(strlen($expected), $jpeg->writeTo($trickle));
		self::assertSame(bin2hex($expected), bin2hex($trickle->buffer));
		self::assertSame((int) ceil(strlen($expected) / 5), $trickle->writes);

		// A stream that stops accepting bytes raises rather than looping forever.
		$stalled = new TChunkedWriteStream(0);
		try {
			$jpeg->writeTo($stalled);
			self::fail('writeTo accepted a stream that never wrote');
		} catch (TIOException $e) {
			self::assertSame('streamio_write_failed', $e->getErrorCode());
		}
	}

	public function testInvalidTargetsAndSources()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		try {
			$jpeg->writeTo('a plain string is not a write target');
			self::fail('writeTo accepted a string target');
		} catch (TInvalidDataTypeException $e) {
			self::assertSame('streamio_target_invalid', $e->getErrorCode());
		}

		self::expectException(TInvalidDataTypeException::class);
		TTIFFDocument::fromStream(12345);
	}
}

/**
 * A write target that accepts at most a fixed number of bytes per call, so the
 * partial-write loop of TStreamIOTrait::writeTo() can be observed; a chunk of zero is a
 * stream that never accepts anything.
 */
class TChunkedWriteStream implements StreamInterface
{
	public string $buffer = '';

	public int $writes = 0;

	public function __construct(private int $chunk)
	{
	}

	public function write(string $string): int
	{
		$this->writes++;
		$bytes = substr($string, 0, $this->chunk);
		$this->buffer .= $bytes;
		return strlen($bytes);
	}

	public function __toString(): string
	{
		return $this->buffer;
	}

	public function close(): void
	{
	}

	public function detach()
	{
		return null;
	}

	public function getSize(): ?int
	{
		return strlen($this->buffer);
	}

	public function tell(): int
	{
		return strlen($this->buffer);
	}

	public function eof(): bool
	{
		return true;
	}

	public function isSeekable(): bool
	{
		return false;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void
	{
		throw new RuntimeException('not seekable');
	}

	public function rewind(): void
	{
		throw new RuntimeException('not seekable');
	}

	public function isWritable(): bool
	{
		return true;
	}

	public function isReadable(): bool
	{
		return false;
	}

	public function read(int $length): string
	{
		throw new RuntimeException('not readable');
	}

	public function getContents(): string
	{
		return $this->buffer;
	}

	public function getMetadata(?string $key = null)
	{
		return $key === null ? [] : null;
	}
}
