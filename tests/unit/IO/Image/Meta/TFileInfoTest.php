<?php

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\Meta\TFileInfo;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;
use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TJPEG;
use Prado\IO\Image\TPhotoshopIRB;
use Prado\IO\Image\TPhotoshopResource;

class TFileInfoTest extends PHPUnit\Framework\TestCase
{
	private function jpeg(): TJPEG
	{
		$im = imagecreatetruecolor(16, 12);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		return TJPEG::fromString(ob_get_clean());
	}

	public function testFieldAccessAndValidation()
	{
		$info = new TFileInfo();
		$info['title'] = 'A Title';
		$info['keywords'] = ['one', 'two'];
		self::assertSame('A Title', $info['Title']);   // case-insensitive
		self::assertTrue(isset($info['title']));
		self::assertFalse(isset($info['caption']));
		unset($info['title']);
		self::assertNull($info['title']);

		self::expectException(TInvalidDataValueException::class);
		$info['nosuchfield'] = 'x';
	}

	public function testApplyToWritesAllThreeStores()
	{
		$jpeg = $this->jpeg();
		$info = new TFileInfo();
		$info['title'] = 'Sunset over Bergen';
		$info['author'] = 'A. Photographer';
		$info['caption'] = 'Long evening light.';
		$info['keywords'] = ['sunset', 'norway'];
		$info['city'] = 'Bergen';
		$info['country'] = 'Norway';
		$info['copyrightstatus'] = TFileInfo::Copyrighted;
		$info['copyrightnotice'] = '© 2026 A. Photographer';
		$info['ownerurl'] = 'https://example.org';
		$info['date'] = '2026-07-17';
		$info['urgency'] = '5';
		$info['jobname'] = 'Job 42';
		$info->applyTo($jpeg);

		$reparsed = TJPEG::fromString($jpeg->toBinary());

		// IPTC (inside the IRB)
		$iptc = $reparsed->getIPTC();
		self::assertSame('Sunset over Bergen', $iptc[TIPTCTags::ObjectName]);
		self::assertSame(['A. Photographer'], $iptc[TIPTCTags::ByLine]);
		self::assertSame(['sunset', 'norway'], $iptc[TIPTCTags::Keywords]);
		self::assertSame('Bergen', $iptc[TIPTCTags::City]);
		self::assertSame('20260717', $iptc[TIPTCTags::DateCreated]);
		self::assertSame(5, (int) $iptc[TIPTCTags::Urgency]);

		// XMP
		$xmp = $reparsed->getXMP();
		self::assertSame('Sunset over Bergen', $xmp->getTitle());
		self::assertSame(['A. Photographer'], $xmp->getCreators());
		self::assertSame(['sunset', 'norway'], $xmp->getKeywords());
		self::assertSame('True', $xmp->getProperty($xmp::NS_RIGHTS, 'Marked'));
		self::assertSame('https://example.org', $xmp->getProperty($xmp::NS_RIGHTS, 'WebStatement'));
		self::assertSame(['name' => 'Job 42'], $xmp->getProperty($xmp::NS_BJ, 'JobRef'));

		// EXIF
		self::assertSame('A. Photographer', $reparsed->getEXIF()->getValueByName('Artist'));
		self::assertSame('Long evening light.', $reparsed->getEXIF()->getValueByName('ImageDescription'));

		// IRB
		self::assertTrue($reparsed->getPhotoshopIRB()->getResource(TPhotoshopResource::CopyrightFlag)->decodeBoolean());
		self::assertSame('https://example.org', $reparsed->getPhotoshopIRB()->getResource(TPhotoshopResource::Url)->decodeText());
	}

	public function testFromJpegMergesAcrossStores()
	{
		$jpeg = $this->jpeg();
		$info = new TFileInfo();
		$info['title'] = 'Merged Title';
		$info['author'] = 'Author One';
		$info['keywords'] = ['alpha', 'beta'];
		$info['headline'] = 'The Headline';
		$info['copyrightstatus'] = TFileInfo::PublicDomain;
		$info->applyTo($jpeg);

		$merged = TFileInfo::fromJpeg(TJPEG::fromString($jpeg->toBinary()));
		self::assertSame('Merged Title', $merged['title']);
		self::assertSame('Author One', $merged['author']);
		self::assertSame(['alpha', 'beta'], $merged['keywords']);
		self::assertSame('The Headline', $merged['headline']);
		self::assertSame(TFileInfo::PublicDomain, $merged['copyrightstatus']);
	}

	public function testIptcLengthLimits()
	{
		$jpeg = $this->jpeg();
		$info = new TFileInfo();
		$info['title'] = str_repeat('T', 100);
		$info->applyTo($jpeg);
		$reparsed = TJPEG::fromString($jpeg->toBinary());
		self::assertSame(64, strlen($reparsed->getIPTC()[TIPTCTags::ObjectName]));
		self::assertSame(str_repeat('T', 100), $reparsed->getXMP()->getTitle());   // XMP keeps the full value
	}

	public function testFromJpegReadsIptcDateUrgencyAndTheIrbCopyrightFlag()
	{
		$jpeg = $this->jpeg();
		$iptc = new TIPTC();
		$iptc[TIPTCTags::DateCreated] = '20260717';
		$iptc[TIPTCTags::Urgency] = 5;
		$jpeg->setIPTC($iptc);
		$irb = new TPhotoshopIRB();
		$irb->setResource(new TPhotoshopResource(TPhotoshopResource::CopyrightFlag, "\x01"));
		$jpeg->setPhotoshopIRB($irb);

		// No XMP names any of these, so the IPTC and IRB stores supply them.
		$info = TFileInfo::fromJpeg(TJPEG::fromString($jpeg->toBinary()));
		self::assertSame('2026-07-17', $info['date']);
		self::assertSame('5', $info['urgency']);
		self::assertSame(TFileInfo::Copyrighted, $info['copyrightstatus']);

		// A cleared flag reads as an unknown status, not as public domain.
		$other = $this->jpeg();
		$clearIrb = new TPhotoshopIRB();
		$clearIrb->setResource(new TPhotoshopResource(TPhotoshopResource::CopyrightFlag, "\x00"));
		$other->setPhotoshopIRB($clearIrb);
		self::assertSame(
			TFileInfo::CopyrightUnknown,
			TFileInfo::fromJpeg(TJPEG::fromString($other->toBinary()))['copyrightstatus'],
		);
	}

	public function testFromJpegTakesThePrimaryValueOfArrayXmpProperties()
	{
		$jpeg = $this->jpeg();
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_PHOTOSHOP, 'DateCreated', ['2026-07-17', '2020-01-01'], 'Bag');
		$xmp->setProperty(TXMP::NS_PHOTOSHOP, 'Urgency', ['level' => '5']);   // a structure has no primary string
		$jpeg->setXMP($xmp);

		$info = TFileInfo::fromJpeg(TJPEG::fromString($jpeg->toBinary()));
		self::assertSame('2026-07-17', $info['date']);
		self::assertNull($info['urgency']);
	}

	public function testListValuedFieldsJoinForSingleValuedStores()
	{
		$jpeg = $this->jpeg();
		$info = new TFileInfo();
		$info['title'] = ['First', 'Second'];
		$info->applyTo($jpeg);

		$reparsed = TJPEG::fromString($jpeg->toBinary());
		self::assertSame('First, Second', $reparsed->getXMP()->getTitle());
		self::assertSame('First', $reparsed->getIPTC()[TIPTCTags::ObjectName]);
	}

	public function testInvalidDateThrows()
	{
		$info = new TFileInfo();
		$info['date'] = '17/07/2026';
		self::expectException(TInvalidDataValueException::class);
		$info->applyTo($this->jpeg());
	}
}
