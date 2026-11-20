<?php

use Prado\Exceptions\TInvalidDataValueException;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\BMFF\TBMFFBox;
use Prado\IO\Image\TAVI;
use Prado\IO\Image\TBMFF;
use Prado\IO\Image\TGIF;
use Prado\IO\Image\TImageFile;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TEXIFAudio;
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
	/** @var string The memory limit the suite runs under, which the tests here move. */
	private string $_memoryLimit;

	protected function setUp(): void
	{
		// The ceilings follow the memory limit, so each test starts from none: the fixed
		// defaults, whatever limit the suite itself was started with.
		$this->_memoryLimit = (string) ini_set('memory_limit', '-1');
	}

	protected function tearDown(): void
	{
		TImageFile::setMemoryBudget(null);
		TImageFile::setMaxParts(null);
		TImageFile::setMaxFileSize(null);
		TPNG::setMaxInflatedSize(null);
		TImageGraphics::setMaxPixels(null);
		ini_set('memory_limit', $this->_memoryLimit);
		array_map('unlink', $this->_tempFiles);
		$this->_tempFiles = [];
	}

	public function testAMemoryBudgetSetsTheThreeCeilings()
	{
		// 128 MiB is the budget the parts and inflation defaults were sized for.
		TImageFile::setMemoryBudget(128 * 1024 * 1024);
		self::assertSame(128 * 1024 * 1024, TImageFile::getMemoryBudget());
		self::assertSame(TImageFile::DefaultMaxParts, TImageFile::getMaxParts());
		self::assertSame(TPNG::MaxInflatedSize, TPNG::getMaxInflatedSize());
		self::assertSame(8 * 1024 * 1024, TImageGraphics::getMaxPixels());

		TImageFile::setMemoryBudget(32 * 1024 * 1024);
		self::assertSame(16384, TImageFile::getMaxParts());
		self::assertSame(4 * 1024 * 1024, TPNG::getMaxInflatedSize());
		self::assertSame(2 * 1024 * 1024, TImageGraphics::getMaxPixels());

		// Each ceiling's own setter moves it alone, and set back it is the budget's.
		TImageFile::setMaxParts(10);
		TPNG::setMaxInflatedSize(20);
		TImageGraphics::setMaxPixels(30);
		self::assertSame(10, TImageFile::getMaxParts());
		self::assertSame(20, TPNG::getMaxInflatedSize());
		self::assertSame(30, TImageGraphics::getMaxPixels());
		TImageFile::setMaxParts(null);
		self::assertSame(16384, TImageFile::getMaxParts());
		self::assertSame(20, TPNG::getMaxInflatedSize());

		// A ceiling set on its own outlasts a new budget, which moves only the others...
		TImageFile::setMemoryBudget(64 * 1024 * 1024);
		self::assertSame(32768, TImageFile::getMaxParts());
		self::assertSame(20, TPNG::getMaxInflatedSize());
		self::assertSame(30, TImageGraphics::getMaxPixels());

		// ...until its own setter hands it back.
		TPNG::setMaxInflatedSize(null);
		TImageGraphics::setMaxPixels(null);
		self::assertSame(8 * 1024 * 1024, TPNG::getMaxInflatedSize());
		self::assertSame(4 * 1024 * 1024, TImageGraphics::getMaxPixels());

		// With no budget and no memory limit, the fixed defaults -- and still a ceiling's own
		// value where it has one.
		TImageFile::setMaxParts(10);
		TImageFile::setMemoryBudget(null);
		self::assertSame(10, TImageFile::getMaxParts());
		TImageFile::setMaxParts(null);
		self::assertNull(TImageFile::getMemoryBudget());
		self::assertNull(TImageFile::getBudgetCeiling(TImageFile::BudgetBytesPerPart));
		self::assertSame(TImageFile::DefaultMaxParts, TImageFile::getMaxParts());
		self::assertSame(TPNG::MaxInflatedSize, TPNG::getMaxInflatedSize());
		self::assertSame(TImageGraphics::DefaultMaxPixels, TImageGraphics::getMaxPixels());
	}

	public function testTheBudgetIsHalfTheMemoryLimitUntilOneIsSet()
	{
		$gib = 1 << 30;
		self::assertSame(0.5, TImageFile::DefaultBudgetShare);

		ini_set('memory_limit', '16G');
		self::assertSame(8 * $gib, TImageFile::getMemoryBudget());
		self::assertSame(intdiv(8 * $gib, TImageFile::BudgetBytesPerPart), TImageFile::getMaxParts());
		self::assertSame($gib, TPNG::getMaxInflatedSize());
		self::assertSame(intdiv($gib, 2), TImageGraphics::getMaxPixels());

		// The limit is read each time, in whichever way PHP was given it.
		ini_set('memory_limit', '32g');
		self::assertSame(16 * $gib, TImageFile::getMemoryBudget());
		ini_set('memory_limit', (string) (64 * $gib));
		self::assertSame(32 * $gib, TImageFile::getMemoryBudget());
		self::assertSame(32 * $gib, TImageFile::getMemoryBudget(), 'and parsed once for each value it takes');

		// A budget that was set does not follow the limit, and unset it does again.
		TImageFile::setMemoryBudget($gib);
		self::assertSame($gib, TImageFile::getMemoryBudget());
		TImageFile::setMemoryBudget(null);
		self::assertSame(32 * $gib, TImageFile::getMemoryBudget());

		// No limit is no budget: nothing to take half of.
		ini_set('memory_limit', '-1');
		self::assertNull(TImageFile::getMemoryBudget());
		self::assertSame(TImageFile::DefaultMaxParts, TImageFile::getMaxParts());
	}

	public function testABudgetMayBeASizeOrAShareOfTheMemoryLimit()
	{
		$sizes = [
			'1024' => 1024,
			'512K' => 512 << 10,
			'256M' => 256 << 20,
			'1G' => 1 << 30,
			'2g' => 2 << 30,
			' 64 M ' => 64 << 20,
			'0' => 0,
			'99999999999999999999G' => intdiv(PHP_INT_MAX, 1 << 30) << 30,
		];
		foreach ($sizes as $text => $bytes) {
			TImageFile::setMemoryBudget((string) $text);
			self::assertSame($bytes, TImageFile::getMemoryBudget(), "'$text'");
		}
		self::assertSame(intdiv(256 << 20, TImageFile::BudgetBytesPerPart), (function () {
			TImageFile::setMemoryBudget('256M');
			return TImageFile::getMaxParts();
		})());

		// A share is of the limit the process runs under, read when it is asked for.
		ini_set('memory_limit', '16G');
		$gib = 1 << 30;
		foreach (['25%' => 4 * $gib, '12.5%' => 2 * $gib, ' 100 % ' => 16 * $gib, '0%' => 0] as $text => $bytes) {
			TImageFile::setMemoryBudget($text);
			self::assertSame($bytes, TImageFile::getMemoryBudget(), "'$text'");
		}
		TImageFile::setMemoryBudget('25%');
		ini_set('memory_limit', '32G');
		self::assertSame(8 * $gib, TImageFile::getMemoryBudget());
		self::assertSame($gib, TPNG::getMaxInflatedSize());

		// A share of no limit is no budget.
		ini_set('memory_limit', '-1');
		self::assertNull(TImageFile::getMemoryBudget());
		self::assertSame(TImageGraphics::DefaultMaxPixels, TImageGraphics::getMaxPixels());
	}

	public function testABudgetThatIsNotOneIsRefused()
	{
		TImageFile::setMemoryBudget('256M');
		foreach ([-1, '-5M', '150%', '100.5%', '5.5M', '1T', '1GB', 'lots', '', '%'] as $budget) {
			try {
				TImageFile::setMemoryBudget($budget);
				self::fail("'$budget' is not a budget");
			} catch (TInvalidDataValueException $e) {
				self::assertSame('imagefile_budget_invalid', $e->getErrorCode());
				self::assertStringContainsString("'$budget'", $e->getMessage());
			}
			self::assertSame(256 << 20, TImageFile::getMemoryBudget(), 'and the budget is as it was');
		}
	}

	public function testABudgetIsWhatRefusesAFileThatWouldNotFitIt()
	{
		// Three hundred empty chunks are nothing to the default ceiling and too many for a
		// budget of a few hundred kilobytes, which allows two hundred.
		$png = $this->png(str_repeat($this->pngChunk('tEXt'), 300));
		self::assertInstanceOf(TPNG::class, TPNG::fromString($png));

		foreach ([200 * TImageFile::BudgetBytesPerPart, '400K'] as $budget) {
			TImageFile::setMemoryBudget($budget);
			try {
				TPNG::fromString($png);
				self::fail('a file over its budget was opened');
			} catch (TIOException $e) {
				self::assertSame('imagefile_too_many_parts', $e->getErrorCode());
			}
		}
	}

	/** @var string[] The files a test wrote, removed when it ends. */
	private array $_tempFiles = [];

	private function tempFile(string $contents): string
	{
		$path = (string) tempnam(sys_get_temp_dir(), 'ceiling');
		file_put_contents($path, $contents);
		return $this->_tempFiles[] = $path;
	}

	/** @return resource A rewound in-memory resource holding the bytes. */
	private function resource(string $bytes)
	{
		$handle = fopen('php://memory', 'w+b');
		fwrite($handle, $bytes);
		rewind($handle);
		return $handle;
	}

	// ---- the file ceiling: the length of a file read whole ----

	private function assertTooLong(callable $open, string $what): void
	{
		try {
			$open();
			self::fail("$what is over the file ceiling");
		} catch (TIOException $e) {
			self::assertStringContainsString('longer than the', $e->getMessage(), $what);
			self::assertStringContainsString('setMaxFileSize()', $e->getMessage(), $what);
		}
	}

	public function testTheFileCeilingFollowsTheBudget()
	{
		// No memory limit and so no budget: a file may be any length.
		self::assertNull(TImageFile::getMaxFileSize());
		TImageFile::assertFileSize(PHP_INT_MAX);

		// Reading and saving a file peaks at up to 3.8 times its length, so a budget allows
		// a quarter of itself.  (The limit set here is one the suite's own memory fits under
		// however it is run: PHP refuses a limit below what is already in use.)
		ini_set('memory_limit', '16G');
		self::assertSame(4, TImageFile::BudgetBytesPerFileByte);
		self::assertSame(2 << 30, TImageFile::getMaxFileSize());
		TImageFile::setMemoryBudget('100M');
		self::assertSame(25 << 20, TImageFile::getMaxFileSize());
		// Half of PHP's stock 128M is a budget of 64 MiB, and so a file of 16.
		TImageFile::setMemoryBudget(64 << 20);
		self::assertSame(16 << 20, TImageFile::getMaxFileSize());

		// Its own setter moves it alone and outlasts a new budget, until it is handed back.
		TImageFile::setMaxFileSize(1000);
		TImageFile::setMemoryBudget('1G');
		self::assertSame(1000, TImageFile::getMaxFileSize());
		TImageFile::assertFileSize(1000);   // exactly at the ceiling
		$this->assertTooLong(fn () => TImageFile::assertFileSize(1001), 'one byte more');
		TImageFile::setMaxFileSize(null);
		self::assertSame(1 << 28, TImageFile::getMaxFileSize());
	}

	public function testEveryContainerRefusesAFileLongerThanTheFileCeiling(): void
	{
		foreach ($this->filesOfEmptyParts() as $what => [$class, $bytes, $hasLazy]) {
			$path = $this->tempFile($bytes);
			$opens = [
				'from a string' => fn () => $class::fromString($bytes),
				'from a file' => fn () => $class::fromFile($path),
				'from a resource' => fn () => $class::fromStream($this->resource($bytes)),
				'from a stream' => fn () => $class::fromStream(TStream::fromString($bytes)),
				'from a file, whatever it holds' => fn () => TImageFile::fromFile($path),
			];
			foreach ($opens as $how => $open) {
				TImageFile::setMaxFileSize(strlen($bytes));
				self::assertInstanceOf($class, $open(), "$what $how: a file exactly at the ceiling is read");
				TImageFile::setMaxFileSize(strlen($bytes) - 1);
				$this->assertTooLong($open, "$what $how");
			}
			if ($hasLazy) {
				// A lazy read holds the structure and leaves the payloads where they are.
				self::assertInstanceOf($class, $class::fromStreamLazy($this->resource($bytes)), "$what, read lazily");
			}
		}
	}

	public function testAStreamIsReadNoFurtherThanTheFileCeiling(): void
	{
		$long = $this->png(str_repeat($this->pngChunk('tEXt', str_repeat('a', 100)), 20));
		TImageFile::setMaxFileSize(100);

		// One byte past the ceiling is all it takes to know, and all that is read.
		$stream = TStream::fromString($long);
		$this->assertTooLong(fn () => TPNG::fromStream($stream), 'a stream');
		self::assertSame(101, $stream->tell());

		$resource = $this->resource($long);
		$this->assertTooLong(fn () => TPNG::fromStream($resource), 'a resource');
		self::assertSame(101, ftell($resource));

		// With no memory limit there is no ceiling, and a stream or a file is read to its end.
		TImageFile::setMaxFileSize(null);
		self::assertNull(TImageFile::getMaxFileSize());
		self::assertInstanceOf(TPNG::class, TPNG::fromStream(TStream::fromString($long)));
		self::assertInstanceOf(TPNG::class, TPNG::fromFile($this->tempFile($long)));

		// The largest ceiling there is still reads a stream to its end.
		TImageFile::setMaxFileSize(PHP_INT_MAX);
		self::assertInstanceOf(TPNG::class, TPNG::fromStream(TStream::fromString($long)));
		self::assertInstanceOf(TPNG::class, TPNG::fromStream($this->resource($long)));
	}

	public function testTheOtherWholeFileReadersKeepToTheFileCeiling(): void
	{
		$wave = $this->riff('WAVE', $this->chunk('JUNK', str_repeat('a', 64)));
		$tiff = "MM\x00\x2A\x00\x00\x00\x08" . pack('n', 1) . pack('nnNN', 0x0100, 4, 1, 1) . pack('N', 0);
		$wavePath = $this->tempFile($wave);
		$tiffPath = $this->tempFile($tiff);
		$opens = [
			'a RIFF container from a string' => [fn () => TRIFF::fromString($wave), strlen($wave)],
			'a RIFF container from a stream' => [fn () => TRIFF::fromStream(TStream::fromString($wave)), strlen($wave)],
			'a RIFF container from a file' => [fn () => TRIFF::fromFile($wavePath), strlen($wave)],
			'an Exif audio file from a string' => [fn () => TEXIFAudio::fromString($wave), strlen($wave)],
			'an Exif audio file from a stream' => [fn () => TEXIFAudio::fromStream($this->resource($wave)), strlen($wave)],
			'an Exif audio file from a file' => [fn () => TEXIFAudio::fromFile($wavePath), strlen($wave)],
			'the EXIF of a TIFF file' => [fn () => TEXIF::fromTiffFile($tiffPath), strlen($tiff)],
		];
		foreach ($opens as $what => [$open, $length]) {
			TImageFile::setMaxFileSize($length);
			self::assertIsObject($open(), "$what: exactly at the ceiling");
			TImageFile::setMaxFileSize($length - 1);
			$this->assertTooLong($open, $what);
		}

		// A file that cannot be opened is still that, not a matter of its length.
		TImageFile::setMaxFileSize(null);
		foreach ([fn () => TImageFile::fromFile($tiffPath . '.absent'), fn () => TRIFF::fromFile($tiffPath . '.absent'), fn () => TEXIFAudio::fromFile($tiffPath . '.absent'), fn () => TEXIF::fromTiffFile($tiffPath . '.absent')] as $open) {
			try {
				$open();
				self::fail('a file that is not there was read');
			} catch (TIOException $e) {
				self::assertStringContainsString('cannot be read', $e->getMessage());
			}
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
