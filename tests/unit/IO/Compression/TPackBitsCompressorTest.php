<?php

use Prado\IO\Compression\TPackBitsCompressor;

class TPackBitsCompressorTest extends PHPUnit\Framework\TestCase
{
	public function testEmpty()
	{
		self::assertSame('', TPackBitsCompressor::compress(''));
		self::assertSame('', TPackBitsCompressor::decompress(''));
	}

	public function testKnownRepeat()
	{
		// 3x 'A' -> header (257-3)=254 then 'A'
		self::assertSame(chr(254) . 'A', TPackBitsCompressor::compress('AAA'));
		self::assertSame('AAA', TPackBitsCompressor::decompress(chr(254) . 'A'));
	}

	public function testKnownLiteral()
	{
		self::assertSame(chr(2) . 'ABC', TPackBitsCompressor::compress('ABC'));
		self::assertSame('ABC', TPackBitsCompressor::decompress(chr(2) . 'ABC'));
	}

	public function testNoOpHeaderSkipped()
	{
		self::assertSame('', TPackBitsCompressor::decompress(chr(128)));
	}

	public function testRoundTripMixed()
	{
		$data = 'AAAAABCDEEEEEEFG' . str_repeat('Z', 300) . random_bytes(500);
		self::assertSame($data, TPackBitsCompressor::decompress(TPackBitsCompressor::compress($data)));
	}

	public function testRoundTripAllBytes()
	{
		$data = '';
		for ($i = 0; $i < 256; $i++) {
			$data .= str_repeat(chr($i), $i % 5 + 1);
		}
		self::assertSame($data, TPackBitsCompressor::decompress(TPackBitsCompressor::compress($data)));
	}

	public function testRunLongerThanMaxSplits()
	{
		$data = str_repeat('Q', 300);   // > 128, must split into multiple repeat packets
		self::assertSame($data, TPackBitsCompressor::decompress(TPackBitsCompressor::compress($data)));
	}
}
