<?php

use Prado\Prado;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\IImageGraphicsLibrary;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;

class TIPTCTest extends PHPUnit\Framework\TestCase
{
	public $obj;
	public static $app;
	public static $assetDir;

	protected function setUp(): void
	{
		// Fake environment variables needed to determine path
		$_SERVER['HTTP_HOST'] = 'localhost';
		$_SERVER['SERVER_NAME'] = 'localhost';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI'] = '/demos/personal/index.php?page=Links';
		$_SERVER['SCRIPT_NAME'] = '/demos/personal/index.php';
		$_SERVER['PHP_SELF'] = '/demos/personal/index.php';
		$_SERVER['QUERY_STRING'] = 'page=Links';
		$_SERVER['SCRIPT_FILENAME'] = __FILE__;
		$_SERVER['PATH_INFO'] = __FILE__;
		$_SERVER['HTTP_REFERER'] = 'https://github.com/pradosoft/prado';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; U; Intel Mac OS X; en-US; rv:1.8.1.3) Gecko/20070309 Firefox/2.0.0.3';
		$_SERVER['REMOTE_HOST'] = 'localhost';

		if (self::$app === null) {
			self::$app = new TApplication(__DIR__ . '/../../../Web/app');
		}

		if (self::$assetDir === null) {
			self::$assetDir = __DIR__ . '/../../../Web/assets';
		}
		// Make asset directory if not exists
		if (!file_exists(self::$assetDir)) {
			if (is_writable(dirname(self::$assetDir))) {
				mkdir(self::$assetDir) ;
			} else {
				throw new Exception('Directory ' . dirname(self::$assetDir) . ' is not writable');
			}
		} elseif (!is_dir(self::$assetDir)) {
			throw new Exception(self::$assetDir . ' exists and is not a directory');
		}
		// Define an alias to asset directory
		prado::setPathofAlias('AssetAlias', self::$assetDir = realpath(self::$assetDir));

		$this->obj = new TIPTC();
	}

	private function removeDirectory($dir)
	{
		// Let's be sure $dir is a directory to avoid any error. Clear the cache !
		clearstatcache();
		if (is_dir($dir)) {
			foreach (scandir($dir) as $content) {
				if ($content === '.' || $content === '..') {
					continue;
				} // skip . and ..
				$content = $dir . '/' . $content;
				if (is_dir($content)) {
					$this->removeDirectory($content);
				} // Recursively remove directories
				else {
					unlink($content);
				} // Remove file
			}
			// Now, directory should be empty, remove it
			rmdir($dir);
		}
	}

	protected function tearDown(): void
	{
		// It cleans it :)
		$this->removeDirectory(self::$assetDir);
	}

	public function testIPTCDate()
	{
		self::assertEquals("20000101", TIPTC::formatIPTCDate(946684800));
		self::assertEquals(date('Ymd'), TIPTC::formatIPTCDate());
	}

	public function testIPTCTime()
	{
		self::assertEquals("000000+0000", TIPTC::formatIPTCTime(946684800));
		self::assertEquals(date('HisO'), TIPTC::formatIPTCTime());
	}

	public function testFormatTag()
	{
		self::assertEquals("1#090", TIPTC::formatTag(1, 90));
		self::assertEquals("1#090", TIPTC::formatTag('1', '90'));
	}

	/**
	 * Fills an TIPTC with datasets from every record the class models, the way a
	 * photo editor writes them.  The original framework test read these from a
	 * tdot.jpg fixture that was never committed; the block is built in memory here
	 * so the parse path is exercised without a binary fixture.
	 */
	private function sampleIPTC(): TIPTC
	{
		$iptc = new TIPTC();
		$iptc[TIPTCTags::FileFormat] = 11;
		$iptc[TIPTCTags::DateSent] = '20230105';
		$iptc[TIPTCTags::TimeSent] = '021159-0800';

		$iptc[TIPTCTags::ObjectName] = 'TDot Sample';
		$iptc[TIPTCTags::EditStatus] = 'Final Sample';
		$iptc[TIPTCTags::Urgency] = '3';
		$iptc[TIPTCTags::Keywords] = ['green, sample, Prado'];
		$iptc[TIPTCTags::ReleaseDate] = '20230201';
		$iptc[TIPTCTags::ReleaseTime] = '021159-0800';
		$iptc[TIPTCTags::ReferenceNumber] = ['00000422'];
		$iptc[TIPTCTags::OriginatingProgram] = 'Prado::TDot';
		$iptc[TIPTCTags::ByLine] = ['Brad: Anderson'];
		$iptc[TIPTCTags::ByLineTitle] = ['P-355335'];
		$iptc[TIPTCTags::City] = 'Los Angeles';
		$iptc[TIPTCTags::ProvinceState] = 'California';
		$iptc[TIPTCTags::CountryPrimaryLocationCode] = 'WGW';
		$iptc[TIPTCTags::CountryPrimaryLocationName] = 'World';
		$iptc[TIPTCTags::CopyrightNotice] = ' Prado License BSD 3 Paragraph';
		$iptc[TIPTCTags::Contact] = ['belisoful@icloud.com'];
		$iptc[TIPTCTags::CaptionAbstract] = 'This is a sample of the TDot.';
		$iptc[TIPTCTags::MasterDocumentID] = '4.2.2';

		$iptc[TIPTCTags::IPTCBitsPerSample] = 8;
		$iptc[TIPTCTags::ScanningDirection] = 0;
		$iptc[TIPTCTags::IPTCImageRotation] = 0;
		$iptc[TIPTCTags::BitsPerComponent] = 8;
		return $iptc;
	}

	/**
	 * Asserts a parsed block round-tripped every dataset of {@see sampleIPTC()}.
	 * @param TIPTC $data
	 */
	private function assertSampleIPTC(TIPTC $data): void
	{
		self::assertEquals(4, $data[TIPTCTags::EnvelopeRecordVersion]);
		self::assertEquals(11, $data[TIPTCTags::FileFormat]);
		self::assertEquals('20230105', $data[TIPTCTags::DateSent]);
		self::assertEquals('021159-0800', $data[TIPTCTags::TimeSent]);

		self::assertEquals(4, $data[TIPTCTags::ApplicationRecordVersion]);
		self::assertEquals('TDot Sample', $data[TIPTCTags::ObjectName]);

		self::assertEquals('Final Sample', $data[TIPTCTags::EditStatus]);
		self::assertEquals('3', $data[TIPTCTags::Urgency]);
		self::assertEquals(['green, sample, Prado'], $data[TIPTCTags::Keywords]);
		self::assertEquals('20230201', $data[TIPTCTags::ReleaseDate]);
		self::assertEquals('021159-0800', $data[TIPTCTags::ReleaseTime]);
		self::assertEquals(['00000422'], $data[TIPTCTags::ReferenceNumber]);
		self::assertEquals('Prado::TDot', $data[TIPTCTags::OriginatingProgram]);
		self::assertEquals(['Brad: Anderson'], $data[TIPTCTags::ByLine]);
		self::assertEquals(['P-355335'], $data[TIPTCTags::ByLineTitle]);
		self::assertEquals('Los Angeles', $data[TIPTCTags::City]);
		self::assertEquals('California', $data[TIPTCTags::ProvinceState]);
		self::assertEquals('WGW', $data[TIPTCTags::CountryPrimaryLocationCode]);
		self::assertEquals('World', $data[TIPTCTags::CountryPrimaryLocationName]);
		self::assertEquals(' Prado License BSD 3 Paragraph', $data[TIPTCTags::CopyrightNotice]);
		self::assertEquals(['belisoful@icloud.com'], $data[TIPTCTags::Contact]);
		self::assertEquals('This is a sample of the TDot.', $data[TIPTCTags::CaptionAbstract]);
		self::assertEquals('4.2.2', $data[TIPTCTags::MasterDocumentID]);

		self::assertEquals(4, $data[TIPTCTags::NewsPhotoVersion]);
		self::assertEquals(8, $data[TIPTCTags::IPTCBitsPerSample]);
		self::assertEquals(0, $data[TIPTCTags::ScanningDirection]);
		self::assertEquals(0, $data[TIPTCTags::IPTCImageRotation]);
		self::assertEquals(8, $data[TIPTCTags::BitsPerComponent]);
	}

	public function testIPTCparse()
	{
		$block = $this->sampleIPTC()->toBinary(true);
		// The Photoshop 8BIM wrapper is decoded before the datasets are read.
		self::assertSame("Photoshop 3.0\0", substr($block, 0, 14));

		$data = TIPTC::iptcparse($block);
		self::assertInstanceof(TIPTC::class, $data);
		$this->assertSampleIPTC($data);
		self::assertEquals(32, count($data));

		// An unwrapped IIM block parses the same way.
		$raw = $this->sampleIPTC()->toBinary(false);
		$data = TIPTC::iptcparse($raw);
		self::assertInstanceof(TIPTC::class, $data);
		$this->assertSampleIPTC($data);
	}

	public function testIPTCparseStream()
	{
		$block = $this->sampleIPTC()->toBinary(true);

		// A JPEG-shaped stream: SOI, a filler APP0, then the APP13 the parser wants.
		$jpeg = "\xFF\xD8" . "\xFF\xE0" . pack('n', 16) . str_repeat("\0", 14)
			. "\xFF\xED" . pack('n', strlen($block) + 2) . $block;
		$stream = fopen('php://memory', 'r+b');
		fwrite($stream, $jpeg);
		rewind($stream);

		$marker = fread($stream, 2);
		while (!feof($stream) && $marker !== "\xFF\xED") {
			$marker = $marker[1] . fgetc($stream);
		}
		self::assertEquals(22, ftell($stream));
		$length = unpack('n', fread($stream, 2))[1] - 2;
		self::assertEquals(strlen($block), $length);

		$data = TIPTC::iptcparse([$stream, $length]);
		self::assertInstanceof(TIPTC::class, $data);
		self::assertEquals(32, count($data));
		$this->assertSampleIPTC($data);
		fclose($stream);
	}

	public function testIPTCTagKeys()
	{
		$keys = TIPTC::getIPTCTagKeys();
		self::assertEquals(123, count($keys));
		self::assertTrue(array_key_exists('enveloperecordversion', $keys));

		$keys = TIPTC::getIPTCTagKeys(true);
		self::assertTrue(array_key_exists('enveloperecordversion', $keys));
		self::assertTrue(array_key_exists('copyright', $keys));

		$keys = TIPTC::getIPTCTagKeys(false);
		self::assertTrue(array_key_exists('EnvelopeRecordVersion', $keys));
		self::assertTrue(array_key_exists('Copyright', $keys));

		$keys = TIPTC::getIPTCTagKeys(true, false);
		self::assertTrue(array_key_exists('enveloperecordversion', $keys));
		self::assertFalse(array_key_exists('copyright', $keys));

		$keys = TIPTC::getIPTCTagKeys(false, false);
		self::assertTrue(array_key_exists('EnvelopeRecordVersion', $keys));
		self::assertFalse(array_key_exists('Copyright', $keys));
	}

	public function testMapToIPTCTagId()
	{
		self::assertEquals('2#005', TIPTC::mapToIPTCTagId('ObjectName'));
		self::assertEquals('2#005', TIPTC::mapToIPTCTagId('objectname'));
		self::assertEquals('2#005', TIPTC::mapToIPTCTagId('2#005'));
		self::assertEquals('2#005', TIPTC::mapToIPTCTagId('2#5'));
		self::assertEquals('2#005', TIPTC::mapToIPTCTagId(0x0205));
		self::assertEquals('2#005', TIPTC::mapToIPTCTagId(2, 5));
		self::assertEquals('2#005', TIPTC::mapToIPTCTagId('2', '5'));
		self::assertEquals('2#193', TIPTC::mapToIPTCTagId('2#193'));
		self::assertEquals(null, TIPTC::mapToIPTCTagId('11#3'));
		self::assertEquals(null, TIPTC::mapToIPTCTagId('invalidTag'));
		self::assertEquals(null, TIPTC::mapToIPTCTagId(0));
		self::assertEquals(null, TIPTC::mapToIPTCTagId(null));
	}

	public function testMapToIPTCTagName()
	{
		self::assertEquals(null, TIPTC::mapToIPTCTagName(''));
		self::assertEquals('ObjectName', TIPTC::mapToIPTCTagName('ObjectName'));
		self::assertEquals('ObjectName', TIPTC::mapToIPTCTagName('objectname'));
		self::assertEquals('ObjectName', TIPTC::mapToIPTCTagName('2#005'));
		self::assertEquals('ObjectName', TIPTC::mapToIPTCTagName('2#5'));
	}

	public function testComputeEnvelopeNumber()
	{
		$serviceId = 'PRADO4.2.3';
		$date = '20230518';
		$ref = 76564395;
		self::assertEquals($ref, TIPTC::computeEnvelopeNumber($serviceId, $date));
		self::assertEquals($ref, TIPTC::computeEnvelopeNumber($serviceId, $date));
		self::assertNotEquals($ref, TIPTC::computeEnvelopeNumber($serviceId, '20230517'));
		self::assertNotEquals($ref, TIPTC::computeEnvelopeNumber('PRADO4.2.4', $date));
	}

	public function testConstruct()
	{
		self::assertEquals(true, $this->obj->contains('1#090'));

		$this->obj = new TIPTC('');
		self::assertEquals(false, $this->obj->contains('1#090'));
	}

	public function testRasterizedCaptionImage()
	{
		$refImage = imageCreate(460, 128);
		$black = imagecolorallocate($refImage, 0, 0, 0);
		$white = imagecolorallocate($refImage, 255, 255, 255);
		imagesetpixel($refImage, 0, 127, $white);
		imagesetpixel($refImage, 0, 126, $white);
		$this->obj->setRasterizedCaptionImage($refImage);
		self::assertEquals(str_pad("\x03", 7360, "\x00"), $this->obj[TIPTCTags::RasterizedCaption]);

		imagecolorset($refImage, 0, 255, 255, 255);
		imagecolorset($refImage, 1, 0, 0, 0);

		$this->obj->setRasterizedCaptionImage($refImage);
		self::assertEquals(str_pad("\xFC", 7360, "\xFF"), $this->obj[TIPTCTags::RasterizedCaption]);

		imagecolorset($refImage, 0, 0, 0, 0);
		imagecolorset($refImage, 1, 255, 255, 255);

		for ($y = 0; $y < 128; $y++) {
			for ($x = 0; $x < 460; $x++) {
				imagesetpixel($refImage, $x, $y, (rand() & 1) ? $white : $black);
			}
		}
		$this->obj->setRasterizedCaptionImage($refImage);

		$image = $this->obj->getRasterizedCaptionImage();
		self::assertInstanceOf(\GdImage::class, $image);
		for ($y = 0; $y < 128; $y++) {
			for ($x = 0; $x < 460; $x++) {
				$ref = imagecolorsforindex($refImage, imagecolorat($refImage, $x, $y));
				$expected = ($ref['red'] << 16) | ($ref['green'] << 8) | $ref['blue'];
				self::assertSame($expected, imagecolorat($image, $x, $y));
			}
		}
		imageDestroy($refImage);
		imageDestroy($image);
	}

	public function testValidate()
	{
		$this->obj[TIPTCTags::ApplicationRecordVersion] = 4;
		$this->obj[TIPTCTags::NewsPhotoVersion] = 4;
		self::assertEquals(3, count($this->obj));
		$this->obj->validate();
		self::assertEquals(7, count($this->obj));
		self::assertEquals(false, $this->obj->contains(TIPTCTags::ApplicationRecordVersion));
		self::assertEquals(false, $this->obj->contains(TIPTCTags::NewsPhotoVersion));

		self::assertEquals(4, $this->obj[TIPTCTags::EnvelopeRecordVersion]);
		self::assertEquals(1, $this->obj[TIPTCTags::FileFormat]);
		self::assertEquals(4, $this->obj[TIPTCTags::FileVersion]);
		self::assertTrue(strncmp('PRADO', $this->obj[TIPTCTags::ServiceIdentifier], 5) === 0);
		self::assertEquals(date('Ymd'), $this->obj[TIPTCTags::DateSent]);
		self::assertEquals(8, strlen($this->obj[TIPTCTags::EnvelopeNumber]));


		$this->obj[TIPTCTags::JobID] = 'Job ID';
		$this->obj[TIPTCTags::IPTCPictureNumber] = "ABCDEF20230209YZ";

		$this->obj[TIPTCTags::EnvelopeRecordVersion] = 5;
		$this->obj[TIPTCTags::FileFormat] = 2;
		$this->obj[TIPTCTags::FileVersion] = 6;
		$this->obj[TIPTCTags::ServiceIdentifier] = '0123456789';
		$this->obj[TIPTCTags::DateSent] = '19991231';
		$envelope = $this->obj[TIPTCTags::EnvelopeNumber] = str_pad(crc32($this->obj[TIPTCTags::ServiceIdentifier] . $this->obj[TIPTCTags::DateSent]) % 100000000, 8, '0', STR_PAD_LEFT);
		$this->obj->validate();
		self::assertEquals(true, $this->obj->contains(TIPTCTags::ApplicationRecordVersion));
		self::assertEquals(true, $this->obj->contains(TIPTCTags::NewsPhotoVersion));

		self::assertEquals(4, $this->obj[TIPTCTags::ApplicationRecordVersion]);
		self::assertEquals(4, $this->obj[TIPTCTags::NewsPhotoVersion]);

		self::assertEquals(5, $this->obj[TIPTCTags::EnvelopeRecordVersion]);
		self::assertEquals(2, $this->obj[TIPTCTags::FileFormat]);
		self::assertEquals(6, $this->obj[TIPTCTags::FileVersion]);
		self::assertEquals('0123456789', $this->obj[TIPTCTags::ServiceIdentifier]);
		self::assertEquals('19991231', $this->obj[TIPTCTags::DateSent]);
		self::assertEquals($envelope, $this->obj[TIPTCTags::EnvelopeNumber]);

		unset($this->obj[TIPTCTags::IPTCPictureNumber]);
		$this->obj->validate();
		self::assertEquals(true, $this->obj->contains(TIPTCTags::ApplicationRecordVersion));
		self::assertEquals(false, $this->obj->contains(TIPTCTags::NewsPhotoVersion));
	}

	public function testToBinary()
	{
		$envVer = "\x1c\x01\x00\x00\x02\x00\x04";
		$char = "\x1c\x01\x5A\x00\x03\x1B\x25\x47";
		$ff = "\x1c\x01\x14\x00\x02\x00\x01";
		$fv = "\x1c\x01\x16\x00\x02\x00\x04";
		$svid = "\x1c\x01\x1E\x00\x0A" . 'PRADO' . substr(Prado::getVersion(), 0, 5);
		$date = "\x1c\x01\x46\x00\x08" . date('Ymd');
		$val = 'PRADO' . substr(Prado::getVersion(), 0, 5) . date('Ymd');
		$envNum = "\x1c\x01\x28\x00\x08" . str_pad(crc32($val) % 100000000, 8, '0', STR_PAD_LEFT);

		$this->obj[TIPTCTags::ByLine] = ['abc', '123'];
		$this->obj[TIPTCTags::ByLineTitle] = 'def';
		$appRec = "\x1c\x02\x00\x00\x02\x00\x04";
		$bl = "\x1c\x02\x50\x00\x03abc\x1c\x02\x50\x00\x03123";
		$blt = "\x1c\x02\x55\x00\x03def";

		self::assertEquals($iptcData = $envVer . $ff . $fv . $svid . $envNum . $date . $char . $appRec . $bl . $blt, $this->obj->toBinary(false));

		self::assertEquals("Photoshop 3.0\08BIM\x04\x04\0\0" . pack('N', strlen($iptcData)) . $iptcData, $this->obj->toBinary(true));
	}

	public function testTagBinary()
	{
		$data = "test string data";
		self::assertEquals("\x1c\x02\xCA\x00" . chr(strlen($data)) . $data, $this->obj->tagBinary('2#202', $data));

		$data = str_pad("test string data", 512);
		$len = strlen($data);
		self::assertEquals("\x1c\x02\xCA" . chr(($len >> 8) & 0xFF) . chr($len & 0xFF) . $data, $this->obj->tagBinary('2#202', $data));

		$data = str_pad("test string data", 0x8000);
		$len = strlen($data);
		self::assertEquals("\x1c\x02\xCA\x80\x04" . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF) . $data, $this->obj->tagBinary('2#202', $data));
	}

	public function testValidateIPTCValue()
	{
		// undefined upper boundary
		$value = str_pad("II\x00\x42\x00\x00", 5000);
		$this->obj[TIPTCTags::ExifCameraInfo] = $value;
		self::assertEquals(4096, strlen($this->obj[TIPTCTags::ExifCameraInfo]));

		$value = 0x58; // 8 bit
		$this->obj[TIPTCTags::ColorSequence] = $value;
		self::assertEquals(0x58, $this->obj[TIPTCTags::ColorSequence]);

		$value = 0x400;
		$this->obj[TIPTCTags::ColorSequence] = $value;
		self::assertEquals(0xFF, $this->obj[TIPTCTags::ColorSequence]);

		$value = '15';
		$this->obj[TIPTCTags::ColorSequence] = $value;
		self::assertEquals(0x0F, $this->obj[TIPTCTags::ColorSequence]);

		$value = 0x9234;
		$this->obj[TIPTCTags::IPTCImageWidth] = $value;
		self::assertEquals(0x9234, $this->obj[TIPTCTags::IPTCImageWidth]);

		$value = 0xC9234;
		$this->obj[TIPTCTags::IPTCImageWidth] = $value;
		self::assertEquals(0xFFFF, $this->obj[TIPTCTags::IPTCImageWidth]);

		$value = '1026';
		$this->obj[TIPTCTags::IPTCImageWidth] = $value;
		self::assertEquals(1026, $this->obj[TIPTCTags::IPTCImageWidth]);

		$value = 0x91235678; // 32 bit
		$this->obj[TIPTCTags::DataCompressionMethod] = $value;
		self::assertEquals(0x91235678, $this->obj[TIPTCTags::DataCompressionMethod]);

		$value = "8475628"; // 32 bit
		$this->obj[TIPTCTags::DataCompressionMethod] = $value;
		self::assertEquals(8475628, $this->obj[TIPTCTags::DataCompressionMethod]);

		$value = "b5"; // numeric string
		$this->obj[TIPTCTags::EditorialUpdate] = $value;
		self::assertEquals("5 ", $this->obj[TIPTCTags::EditorialUpdate]);

		$value = "b5c"; // alpha string
		$this->obj[TIPTCTags::CountryPrimaryLocationCode] = $value;
		self::assertEquals("bc ", $this->obj[TIPTCTags::CountryPrimaryLocationCode]);

		$value = "B5C"; // alpha string
		$this->obj[TIPTCTags::CountryPrimaryLocationCode] = $value;
		self::assertEquals("BC ", $this->obj[TIPTCTags::CountryPrimaryLocationCode]);


		$value = "abcABC0123()#@^&.,;'!*? \t\r\n"; // graphic char
		$this->obj[TIPTCTags::Destination] = $value;
		self::assertEquals("abcABC0123()#@^&.,;'!*?", $this->obj[TIPTCTags::Destination]);

		$value = "abcABC0123()#@^&.,;'!*? \t\r\n"; // graphic char + space
		$this->obj[TIPTCTags::Contact] = $value;
		self::assertEquals("abcABC0123()#@^&.,;'!*? \t", $this->obj[TIPTCTags::Contact]);

		$value = "abcABC0123()#@^&.,;'!*? \t\r\n"; // graphic char + space + \r\n
		$this->obj[TIPTCTags::DocumentNotes] = $value;
		self::assertEquals("abcABC0123()#@^&.,;'!*? \t\r\n", $this->obj[TIPTCTags::DocumentNotes]);

		$value = "abcABC0123()#@^&.,;'!*? \t\r\n"; // graphic char + object name
		$this->obj[TIPTCTags::UniqueObjectName] = $value;
		self::assertEquals("abcABC0123()#@^&.,;'!", $this->obj[TIPTCTags::UniqueObjectName]);
	}

	public function testRoundTrip()
	{
		$now = time();
		$this->obj['Destination'] = ['abc', 'efg'];
		$this->obj['ProductID'] = ['Product1', 'Product2'];
		$this->obj['EnvelopePriority'] = "2";
		$this->obj['TimeSent'] = $now;
		$this->obj['UniqueObjectName'] = "NAME:SUB:CONTEXT:LAST:variable";
		$this->obj['ARMIdentifier'] = 20;
		$this->obj['ARMVersion'] = 21;

		$this->obj['ObjectTypeReference'] = "11:ObjectRef";
		$this->obj['ObjectAttributeReference'] = ["12:ObjAttrRef", '13:SecondAttrRef'];
		$this->obj['ObjectName'] = "Object Name";
		$this->obj['EditStatus'] = "Unit Testing";
		$this->obj['EditorialUpdate'] = "23";
		$this->obj['Urgency'] = "1";
		$this->obj['SubjectReference'] = ["ABCDEF0123456789", "0123456789ABCDEF"];
		$this->obj['Category'] = "aEU";
		$this->obj['SupplementalCategories'] = ['test', 'iptc unit'];

		$this->obj['FixtureIdentifier'] = 'fixtureID1234';
		$this->obj['Keywords'] = ['first', 'iptc', 'test'];
		$this->obj['ContentLocationCode'] = ['ABC', 'BCD', 'CDE'];
		$this->obj['ContentLocationName'] = ['First', 'Second', 'Third'];
		$this->obj['ReleaseDate'] = $now;
		$this->obj['ReleaseTime'] = $now;
		$this->obj['ExpirationDate'] = $now;
		$this->obj['ExpirationTime'] = $now;
		$this->obj['SpecialInstructions'] = "These are special instructions.";
		$this->obj['ActionAdvised'] = "45";

		$this->obj['ReferenceService'] = ["PRADO4.2.0", "ANOTHER123"];
		$this->obj['ReferenceDate'] = [$now, '11/12/2013'];
		$this->obj['ReferenceNumber'] = ['47632500', '00008773'];

		$this->obj['DateCreated'] = $now;
		$this->obj['TimeCreated'] = $now;
		$this->obj['DigitalCreationDate'] = $now;
		$this->obj['DigitalCreationTime'] = $now;
		$this->obj['OriginatingProgram'] = 'PRADO';
		$this->obj['ProgramVersion'] = '4.2.2';
		$this->obj['ObjectCycle'] = 'A';
		$this->obj['By-line'] = ['author1', 'author 2'];
		$this->obj['By-lineTitle'] = ['doctor', 'PhD'];

		$this->obj['City'] = 'Los Angeles';
		$this->obj['Sub-location'] = 'Long Beach';
		$this->obj['Province-State'] = 'California';
		$this->obj['Country-PrimaryLocationCode'] = 'USA';
		$this->obj['Country-PrimaryLocationName'] = 'United Nations - MemberState \'gov\'';
		$this->obj['OriginalTransmissionReference'] = 'WGWC';

		$this->obj['Headline'] = 'Image gets an IPTC in PRADO - First Time!!';
		$this->obj['Credit'] = 'Brad Anderson';
		$this->obj['Source'] = 'belisoful [ut] icloud [dat] com';
		$this->obj['CopyrightNotice'] = 'Copyright ©2023 PRADO';
		$this->obj['Contact'] = ['brad anderson', 'belisoful'];
		$this->obj['Caption-Abstract'] = 'This is a caption of the data with the IPTC';
		$this->obj['LocalCaption'] = 'The local caption';
		$this->obj['Writer-Editor'] = ['editor', 'writer'];
		$this->obj['RasterizedCaption'] = str_pad('', 7360, "\x00");
		$this->obj['ImageType'] = '7A';
		$this->obj['ImageOrientation'] = 'L';
		$this->obj['LanguageIdentifier'] = 'en';

		$this->obj['AudioType'] = '8Z';
		$this->obj['AudioSamplingRate'] = '044100';
		$this->obj['AudioSamplingResolution'] = '08';
		$this->obj['AudioDuration'] = '000120';
		$this->obj['AudioOutcue'] = '....and....  cut';

		$this->obj['JobID'] = 'jobId $&#^ 234.:';
		$this->obj['MasterDocumentID'] = "Master Doc 12345";
		$this->obj['ShortDocumentID'] = "12345";
		$this->obj['UniqueDocumentID'] = "12345ABC";
		$this->obj['OwnerID'] = "belisoful";
		$this->obj['ObjectPreviewFileFormat'] = '11';
		$this->obj['ObjectPreviewFileVersion'] = '2';
		$this->obj['ObjectPreviewData'] = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$this->obj['Prefs'] = 'quality: 75';
		$this->obj['ClassifyState'] = 'unclassified';
		$this->obj['SimilarityIndex'] = '484736';
		$this->obj['DocumentNotes'] = 'My Document Notes, Take heed, unit test ahead';
		$this->obj['DocumentHistory'] = 'checking all variables';
		$this->obj['ExifCameraInfo'] = 'II\x00\x42\x00\x00';
		$this->obj['CatalogSets'] = ['abc', 'def', 'ghi'];

		$this->obj['IPTCPictureNumber'] = "ABCDEF20230210-0";
		$this->obj['IPTCImageWidth'] = 1030;
		$this->obj['IPTCImageHeight'] = 512;
		$this->obj['IPTCPixelWidth'] = 700;
		$this->obj['IPTCPixelHeight'] = 800;
		$this->obj['SupplementalType'] = 3;
		$this->obj['ColorRepresentation'] = 289;
		$this->obj['InterchangeColorSpace'] = 3;
		$this->obj['ColorSequence'] = 3;
		$this->obj['ICC_Profile'] = "ABCDEFGHIJK0123456789";
		$this->obj['LookupTable'] = "1234123412341234";
		$this->obj['NumIndexEntries'] = 700;
		$this->obj['ColorPalette'] = '2345234523452345234523452345';
		$this->obj['IPTCBitsPerSample'] = 16;
		$this->obj['SampleStructure'] = 13;
		$this->obj['ScanningDirection'] = 2;
		$this->obj['IPTCImageRotation'] = 3;
		$this->obj['DataCompressionMethod'] = 0x91873477;
		$this->obj['QuantizationMethod'] = 5;
		$this->obj['EndPoints'] = '13';
		$this->obj['ExcursionTolerance'] = 99;
		$this->obj['BitsPerComponent'] = 8;
		$this->obj['MaximumDensityRange'] = 50899;
		$this->obj['GammaCompensatedValue'] = 30498;

		$this->obj['SizeMode'] = 3;
		$this->obj['MaxSubfileSize'] = 6;
		$this->obj['ObjectSizeAnnounced'] = 6;
		$this->obj['MaximumObjectSize'] = 6;

		$this->obj['SubFile'] = ['A', 'BC', 'DEF'];

		$this->obj['ConfirmedObjectSize'] = 6;

		self::assertEquals('UTF-8', $this->obj['CodedCharacterSet']);

		$data = $this->obj->toBinary(false);
		self::assertEquals(9160, strlen($data));
		$iptc = TIPTC::iptcparse($data);
		self::assertNotEquals(false, $iptc);
		self::assertEquals(115, $iptc->count()); //ColorCalibrationMatrix is obsolete, not set
		self::assertEquals(116, count(TIPTC::TAG_MAP));
		self::assertEquals(115, $this->obj->count()); //ColorCalibrationMatrix is obsolete, not set
		self::assertEquals($this->obj->toArray(), $iptc->toArray());

		foreach ($this->obj->toArray() as $key => $refVal) {
			self::assertEquals($this->obj[$key], $iptc[$key], "bad $key");
		}
	}


	public function testTList()
	{
		// itemAt, priorityAt, add, remove, and contains are tested.
		$now = time();

		//Add
		$this->obj->add('ProductID', $now);
		$this->obj->add(TIPTCTags::ObjectName, $now + 1);
		$this->obj->add('ICC_Profile', $now + 2);

		//Contains
		self::assertTrue($this->obj->contains(TIPTCTags::ProductID));
		self::assertTrue($this->obj->contains('ProductID'));
		self::assertTrue($this->obj->contains('productid'));

		self::assertTrue($this->obj->contains(TIPTCTags::ObjectName));
		self::assertTrue($this->obj->contains('ObjectName'));
		self::assertTrue($this->obj->contains('objectname'));

		self::assertTrue($this->obj->contains(TIPTCTags::ICC_Profile));
		self::assertTrue($this->obj->contains('ICC_Profile'));
		self::assertTrue($this->obj->contains('icc_profile'));

		//ItemAt
		self::assertEquals($now, $this->obj[TIPTCTags::ProductID]);
		self::assertEquals($now, $this->obj['ProductID']);
		self::assertEquals($now + 1, $this->obj[TIPTCTags::ObjectName]);
		self::assertEquals($now + 1, $this->obj['ObjectName']);
		self::assertEquals($now + 2, $this->obj[TIPTCTags::ICC_Profile]);
		self::assertEquals($now + 2, $this->obj['ICC_Profile']);

		//Add null
		$this->obj->add(TIPTCTags::ObjectName, null);
		$this->obj->add('ReleaseTime', null);
		$this->obj->add('ICC_Profile', null);

		//contains, null
		self::assertTrue($this->obj->contains(TIPTCTags::ObjectName));
		self::assertTrue($this->obj->contains('ObjectName'));
		self::assertTrue($this->obj->contains('objectname'));

		self::assertTrue($this->obj->contains(TIPTCTags::ReleaseTime));
		self::assertTrue($this->obj->contains('ReleaseTime'));
		self::assertTrue($this->obj->contains('releasetime'));

		//remove
		$this->obj->remove(TIPTCTags::ObjectName);
		$this->obj->remove('ReleaseTime');
		$this->obj->remove('ICC_Profile');

		//contains, false
		self::assertFalse($this->obj->contains(TIPTCTags::ObjectName));
		self::assertFalse($this->obj->contains('ObjectName'));
		self::assertFalse($this->obj->contains('objectname'));

		self::assertFalse($this->obj->contains(TIPTCTags::ReleaseTime));
		self::assertFalse($this->obj->contains('ReleaseTime'));
		self::assertFalse($this->obj->contains('releasetime'));
	}


	public function testTListAddTypes()
	{
		$now = $this->obj[TIPTCTags::TimeSent] = time();
		self::assertEquals(date('HisO'), $this->obj[TIPTCTags::TimeSent]);

		$this->obj[TIPTCTags::TimeSent] = '15:45:11';
		self::assertEquals('154511+0000', $this->obj[TIPTCTags::TimeSent]);

		$this->obj[TIPTCTags::DateSent] = $now;
		self::assertEquals(date('Ymd'), $this->obj[TIPTCTags::DateSent]);

		$this->obj[TIPTCTags::DateSent] = '12/01/2022';
		self::assertEquals('20221201', $this->obj[TIPTCTags::DateSent]);

		$this->obj[TIPTCTags::ReferenceDate] = ['01/01/2000', '11/29/2023'];
		self::assertEquals(['20000101', '20231129'], $this->obj[TIPTCTags::ReferenceDate]);

		$this->obj[TIPTCTags::ReferenceDate] = ['01/01/2000', '11/29/2023'];
		self::assertEquals(['20000101', '20231129'], $this->obj[TIPTCTags::ReferenceDate]);

		$this->obj[TIPTCTags::SupplementalType] = '45';
		self::assertEquals(45, $this->obj[TIPTCTags::SupplementalType]);
		self::assertTrue(is_int($this->obj[TIPTCTags::SupplementalType]));

		$this->obj[TIPTCTags::IPTCPixelHeight] = '45920';
		self::assertEquals(45920, $this->obj[TIPTCTags::IPTCPixelHeight]);
		self::assertTrue(is_int($this->obj[TIPTCTags::IPTCPixelHeight]));

		$this->obj[TIPTCTags::DataCompressionMethod] = '28746065';
		self::assertEquals(28746065, $this->obj[TIPTCTags::DataCompressionMethod]);
		self::assertTrue(is_int($this->obj[TIPTCTags::DataCompressionMethod]));

		$this->obj[TIPTCTags::ServiceIdentifier] = 'new value';
		$this->obj[TIPTCTags::DateSent] = time() - 10;
		$ref = $this->obj[TIPTCTags::EnvelopeNumber] = '12345678';
		self::assertEquals($ref, $this->obj[TIPTCTags::EnvelopeNumber]);

		$this->obj[TIPTCTags::ServiceIdentifier] = 'new value';
		self::assertEquals($ref, $this->obj[TIPTCTags::EnvelopeNumber]);

		$this->obj[TIPTCTags::EnvelopeNumber] = '12345678';
		$this->obj[TIPTCTags::DateSent] = time();
		self::assertEquals($ref, $this->obj[TIPTCTags::EnvelopeNumber]);

		unset($this->obj[TIPTCTags::EnvelopeNumber]);
	}

	public function testWidth()
	{
		self::assertNull($this->obj->getWidth());
		self::assertFalse($this->obj->contains(TIPTCTags::IPTCImageWidth));
		$this->obj->setWidth(55);
		self::assertTrue($this->obj->contains(TIPTCTags::IPTCImageWidth));
		self::assertEquals(55, $this->obj->getWidth());
		$this->obj->setWidth(null);
		self::assertFalse($this->obj->contains(TIPTCTags::IPTCImageWidth));
		self::assertNull($this->obj->getWidth());
	}

	public function testHeight()
	{
		self::assertNull($this->obj->getHeight());
		self::assertFalse($this->obj->contains(TIPTCTags::IPTCImageHeight));
		$this->obj->setHeight(55);
		self::assertTrue($this->obj->contains(TIPTCTags::IPTCImageHeight));
		self::assertEquals(55, $this->obj->getHeight());
		$this->obj->setHeight(null);
		self::assertFalse($this->obj->contains(TIPTCTags::IPTCImageHeight));
		self::assertNull($this->obj->getHeight());
	}

	public function testICCProfile()
	{
		self::assertFalse($this->obj->hasICCProfile());
		self::assertFalse($this->obj->contains(TIPTCTags::ICC_Profile));
		self::assertNull($this->obj->getICCProfile());

		$this->obj->setICCProfile($data = "abcdef0123456789");

		self::assertTrue($this->obj->contains(TIPTCTags::ICC_Profile));
		self::assertTrue($this->obj->hasICCProfile());
		self::assertEquals($data, $this->obj->getICCProfile());

		$this->obj->setICCProfile(null);

		self::assertFalse($this->obj->contains(TIPTCTags::ICC_Profile));
		self::assertFalse($this->obj->hasICCProfile());
		self::assertNull($this->obj->getICCProfile());
	}

	public function testIPTC()
	{
		self::assertTrue($this->obj->hasIPTC());
		self::assertEquals($this->obj, $this->obj->getIPTC());

		$this->obj[TIPTCTags::TimeSent] = $time = time();
		$binary = $this->obj->toBinary();

		self::assertEquals(8, $this->obj->getCount());
		self::assertTrue($this->obj->setIPTC(null));
		self::assertEquals(0, $this->obj->getCount());

		self::assertTrue($this->obj->setIPTC($binary));
		self::assertEquals(8, $this->obj->getCount());
		self::assertEquals(TIPTC::formatIPTCTime($time), $this->obj[TIPTCTags::TimeSent]);

		$iptc = new TIPTC('');
		$iptc[TIPTCTags::DateSent] = $time;

		self::assertTrue($this->obj->setIPTC($iptc));
		self::assertEquals(1, $this->obj->getCount());
		self::assertEquals(TIPTC::formatIPTCDate($time), $this->obj[TIPTCTags::DateSent]);
		self::assertNull($this->obj[TIPTCTags::TimeSent]);
	}


	public function testEXIF()
	{
		self::assertFalse($this->obj->hasEXIF());
		self::assertFalse($this->obj->contains(TIPTCTags::ExifCameraInfo));
		self::assertNull($this->obj->getEXIF());

		self::assertTrue($this->obj->setEXIF($data = "abcdef0123456789"));

		self::assertTrue($this->obj->contains(TIPTCTags::ExifCameraInfo));
		self::assertTrue($this->obj->hasEXIF());
		self::assertEquals($data, $this->obj->getEXIF());

		$this->obj->setEXIF(null);

		self::assertFalse($this->obj->contains(TIPTCTags::ExifCameraInfo));
		self::assertFalse($this->obj->hasEXIF());
		self::assertNull($this->obj->getEXIF());
	}

	public function testXMP()
	{
		self::assertFalse($this->obj->hasXMP());
		self::assertFalse($this->obj->contains(TIPTCTags::ExifCameraInfo));
		self::assertNull($this->obj->getEXIF());

		self::assertTrue($this->obj->setEXIF($data = "abcdef0123456789"));

		self::assertTrue($this->obj->contains(TIPTCTags::ExifCameraInfo));
		self::assertTrue($this->obj->hasEXIF());
		self::assertEquals($data, $this->obj->getEXIF());

		$this->obj->setEXIF(null);

		self::assertFalse($this->obj->contains(TIPTCTags::ExifCameraInfo));
		self::assertFalse($this->obj->hasEXIF());
		self::assertNull($this->obj->getEXIF());
	}

	public function testReadRejectsANonStringSource()
	{
		$block = 42;
		self::assertFalse($this->obj->read($block));
		// The refused source leaves the existing record set alone.
		self::assertEquals(1, $this->obj->getCount());
		self::assertTrue($this->obj->contains(TIPTCTags::CodedCharacterSet));
	}

	public function testReadStopsWhenTheDeclaredSizeOutrunsTheData()
	{
		// The declared length runs past the end of the block: the reader takes the
		// datasets that are there and stops at the end of the data.
		$block = ["\x1C\x02\x05\x00\x04Test", 100, 0];
		self::assertTrue($this->obj->read($block));
		self::assertEquals('Test', $this->obj[TIPTCTags::ObjectName]);
	}

	public function testReadExtendedLengthDataset()
	{
		// The 0x8004 length marker introduces a 32-bit length.
		$block = ["\x1C\x02\x05" . pack('n', 0x8004) . pack('N', 4) . 'Test', null, 0];
		self::assertTrue($this->obj->read($block));
		self::assertEquals('Test', $this->obj[TIPTCTags::ObjectName]);
	}

	public function testReadRejectsMalformedDatasets()
	{
		// Only one of the two length bytes is present.
		$block = ["\x1C\x02\x05\x00", 100, 0];
		self::assertFalse($this->obj->read($block));

		// The 32-bit extended length is cut short.
		$block = ["\x1C\x02\x05" . pack('n', 0x8004) . "\x00\x00", 100, 0];
		self::assertFalse($this->obj->read($block));

		// The length is declared but the value bytes are missing.
		$block = ["\x1C\x02\x05\x00\x04", 100, 0];
		self::assertFalse($this->obj->read($block));

		// Record 9 dataset 99 is not an IIM dataset.
		$block = "\x1C\x09\x63\x00\x00";
		self::assertFalse($this->obj->read($block));
	}

	public function testUnknownTagKeysAreRefused()
	{
		self::assertNull($this->obj['NotATag']);
		self::assertFalse($this->obj->contains('NotATag'));
		self::assertNull($this->obj->remove('NotATag'));
		self::assertEquals('', TIPTC::tagBinary('9#099', 'value'));

		self::expectException(TInvalidDataValueException::class);
		$this->obj['NotATag'] = 'value';
	}

	public function testUnboundedDatasetIsStoredVerbatim()
	{
		// ColorCalibrationMatrix declares no size bounds, so nothing is padded or trimmed.
		$this->obj[TIPTCTags::ColorCalibrationMatrix] = "\x01\x02\x03";
		self::assertSame("\x01\x02\x03", $this->obj[TIPTCTags::ColorCalibrationMatrix]);
	}

	public function testClearPrivateDataRederivesAnInconsistentEnvelopeNumber()
	{
		$iptc = new TIPTC('');
		$iptc[TIPTCTags::ServiceIdentifier] = 'ACME';
		$iptc[TIPTCTags::DateSent] = '20260717';
		$iptc[TIPTCTags::EnvelopeNumber] = '12345678';   // not derived from the pair above
		$iptc[TIPTCTags::City] = 'Oslo';

		// The city goes, the envelope date is replaced, and the envelope number that no
		// longer matches it is re-derived from the scrubbed date.
		self::assertEquals(3, $iptc->clearPrivateData());
		self::assertFalse($iptc->contains(TIPTCTags::City));
		self::assertEquals(TIPTC::ScrubbedDate, $iptc[TIPTCTags::DateSent]);
		self::assertEquals(
			TIPTC::computeEnvelopeNumber('ACME', TIPTC::ScrubbedDate),
			$iptc[TIPTCTags::EnvelopeNumber],
		);

		// A second scrub has nothing left to do.
		self::assertEquals(0, $iptc->clearPrivateData());
	}

	public function testRasterizedCaptionImageGuards()
	{
		self::assertNull($this->obj->getRasterizedCaptionImage());

		// A non-string value skips the dataset's size fixing, so the payload is not the
		// mandated 460x128 bitmap and the caption is reported as malformed.
		$this->obj[TIPTCTags::RasterizedCaption] = 12345;
		self::assertFalse($this->obj->getRasterizedCaptionImage());
	}

	/**
	 * Runs a callable with a stand-in graphics library registered for the GD mode.
	 * @param IImageGraphicsLibrary $library The stand-in library.
	 * @param callable $callback The code to run.
	 */
	private function withGraphicsLibrary(IImageGraphicsLibrary $library, callable $callback): void
	{
		$property = new ReflectionProperty(TImageGraphics::class, '_libraries');
		$property->setAccessible(true);
		$saved = $property->getValue();
		$property->setValue(null, [TImageGraphicsMode::GD => $library] + $saved);
		try {
			$callback();
		} finally {
			$property->setValue(null, $saved);
		}
	}

	public function testSetRasterizedCaptionImageReportsGraphicsFailures()
	{
		$source = imagecreatetruecolor(46, 13);

		$noResample = $this->createMock(IImageGraphicsLibrary::class);
		$noResample->method('resampled')->willReturn(false);
		$this->withGraphicsLibrary($noResample, function () use ($source) {
			self::assertFalse($this->obj->setRasterizedCaptionImage($source));
		});
		self::assertFalse($this->obj->contains(TIPTCTags::RasterizedCaption));

		$noMono = $this->createMock(IImageGraphicsLibrary::class);
		$noMono->method('resampled')->willReturn(imagecreatetruecolor(460, 128));
		$noMono->method('monoPixels')->willReturn(false);
		$this->withGraphicsLibrary($noMono, function () use ($source) {
			self::assertFalse($this->obj->setRasterizedCaptionImage($source));
		});
		self::assertFalse($this->obj->contains(TIPTCTags::RasterizedCaption));

		imagedestroy($source);
	}
}
