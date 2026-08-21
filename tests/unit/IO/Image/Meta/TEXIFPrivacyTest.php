<?php

use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\TPrivacyCategory;
use Prado\IO\Image\Meta\TEXIFTags;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TJPEG;

/**
 * TEXIF::clearPrivateData(): removing identifying information by TPrivacyCategory category.
 * Every flag must remove exactly its category and nothing else, and the picture-describing
 * fields (exposure, colour, dimensions) must always survive.
 */
class TEXIFPrivacyTest extends PHPUnit\Framework\TestCase
{
	/**
	 * An EXIF carrying every identifying category plus a few non-identifying picture
	 * fields, reparsed so it is a realistic on-disk block (pinned maker note, real IFDs).
	 */
	private function loaded(): TEXIF
	{
		$exif = new TEXIF();
		foreach ([
			'Make' => 'TestCam', 'Model' => 'ZZ-1', 'LensModel' => '50mm f/1.8',
			'Software' => 'Editor 1.0', 'HostComputer' => 'janes-laptop',
			'Artist' => 'Jane Doe', 'Copyright' => '(c) Jane Doe', 'CameraOwnerName' => 'Jane',
			'ImageDescription' => 'At home with the kids', 'UserComment' => 'birthday',
			'DateTime' => '2026:01:01 12:00:00', 'DateTimeOriginal' => '2026:01:01 12:00:00', 'SubSecTimeOriginal' => '123',
			'BodySerialNumber' => 'SN-000123', 'LensSerialNumber' => 'LS-999', 'ImageUniqueID' => 'deadbeef',
			// non-identifying picture fields that must survive every scrub
			'FNumber' => 2.8, 'ExposureTime' => 0.008, 'ISOSpeedRatings' => 400,
		] as $name => $value) {
			$exif->setValueByName($name, $value);
		}
		$exif->setLatitude(34.05);
		$exif->setLongitude(-118.24);
		$exif->getExifIfd(true)->setTagValues(TEXIF::MakerNoteTag, TTIFFDataType::Undefined, 'MAKER-PRIVATE-DATA-1234');
		$exif->getInteropIfd(true)->setTagValues(1, TTIFFDataType::Ascii, "R98\0");
		$exif->setThumbnail("\xFF\xD8\xFF\xD9");
		return TEXIF::fromSegment($exif->toBinary());
	}

	/** Reparses after a scrub so the assertion is about what survives composition. */
	private function scrubbed(int $types): TEXIF
	{
		$exif = $this->loaded();
		$exif->clearPrivateData($types);
		return TEXIF::fromSegment($exif->toBinary());
	}

	/** @return array<string, callable(TEXIF): bool> A probe per category: true when the category is still present. */
	private function probes(): array
	{
		return [
			'Location' => fn (TEXIF $e): bool => $e->getLatitude() !== null,
			'Author' => fn (TEXIF $e): bool => $e->getValueByName('Artist') !== null,
			'Description' => fn (TEXIF $e): bool => $e->getValueByName('UserComment') !== null,
			'CameraModel' => fn (TEXIF $e): bool => $e->getValueByName('Make') !== null,
			'SerialNumber' => fn (TEXIF $e): bool => $e->getValueByName('BodySerialNumber') !== null,
			'Timestamp' => fn (TEXIF $e): bool => $e->getValueByName('DateTime') !== null,
			'Software' => fn (TEXIF $e): bool => $e->getValueByName('Software') !== null,
			'MakerNote' => fn (TEXIF $e): bool => $e->getMakernoteData() !== null,
			'Thumbnail' => fn (TEXIF $e): bool => $e->getThumbnail() !== null,
			'Interoperability' => fn (TEXIF $e): bool => $e->getInteropIfd() !== null,
		];
	}

	private function flagFor(string $name): int
	{
		return constant(TPrivacyCategory::class . '::' . $name);
	}

	public function testTheFixtureCarriesEveryCategory()
	{
		$exif = $this->loaded();
		foreach ($this->probes() as $name => $probe) {
			self::assertTrue($probe($exif), "fixture should carry $name");
		}
	}

	public function testEachFlagRemovesExactlyItsCategory()
	{
		foreach ($this->probes() as $name => $probe) {
			$exif = $this->scrubbed($this->flagFor($name));
			self::assertFalse($probe($exif), "$name should be removed by its own flag");
			foreach ($this->probes() as $other => $otherProbe) {
				if ($other !== $name) {
					self::assertTrue($otherProbe($exif), "$name flag must not remove $other");
				}
			}
			// The picture-describing fields always survive.
			self::assertNotNull($exif->getValueByName('FNumber'), "$name must keep FNumber");
			self::assertNotNull($exif->getValueByName('ExposureTime'), "$name must keep ExposureTime");
		}
	}

	public function testDefaultClearsEverythingIdentifying()
	{
		$exif = $this->loaded();
		$removed = $exif->clearPrivateData();   // TPrivacyCategory::All
		self::assertGreaterThan(10, $removed);

		$exif = TEXIF::fromSegment($exif->toBinary());
		foreach ($this->probes() as $name => $probe) {
			self::assertFalse($probe($exif), "All should remove $name");
		}
		// ...and only the identifying data: the photo is still a described photo.
		self::assertNotNull($exif->getValueByName('FNumber'));
		self::assertNotNull($exif->getValueByName('ExposureTime'));
		self::assertNotNull($exif->getValueByName('ISOSpeedRatings'));

		// Every listed tag in every category is really gone, not just the probes.
		foreach (['Model', 'LensModel', 'HostComputer', 'Copyright', 'CameraOwnerName', 'ImageDescription',
			'DateTimeOriginal', 'SubSecTimeOriginal', 'LensSerialNumber', 'ImageUniqueID'] as $name) {
			self::assertNull($exif->getValueByName($name), "$name should be gone");
		}
		self::assertNull($exif->getLongitude());
		self::assertNull($exif->getGpsIfd());
	}

	public function testAllIsEveryBitSet()
	{
		self::assertSame(-1, TPrivacyCategory::All);
		foreach (['Location', 'Author', 'Description', 'CameraModel', 'SerialNumber', 'Timestamp',
			'Software', 'MakerNote', 'Thumbnail', 'Interoperability'] as $name) {
			$flag = $this->flagFor($name);
			self::assertSame($flag, TPrivacyCategory::All & $flag, "All must include $name");
			// One bit per category.
			self::assertSame(1, substr_count(decbin($flag), '1'), "$name must be a single bit");
		}
		// The presets are unions of their parts.
		self::assertSame(TPrivacyCategory::Author | TPrivacyCategory::SerialNumber | TPrivacyCategory::MakerNote, TPrivacyCategory::Identity);
		self::assertSame(TPrivacyCategory::Location | TPrivacyCategory::Timestamp | TPrivacyCategory::Software, TPrivacyCategory::Provenance);
	}

	public function testCategoriesAreDistinctBits()
	{
		$flags = [TPrivacyCategory::Location, TPrivacyCategory::Author, TPrivacyCategory::Description, TPrivacyCategory::CameraModel,
			TPrivacyCategory::SerialNumber, TPrivacyCategory::Timestamp, TPrivacyCategory::Software, TPrivacyCategory::MakerNote,
			TPrivacyCategory::Thumbnail, TPrivacyCategory::Interoperability];
		self::assertCount(count($flags), array_unique($flags), 'no two categories share a bit');
		foreach ($flags as $a) {
			foreach ($flags as $b) {
				if ($a !== $b) {
					self::assertSame(0, $a & $b, 'category bits must not overlap');
				}
			}
		}
	}

	public function testNegationKeepsTheExcludedCategory()
	{
		$exif = $this->scrubbed(TPrivacyCategory::All & ~TPrivacyCategory::CameraModel);
		self::assertSame('TestCam', $exif->getValueByName('Make'));
		self::assertSame('ZZ-1', $exif->getValueByName('Model'));
		self::assertNull($exif->getValueByName('Artist'));
		self::assertNull($exif->getLatitude());
	}

	public function testCombinedFlagsAndPresets()
	{
		$exif = $this->scrubbed(TPrivacyCategory::Location | TPrivacyCategory::Identity);
		self::assertNull($exif->getLatitude());
		self::assertNull($exif->getValueByName('Artist'));
		self::assertNull($exif->getValueByName('BodySerialNumber'));
		self::assertNull($exif->getMakernoteData());
		// Not in the combination: still present.
		self::assertNotNull($exif->getValueByName('DateTime'));
		self::assertNotNull($exif->getValueByName('Make'));

		$exif = $this->scrubbed(TPrivacyCategory::Provenance);
		self::assertNull($exif->getLatitude());
		self::assertNull($exif->getValueByName('DateTime'));
		self::assertNull($exif->getValueByName('Software'));
		self::assertNotNull($exif->getValueByName('Artist'));
	}

	public function testReturnsTheRemovedCountAndIsIdempotent()
	{
		$exif = $this->loaded();
		$first = $exif->clearPrivateData();
		self::assertGreaterThan(0, $first);
		self::assertSame(0, $exif->clearPrivateData(), 'a second scrub finds nothing');
	}

	public function testZeroFlagsRemovesNothing()
	{
		$exif = $this->loaded();
		self::assertSame(0, $exif->clearPrivateData(0));
		foreach ($this->probes() as $name => $probe) {
			self::assertTrue($probe($exif), "0 must not remove $name");
		}
	}

	public function testEmptyAndPartialExifDoNotFail()
	{
		self::assertSame(0, (new TEXIF())->clearPrivateData());

		// A block with only picture fields and no GPS/EXIF sub-IFDs.
		$exif = new TEXIF();
		$exif->setValueByName('FNumber', 4.0);
		self::assertSame(0, $exif->clearPrivateData());
		self::assertNotNull($exif->getValueByName('FNumber'));
	}

	public function testScrubbingNeverCreatesDirectoriesAsASideEffect()
	{
		// An EXIF with only IFD0 fields: clearing must remove from directories that exist,
		// never conjure an empty EXIF/GPS/Interoperability IFD in order to remove from it.
		$exif = new TEXIF();
		$exif->setValueByName('Make', 'X');
		self::assertNull($exif->getExifIfd());
		self::assertNull($exif->getGpsIfd());

		$exif->clearPrivateData();
		self::assertNull($exif->getExifIfd(), 'no EXIF IFD created');
		self::assertNull($exif->getGpsIfd(), 'no GPS IFD created');
		self::assertNull($exif->getInteropIfd(), 'no Interoperability IFD created');
		self::assertCount(1, $exif->getTiff()->getIfds(), 'the IFD chain is unchanged');
		self::assertNull(TEXIF::fromSegment($exif->toBinary())->getValueByName('Make'));
	}

	public function testGpsRemovalDropsTheWholeDirectory()
	{
		$exif = $this->scrubbed(TPrivacyCategory::Location);
		self::assertNull($exif->getGpsIfd());
		self::assertNull($exif->getIfd0()->getTag(34853), 'the GPS pointer tag is gone');
		self::assertNull($exif->getLatitude());
		self::assertNull($exif->getLongitude());
	}

	public function testThumbnailRemovalDropsIfd1Entirely()
	{
		$exif = $this->scrubbed(TPrivacyCategory::Thumbnail);
		self::assertNull($exif->getThumbnail());
		self::assertNull($exif->getThumbnailIfd(), 'IFD1 itself is removed, not just the pointer pair');
	}

	public function testMakerNoteRemovalLeavesTheExifIfdIntact()
	{
		$exif = $this->scrubbed(TPrivacyCategory::MakerNote);
		self::assertNull($exif->getMakernoteData());
		self::assertNull($exif->getExifIfd()?->getTag(TEXIF::MakerNoteTag));
		self::assertNotNull($exif->getExifIfd(), 'the EXIF IFD survives');
		self::assertNotNull($exif->getValueByName('FNumber'));
	}

	public function testScrubbedExifStillEmbedsInAJpeg()
	{
		// The end-to-end use: scrub, write into a container, and read a clean file back.
		$image = imagecreatetruecolor(4, 4);
		ob_start();
		imagejpeg($image);
		$jpeg = TJPEG::fromString((string) ob_get_clean());
		$jpeg->setEXIF($this->loaded());

		$exif = $jpeg->getEXIF();
		$exif->clearPrivateData();
		$jpeg->setEXIF($exif);

		$round = TJPEG::fromString($jpeg->toBinary());
		self::assertNull($round->getEXIF()?->getLatitude());
		self::assertNull($round->getEXIF()?->getValueByName('Artist'));
		self::assertNull($round->getEXIF()?->getMakernoteData());
		self::assertNotNull($round->getEXIF()?->getValueByName('FNumber'));
		self::assertSame([4, 4], [$round->getWidth(), $round->getHeight()]);
	}

	public function testNewIdentifyingTagsAreInTheFactTable()
	{
		// The Windows XP* fields and the TIFF host/document fields were added so the scrub
		// can reach them; they must resolve by name and by id.
		foreach (['XPTitle' => 40091, 'XPComment' => 40092, 'XPAuthor' => 40093, 'XPKeywords' => 40094,
			'XPSubject' => 40095, 'HostComputer' => 316, 'DocumentName' => 269, 'PageName' => 285] as $name => $id) {
			self::assertSame([TEXIFTags::TIFF, $id], TEXIFTags::findByName($name), $name);
			self::assertSame($name, TEXIFTags::nameOf(TEXIFTags::TIFF, $id), "$id");
		}
	}
}
