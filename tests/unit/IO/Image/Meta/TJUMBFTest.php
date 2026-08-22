<?php

use Prado\IO\Image\Meta\JUMBF\TJUMBFBox;
use Prado\IO\Image\Meta\JUMBF\TJUMBFDescription;
use Prado\IO\Image\TJPEG;
use Prado\IO\TStream;

class TJUMBFTest extends PHPUnit\Framework\TestCase
{
	private function jpegBytes(): string
	{
		$im = imagecreatetruecolor(12, 9);
		ob_start();
		imagejpeg($im);
		imagedestroy($im);
		return ob_get_clean();
	}

	public function testDescriptionRoundTripAndUuids()
	{
		// The reserved content-type UUID pattern: type bytes then the fixed suffix.
		self::assertSame("Exif\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71", TJUMBFDescription::ExifUuid);
		self::assertSame(TJUMBFDescription::XmlUuid, TJUMBFDescription::typeUuid('xml '));
		self::assertSame(TJUMBFDescription::JsonUuid, TJUMBFDescription::typeUuid('json'));

		$description = new TJUMBFDescription(TJUMBFDescription::ExifUuid, 'exif-annotation');
		// Label present and requestable, per the spec's 3.H flag group.
		self::assertSame(0x03, $description->getToggles());

		$reparsed = TJUMBFDescription::parse($description->toBinary());
		self::assertNotFalse($reparsed);
		self::assertSame(TJUMBFDescription::ExifUuid, $reparsed->getUuid());
		self::assertSame('Exif', $reparsed->getUuidType());
		self::assertSame('exif-annotation', $reparsed->getLabel());
		self::assertNull($reparsed->getId());
		self::assertNull($reparsed->getSignature());

		// Optional id and signature toggle on and round-trip.
		$description->setId(42);
		$description->setSignature(str_repeat("\xC3", 32));
		self::assertSame(0x0F, $description->getToggles());
		$full = TJUMBFDescription::parse($description->toBinary());
		self::assertSame(42, $full->getId());
		self::assertSame(str_repeat("\xC3", 32), $full->getSignature());

		$description->setLabel(null);
		self::assertNull(TJUMBFDescription::parse($description->toBinary())->getLabel());
		self::assertFalse(TJUMBFDescription::parse('too short'));

		$description->setUuid(TJUMBFDescription::JsonUuid);
		self::assertSame('json', $description->getUuidType());
		$description->setToggles(TJUMBFDescription::RequestableToggle);
		self::assertSame(TJUMBFDescription::RequestableToggle, $description->getToggles());
		self::assertNull((new TJUMBFDescription(str_repeat("\x11", 16)))->getUuidType());
	}

	public function testBoxMutators()
	{
		$box = new TJUMBFBox(TJUMBFBox::SuperBox);
		self::assertSame([], $box->getChildren());
		$box->addChild(new TJUMBFBox(TJUMBFBox::DescriptionBox, (new TJUMBFDescription(TJUMBFDescription::XmlUuid, 'l'))->toBinary()));
		$content = new TJUMBFBox(TJUMBFBox::CborBox, 'raw');
		$content->setType(TJUMBFBox::XmlBox);
		$box->addChild($content);
		self::assertCount(2, $box->getChildren());
		self::assertSame(TJUMBFBox::XmlBox, $box->getContentType());
		self::assertSame('raw', TJUMBFBox::parse($box->toBinary())->getContentData());
	}

	public function testBoxStructureRoundTrip()
	{
		$box = TJUMBFBox::xml('exif-annotation', '<rdf:RDF>annotation</rdf:RDF>');
		$bytes = $box->toBinary();

		// LBox/TBox framing: 'jumb' superbox whose first child is the 'jumd' description.
		self::assertSame(strlen($bytes), unpack('N', substr($bytes, 0, 4))[1]);
		self::assertSame(TJUMBFBox::SuperBox, substr($bytes, 4, 4));
		self::assertSame(TJUMBFBox::DescriptionBox, substr($bytes, 12, 4));

		$reparsed = TJUMBFBox::parse($bytes);
		self::assertNotFalse($reparsed);
		self::assertTrue($reparsed->getIsSuperBox());
		self::assertSame('exif-annotation', $reparsed->getLabel());
		self::assertSame(TJUMBFBox::XmlBox, $reparsed->getContentType());
		self::assertSame('<rdf:RDF>annotation</rdf:RDF>', $reparsed->getContentData());
		self::assertCount(1, $reparsed->getContentBoxes());
		self::assertSame(bin2hex($bytes), bin2hex($reparsed->toBinary()));
	}

	public function testJsonAndExifAnnotationBuilders()
	{
		$json = TJUMBFBox::json('ld', '{"@context":"x"}');
		self::assertSame(TJUMBFBox::JsonBox, $json->getContentType());
		self::assertSame(TJUMBFDescription::JsonUuid, $json->getDescription()->getUuid());

		$exif = TJUMBFBox::exifAnnotation('exif-note', '{"a":1}', TJUMBFBox::JsonBox);
		self::assertSame(TJUMBFDescription::ExifUuid, $exif->getDescription()->getUuid());
		self::assertSame('{"a":1}', $exif->getContentData());
	}

	public function testNestedSuperBoxesAndSequences()
	{
		$inner = TJUMBFBox::xml('inner', '<a/>');
		$outer = TJUMBFBox::superBox(new TJUMBFDescription(TJUMBFDescription::typeUuid('jumb'), 'outer'), [$inner]);

		$reparsed = TJUMBFBox::parse($outer->toBinary());
		self::assertSame('outer', $reparsed->getLabel());
		$nested = $reparsed->getContentBoxes()[0];
		self::assertTrue($nested->getIsSuperBox());
		self::assertSame('inner', $nested->getLabel());
		self::assertSame('<a/>', $nested->getContentData());

		// Several boxes in sequence parse as a list.
		$sequence = $inner->toBinary() . TJUMBFBox::json('two', '{}')->toBinary();
		$boxes = TJUMBFBox::parseBoxes($sequence);
		self::assertCount(2, $boxes);
		self::assertSame(['inner', 'two'], array_map(fn ($b) => $b->getLabel(), $boxes));
	}

	public function testMalformedBoxesAreTolerated()
	{
		self::assertSame([], TJUMBFBox::parseBoxes('tiny'));
		self::assertFalse(TJUMBFBox::parse(''));
		// A length running past the data stops the walk rather than throwing.
		self::assertSame([], TJUMBFBox::parseBoxes(pack('N', 9999) . 'jumb' . 'short'));
		// A zero length means "to the end of the data".
		$open = TJUMBFBox::parseBoxes(pack('N', 0) . 'json' . '{"a":1}');
		self::assertCount(1, $open);
		self::assertSame('{"a":1}', $open[0]->getPayload());
	}

	public function testExtendedLengthBoxes()
	{
		// LBox == 1 moves the length into the 64-bit XLBox that follows the type.
		$payload = '{"big":true}';
		$bytes = pack('N', 1) . TJUMBFBox::JsonBox . pack('NN', 0, 16 + strlen($payload)) . $payload;

		$boxes = TJUMBFBox::parseBoxes($bytes);
		self::assertCount(1, $boxes);
		self::assertSame(TJUMBFBox::JsonBox, $boxes[0]->getType());
		self::assertSame($payload, $boxes[0]->getPayload());

		// A box announcing the extended length without room for it stops the walk.
		self::assertSame([], TJUMBFBox::parseBoxes(pack('N', 1) . TJUMBFBox::JsonBox . pack('N', 0)));
		// And an extended length running past the data is rejected like a plain one.
		self::assertSame([], TJUMBFBox::parseBoxes(pack('N', 1) . TJUMBFBox::JsonBox . pack('NN', 0, 9999) . $payload));
	}

	public function testDescriptionlessSuperBoxAndPlainBoxData()
	{
		// A superbox whose children carry no 'jumd' has no description to report.
		$box = new TJUMBFBox(TJUMBFBox::SuperBox, '', [new TJUMBFBox(TJUMBFBox::XmlBox, '<a/>')]);
		self::assertNull($box->getDescription());
		self::assertNull($box->getLabel());
		self::assertSame('<a/>', $box->getContentData());
		self::assertNull(TJUMBFBox::parse($box->toBinary())->getLabel());

		// A content box outside a superbox answers with its own payload.
		$plain = new TJUMBFBox(TJUMBFBox::XmlBox, '<b/>');
		self::assertFalse($plain->getIsSuperBox());
		self::assertSame('<b/>', $plain->getContentData());

		// A superbox with no children at all has no description either.
		self::assertNull((new TJUMBFBox(TJUMBFBox::SuperBox))->getDescription());
	}

	public function testDescriptionTogglesFollowTheOptionalFields()
	{
		// A label, id, or signature set on a description raises its toggle; clearing one
		// lowers it, and the packed payload follows.
		$description = new TJUMBFDescription(TJUMBFDescription::XmlUuid);
		self::assertSame(TJUMBFDescription::RequestableToggle, $description->getToggles());

		$description->setLabel('added-later');
		self::assertSame(
			TJUMBFDescription::RequestableToggle | TJUMBFDescription::LabelToggle,
			$description->getToggles(),
		);
		self::assertSame('added-later', TJUMBFDescription::parse($description->toBinary())->getLabel());

		$description->setId(7);
		$description->setSignature(str_repeat("\x5A", 32));
		self::assertSame(0x0F, $description->getToggles());

		$description->setId(null);
		self::assertSame(0x0B, $description->getToggles());
		$description->setSignature(null);
		self::assertSame(0x03, $description->getToggles());

		$reparsed = TJUMBFDescription::parse($description->toBinary());
		self::assertSame('added-later', $reparsed->getLabel());
		self::assertNull($reparsed->getId());
		self::assertNull($reparsed->getSignature());
	}

	public function testStreamIo()
	{
		$box = TJUMBFBox::xml('streamed', '<x/>');
		$stream = TStream::fromString('');
		$box->writeTo($stream);
		$stream->rewind();
		self::assertSame('streamed', TJUMBFBox::fromStream($stream)->getLabel());
	}

	public function testJpegApp11RoundTrip()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		self::assertSame([], $jpeg->getJumbfBoxes());

		$jpeg->setJumbfBoxes([
			TJUMBFBox::exifAnnotation('exif-annotation', '<rdf:RDF>first</rdf:RDF>'),
			TJUMBFBox::json('second-label', '{"n":2}'),
		]);

		$reparsed = TJPEG::fromString($jpeg->toBinary());
		$boxes = $reparsed->getJumbfBoxes();
		self::assertCount(2, $boxes);
		self::assertSame('exif-annotation', $boxes[0]->getLabel());
		self::assertSame('<rdf:RDF>first</rdf:RDF>', $boxes[0]->getContentData());
		self::assertSame('{"n":2}', $boxes[1]->getContentData());
		self::assertSame('{"n":2}', $reparsed->getJumbfBox('second-label')->getContentData());
		self::assertNull($reparsed->getJumbfBox('no-such-label'));

		// The segments carry the 'JP' identifier and are true APP11 markers.
		$found = false;
		foreach ($reparsed->getSegments() as $segment) {
			if ($segment['marker'] === TJPEG::APP11) {
				$found = true;
			}
		}
		self::assertTrue($found);

		$reparsed->setJumbfBoxes([]);
		self::assertSame([], TJPEG::fromString($reparsed->toBinary())->getJumbfBoxes());
	}

	public function testJpegApp11SplitsAndReassemblesLargeBoxes()
	{
		// A box larger than one 64 KB segment must fragment and rejoin byte-perfectly.
		$payload = str_repeat('<t>x</t>', 20000);   // 160 KB
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$jpeg->setJumbfBoxes([TJUMBFBox::xml('big', $payload)]);
		$bytes = $jpeg->toBinary();

		$segments = 0;
		foreach (TJPEG::fromString($bytes)->getSegments() as $segment) {
			if ($segment['marker'] === TJPEG::APP11) {
				$segments++;
			}
		}
		self::assertSame(1, $segments);   // one recorded 'jumbf' segment kind

		$reparsed = TJPEG::fromString($bytes);
		$boxes = $reparsed->getJumbfBoxes();
		self::assertCount(1, $boxes);
		self::assertSame('big', $boxes[0]->getLabel());
		self::assertSame(strlen($payload), strlen((string) $boxes[0]->getContentData()));
		self::assertSame(md5($payload), md5((string) $boxes[0]->getContentData()));

		// The composed file really did use several APP11 markers.
		self::assertGreaterThan(2, substr_count($bytes, "\xFF\xEB"));
	}

	public function testJpegKeepsOtherMetadataAlongsideJumbf()
	{
		$jpeg = TJPEG::fromString($this->jpegBytes());
		$exif = new Prado\IO\Image\Meta\TEXIF();
		$exif->setValueByName('Make', 'BoxCam');
		$jpeg->setEXIF($exif);
		$jpeg->setXmpText('<x:xmpmeta xmlns:x="adobe:ns:meta/"/>');
		$jpeg->setJumbfBoxes([TJUMBFBox::xml('note', '<n/>')]);

		$reparsed = TJPEG::fromString($jpeg->toBinary());
		self::assertSame('BoxCam', $reparsed->getEXIF()->getMake());
		self::assertNotNull($reparsed->getXmpText());
		self::assertSame('<n/>', $reparsed->getJumbfBoxes()[0]->getContentData());
		self::assertSame(12, $reparsed->getWidth());
	}

	public function testSuperBoxWithOnlyADescriptionHasNoContent()
	{
		// A superbox is allowed to carry a description and nothing else.  Asking such a
		// box for its content must answer null, not reach past the end of an empty list.
		$description = new TJUMBFBox();
		$description->setType(TJUMBFBox::DescriptionBox);
		$description->setPayload('json' . "\x03\x00" . "label\x00");
		$box = new TJUMBFBox();
		$box->setType('jumb');
		$box->setChildren([$description]);

		self::assertTrue($box->getIsSuperBox());
		self::assertSame([], $box->getContentBoxes());
		self::assertNull($box->getContentType(), 'no content box, so no content type');
		self::assertNull($box->getContentData(), 'no content box, so no content data');

		// and it survives a round trip in that shape
		$bytes = $box->toBinary();
		$parsed = TJUMBFBox::parse($bytes);
		self::assertNotFalse($parsed);
		self::assertNull($parsed->getContentType());
		self::assertNull($parsed->getContentData());
	}

	public function testConstructorDerivesTogglesFromTheOptionalArguments()
	{
		// Handed the optional fields but no toggles, the constructor derives the byte
		// from what it was given -- each field raising only its own bit.
		$id = new TJUMBFDescription(TJUMBFDescription::JsonUuid, null, null, 0x12345678);
		self::assertSame(
			TJUMBFDescription::RequestableToggle | TJUMBFDescription::IdToggle,
			$id->getToggles(),
		);

		$signature = new TJUMBFDescription(TJUMBFDescription::JsonUuid, null, null, null, str_repeat("\x7E", 32));
		self::assertSame(
			TJUMBFDescription::RequestableToggle | TJUMBFDescription::SignatureToggle,
			$signature->getToggles(),
		);

		// All three together, and the derived toggles really drive what is packed.
		$full = new TJUMBFDescription(TJUMBFDescription::XmlUuid, 'ctor-label', null, 0x12345678, str_repeat("\x7E", 32));
		self::assertSame(0x0F, $full->getToggles());

		$reparsed = TJUMBFDescription::parse($full->toBinary());
		self::assertNotFalse($reparsed);
		self::assertSame('ctor-label', $reparsed->getLabel());
		self::assertSame(0x12345678, $reparsed->getId());
		self::assertSame(str_repeat("\x7E", 32), $reparsed->getSignature());

		// An explicit toggles byte is used as given, whatever the other arguments say.
		$explicit = new TJUMBFDescription(TJUMBFDescription::XmlUuid, 'quiet', 0x01, 9, str_repeat("\x01", 32));
		self::assertSame(0x01, $explicit->getToggles());
		self::assertSame(TJUMBFDescription::XmlUuid . "\x01", $explicit->toBinary());
	}

	public function testUnterminatedLabelRunsToTheEndOfThePayload()
	{
		// A writer that left the label's NUL off: the label is everything that remains,
		// and the read position lands at the end, so the id its toggle promises has no
		// room left and is reported absent rather than read out of the label's bytes.
		$toggles = TJUMBFDescription::RequestableToggle | TJUMBFDescription::LabelToggle | TJUMBFDescription::IdToggle;
		$payload = TJUMBFDescription::XmlUuid . chr($toggles) . 'unterminated';

		$description = TJUMBFDescription::parse($payload);
		self::assertNotFalse($description);
		self::assertSame('unterminated', $description->getLabel());
		self::assertNull($description->getId());
		self::assertNull($description->getSignature());

		// Composing it back supplies the terminator the payload was missing.
		self::assertSame(
			TJUMBFDescription::XmlUuid . chr($toggles) . "unterminated\0",
			$description->toBinary(),
		);
	}

	public function testUnparsableDescriptionBoxLeavesTheBoxWithoutADescription()
	{
		// A 'jumd' child too short to hold the UUID and toggles is not a description:
		// the superbox reports none rather than a half-read one, and its content is
		// still readable.
		self::assertFalse(TJUMBFDescription::parse('short'));

		$box = new TJUMBFBox(TJUMBFBox::SuperBox, '', [
			new TJUMBFBox(TJUMBFBox::DescriptionBox, 'short'),
			new TJUMBFBox(TJUMBFBox::XmlBox, '<a/>'),
		]);
		self::assertNull($box->getDescription());
		self::assertNull($box->getLabel());
		self::assertSame('<a/>', $box->getContentData());

		$parsed = TJUMBFBox::parse($box->toBinary());
		self::assertNotFalse($parsed);
		self::assertNull($parsed->getDescription());
		self::assertSame('<a/>', $parsed->getContentData());
	}
}
