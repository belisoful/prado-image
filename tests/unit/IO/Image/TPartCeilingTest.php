<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Image\BMFF\TBMFFBox;
use Prado\IO\Image\TAVI;
use Prado\IO\Image\TBMFF;
use Prado\IO\Image\TGIF;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TJXL;
use Prado\IO\Image\TPNG;
use Prado\IO\Image\TRIFF;
use Prado\IO\Image\TRIFFChunkType;
use Prado\IO\Image\TRIFFList;
use Prado\IO\Image\TTIFF;
use Prado\IO\Image\TWebP;
use Prado\IO\TStream;

/**
 * Every parser makes one record of each part it meets — a segment, a chunk, a box, a block —
 * and a record costs a few hundred bytes however small the part is on disk.  The part
 * ceiling is what stops a file of nothing but empty parts from costing many times its size:
 * each container is given such a file here, eagerly and through its lazy reader, and must
 * refuse it with an exception a caller can catch rather than exhaust the memory limit.
 */
class TPartCeilingTest extends PHPUnit\Framework\TestCase
{
	protected function tearDown(): void
	{
		TImageFile::setMemoryBudget(null);
	}

	public function testAMemoryBudgetSetsTheThreeCeilings()
	{
		// 128 MiB is the budget the parts and inflation defaults were sized for.
		TImageFile::setMemoryBudget(128 * 1024 * 1024);
		self::assertSame(TImageFile::DefaultMaxParts, TImageFile::getMaxParts());
		self::assertSame(TPNG::MaxInflatedSize, TPNG::getMaxInflatedSize());
		self::assertSame(8 * 1024 * 1024, TImageGraphics::getMaxPixels());

		TImageFile::setMemoryBudget(32 * 1024 * 1024);
		self::assertSame(16384, TImageFile::getMaxParts());
		self::assertSame(4 * 1024 * 1024, TPNG::getMaxInflatedSize());
		self::assertSame(2 * 1024 * 1024, TImageGraphics::getMaxPixels());

		// Each ceiling's own setter still moves it alone.
		TImageFile::setMaxParts(10);
		self::assertSame(10, TImageFile::getMaxParts());
		self::assertSame(4 * 1024 * 1024, TPNG::getMaxInflatedSize());

		TImageFile::setMemoryBudget(null);
		self::assertSame(TImageFile::DefaultMaxParts, TImageFile::getMaxParts());
		self::assertSame(TPNG::MaxInflatedSize, TPNG::getMaxInflatedSize());
		self::assertSame(TImageGraphics::DefaultMaxPixels, TImageGraphics::getMaxPixels());
	}

	public function testABudgetIsWhatRefusesAFileThatWouldNotFitIt()
	{
		// Three hundred empty chunks are nothing to the default ceiling and too many for a
		// budget of a few hundred kilobytes, which allows two hundred.
		$png = $this->png(str_repeat($this->pngChunk('tEXt'), 300));
		self::assertInstanceOf(TPNG::class, TPNG::fromString($png));

		TImageFile::setMemoryBudget(200 * TImageFile::BudgetBytesPerPart);
		try {
			TPNG::fromString($png);
			self::fail('a file over its budget was opened');
		} catch (TIOException $e) {
			self::assertSame('imagefile_too_many_parts', $e->getErrorCode());
		}
	}

	private function chunk(string $id, string $payload = ''): string
	{
		return ContainerFixtures::chunk($id, $payload);
	}

	private function riff(string $form, string $body): string
	{
		return 'RIFF' . pack('V', 4 + strlen($body)) . $form . $body;
	}

	private function pngChunk(string $type, string $payload = ''): string
	{
		return pack('N', strlen($payload)) . $type . $payload . pack('N', crc32($type . $payload));
	}

	private function png(string $chunks): string
	{
		return "\x89PNG\r\n\x1a\n" . $this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0)) . $chunks . $this->pngChunk('IEND');
	}

	private function gif(string $blocks): string
	{
		return 'GIF89a' . pack('vv', 1, 1) . "\x00\x00\x00" . $blocks . "\x3B";
	}

	private function assertRefused(callable $open, string $what): void
	{
		try {
			$open();
			self::fail("$what is over the part ceiling");
		} catch (TIOException $e) {
			self::assertStringContainsString('parts its ceiling and length allow', $e->getMessage(), $what);
		}
	}

	public function testPartCeiling(): void
	{
		self::assertSame(1 << 16, TImageFile::DefaultMaxParts);
		self::assertSame(TImageFile::DefaultMaxParts, TImageFile::getMaxParts());
		TImageFile::assertPartCount(65536);   // exactly at the ceiling
		$this->assertRefused(fn () => TImageFile::assertPartCount(65537), 'one part more');

		// A file earns one more part for every 256 bytes of its length, so a large file of
		// ordinary parts is never refused for being large.
		TImageFile::assertPartCount(65537, 256);
		TImageFile::assertPartCount(65536 + 4096, 1 << 20);
		$this->assertRefused(fn () => TImageFile::assertPartCount(65538, 511), 'two parts more in 511 bytes');

		TImageFile::setMaxParts(4);
		self::assertSame(4, TImageFile::getMaxParts());
		self::assertTrue(TImageFile::allowsPartCount(4));
		self::assertFalse(TImageFile::allowsPartCount(5), 'the same answer as a question, for metadata that reads as absent');
		self::assertTrue(TImageFile::allowsPartCount(5, 256));
		TImageFile::assertPartCount(4);
		$this->assertRefused(fn () => TImageFile::assertPartCount(5), 'five parts under a ceiling of four');

		TImageFile::setMaxParts(null);
		self::assertSame(TImageFile::DefaultMaxParts, TImageFile::getMaxParts());
	}

	/**
	 * Each container, given forty of the smallest part its format has.
	 * @return array<string, array{0: class-string<TImageFile>, 1: string, 2: bool}> The class, the bytes,
	 *   and whether the class has a lazy reader.
	 */
	private function filesOfEmptyParts(): array
	{
		return [
			'JPEG segments' => [TJPEG::class, "\xFF\xD8" . str_repeat("\xFF\xE5\x00\x02", 40) . "\xFF\xD9", true],
			'PNG chunks' => [TPNG::class, $this->png(str_repeat($this->pngChunk('tEXt'), 40)), true],
			'GIF blocks' => [TGIF::class, $this->gif(str_repeat("\x21\xFE\x00", 40)), true],
			'GIF data sub-blocks' => [TGIF::class, $this->gif("\x21\xFE" . str_repeat("\x01x", 40) . "\x00"), true],
			'WebP chunks' => [TWebP::class, $this->riff('WEBP', str_repeat($this->chunk('JUNK'), 40)), true],
			'AVI chunks' => [TAVI::class, $this->riff('AVI ', str_repeat($this->chunk('JUNK'), 40)), true],
			'HEIF boxes' => [TBMFF::class, ContainerFixtures::box('ftyp', 'heic' . pack('N', 0) . 'mif1heic') . str_repeat(ContainerFixtures::box('free'), 40), false],
			'JPEG XL boxes' => [TJXL::class, ContainerFixtures::jxl(...array_fill(0, 40, ContainerFixtures::box('free'))), false],
			'TIFF directory entries' => [TTIFF::class, "MM\x00\x2A\x00\x00\x00\x08" . pack('n', 40) . str_repeat(pack('nnNN', 0x0100, 4, 1, 1), 40) . pack('N', 0), true],
		];
	}

	public function testEveryContainerRefusesAFileOfMorePartsThanItsCeilingAllows(): void
	{
		foreach ($this->filesOfEmptyParts() as $what => [$class, $bytes, $hasLazy]) {
			TImageFile::setMaxParts(null);
			self::assertInstanceOf($class, $class::fromString($bytes), "$what: forty parts are nothing under the default ceiling");

			TImageFile::setMaxParts(8);
			$this->assertRefused(fn () => $class::fromString($bytes), $what);
			if ($hasLazy) {
				$this->assertRefused(fn () => $class::fromStreamLazy(TStream::fromString($bytes)), "$what, read lazily");
			}
		}
	}

	public function testALargeFileOfOrdinaryPartsIsNotRefused(): void
	{
		// Eight chunks under a ceiling of two: allowed, because they span over 1800 bytes.
		TImageFile::setMaxParts(2);
		$bytes = $this->png(str_repeat($this->pngChunk('tEXt', str_repeat('a', 300)), 6));

		self::assertCount(8, TPNG::fromString($bytes)->getChunks());
		self::assertCount(8, TPNG::fromStreamLazy(TStream::fromString($bytes))->getChunks());
	}

	public function testNestedBoxesCountTowardTheOneCeiling(): void
	{
		// No level holds more than eight boxes, but the file holds thirteen.
		$free = ContainerFixtures::box('free');
		$bytes = ContainerFixtures::box('ftyp', 'isom' . pack('N', 0) . 'isommp41')
			. ContainerFixtures::box('moov', str_repeat($free, 6))
			. str_repeat($free, 5);
		TImageFile::setMaxParts(13);
		self::assertInstanceOf(TBMFF::class, TBMFF::fromString($bytes));

		TImageFile::setMaxParts(8);
		$this->assertRefused(fn () => TBMFF::fromString($bytes), 'thirteen boxes across two levels');

		// A walk started on its own is counted on its own.
		self::assertCount(5, TBMFFBox::parseBoxes(str_repeat($free, 5)));
		$this->assertRefused(fn () => TBMFFBox::parseBoxes(str_repeat($free, 9)), 'nine boxes');
	}

	public function testAListsChunksCountTowardItsContainersCeiling(): void
	{
		// A list is walked only when it is asked for its children, so each list of a file
		// would otherwise start counting again from nothing.
		$list = fn (string $type, int $children) => $this->chunk('LIST', $type . str_repeat($this->chunk('JUNK'), $children));
		$bytes = $this->riff('AVI ', $list('hdrl', 6) . str_repeat($this->chunk('JUNK'), 4));
		TImageFile::setMaxParts(8);

		$riff = TRIFF::fromString($bytes);
		self::assertCount(5, $riff->getChunks(), 'the five top-level chunks are under the ceiling');
		$this->assertRefused(fn () => $riff->getList('hdrl')->getChunks(), 'six more chunks in a list of that container');

		$lazy = TRIFF::fromStreamLazy(TStream::fromString($bytes), [TRIFFChunkType::RiffList . ':movi']);
		$this->assertRefused(fn () => $lazy->getList('hdrl')->getChunks(), 'the same list of a lazily read container');

		// A list built on its own is a walk of its own.
		$payload = fn (int $children) => 'INFO' . str_repeat($this->chunk('JUNK'), $children);
		self::assertCount(6, (new TRIFFList('LIST', strlen($payload(6)), 0, $payload(6)))->getChunks());
		$this->assertRefused(fn () => (new TRIFFList('LIST', strlen($payload(9)), 0, $payload(9)))->getChunks(), 'nine chunks in a list on its own');
	}
}
