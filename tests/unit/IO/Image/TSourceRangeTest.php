<?php

use Prado\IO\Image\TSourceRange;
use Prado\IO\TStream;

/**
 * Unit tests for {@see TSourceRange}, a deferred `[offset, length]` window into a still-open
 * source stream that copies straight to a target.
 */
class TSourceRangeTest extends PHPUnit\Framework\TestCase
{
	public function testWriteToCopiesTheWindowToAStream()
	{
		$range = new TSourceRange(TStream::fromString('HEADER' . 'PAYLOAD' . 'TRAILER'), 6, 7);
		self::assertSame(7, $range->getLength());

		$target = TStream::fromMemory();
		self::assertSame(7, $range->writeTo($target));
		$target->rewind();
		self::assertSame('PAYLOAD', $target->getContents());
	}

	public function testWriteToAcceptsAResourceTarget()
	{
		$range = new TSourceRange(TStream::fromString('....abcXYZ....'), 7, 3);
		$target = fopen('php://temp', 'r+b');
		self::assertSame(3, $range->writeTo($target));
		rewind($target);
		self::assertSame('XYZ', (string) stream_get_contents($target));
		fclose($target);
	}

	public function testWriteToRejectsAnInvalidTarget()
	{
		$this->expectException(\Prado\Exceptions\TInvalidDataTypeException::class);
		(new TSourceRange(TStream::fromString('abc'), 0, 3))->writeTo('not a stream');
	}

	public function testReadMaterializesTheWindow()
	{
		$range = new TSourceRange(TStream::fromString('....abcXYZ....'), 7, 3);
		self::assertSame('XYZ', $range->read());
	}
}
