<?php

use Prado\IO\Image\TPhotoshop8BIM;

class TPhotoshop8BIMTest extends PHPUnit\Framework\TestCase
{
	public function testIsPhotoshop_String()
	{
		$data = null;
		self::assertFalse(TPhotoshop8BIM::isPhotoshop($data));
		$data = false;
		self::assertFalse(TPhotoshop8BIM::isPhotoshop($data));

		$data = "AdobeIllustrator";
		self::assertFalse(TPhotoshop8BIM::isPhotoshop($data));
		$data = "Photoshop 3.0\0";
		self::assertTrue(TPhotoshop8BIM::isPhotoshop($data));
	}

	public function testIsPhotoshop_Stream()
	{
		$mem = fopen('php://memory', 'w+b');
		fwrite($mem, "AdobePhotoshop 3.0\0");
		rewind($mem);

		self::assertFalse(TPhotoshop8BIM::isPhotoshop($mem));
		self::assertEquals(0, ftell($mem));
		fread($mem, 5);
		self::assertTrue(TPhotoshop8BIM::isPhotoshop($mem));
		self::assertEquals(5, ftell($mem));
		fclose($mem);
	}

	public function testIsPhotoshop_NotAStringOrStream()
	{
		$data = 42;
		self::expectException(Prado\Exceptions\TInvalidDataTypeException::class);
		TPhotoshop8BIM::isPhotoshop($data);
	}

	public function testIPTCEncode()
	{
		$data = '';
		self::assertEquals("Photoshop 3.0\08BIM\x04\x04\0\0\0\0\0\0", TPhotoshop8BIM::iptcEncode($data));

		$data = 'X';
		self::assertEquals("Photoshop 3.0\08BIM\x04\x04A\0\0\0\0\1X", TPhotoshop8BIM::iptcEncode($data, 'A'));

		$data = 'XY';
		self::assertEquals("Photoshop 3.0\08BIM\x04\x04AB\0\0\0\0\0\2XY", TPhotoshop8BIM::iptcEncode($data, 'AB'));

		$data = 'XYZ';
		self::assertEquals("Photoshop 3.0\08BIM\x04\x04ABC\0\0\0\0\3XYZ", TPhotoshop8BIM::iptcEncode($data, 'ABC'));
	}

	public function testIPTCDecode_String()
	{
		$data = "Photo";
		self::assertNull(TPhotoshop8BIM::iptcDecode($data));

		$data = "Photoshop 3.0\07BIM";
		self::assertFalse(TPhotoshop8BIM::iptcDecode($data));

		$data = "Photoshop 3.0\08BIM";
		self::assertFalse(TPhotoshop8BIM::iptcDecode($data));

		$data = "Photoshop 3.0abcdefghijklmnopqrstuvwxyz\08BIM\x04\x04\0\0\0\0\0\0";
		;
		self::assertFalse(TPhotoshop8BIM::iptcDecode($data));

		$data = "Photoshop 3.0\08BIM\x04\x04\0\0\0";
		self::assertFalse(TPhotoshop8BIM::iptcDecode($data));


		$data = "Photoshop 3.0\08BIM\x04\x04\0\0\0\0\0\0";
		self::assertEquals(0, TPhotoshop8BIM::iptcDecode($data));
		self::assertEquals('', $data);

		$data = "Photoshop 3.0\08BIM\x04\x04A\0\0\0\0\1X";
		self::assertEquals(1, TPhotoshop8BIM::iptcDecode($data));
		self::assertEquals('X', $data);

		$data = "Photoshop 3.0\08BIM\x04\x04AB\0\0\0\0\0\2XY";
		self::assertEquals(2, TPhotoshop8BIM::iptcDecode($data));
		self::assertEquals('XY', $data);

		$data = "Photoshop 3.0\08BIM\x04\x04ABC\0\0\0\0\3XYZ";
		self::assertEquals(3, TPhotoshop8BIM::iptcDecode($data));
		self::assertEquals('XYZ', $data);
	}

	public function testIPTCDecode_StringUnterminatedName()
	{
		// The resource name is never terminated.
		$data = "Photoshop 3.0\08BIM\x04\x04" . str_repeat('N', 300);
		self::assertFalse(TPhotoshop8BIM::iptcDecode($data));

		// The name terminates, but only past the 256-byte name limit.
		$data = "Photoshop 3.0\08BIM\x04\x04" . str_repeat('N', 300) . "\0\0\0\0\0";
		self::assertFalse(TPhotoshop8BIM::iptcDecode($data));
	}

	protected static function putDataInStream(mixed $stream, string $data)
	{
		rewind($stream);
		ftruncate($stream, 0);
		fwrite($stream, $data);
		rewind($stream);
	}

	public function testIPTCDecode_Stream()
	{
		$mem = fopen('php://memory', 'w+b');

		self::putDataInStream($mem, "Photo");
		self::assertFalse(TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(0, ftell($mem));

		self::putDataInStream($mem, "Photoshop 3.0abcdefghijklmnopqrstuvwxyz\08BIM\x04\x04\0\0\0\0\0\0");
		self::assertFalse(TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(0, ftell($mem));

		self::putDataInStream($mem, "Photoshop 3.0\07BIM");
		self::assertFalse(TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(0, ftell($mem));

		self::putDataInStream($mem, "Photoshop 3.0\08BIM");
		self::assertFalse(TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(0, ftell($mem));

		self::putDataInStream($mem, "Photoshop 3.0\08BIM\x04\x04\0\0\0");
		self::assertFalse(TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(0, ftell($mem));


		self::putDataInStream($mem, "Photoshop 3.0\08BIM\x04\x04\0\0\0\0\0\0");
		self::assertEquals(0, TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(26, ftell($mem));

		self::putDataInStream($mem, "Photoshop 3.0\08BIM\x04\x04A\0\0\0\0\1X");
		self::assertEquals(1, $size = TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(26, ftell($mem));
		self::assertEquals('X', fread($mem, $size));

		self::putDataInStream($mem, "Photoshop 3.0\08BIM\x04\x04AB\0\0\0\0\0\2XY");
		self::assertEquals(2, $size = TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(28, ftell($mem));
		self::assertEquals('XY', fread($mem, $size));

		self::putDataInStream($mem, "Photoshop 3.0\08BIM\x04\x04\0\0\0\0\0\3XYZ");
		self::assertEquals(3, $size = TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(26, ftell($mem));
		self::assertEquals('XYZ', fread($mem, $size));

		self::putDataInStream($mem, "Photoshop 3.0\08BIM\x04\x04ABC\0\0\0\0\3XYZ");
		self::assertEquals(3, $size = TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(28, ftell($mem));
		self::assertEquals('XYZ', fread($mem, $size));

		fclose($mem);
	}

	public function testIPTCDecode_StreamMalformedName()
	{
		$mem = fopen('php://memory', 'w+b');

		// A resource name running past the 256-byte limit leaves the stream where it was.
		self::putDataInStream($mem, "Photoshop 3.0\08BIM\x04\x04" . str_repeat('N', 300) . "\0\0\0\0\0");
		self::assertFalse(TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(0, ftell($mem));

		// The pad byte after the odd-length empty name is missing.
		self::putDataInStream($mem, "Photoshop 3.0\08BIM\x04\x04\0");
		self::assertFalse(TPhotoshop8BIM::iptcDecode($mem));
		self::assertEquals(0, ftell($mem));

		fclose($mem);
	}
}
