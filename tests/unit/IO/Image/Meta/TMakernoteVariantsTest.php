<?php

use Prado\IO\Image\Meta\Makernote\TCanonMakernote;
use Prado\IO\Image\Meta\Makernote\TKonicaMinoltaMakernote;
use Prado\IO\Image\Meta\Makernote\TMakernote;
use Prado\IO\Image\TIFF\TTIFFDataType;

/**
 * Exercises every maker variant the registry knows: header signatures, forced byte
 * orders, note-relative offsets, missing next-IFD pointers, and the nested sub-IFD.
 */
class TMakernoteVariantsTest extends PHPUnit\Framework\TestCase
{
	/**
	 * Packs an IFD: entries as [tag, type, count, valueFieldBytes(4)].
	 * @param array $entries
	 * @param bool $bigEndian
	 * @param bool $nextPointer
	 */
	private function packIfd(array $entries, bool $bigEndian, bool $nextPointer = true): string
	{
		$n = $bigEndian ? 'n' : 'v';
		$out = pack($n, count($entries));
		foreach ($entries as [$tag, $type, $count, $field]) {
			$out .= pack($n, $tag) . pack($n, $type) . pack($bigEndian ? 'N' : 'V', $count) . str_pad($field, 4, "\0");
		}
		return $nextPointer ? $out . "\0\0\0\0" : $out;
	}

	public function testOlympusEpsonAgfaSharedGroup()
	{
		// Same tag group through three different vendor headers; inline-only IFDs.
		$ifd = $this->packIfd([[0x0204, TTIFFDataType::URational, 1, '']], true);   // needs offset: use inline short instead
		$ifd = $this->packIfd([[0x0201, TTIFFDataType::UShort, 1, pack('n', 2)]], true);   // Quality
		$cases = [
			['OLYMPUS OPTICAL CO.,LTD', "OLYMP\x00\x01\x00" . $ifd, 'Olympus', 'Olympus'],
			['SEIKO EPSON CORP.', "EPSON\x00\x01\x00" . $ifd, 'Epson', 'Olympus'],
			['AGFA', "AGFA \x00\x01\x00" . $ifd, 'Agfa', 'Olympus'],
		];
		foreach ($cases as [$make, $note, $maker, $group]) {
			$parsed = TMakernote::fromNote($note, $make);
			self::assertNotNull($parsed, $maker);
			self::assertSame($maker, $parsed->getMaker());
			self::assertSame($group, $parsed->getTagGroup());
			self::assertTrue($parsed->getIsDecoded());
			self::assertSame(2, $parsed->getIfd()->getTagValue(0x0201));
			self::assertNotNull($parsed->getTagText(0x0201));
		}
	}

	public function testKyoceraLocalOffsetsNoNextPointer()
	{
		// Kyocera: 22-byte header, IFD with note-relative value offsets, no next pointer.
		$header = 'KYOCERA            ' . "\x00\x00\x00";
		$text = "SerialXYZ\0";
		$ifdStart = 22;
		$dataPos = $ifdStart + 2 + 12;   // note-relative: count + one entry, no next pointer
		$ifd = $this->packIfd(
			[[0x0001, TTIFFDataType::Ascii, strlen($text), pack('N', $dataPos)]],
			true,
			false,
		);
		$parsed = TMakernote::fromNote($header . $ifd . $text, 'KYOCERA');
		self::assertNotNull($parsed);
		self::assertSame('Kyocera', $parsed->getMaker());
		self::assertSame('SerialXYZ', $parsed->getIfd()->getTag(0x0001)->getValue());

		self::assertNotNull(TMakernote::fromNote($header . $ifd . $text, 'CONTAX'));
	}

	public function testPentaxBothTypes()
	{
		$ifd = $this->packIfd([[0x0008, TTIFFDataType::UShort, 1, pack('n', 1)]], true);
		$type2 = TMakernote::fromNote("AOC\x00\x4D\x4D" . $ifd, 'PENTAX Corporation');
		self::assertSame('Pentax Type 2', $type2->getVariant());
		self::assertSame('Casio Type 2', $type2->getTagGroup());
		self::assertSame(1, $type2->getIfd()->getTagValue(0x0008));

		$type1 = TMakernote::fromNote($ifd, 'Asahi Optical');
		self::assertSame('Pentax Type 1', $type1->getVariant());
		self::assertSame('Pentax', $type1->getTagGroup());
	}

	public function testPanasonicWithAndWithoutIfd()
	{
		$ifd = $this->packIfd([[0x0001, TTIFFDataType::UShort, 1, pack('n', 2)]], true, false);
		$parsed = TMakernote::fromNote("Panasonic\x00\x00\x00" . $ifd, 'Panasonic DMC');
		self::assertSame('Panasonic', $parsed->getMaker());
		self::assertSame(2, $parsed->getIfd()->getTagValue(0x0001));
		self::assertSame('2', $parsed->getTagText(0x0001));   // Quality Mode is Numeric

		$empty = TMakernote::fromNote('MKED', 'Panasonic DMC');
		self::assertNotNull($empty);
		self::assertSame('Panasonic Empty Makernote', $empty->getVariant());
		self::assertFalse($empty->getIsDecoded());
	}

	public function testNikonType1()
	{
		$ifd = $this->packIfd([[0x0003, TTIFFDataType::UShort, 1, pack('n', 1)]], true);
		$parsed = TMakernote::fromNote("Nikon\x00\x01\x00" . $ifd, 'NIKON');
		self::assertSame('Nikon Type 1', $parsed->getVariant());
		self::assertSame('VGA (640x480) Basic', $parsed->getTagText(0x0003));
	}

	public function testCasioType1HeaderlessForcedBigEndian()
	{
		// Casio Type 1: no header, forced MM even inside a little-endian EXIF.
		$ifd = $this->packIfd([[0x0001, TTIFFDataType::UShort, 1, pack('n', 1)]], true);
		$parsed = TMakernote::fromNote($ifd, 'CASIO', '', 0, false);
		self::assertSame('Casio Type 1', $parsed->getVariant());
		self::assertSame('Single Shutter', $parsed->getTagText(0x0001));
	}

	public function testRicohIfdFormWithNestedCameraInfo()
	{
		// Ricoh: 'Ricoh' header, MM, IFD at 8; tag 0x2001 points at a signed camera-info
		// block holding its own IFD with local offsets and no next pointer.
		$camInfo = '[Ricoh Camera Info]' . "\x00"
			. $this->packIfd([[0x0001, TTIFFDataType::UShort, 1, pack('n', 7)]], true, false);
		$notePrefix = "Ricoh\x00\x00\x00";
		$ifdStart = 8;
		$blockPos = $ifdStart + 2 + 12 + 4;   // after count + one entry + next pointer
		$ifd = $this->packIfd(
			[[0x2001, TTIFFDataType::Undefined, strlen($camInfo), pack('N', $blockPos)]],
			true,
		);
		$note = $notePrefix . $ifd . $camInfo;
		$parsed = TMakernote::fromNote($note, 'RICOH', $note, 0, true);
		self::assertNotNull($parsed);
		self::assertSame('Ricoh', $parsed->getVariant());
		self::assertArrayHasKey('RicohSubIFD', $parsed->getSubIfds());
		self::assertSame(7, $parsed->getSubIfds()['RicohSubIFD']->getTagValue(0x0001));
	}

	public function testMinoltaLegacySignaturesRecognizedUndecoded()
	{
		foreach (['KC', '+M+M+M+M', 'MINOL'] as $signature) {
			$parsed = TMakernote::fromNote($signature . str_repeat("\x00", 16), 'KONICA MINOLTA');
			self::assertNotNull($parsed, $signature);
			self::assertFalse($parsed->getIsDecoded(), $signature);
			self::assertStringContainsString($signature, $parsed->getVariant());
		}
	}

	public function testMinoltaUndefinedBytesSettingsBranch()
	{
		// The camera-settings block stored as Undefined bytes (big-endian longs).
		$settings = array_fill(0, 6, 0);
		$settings[2] = 3;   // Exposure Mode: M
		$block = pack('N*', ...$settings);
		$ifd = $this->packIfd([[0x0001, TTIFFDataType::Undefined, strlen($block), '']], true);
		// Inline impossible (24 bytes): rebuild with offset.
		$dataPos = 2 + 12 + 4;
		$ifd = $this->packIfd([[0x0001, TTIFFDataType::Undefined, strlen($block), pack('N', $dataPos)]], true);
		$note = $ifd . $block;
		$parsed = TMakernote::fromNote($note, 'MINOLTA', $note, 0, true);
		self::assertInstanceOf(TKonicaMinoltaMakernote::class, $parsed);
		self::assertSame('M', $parsed->getCameraSettings()['Exposure Mode']);
	}

	public function testCanonCustomFunctionsDecode()
	{
		// Custom functions: element 0 is the byte count, then (function << 8) | value.
		$values = [8, (0x01 << 8) | 1, (0x02 << 8) | 2, (0x63 << 8) | 5];
		$dataPos = 2 + 12 + 4;
		$ifd = $this->packIfd(
			[[TCanonMakernote::CustomFunctionsTag, TTIFFDataType::UShort, count($values), pack('N', $dataPos)]],
			true,
		);
		$note = $ifd . pack('n*', ...$values);
		$parsed = TMakernote::fromNote($note, 'Canon', $note, 0, true);
		self::assertInstanceOf(TCanonMakernote::class, $parsed);
		$functions = $parsed->getCustomFunctions();
		self::assertSame('On', $functions['Long Exposure Noise Reduction']);
		self::assertArrayHasKey('Custom Function 99', $functions);
		self::assertSame('5', $functions['Custom Function 99']);
	}

	public function testRicohWithoutTheCameraInfoBlock()
	{
		// The Ricoh variant declares a nested sub-IFD, but a note that has no 0x2001
		// tag simply has no sub-IFD; the rest of the note still decodes.
		$ifd = $this->packIfd([[0x0002, TTIFFDataType::UShort, 1, pack('n', 5)]], true);
		$note = "Ricoh\x00\x00\x00" . $ifd;
		$parsed = TMakernote::fromNote($note, 'RICOH', $note, 0, true);
		self::assertSame('Ricoh', $parsed->getVariant());
		self::assertSame([], $parsed->getSubIfds());
		self::assertSame(5, $parsed->getIfd()->getTagValue(0x0002));
	}

	public function testFujifilmNoteTruncatedBeforeItsIfdPointer()
	{
		// Fujifilm reads the IFD offset from bytes 8..11 of the note; a note that stops
		// at the signature is still recognized as Fujifilm but decodes to nothing.
		// The truncated unpack() raises a PHP warning the reader tolerates.
		set_error_handler(fn () => true, E_WARNING);
		try {
			$parsed = TMakernote::fromNote('FUJIFILM', 'FUJIFILM FinePix');
		} finally {
			restore_error_handler();
		}
		self::assertNotNull($parsed);
		self::assertSame('Fujifilm', $parsed->getVariant());
		self::assertFalse($parsed->getIsDecoded());
		self::assertNull($parsed->getIfd());
	}

	public function testCanonSettingsBlocksBeyondTheirTables()
	{
		// Camera Settings 1 with the whole 35-word block: the plain numeric entries
		// render as themselves, and words past the table's end are left out.
		$settings = array_fill(0, 35, 0);
		$settings[0] = 70;      // size field: never displayed
		$settings[23] = 105;    // Maximum Focal Length of Lens (plain)
		$settings[24] = 24;     // Minimum Focal Length of Lens (plain)
		$settings[25] = 1;      // Focal Length Units per mm (plain)
		$settings[34] = 7;      // past the last defined index
		$dataPos = 2 + 12 + 4;
		$ifd = $this->packIfd(
			[[TCanonMakernote::CameraSettings1Tag, TTIFFDataType::UShort, count($settings), pack('N', $dataPos)]],
			true,
		);
		$note = $ifd . pack('n*', ...$settings);
		$parsed = TMakernote::fromNote($note, 'Canon', $note, 0, true);
		self::assertInstanceOf(TCanonMakernote::class, $parsed);

		$decoded = $parsed->getCameraSettings();
		self::assertSame('105', $decoded['Maximum Focal Length of Lens']);
		self::assertSame('24', $decoded['Minimum Focal Length of Lens']);
		self::assertSame('1', $decoded['Focal Length Units per mm']);
		self::assertArrayNotHasKey('Number of Bytes in Tag', $decoded);

		// The same note carries no Custom Functions block.
		self::assertSame([], $parsed->getCustomFunctions());
	}

	public function testMinoltaSevenHiSettingsTagAndPlainValues()
	{
		// The 7Hi stores its camera settings in tag 0x0003 instead of 0x0001.
		$values = array_fill(0, 21, 0);
		$values[2] = 1;      // Exposure Mode: A
		$values[18] = 4;     // Interval Number (plain)
		$values[20] = 250;   // Focus Distance (plain)
		$dataPos = 2 + 12 + 4;
		$ifd = $this->packIfd(
			[[TKonicaMinoltaMakernote::CameraSettings7HiTag, TTIFFDataType::ULong, count($values), pack('N', $dataPos)]],
			true,
		);
		$note = $ifd . pack('N*', ...$values);
		$parsed = TMakernote::fromNote($note, 'KONICA MINOLTA', $note, 0, true);
		self::assertInstanceOf(TKonicaMinoltaMakernote::class, $parsed);

		$settings = $parsed->getCameraSettings();
		self::assertSame('A', $settings['Exposure Mode']);
		self::assertSame('4', $settings['Interval Number']);
		self::assertSame('250', $settings['Focus Distance']);
	}

	public function testMinoltaWithoutAnySettingsBlock()
	{
		$ifd = $this->packIfd([[0x0040, TTIFFDataType::UShort, 1, pack('n', 1)]], true);
		$parsed = TMakernote::fromNote($ifd, 'MINOLTA', $ifd, 0, true);
		self::assertInstanceOf(TKonicaMinoltaMakernote::class, $parsed);
		self::assertSame(1, $parsed->getIfd()->getTagValue(0x0040));
		self::assertSame([], $parsed->getCameraSettings());
	}

	public function testRegisterMakerClassOverride()
	{
		$custom = new class () extends TMakernote {
		};
		try {
			TMakernote::registerMakerClass('Sony', $custom::class);
			$ifd = $this->packIfd([[0x9001, TTIFFDataType::UShort, 1, pack('n', 3)]], true, false);
			$parsed = TMakernote::fromNote("SONY CAM \x00\x00\x00" . $ifd, 'SONY');
			self::assertInstanceOf($custom::class, $parsed);
			self::assertSame('Sony', $parsed->getMaker());
		} finally {
			TMakernote::registerMakerClass('Sony', null);
		}
		// The default class is restored.
		$ifd = $this->packIfd([[0x9001, TTIFFDataType::UShort, 1, pack('n', 3)]], true, false);
		self::assertSame(TMakernote::class, get_class(TMakernote::fromNote("SONY DSC \x00\x00\x00" . $ifd, 'SONY')));
	}

	public function testAccessorsAndUnknownTagNaming()
	{
		$ifd = $this->packIfd([
			[0x0001, TTIFFDataType::UShort, 1, pack('n', 2)],
			[0xEEEE, TTIFFDataType::UShort, 1, pack('n', 9)],   // unknown tag
		], true, false);
		$note = "Panasonic\x00\x00\x00" . $ifd;
		$parsed = TMakernote::fromNote($note, 'Panasonic');
		self::assertSame($note, $parsed->getNote());
		self::assertSame([], $parsed->getWarnings());
		self::assertNull($parsed->getText());
		self::assertNull($parsed->getTagText(0x9999));
		$values = $parsed->getValues();
		self::assertArrayHasKey('Tag 0xEEEE', $values);
	}
}
