<?php

use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\Meta\TEXIFAudio;
use Prado\IO\Image\TRIFF;
use Prado\IO\TStream;

class TEXIFAudioTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Builds a small PCM WAVE file, optionally with an Exif attribute list.
	 * @param array<string, string> $exifChunks The exif list sub-chunks.
	 * @param int $samples
	 */
	private function waveBytes(array $exifChunks = [], int $samples = 8000): string
	{
		$channels = 1;
		$rate = 8000;
		$bits = 8;
		$byteRate = $rate * $channels * intdiv($bits, 8);
		$fmt = pack('vvVVvv', 1, $channels, $rate, $byteRate, $channels * intdiv($bits, 8), $bits);
		$audio = str_repeat("\x80", $samples);

		$body = 'WAVE';
		$body .= 'fmt ' . pack('V', strlen($fmt)) . $fmt;
		if ($exifChunks !== []) {
			$list = 'exif';
			foreach ($exifChunks as $id => $payload) {
				$list .= $id . pack('V', strlen($payload)) . $payload;
				if (strlen($payload) & 1) {
					$list .= "\0";
				}
			}
			$body .= 'LIST' . pack('V', strlen($list)) . $list;
		}
		$body .= 'data' . pack('V', strlen($audio)) . $audio;
		return 'RIFF' . pack('V', strlen($body)) . $body;
	}

	public function testReadsSpecAttributeChunks()
	{
		$bytes = $this->waveBytes([
			'ever' => '0300',
			'erel' => "DSC00001.JPG\0",
			'etim' => "10:05:10.130\0",
			'ecor' => "Digital Still Camera Corporation\0",
			'emdl' => "DSCamera1000\0",
			'emnt' => "\x01\x02\x03maker",
			'eucm' => "ASCII\0\0\0Voice memo",
		]);

		self::assertTrue(TEXIFAudio::isWave($bytes));
		self::assertTrue(TEXIFAudio::isExifAudio($bytes));

		$audio = TEXIFAudio::fromString($bytes);
		self::assertTrue($audio->getHasExifList());
		self::assertSame('0300', $audio->getVersion());
		self::assertSame('DSC00001.JPG', $audio->getRelatedImage());
		self::assertSame('10:05:10.130', $audio->getRecordingTime());
		self::assertSame('Digital Still Camera Corporation', $audio->getManufacturer());
		self::assertSame('DSCamera1000', $audio->getModel());
		self::assertSame("\x01\x02\x03maker", $audio->getMakerNote());
		self::assertSame('Voice memo', $audio->getUserComment());
		self::assertSame('ASCII', $audio->getUserCommentCharset());
		self::assertCount(7, $audio->getAttributes());
	}

	public function testWaveFormatAndDuration()
	{
		$audio = TEXIFAudio::fromString($this->waveBytes(['ever' => '0300'], 16000));
		$format = $audio->getFormat();
		self::assertSame(1, $format['format']);
		self::assertSame(1, $format['channels']);
		self::assertSame(8000, $format['sampleRate']);
		self::assertSame(8, $format['bitsPerSample']);
		self::assertEqualsWithDelta(2.0, $audio->getDurationSeconds(), 1e-9);
	}

	public function testEditPreservesAudioDataAndOtherChunks()
	{
		$original = $this->waveBytes(['ever' => '0300', 'erel' => "DSC00001.JPG\0"]);
		$audio = TEXIFAudio::fromString($original);
		$audio->setRelatedImage('DSC00042.JPG');
		$audio->setManufacturer('PradoCam Corp');
		$audio->setUserComment('Edited memo');

		$reparsed = TEXIFAudio::fromString($audio->toBinary());
		self::assertSame('DSC00042.JPG', $reparsed->getRelatedImage());
		self::assertSame('PradoCam Corp', $reparsed->getManufacturer());
		self::assertSame('Edited memo', $reparsed->getUserComment());
		self::assertSame('0300', $reparsed->getVersion());

		// The fmt and data chunks came through untouched.
		$before = TRIFF::fromString($original);
		$after = TRIFF::fromString($audio->toBinary());
		self::assertSame(bin2hex($before->getChunk('data')->getData()), bin2hex($after->getChunk('data')->getData()));
		self::assertSame(bin2hex($before->getChunk('fmt ')->getData()), bin2hex($after->getChunk('fmt ')->getData()));
	}

	public function testAddsExifListToPlainWave()
	{
		$plain = $this->waveBytes();
		self::assertTrue(TEXIFAudio::isWave($plain));
		self::assertFalse(TEXIFAudio::isExifAudio($plain));

		$audio = TEXIFAudio::fromString($plain);
		$audio->setVersion('0300');
		$audio->setRelatedImage('DSC00007.JPG');
		$audio->setRecordingTime(new DateTimeImmutable('2026-07-28 14:03:09.250'));

		$bytes = $audio->toBinary();
		self::assertTrue(TEXIFAudio::isExifAudio($bytes));
		$reparsed = TEXIFAudio::fromString($bytes);
		self::assertSame('0300', $reparsed->getVersion());
		self::assertSame('DSC00007.JPG', $reparsed->getRelatedImage());
		self::assertSame('14:03:09.250', $reparsed->getRecordingTime());
		// Still a valid plain WAVE for any other reader.
		self::assertNotNull(TRIFF::fromString($bytes)->getChunk('data'));
	}

	public function testUnicodeUserCommentAndRemoval()
	{
		$audio = TEXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		$audio->setUserComment('メモ 音声');
		self::assertSame('UNICODE', $audio->getUserCommentCharset());

		$reparsed = TEXIFAudio::fromString($audio->toBinary());
		self::assertSame('メモ 音声', $reparsed->getUserComment());

		$reparsed->setUserComment(null);
		self::assertNull($reparsed->getUserComment());
		self::assertNull(TEXIFAudio::fromString($reparsed->toBinary())->getUserComment());
	}

	public function testOddLengthChunksPadCorrectly()
	{
		$audio = TEXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		$audio->setModel('ODD');                       // 3 + NUL = 4 (even)
		$audio->setManufacturer('FIVEC');              // 5 + NUL = 6 (even)
		$audio->setMakerNote("\x01\x02\x03");          // 3 bytes: needs a pad byte
		$audio->setRelatedImage('A.JPG');              // 5 + NUL = 6

		$reparsed = TEXIFAudio::fromString($audio->toBinary());
		self::assertSame('ODD', $reparsed->getModel());
		self::assertSame('FIVEC', $reparsed->getManufacturer());
		self::assertSame("\x01\x02\x03", $reparsed->getMakerNote());
		self::assertSame('A.JPG', $reparsed->getRelatedImage());
	}

	public function testImageAudioLinkage()
	{
		// The image half of the link is the EXIF RelatedSoundFile tag.
		$exif = new TEXIF();
		$exif->setValueByName('RelatedSoundFile', 'SND00001.WAV');
		$reparsed = TEXIF::fromSegment($exif->toBinary());
		self::assertSame('SND00001.WAV', $reparsed->getValueByName('RelatedSoundFile'));

		$audio = TEXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		$audio->setRelatedImage('DSC00001.JPG');
		self::assertSame('DSC00001.JPG', TEXIFAudio::fromString($audio->toBinary())->getRelatedImage());
	}

	public function testStreamAndFileIo()
	{
		$audio = TEXIFAudio::fromString($this->waveBytes(['ever' => '0300', 'ecor' => "PradoCam\0"]));

		$stream = TStream::fromString('');
		$audio->writeTo($stream);
		$stream->rewind();
		self::assertSame('PradoCam', TEXIFAudio::fromStream($stream)->getManufacturer());

		$path = tempnam(sys_get_temp_dir(), 'exifaudio');
		try {
			self::assertGreaterThan(0, $audio->save($path));
			self::assertSame('PradoCam', TEXIFAudio::fromFile($path)->getManufacturer());
		} finally {
			@unlink($path);
		}

		self::expectException(TIOException::class);
		TEXIFAudio::fromFile('/nonexistent/nope.wav');
	}

	public function testBlankAudioAndRawAttributeAccess()
	{
		// A blank instance builds its own WAVE container.
		$audio = new TEXIFAudio();
		self::assertSame('WAVE', $audio->getRiff()->getFormType());
		self::assertFalse($audio->getHasExifList());

		$audio->setVersion('0300');
		$audio->setAttribute('exlt', "\x09custom");
		self::assertSame("\x09custom", $audio->getAttribute('exlt'));
		self::assertNull($audio->getAttribute('none'));

		$reparsed = TEXIFAudio::fromString($audio->toBinary());
		self::assertTrue($reparsed->getHasExifList());
		self::assertSame("\x09custom", $reparsed->getAttribute('exlt'));
		self::assertSame('0300', $reparsed->getVersion());
	}

	public function testAbsentTextAttributesReadAsNull()
	{
		// A WAVE carrying only the mandatory version has none of the text chunks, and
		// none of them is invented as an empty string.
		$audio = TEXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		self::assertNull($audio->getRelatedImage());
		self::assertNull($audio->getRecordingTime());
		self::assertNull($audio->getManufacturer());
		self::assertNull($audio->getModel());
		self::assertNull($audio->getUserComment());
		self::assertNull($audio->getUserCommentCharset());
		self::assertCount(1, $audio->getAttributes());
	}

	public function testRemovingATextAttributeDropsItsChunk()
	{
		$audio = TEXIFAudio::fromString($this->waveBytes([
			'ever' => '0300',
			'erel' => "DSC00001.JPG\0",
			'etim' => "10:05:10.130\0",
			'ecor' => "PradoCam\0",
			'emdl' => "PC-2000\0",
		]));
		$audio->setRelatedImage(null);
		$audio->setRecordingTime(null);
		$audio->setManufacturer(null);
		$audio->setModel(null);

		self::assertNull($audio->getAttribute(TEXIFAudio::RelatedImageChunk));
		self::assertSame(['ever'], array_keys($audio->getAttributes()));

		$reparsed = TEXIFAudio::fromString($audio->toBinary());
		self::assertTrue($reparsed->getHasExifList());
		self::assertSame('0300', $reparsed->getVersion());
		self::assertNull($reparsed->getRelatedImage());
		self::assertNull($reparsed->getRecordingTime());
		self::assertNull($reparsed->getManufacturer());
		self::assertNull($reparsed->getModel());
	}

	public function testDroppingEveryAttributeRemovesTheExifList()
	{
		$audio = TEXIFAudio::fromString($this->waveBytes(['ever' => '0300', 'erel' => "DSC00001.JPG\0"]));
		self::assertTrue($audio->getHasExifList());

		foreach (array_keys($audio->getAttributes()) as $id) {
			$audio->setAttribute($id, null);
		}
		self::assertSame([], $audio->getAttributes());

		$bytes = $audio->toBinary();
		self::assertTrue(TEXIFAudio::isWave($bytes));
		self::assertFalse(TEXIFAudio::isExifAudio($bytes));
		self::assertNull(TRIFF::fromString($bytes)->getChunk('LIST'));
		// The audio itself came through untouched.
		self::assertSame(8000, strlen(TRIFF::fromString($bytes)->getChunk('data')->getData()));

		// A WAVE that never had a list composes unchanged too.
		$plain = TEXIFAudio::fromString($this->waveBytes());
		self::assertFalse($plain->getHasExifList());
		self::assertNull(TRIFF::fromString($plain->toBinary())->getChunk('LIST'));
	}

	public function testWaveWithoutFormatOrDataChunks()
	{
		$body = 'WAVE' . 'data' . pack('V', 4) . "\x01\x02\x03\x04";
		$audio = TEXIFAudio::fromString('RIFF' . pack('V', strlen($body)) . $body);
		self::assertNull($audio->getFormat());
		self::assertNull($audio->getDurationSeconds());

		// A format chunk too short to hold the sixteen mandatory bytes is not read.
		$short = 'WAVE' . 'fmt ' . pack('V', 8) . pack('vvV', 1, 1, 8000);
		$truncated = TEXIFAudio::fromString('RIFF' . pack('V', strlen($short)) . $short);
		self::assertNull($truncated->getFormat());
		self::assertNull($truncated->getDurationSeconds());
	}

	public function testIsExifAudioRejectsNonWaveBytes()
	{
		self::assertFalse(TEXIFAudio::isExifAudio('not a riff container at all'));
		self::assertFalse(TEXIFAudio::isExifAudio('RIFF' . pack('V', 4) . 'AVI '));
	}

	public function testSaveToAnUnwritablePath()
	{
		$audio = TEXIFAudio::fromString($this->waveBytes(['ever' => '0300']));
		try {
			$audio->save('/no-such-directory-for-prado/SND00001.WAV');
			self::fail('save() accepted an unwritable path');
		} catch (TIOException $e) {
			self::assertSame('imagefile_unwritable', $e->getErrorCode());
		}
	}

	public function testRejectsNonWaveRiff()
	{
		$riff = 'RIFF' . pack('V', 4) . 'AVI ';
		self::assertFalse(TEXIFAudio::isWave($riff));
		self::expectException(TIOException::class);
		TEXIFAudio::fromString($riff);
	}
}
