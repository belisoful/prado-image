<?php

use Prado\IO\Image\Meta\TXMP;
use Prado\IO\Image\TJPEG;

class TXMPTest extends PHPUnit\Framework\TestCase
{
	private const SAMPLE = <<<XML
		<x:xmpmeta xmlns:x="adobe:ns:meta/">
		 <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
		  <rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">
		   <dc:title><rdf:Alt><rdf:li xml:lang="x-default">Sunset</rdf:li></rdf:Alt></dc:title>
		   <dc:creator><rdf:Seq><rdf:li>A. Photographer</rdf:li><rdf:li>B. Editor</rdf:li></rdf:Seq></dc:creator>
		   <dc:subject><rdf:Bag><rdf:li>sky</rdf:li><rdf:li>sea</rdf:li></rdf:Bag></dc:subject>
		  </rdf:Description>
		  <rdf:Description rdf:about="" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" photoshop:City="Oslo">
		   <photoshop:Headline>Evening light</photoshop:Headline>
		  </rdf:Description>
		 </rdf:RDF>
		</x:xmpmeta>
		XML;

	public function testParseAndRead()
	{
		$xmp = TXMP::parse(self::SAMPLE);
		self::assertNotFalse($xmp);
		self::assertSame('Sunset', $xmp->getTitle());
		self::assertSame(['A. Photographer', 'B. Editor'], $xmp->getCreators());
		self::assertSame(['sky', 'sea'], $xmp->getKeywords());
		self::assertSame('Oslo', $xmp->getProperty(TXMP::NS_PHOTOSHOP, 'City'));       // attribute form
		self::assertSame('Evening light', $xmp->getProperty(TXMP::NS_PHOTOSHOP, 'Headline'));
		self::assertNull($xmp->getProperty(TXMP::NS_PHOTOSHOP, 'Credit'));
		self::assertTrue($xmp->containsProperty(TXMP::NS_DC, 'title'));
		self::assertFalse($xmp->containsProperty(TXMP::NS_DC, 'rights'));
		self::assertArrayHasKey('dc', $xmp->getNamespaces());
	}

	public function testParseRejectsBadXml()
	{
		self::assertFalse(TXMP::parse('not xml <'));
		self::assertFalse(TXMP::parse('<root>no rdf here</root>'));
	}

	public function testWriteAndRoundTrip()
	{
		$xmp = TXMP::blank();
		$xmp->setTitle('New Title');
		$xmp->setDescription('A caption');
		$xmp->setCreators(['One', 'Two']);
		$xmp->setKeywords(['a', 'b', 'c']);
		$xmp->setRights('© Somebody');
		$xmp->setProperty(TXMP::NS_PHOTOSHOP, 'City', 'Bergen');
		$xmp->setProperty(TXMP::NS_XMP, 'CreatorTool', 'prado-image');

		$packet = $xmp->toPacketText();
		self::assertStringContainsString(TXMP::XPACKET_ID, $packet);
		self::assertStringContainsString('<?xpacket end="w"?>', $packet);

		$reparsed = TXMP::parse($packet);
		self::assertNotFalse($reparsed);
		self::assertSame('New Title', $reparsed->getTitle());
		self::assertSame('A caption', $reparsed->getDescription());
		self::assertSame(['One', 'Two'], $reparsed->getCreators());
		self::assertSame(['a', 'b', 'c'], $reparsed->getKeywords());
		self::assertSame('© Somebody', $reparsed->getRights());
		self::assertSame('Bergen', $reparsed->getProperty(TXMP::NS_PHOTOSHOP, 'City'));
		self::assertSame('prado-image', $reparsed->getProperty(TXMP::NS_XMP, 'CreatorTool'));
	}

	public function testReplaceAndRemove()
	{
		$xmp = TXMP::parse(self::SAMPLE);
		$xmp->setTitle('Replaced');
		self::assertSame('Replaced', $xmp->getTitle());

		$xmp->setProperty(TXMP::NS_PHOTOSHOP, 'City', 'Trondheim');   // replaces the attribute form
		self::assertSame('Trondheim', TXMP::parse($xmp->toXml())->getProperty(TXMP::NS_PHOTOSHOP, 'City'));

		$xmp->setTitle(null);
		self::assertNull($xmp->getTitle());
		$xmp->setKeywords([]);
		self::assertSame([], $xmp->getKeywords());
	}

	public function testStructuredProperty()
	{
		$xmp = TXMP::blank();
		$xmp->setProperty(TXMP::NS_BJ, 'JobRef', ['name' => 'Job A', 'id' => '42']);
		$value = TXMP::parse($xmp->toXml())->getProperty(TXMP::NS_BJ, 'JobRef');
		self::assertSame(['name' => 'Job A', 'id' => '42'], $value);
	}

	public function testAltLanguageMarking()
	{
		$xmp = TXMP::blank();
		$xmp->setTitle('T');
		self::assertStringContainsString('xml:lang="x-default"', $xmp->toXml());
	}

	public function testJpegXmpObjectBridge()
	{
		$im = imagecreatetruecolor(8, 8);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		$jpeg = TJPEG::fromString(ob_get_clean());

		$xmp = TXMP::blank();
		$xmp->setTitle('Bridged');
		$jpeg->setXMP($xmp);

		$reparsed = TJPEG::fromString($jpeg->toBinary());
		self::assertNotNull($reparsed->getXMP());
		self::assertSame('Bridged', $reparsed->getXMP()->getTitle());
	}
}
