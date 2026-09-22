<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Image\TImageChunk;
use Prado\IO\Image\TRIFF;
use Prado\IO\Image\TRIFFChunkType;
use Prado\IO\Image\TRIFFList;
use Prado\IO\TStream;

/**
 * The three variants of the RIFF header: `RIFX`, whose every size is big-endian, and `RF64`
 * and `BW64`, which carry sizes past four gigabytes in a leading `ds64` chunk.  Each is read
 * and written back in the form it was found, and an edit keeps `ds64` in step with what was
 * actually written.
 */
class TRIFFVariantsTest extends PHPUnit\Framework\TestCase
{
	private function chunk(string $id, string $payload, bool $big = false, ?int $stored = null): string
	{
		return $id . pack($big ? 'N' : 'V', $stored ?? strlen($payload)) . $payload . ((strlen($payload) & 1) ? "\0" : '');
	}

	private function container(string $signature, string $formType, string ...$chunks): string
	{
		$big = $signature === TRIFFChunkType::Rifx;
		$body = $formType . implode('', $chunks);
		$size = ($signature === TRIFFChunkType::Riff || $big) ? strlen($body) : TRIFFChunkType::Size64Sentinel;
		return $signature . pack($big ? 'N' : 'V', $size) . $body;
	}

	/**
	 * A 64-bit container whose `ds64` states the true container size, as a real writer's does.
	 * The `ds64` chunk's own length does not depend on its contents, so the size can be
	 * computed from a first pass and patched in.
	 * @param string $signature
	 * @param string $formType
	 * @param string $ds64
	 * @param string[] $chunks
	 */
	private function rf64(string $signature, string $formType, string $ds64, string ...$chunks): string
	{
		$body = $formType . $this->chunk(TRIFFChunkType::DataSize64, $ds64) . implode('', $chunks);
		$ds64 = substr_replace($ds64, pack('P', strlen($body)), 0, 8);
		return $signature . pack('V', TRIFFChunkType::Size64Sentinel)
			. $formType . $this->chunk(TRIFFChunkType::DataSize64, $ds64) . implode('', $chunks);
	}

	/** A `ds64` payload: the container size, the `data` size, the sample count, then a table. */
	private function ds64(int $riffSize, int $dataSize, int $samples = 0, array $table = []): string
	{
		$payload = pack('P', $riffSize) . pack('P', $dataSize) . pack('P', $samples) . pack('V', count($table));
		foreach ($table as $id => $size) {
			$payload .= $id . pack('P', $size);
		}
		return $payload;
	}

	//
	// ─── RIFX: the same grammar, big-endian ──────────────────────────────────
	//

	public function testABigEndianContainerIsReadAndWrittenAsOne(): void
	{
		$bytes = $this->container(
			TRIFFChunkType::Rifx,
			'WAVE',
			$this->chunk('fmt ', str_repeat("\x01", 16), true),
			$this->chunk('data', str_repeat("\x7f", 10), true),
		);
		$riff = TRIFF::fromString($bytes);

		self::assertSame(TRIFFChunkType::Rifx, $riff->getSignature());
		self::assertTrue($riff->getIsBigEndian());
		self::assertFalse($riff->getIsSize64());
		self::assertSame(16, strlen((string) $riff->getChunk('fmt ')?->getData()));
		self::assertSame(str_repeat("\x7f", 10), $riff->getChunk('data')?->getData());
		self::assertSame($bytes, $riff->toBinary());
	}

	public function testABigEndianEditKeepsTheByteOrder(): void
	{
		$bytes = $this->container(TRIFFChunkType::Rifx, 'WAVE', $this->chunk('data', 'abcd', true));
		$riff = TRIFF::fromString($bytes);
		$riff->addChunk(new TImageChunk('note', 0, 0, 'a note'));
		$out = $riff->toBinary();

		// The container size and both chunk sizes must be big-endian.
		self::assertSame(strlen($out) - 8, (int) unpack('N', substr($out, 4, 4))[1]);
		$round = TRIFF::fromString($out);
		self::assertSame('a note', $round->getChunk('note')?->getData());
		self::assertSame('abcd', $round->getChunk('data')?->getData());
	}

	public function testNestedListsInheritTheByteOrder(): void
	{
		$inner = $this->chunk('IART', "Someone\0", true);
		$bytes = $this->container(
			TRIFFChunkType::Rifx,
			'WAVE',
			$this->chunk(TRIFFChunkType::RiffList, TRIFFChunkType::InfoList . $inner, true),
		);
		$riff = TRIFF::fromString($bytes);
		$list = $riff->getList(TRIFFChunkType::InfoList);

		self::assertInstanceOf(TRIFFList::class, $list);
		self::assertTrue($list->getIsBigEndian(), 'a list reads its children the way the container does');
		self::assertSame("Someone\0", $list->getChunk('IART')?->getData());
		self::assertSame($bytes, $riff->toBinary());

		$list->setChunk(new TImageChunk('ICMT', 0, 0, "Edited\0"));
		self::assertSame("Edited\0", TRIFF::fromString($riff->toBinary())->getList(TRIFFChunkType::InfoList)?->getChunk('ICMT')?->getData());
	}

	public function testTheByteOrderOfAListCanBeSet(): void
	{
		$list = new TRIFFList(TRIFFChunkType::RiffList, 4, 0, TRIFFChunkType::InfoList);
		self::assertFalse($list->getIsBigEndian(), 'a list authored from nothing is little-endian');
		$list->setIsBigEndian(true);
		self::assertTrue($list->getIsBigEndian());
	}

	//
	// ─── RF64 and BW64: the sizes live in ds64 ───────────────────────────────
	//

	/** @dataProvider size64Provider */
	public function testASixtyFourBitContainerTakesItsSizesFromDs64(string $signature): void
	{
		$data = str_repeat("\x7f", 10);
		$ds64 = $this->ds64(0, strlen($data), 5);
		$bytes = $this->container(
			$signature,
			'WAVE',
			$this->chunk(TRIFFChunkType::DataSize64, $ds64),
			$this->chunk('fmt ', str_repeat("\x01", 16)),
			$this->chunk('data', $data, false, TRIFFChunkType::Size64Sentinel),
		);
		$riff = TRIFF::fromString($bytes);

		self::assertSame($signature, $riff->getSignature());
		self::assertTrue($riff->getIsSize64());
		self::assertFalse($riff->getIsBigEndian());
		self::assertSame($data, $riff->getChunk('data')?->getData(), 'the sentinel resolved through ds64');
		self::assertSame(16, strlen((string) $riff->getChunk('fmt ')?->getData()), 'an ordinary size is still its own');
	}

	public static function size64Provider(): array
	{
		return [TRIFFChunkType::Rf64 => [TRIFFChunkType::Rf64], TRIFFChunkType::Bw64 => [TRIFFChunkType::Bw64]];
	}

	public function testTheSentinelAndDs64AreBothRewritten(): void
	{
		$data = str_repeat("\x7f", 10);
		$bytes = $this->container(
			TRIFFChunkType::Rf64,
			'WAVE',
			$this->chunk(TRIFFChunkType::DataSize64, $this->ds64(0, strlen($data), 5)),
			$this->chunk('data', $data, false, TRIFFChunkType::Size64Sentinel),
		);
		$riff = TRIFF::fromString($bytes);
		$riff->addChunk(new TImageChunk('note', 0, 0, str_repeat('Z', 40)));
		$out = $riff->toBinary();

		self::assertSame(TRIFFChunkType::Size64Sentinel, (int) unpack('V', substr($out, 4, 4))[1], 'the container keeps the sentinel');
		$round = TRIFF::fromString($out);
		$ds64 = (string) $round->getChunk(TRIFFChunkType::DataSize64)?->getData();
		self::assertSame(strlen($out) - 8, (int) unpack('P', substr($ds64, 0, 8))[1], 'ds64 states the new container size');
		self::assertSame(10, (int) unpack('P', substr($ds64, 8, 8))[1], 'and the unchanged data size');
		self::assertSame(5, (int) unpack('P', substr($ds64, 16, 8))[1], 'the sample count is left alone');
		self::assertSame($data, $round->getChunk('data')?->getData());
		self::assertSame(str_repeat('Z', 40), $round->getChunk('note')?->getData());
	}

	public function testATableEntryGivesAnyChunkASixtyFourBitSize(): void
	{
		$big = str_repeat('B', 30);
		$bytes = $this->container(
			TRIFFChunkType::Rf64,
			'WAVE',
			$this->chunk(TRIFFChunkType::DataSize64, $this->ds64(0, 0, 0, ['huge' => strlen($big)])),
			$this->chunk('huge', $big, false, TRIFFChunkType::Size64Sentinel),
		);
		$riff = TRIFF::fromString($bytes);
		self::assertSame($big, $riff->getChunk('huge')?->getData());

		// The table entry is rewritten with whatever the chunk now holds.
		$riff->getChunk('huge')?->setData(str_repeat('C', 50));
		$out = $riff->toBinary();
		$ds64 = (string) TRIFF::fromString($out)->getChunk(TRIFFChunkType::DataSize64)?->getData();
		self::assertSame('huge', substr($ds64, 28, 4));
		self::assertSame(50, (int) unpack('P', substr($ds64, 32, 8))[1]);
		self::assertSame(str_repeat('C', 50), TRIFF::fromString($out)->getChunk('huge')?->getData());
	}

	public function testAChunkWithNoSixtyFourBitEntryKeepsItsOwnSize(): void
	{
		$bytes = $this->rf64(
			TRIFFChunkType::Rf64,
			'WAVE',
			$this->ds64(0, 4),
			$this->chunk('data', 'abcd', false, TRIFFChunkType::Size64Sentinel),
			$this->chunk('fmt ', str_repeat("\x01", 16)),
		);
		$out = TRIFF::fromString($bytes)->toBinary();
		self::assertSame(16, (int) unpack('V', substr($out, (int) strpos($out, 'fmt ') + 4, 4))[1]);
		self::assertSame($bytes, $out, 'and the whole container is byte-faithful');
	}

	public function testWithoutAUsableDs64TheRealSizeIsWrittenInstead(): void
	{
		// A 64-bit signature whose first chunk is not ds64: nothing can resolve a sentinel, so
		// the rewrite states the size it actually wrote rather than keeping an unreadable one.
		$bytes = $this->container(TRIFFChunkType::Rf64, 'WAVE', $this->chunk('fmt ', 'abcd'));
		$riff = TRIFF::fromString($bytes);
		self::assertSame('abcd', $riff->getChunk('fmt ')?->getData());
		$out = $riff->toBinary();
		self::assertSame(strlen($out) - 8, (int) unpack('V', substr($out, 4, 4))[1]);
		self::assertSame(TRIFFChunkType::Rf64, $riff->getSignature(), 'the signature is still kept');

		// A ds64 too short to read is carried through untouched, and cannot size anything.
		$short = $this->container(TRIFFChunkType::Rf64, 'WAVE', $this->chunk(TRIFFChunkType::DataSize64, 'tiny'));
		$riff = TRIFF::fromString($short);
		self::assertSame('tiny', $riff->getChunk(TRIFFChunkType::DataSize64)?->getData());
		self::assertSame(strlen($riff->toBinary()) - 8, (int) unpack('V', substr($riff->toBinary(), 4, 4))[1]);
	}

	public function testATruncatedTableStopsTheRead(): void
	{
		// A table claiming two entries but holding one.
		$payload = pack('P', 0) . pack('P', 0) . pack('P', 0) . pack('V', 2) . 'huge' . pack('P', 30);
		$bytes = $this->rf64(
			TRIFFChunkType::Rf64,
			'WAVE',
			$payload,
			$this->chunk('huge', str_repeat('B', 30), false, TRIFFChunkType::Size64Sentinel),
		);
		$riff = TRIFF::fromString($bytes);
		self::assertSame(str_repeat('B', 30), $riff->getChunk('huge')?->getData());
		self::assertSame($bytes, $riff->toBinary());
	}

	//
	// ─── The signature itself ────────────────────────────────────────────────
	//

	public function testAnOrdinaryContainerIsUnchanged(): void
	{
		$bytes = $this->container(TRIFFChunkType::Riff, 'WEBP', $this->chunk('VP8 ', 'pixels'));
		$riff = TRIFF::fromString($bytes);
		self::assertSame(TRIFFChunkType::Riff, $riff->getSignature());
		self::assertFalse($riff->getIsBigEndian());
		self::assertFalse($riff->getIsSize64());
		self::assertSame($bytes, $riff->toBinary());
	}

	public function testTheSignatureIsWritable(): void
	{
		$riff = TRIFF::fromString($this->container(TRIFFChunkType::Riff, 'WAVE', $this->chunk('data', 'abcd')));
		$riff->setSignature(TRIFFChunkType::Rifx);
		$out = $riff->toBinary();
		self::assertStringStartsWith(TRIFFChunkType::Rifx, $out);
		self::assertSame('abcd', TRIFF::fromString($out)->getChunk('data')?->getData(), 'and the sizes follow');
	}

	public function testAnUnknownSignatureIsRefused(): void
	{
		$riff = new TRIFF();
		$this->expectException(TIOException::class);
		$riff->setSignature('JUNK');
	}

	public function testSomethingThatIsNoVariantIsRefused(): void
	{
		$this->expectException(TIOException::class);
		TRIFF::fromString('FORM' . pack('V', 4) . 'AIFF');
	}

	//
	// ─── Streaming ───────────────────────────────────────────────────────────
	//

	public function testAVariantStreamsOutAsItself(): void
	{
		foreach ([TRIFFChunkType::Rifx, TRIFFChunkType::Riff] as $signature) {
			$big = $signature === TRIFFChunkType::Rifx;
			$bytes = $this->container($signature, 'WAVE', $this->chunk('data', str_repeat('d', 20), $big));
			$riff = TRIFF::fromStreamLazy(TStream::fromString($bytes), ['data']);

			self::assertSame($signature, $riff->getSignature());
			self::assertTrue($riff->getChunk('data')?->getIsDeferred());
			$target = TStream::fromString('');
			$riff->streamTo($target);
			$target->seek(0);
			self::assertSame($bytes, $target->getContents(), $signature . ' streams back unchanged');
		}
	}

	public function testASixtyFourBitContainerStreamsWithItsSizesResolved(): void
	{
		$data = str_repeat('d', 20);
		$bytes = $this->container(
			TRIFFChunkType::Rf64,
			'WAVE',
			$this->chunk(TRIFFChunkType::DataSize64, $this->ds64(0, strlen($data), 7)),
			$this->chunk('data', $data, false, TRIFFChunkType::Size64Sentinel),
		);
		$riff = TRIFF::fromStreamLazy(TStream::fromString($bytes), ['data']);
		self::assertSame(strlen($data), $riff->getChunk('data')?->getSize(), 'the deferred chunk knows its real size');

		$target = TStream::fromString('');
		$riff->streamTo($target);
		$target->seek(0);
		$out = $target->getContents();
		self::assertSame(TRIFFChunkType::Size64Sentinel, (int) unpack('V', substr($out, 4, 4))[1]);
		self::assertSame($data, TRIFF::fromString($out)->getChunk('data')?->getData());
	}
}
