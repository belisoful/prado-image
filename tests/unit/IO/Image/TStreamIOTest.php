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

	// ---- save(): the file is replaced whole or left as it was ----

	/**
	 * Runs a test against a file in a directory of its own, removed afterwards.
	 * @param callable(string, string): void $test Given the file's path and its directory.
	 */
	private function withSavedFile(callable $test): void
	{
		$dir = sys_get_temp_dir() . '/save-' . bin2hex(random_bytes(6));
		mkdir($dir);
		$path = $dir . '/picture.jpg';
		file_put_contents($path, $this->jpegBytes());
		try {
			$test($path, $dir);
		} finally {
			foreach (array_diff((array) scandir($dir), ['.', '..']) as $name) {
				@unlink($dir . '/' . $name);
			}
			@rmdir($dir);
		}
	}

	private function editedJpeg(string $path): TJPEG
	{
		$jpeg = TJPEG::fromFile($path);
		$jpeg->setEXIF($this->sampleExif());
		return $jpeg;
	}

	public function testSaveReplacesTheFileAndKeepsItsPermissions()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			chmod($path, 0o600);
			$before = fileinode($path);
			$jpeg = $this->editedJpeg($path);

			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save($path));
			clearstatcache();
			// A new file took the old one's name, with its permissions, and nothing else
			// is left beside it.
			self::assertNotSame($before, fileinode($path));
			self::assertSame(0o600, fileperms($path) & 0o777);
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($path)));
			self::assertSame(['picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
		});
	}

	public function testAWriteThatStopsShortLeavesTheOriginalAsItWas()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$original = (string) file_get_contents($path);
			$jpeg = TFullDiskJpeg::fromString($original);
			$jpeg->setEXIF($this->sampleExif());
			try {
				$jpeg->save($path);
				self::fail('a short write was reported as a save');
			} catch (TIOException $e) {
				self::assertSame('imagefile_unwritable', $e->getErrorCode());
			}
			self::assertSame(bin2hex($original), bin2hex((string) file_get_contents($path)));
			self::assertSame(['picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
		});
	}

	public function testSaveFollowsALinkToTheFileItNames()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$link = $dir . '/link.jpg';
			symlink($path, $link);
			$jpeg = $this->editedJpeg($path);
			$jpeg->save($link);
			clearstatcache();
			self::assertTrue(is_link($link));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($path)));
		});
	}

	public function testSaveThroughALinkToNothingWritesTheFileItNames()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$link = $dir . '/link.jpg';
			symlink($dir . '/absent.jpg', $link);
			$jpeg = $this->editedJpeg($path);
			$jpeg->save($link);
			clearstatcache();
			self::assertTrue(is_link($link));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($dir . '/absent.jpg')));
		});
	}

	/**
	 * Asserts that a save is refused as one that cannot replace the file, and that the
	 * file and its directory are as they were.
	 * @param TJPEG $jpeg
	 * @param string $path
	 * @param string $dir
	 * @param string $why
	 */
	private function assertNotReplaced(TJPEG $jpeg, string $path, string $dir, string $why): void
	{
		$original = (string) file_get_contents($path);
		$before = fileinode($path);
		try {
			$jpeg->save($path);
			self::fail('a file that could not be replaced was reported as saved');
		} catch (TIOException $e) {
			self::assertSame($why, $e->getErrorCode());
			self::assertStringContainsString('writeTo()', $e->getMessage());
		}
		clearstatcache();
		self::assertSame($before, fileinode($path));   // the same file, never written over in place
		self::assertSame(bin2hex($original), bin2hex((string) file_get_contents($path)));
		self::assertSame(['picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
	}

	public function testSaveThrowsWhenTheRenameIsRefusedAndLeavesTheFileAsItWas()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$jpeg = TNoRenameJpeg::fromString((string) file_get_contents($path));
			$jpeg->setEXIF($this->sampleExif());
			$this->assertNotReplaced($jpeg, $path, $dir, 'imagefile_rename_refused');
		});
	}

	public function testSaveThrowsWhenTheDirectoryTakesNoTemporaryFile()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			// The file itself can be written, which is what a write in place would have
			// used; its directory takes no new file, so there is nothing to rename.
			chmod($dir, 0o555);
			try {
				if (@touch($dir . '/probe')) {
					self::markTestSkipped('this user writes to a read-only directory');
				}
				$this->assertNotReplaced($this->editedJpeg($path), $path, $dir, 'imagefile_no_temporary_file');
			} finally {
				chmod($dir, 0o755);
			}
		});
	}

	public function testSaveIntoADirectoryThatDoesNotExistThrows()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			try {
				$this->editedJpeg($path)->save($dir . '/absent/picture.jpg');
				self::fail('a file was saved into a directory that does not exist');
			} catch (TIOException $e) {
				self::assertSame('imagefile_no_temporary_file', $e->getErrorCode());
			}
			self::assertFalse(is_dir($dir . '/absent'));
		});
	}

	public function testSaveToANewFileWritesIt()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$jpeg = $this->editedJpeg($path);
			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save($dir . '/copy.jpg'));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($dir . '/copy.jpg')));
			self::assertSame(['copy.jpg', 'picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
		});
	}

	public function testSaveOverAFileWhoseNameIsAsLongAsANameMayBe()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			// The temporary file is not named after the target, so there is room for it.
			$long = $dir . '/' . str_repeat('n', 251) . '.jpg';
			copy($path, $long);
			$jpeg = $this->editedJpeg($long);
			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save($long));
			self::assertSame(bin2hex($jpeg->toBinary()), bin2hex((string) file_get_contents($long)));
		});
	}

	public function testSaveOverSomethingThatIsNotAFileIsNotARename()
	{
		$this->withSavedFile(function (string $path, string $dir): void {
			$jpeg = $this->editedJpeg($path);
			// A directory is not a file to replace: the plain write is tried and refuses it.
			try {
				$jpeg->save($dir);
				self::fail('a directory was written over');
			} catch (TIOException $e) {
				self::assertTrue(is_dir($dir));
				self::assertSame(['picture.jpg'], array_values(array_diff((array) scandir($dir), ['.', '..'])));
			}
			// A stream wrapper has no rename to rely on and is written directly.
			self::assertSame(strlen($jpeg->toBinary()), $jpeg->save('php://memory'));
		});
	}
}

/**
 * A JPEG on a disk that fills half-way through a write.
 */
class TFullDiskJpeg extends TJPEG
{
	protected function writeHandle($handle, string $bytes): false|int
	{
		return fwrite($handle, substr($bytes, 0, intdiv(strlen($bytes), 2)));
	}
}

/**
 * A JPEG whose temporary file is gone by the time it is to be renamed, as when the
 * directory refuses the rename.
 */
class TNoRenameJpeg extends TJPEG
{
	protected function writeHandle($handle, string $bytes): false|int
	{
		$written = fwrite($handle, $bytes);
		unlink(stream_get_meta_data($handle)['uri']);
		return $written;
	}
}
