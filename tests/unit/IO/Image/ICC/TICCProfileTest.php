<?php

use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\ICC\TICCProfile;
use Prado\IO\Image\ICC\TICCTransform;

class TICCProfileTest extends PHPUnit\Framework\TestCase
{
	public function testParseHeaderFields()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::assertInstanceOf(TICCProfile::class, $profile);

		self::assertSame(strlen(ICCProfileBuilder::sRgb()), $profile->getSize());
		self::assertSame(0x02300000, $profile->getVersion());
		self::assertSame('2.3.0', $profile->getVersionString());
		self::assertSame(TICCProfile::ClassDisplay, $profile->getDeviceClass());
		self::assertSame(TICCProfile::SpaceRgb, $profile->getColorSpace());
		self::assertSame(TICCProfile::SpaceXyz, $profile->getConnectionSpace());
		self::assertSame(TICCProfile::IntentPerceptual, $profile->getRenderingIntent());
		self::assertSame('2026-01-01 00:00:00', $profile->getDateTime());
		self::assertSame('APPL', $profile->getPlatform());
		self::assertSame('    ', $profile->getManufacturer());
		self::assertSame('    ', $profile->getModel());
		self::assertSame('    ', $profile->getCreator());
		self::assertNull($profile->getProfileId());

		$illuminant = $profile->getIlluminant();
		self::assertEqualsWithDelta(0.9642, $illuminant[0], 0.0001);
		self::assertEqualsWithDelta(1.0, $illuminant[1], 0.0001);
		self::assertEqualsWithDelta(0.8249, $illuminant[2], 0.0001);
	}

	public function testUnsetDateAndVersion4()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::build(
			['desc' => ICCProfileBuilder::descTag('No date')],
			['date' => [0, 0, 0, 0, 0, 0], 'version' => 0x04300000],
		));
		self::assertNull($profile->getDateTime());
		self::assertSame('4.3.0', $profile->getVersionString());
	}

	public function testTextTagForms()
	{
		// Version 2 textDescriptionType, version 4 multiLocalizedUnicodeType, and textType.
		self::assertSame('sRGB built for tests', TICCProfile::parse(ICCProfileBuilder::sRgb())->getDescription());
		self::assertSame('Public Domain', TICCProfile::parse(ICCProfileBuilder::sRgb())->getCopyright());
		self::assertSame('Wide gamut built for tests', TICCProfile::parse(ICCProfileBuilder::wideGamut())->getDescription());

		// Non-ASCII survives the UTF-16BE decoding of an mluc tag.
		$profile = TICCProfile::parse(ICCProfileBuilder::build(['desc' => ICCProfileBuilder::mlucTag('Profil couleur — sRGB ✓')]));
		self::assertSame('Profil couleur — sRGB ✓', $profile->getDescription());

		// An absent, empty, or foreign-typed tag reports null rather than guessing.
		self::assertNull(TICCProfile::parse(ICCProfileBuilder::wideGamut())->getCopyright());
		self::assertNull(TICCProfile::parse(ICCProfileBuilder::build(['desc' => ICCProfileBuilder::xyzTag(1, 1, 1)]))->getDescription());
		self::assertNull(TICCProfile::parse(ICCProfileBuilder::build(['desc' => 'mluc' . str_repeat("\0", 12)]))->getDescription());
		self::assertNull(TICCProfile::parse(ICCProfileBuilder::build(['desc' => 'desc' . "\0\0\0\0"]))->getDescription());
		self::assertNull(TICCProfile::parse(ICCProfileBuilder::build(['desc' => 'text']))->getDescription());
	}

	public function testTagAccess()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::assertSame(
			['desc', 'wtpt', 'rXYZ', 'gXYZ', 'bXYZ', 'rTRC', 'gTRC', 'bTRC', 'cprt'],
			$profile->getTagSignatures(),
		);
		self::assertTrue($profile->hasTag('wtpt'));
		self::assertFalse($profile->hasTag('A2B0'));
		self::assertNull($profile->getTag('A2B0'));
		self::assertSame('XYZ ', $profile->getTagType('wtpt'));
		self::assertSame('para', $profile->getTagType('rTRC'));
		self::assertNull($profile->getTagType('A2B0'));
	}

	public function testEditAndRecompose()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());

		$profile->setTag('cprt', ICCProfileBuilder::textTag('Prado License BSD 3 Paragraph'));
		$profile->setTag('desc', null);
		$profile->setTag('tech', ICCProfileBuilder::textTag('CRT '));
		$profile->setRenderingIntent(TICCProfile::IntentRelativeColorimetric);

		$reparsed = TICCProfile::parse($profile->toBinary());
		self::assertInstanceOf(TICCProfile::class, $reparsed);
		self::assertSame('Prado License BSD 3 Paragraph', $reparsed->getCopyright());
		self::assertNull($reparsed->getDescription());
		self::assertFalse($reparsed->hasTag('desc'));
		self::assertTrue($reparsed->hasTag('tech'));
		self::assertSame(TICCProfile::IntentRelativeColorimetric, $reparsed->getRenderingIntent());

		// The recomposed size is declared correctly, and the color math survives.
		self::assertSame(strlen($profile->toBinary()), $reparsed->getSize());
		self::assertTrue($reparsed->getIsMatrixShaper());
	}

	public function testEditingClearsTheProfileId()
	{
		$identified = ICCProfileBuilder::build(
			['desc' => ICCProfileBuilder::descTag('Identified')],
			['id' => str_repeat("\x11", 16)],
		);
		$profile = TICCProfile::parse($identified);
		self::assertSame(str_repeat("\x11", 16), $profile->getProfileId());

		// An untouched profile keeps its digest; an edited one drops it rather than
		// carrying a digest that no longer matches the bytes.
		self::assertSame(str_repeat("\x11", 16), TICCProfile::parse($profile->toBinary())->getProfileId());
		$profile->setTag('cprt', ICCProfileBuilder::textTag('Edited'));
		self::assertNull(TICCProfile::parse($profile->toBinary())->getProfileId());
	}

	public function testRepeatedTagContentSharesOneBlock()
	{
		// The three identical tone curves of a real profile are stored once.
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		$bytes = $profile->toBinary();
		$reparsed = TICCProfile::parse($bytes);
		self::assertSame($profile->getTag('rTRC'), $reparsed->getTag('bTRC'));

		$curve = (string) $profile->getTag('rTRC');
		self::assertSame(1, substr_count($bytes, $curve), 'the shared curve should appear once');
	}

	public function testSetTagRejectsAMalformedSignature()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::expectException(TInvalidDataValueException::class);
		$profile->setTag('toolong', ICCProfileBuilder::textTag('x'));
	}

	public function testSetRenderingIntentRejectsAnUnknownIntent()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		$profile->setRenderingIntent(TICCProfile::IntentAbsoluteColorimetric);
		self::assertSame(3, $profile->getRenderingIntent());

		self::expectException(TInvalidDataValueException::class);
		$profile->setRenderingIntent(4);
	}

	public function testParseRejectsNonProfiles()
	{
		self::assertNull(TICCProfile::parse(''));
		self::assertNull(TICCProfile::parse(str_repeat("\0", 100)));
		// 132 bytes without the 'acsp' signature.
		self::assertNull(TICCProfile::parse(str_repeat("\0", 132)));
		// An absurd tag count, and a count the data cannot cover.
		$profile = ICCProfileBuilder::sRgb();
		self::assertNull(TICCProfile::parse(substr($profile, 0, 128) . pack('N', 0x10000) . substr($profile, 132)));
		self::assertNull(TICCProfile::parse(substr($profile, 0, 128) . pack('N', 500) . substr($profile, 132)));
	}

	public function testTruncatedTagIsDropped()
	{
		// A tag pointing past the end of the data is skipped, leaving the rest readable.
		$profile = TICCProfile::parse(ICCProfileBuilder::build([
			'desc' => ICCProfileBuilder::descTag('Partly readable'),
			'cprt' => ICCProfileBuilder::textTag('Public Domain'),
		]));
		$bytes = $profile->toBinary();
		$broken = substr($bytes, 0, 128 + 4 + 12) . 'cprt' . pack('N', 0xFFFF) . pack('N', 32) . substr($bytes, 128 + 4 + 24);

		$reparsed = TICCProfile::parse($broken);
		self::assertInstanceOf(TICCProfile::class, $reparsed);
		self::assertSame('Partly readable', $reparsed->getDescription());
		self::assertFalse($reparsed->hasTag('cprt'));
	}

	public function testMatrixAndWhitePoint()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::assertTrue($profile->getIsMatrixShaper());
		self::assertFalse($profile->getIsLutBased());

		$white = (array) $profile->getWhitePoint();
		self::assertEqualsWithDelta([0.9642, 1.0, 0.8249], $white, 0.0001);

		// The colorant matrix holds the primaries as columns, so the row sums are the
		// white point the primaries add up to.
		$matrix = (array) $profile->getMatrix();
		self::assertCount(3, $matrix);
		self::assertEqualsWithDelta(0.4360, $matrix[0][0], 0.0001);
		self::assertEqualsWithDelta(0.3851, $matrix[0][1], 0.0001);
		self::assertEqualsWithDelta(0.1431, $matrix[0][2], 0.0001);
		for ($row = 0; $row < 3; $row++) {
			self::assertEqualsWithDelta($white[$row], array_sum($matrix[$row]), 0.001, "row $row");
		}
	}

	public function testLutProfileIsReadButNotMatrixShaped()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::cmykLut());
		self::assertSame(TICCProfile::SpaceCmyk, $profile->getColorSpace());
		self::assertSame(TICCProfile::ClassOutput, $profile->getDeviceClass());
		self::assertSame(TICCProfile::IntentRelativeColorimetric, $profile->getRenderingIntent());
		self::assertTrue($profile->getIsLutBased());
		self::assertFalse($profile->getIsMatrixShaper());
		self::assertNull($profile->getMatrix());
		self::assertNull($profile->getToneCurves());
		self::assertSame('mft2', $profile->getTagType('A2B0'));

		// It still rewrites faithfully: the tables survive untouched.
		$reparsed = TICCProfile::parse($profile->toBinary());
		self::assertSame($profile->getTag('A2B0'), $reparsed->getTag('A2B0'));
	}

	public function testMatrixShaperNeedsEveryTagAndAnRgbSpace()
	{
		// A grayscale profile carries a kTRC, not the three curves.
		$gray = TICCProfile::parse(ICCProfileBuilder::build([
			'wtpt' => ICCProfileBuilder::xyzTag(0.9642, 1.0, 0.8249),
			'kTRC' => ICCProfileBuilder::curvGamma(2.2),
		], ['space' => 'GRAY']));
		self::assertFalse($gray->getIsMatrixShaper());
		self::assertNull($gray->getWhitePoint() === null ? null : $gray->getMatrix());

		// Missing one colorant, and a Lab connection space, both disqualify.
		$partial = TICCProfile::parse(ICCProfileBuilder::build([
			'rXYZ' => ICCProfileBuilder::xyzTag(0.4360, 0.2225, 0.0139),
			'gXYZ' => ICCProfileBuilder::xyzTag(0.3851, 0.7169, 0.0971),
			'rTRC' => ICCProfileBuilder::curvGamma(2.2),
			'gTRC' => ICCProfileBuilder::curvGamma(2.2),
			'bTRC' => ICCProfileBuilder::curvGamma(2.2),
		]));
		self::assertFalse($partial->getIsMatrixShaper());
		self::assertNull($partial->getMatrix());

		$lab = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::assertTrue($lab->getIsMatrixShaper());
		$labBytes = substr($lab->toBinary(), 0, 20) . 'Lab ' . substr($lab->toBinary(), 24);
		self::assertFalse(TICCProfile::parse($labBytes)->getIsMatrixShaper());

		// A malformed colorant tag reports null instead of a partial matrix.
		$malformed = TICCProfile::parse(ICCProfileBuilder::build([
			'rXYZ' => 'XYZ ' . "\0\0\0\0",
			'gXYZ' => ICCProfileBuilder::xyzTag(0.3851, 0.7169, 0.0971),
			'bXYZ' => ICCProfileBuilder::xyzTag(0.1431, 0.0606, 0.7141),
		]));
		self::assertNull($malformed->getMatrix());
		self::assertNull($malformed->getWhitePoint());
	}

	public function testDecodeCurveForms()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::build([
			'rTRC' => ICCProfileBuilder::curvIdentity(),
			'gTRC' => ICCProfileBuilder::curvGamma(2.2),
			'bTRC' => ICCProfileBuilder::curvTable([0.0, 0.25, 1.0]),
			'kTRC' => ICCProfileBuilder::paraCurve(3, [2.4, 1 / 1.055, 0.055 / 1.055, 1 / 12.92, 0.04045]),
		]));

		self::assertSame(['type' => 'identity'], $profile->decodeCurve('rTRC'));

		$gamma = (array) $profile->decodeCurve('gTRC');
		self::assertSame('gamma', $gamma['type']);
		self::assertEqualsWithDelta(2.19921875, $gamma['gamma'], 0.0001);

		$table = (array) $profile->decodeCurve('bTRC');
		self::assertSame('table', $table['type']);
		self::assertEqualsWithDelta([0.0, 0.25, 1.0], $table['samples'], 0.0001);

		$parametric = (array) $profile->decodeCurve('kTRC');
		self::assertSame('parametric', $parametric['type']);
		self::assertSame(3, $parametric['function']);
		self::assertCount(5, $parametric['parameters']);
		self::assertEqualsWithDelta(2.4, $parametric['parameters'][0], 0.0001);

		self::assertNull($profile->decodeCurve('mTRC'));
	}

	public function testDecodeCurveRejectsMalformedCurves()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::build([
			'aTRC' => 'sf32' . "\0\0\0\0" . pack('N', 1),                       // the wrong tag type
			'bTRC' => 'curv' . "\0\0\0\0" . pack('N', 40) . "\0\0\0\0",         // a count the data cannot cover
			'cTRC' => 'para' . "\0\0\0\0" . pack('n', 9) . "\0\0" . pack('N', 0), // an unknown function type
			'dTRC' => 'para' . "\0\0\0\0" . pack('n', 4) . "\0\0" . pack('N', 0), // too few parameters for type 4
			'eTRC' => 'curv' . "\0\0",                                          // truncated before the count
		]));
		foreach (['aTRC', 'bTRC', 'cTRC', 'dTRC', 'eTRC'] as $signature) {
			self::assertNull($profile->decodeCurve($signature), $signature);
		}
	}

	public function testToneCurvesRequireAllThree()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::build([
			'rTRC' => ICCProfileBuilder::curvGamma(2.2),
			'gTRC' => ICCProfileBuilder::curvGamma(2.2),
		]));
		self::assertNull($profile->getToneCurves());

		$curves = (array) TICCProfile::parse(ICCProfileBuilder::wideGamut())->getToneCurves();
		self::assertCount(3, $curves);
		self::assertSame('gamma', $curves[2]['type']);
	}

	public function testNegativeFixedPointValues()
	{
		// s15Fixed16 is signed; a negative colorant must not read as a huge positive.
		$profile = TICCProfile::parse(ICCProfileBuilder::build([
			'rXYZ' => ICCProfileBuilder::xyzTag(-0.25, 0.5, 0.0),
			'gXYZ' => ICCProfileBuilder::xyzTag(0.3851, 0.7169, 0.0971),
			'bXYZ' => ICCProfileBuilder::xyzTag(0.1431, 0.0606, 0.7141),
		]));
		$matrix = (array) $profile->getMatrix();
		self::assertEqualsWithDelta(-0.25, $matrix[0][0], 0.0001);
		self::assertEqualsWithDelta(0.5, $matrix[1][0], 0.0001);
	}

	//
	// ─── The write side: editing a profile rather than replacing it ──────────
	//

	public function testHeaderFieldsAreWritable()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());

		$profile->setCMMType('appl');
		$profile->setVersionString('4.3.0');
		$profile->setDeviceClass(TICCProfile::ClassOutput);
		$profile->setColorSpace(TICCProfile::SpaceCmyk);
		$profile->setConnectionSpace(TICCProfile::SpaceLab);
		$profile->setDateTime('2026-07-30 12:34:56');
		$profile->setPlatform('MSFT');
		$profile->setFlags(0x00000003);
		$profile->setManufacturer('ACME');
		$profile->setModel('MDL1');
		$profile->setAttributes(0x0000000100000002);
		$profile->setRenderingIntent(TICCProfile::IntentSaturation);
		$profile->setIlluminant([0.9642, 1.0, 0.8249]);
		$profile->setCreator('PRDO');

		$round = TICCProfile::parse($profile->toBinary());
		self::assertSame('appl', $round->getCMMType());
		self::assertSame(0x04300000, $round->getVersion());
		self::assertSame('4.3.0', $round->getVersionString());
		self::assertSame([4, 3, 0], [$round->getVersionMajor(), $round->getVersionMinor(), $round->getVersionBugFix()]);
		self::assertSame(TICCProfile::ClassOutput, $round->getDeviceClass());
		self::assertSame(TICCProfile::SpaceCmyk, $round->getColorSpace());
		self::assertSame(TICCProfile::SpaceLab, $round->getConnectionSpace());
		self::assertSame('2026-07-30 12:34:56', $round->getDateTime());
		self::assertSame('MSFT', $round->getPlatform());
		self::assertSame(0x00000003, $round->getFlags());
		self::assertSame('ACME', $round->getManufacturer());
		self::assertSame('MDL1', $round->getModel());
		self::assertSame(0x0000000100000002, $round->getAttributes());
		self::assertSame(TICCProfile::IntentSaturation, $round->getRenderingIntent());
		self::assertSame('PRDO', $round->getCreator());
		self::assertEqualsWithDelta([0.9642, 1.0, 0.8249], $round->getIlluminant(), 0.0001);
	}

	public function testVersionAndDateEdgeCases()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());

		// The minor and bug-fix parts are optional.
		$profile->setVersionString('2');
		self::assertSame('2.0.0', $profile->getVersionString());
		$profile->setVersion(0x02100000);
		self::assertSame('2.1.0', $profile->getVersionString());

		// A DateTimeInterface is taken as given and stored as UTC.
		$profile->setDateTime(new \DateTimeImmutable('2020-02-29 06:07:08', new \DateTimeZone('+02:00')));
		self::assertSame('2020-02-29 04:07:08', $profile->getDateTime());

		$profile->setDateTime(null);
		self::assertNull($profile->getDateTime());

		$signatures = ['a', 'ab', 'abc', 'abcd'];
		foreach ($signatures as $signature) {
			$profile->setCreator($signature);
			self::assertSame(str_pad($signature, 4, ' '), $profile->getCreator(), $signature);
		}
	}

	public function testVersionRejectsNonDottedDecimal()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::expectException(TInvalidDataValueException::class);
		$profile->setVersionString('four point three');
	}

	public function testDateRejectsNonsense()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::expectException(TInvalidDataValueException::class);
		$profile->setDateTime('not a date at all');
	}

	public function testSignatureLongerThanFourIsRejected()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::expectException(TInvalidDataValueException::class);
		$profile->setPlatform('TOOLONG');
	}

	public function testComputeProfileId()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::assertNull($profile->getProfileId());

		$digest = $profile->computeProfileId();
		self::assertSame(16, strlen($digest));
		self::assertSame($digest, $profile->getProfileId());

		// The stored digest matches the bytes: recomputing from the written profile
		// reproduces it, and the id survives the write because it is no longer stale.
		$round = TICCProfile::parse($profile->toBinary());
		self::assertSame(bin2hex($digest), bin2hex((string) $round->getProfileId()));
		self::assertSame(bin2hex($digest), bin2hex($round->computeProfileId()));

		// It is the specification's digest: flags, intent, and the id itself are zeroed.
		$bytes = $round->toBinary();
		$zeroed = substr_replace($bytes, str_repeat("\0", 16), 84, 16);
		$zeroed = substr_replace($zeroed, "\0\0\0\0", 44, 4);
		$zeroed = substr_replace($zeroed, "\0\0\0\0", 64, 4);
		self::assertSame(bin2hex(md5($zeroed, true)), bin2hex($digest));

		// Editing after computing invalidates it again.
		$round->setDescription('Changed');
		self::assertNull(TICCProfile::parse($round->toBinary())->getProfileId());
	}

	public function testTextTagsAreWritable()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());

		// An existing tag keeps its type: the sRGB fixture's desc is a version 2 desc.
		self::assertSame('desc', $profile->getTagType('desc'));
		$profile->setDescription('An edited description');
		self::assertSame('desc', $profile->getTagType('desc'));
		self::assertSame('An edited description', $profile->getDescription());

		$profile->setCopyright('Prado License BSD 3 Paragraph');
		self::assertSame('Prado License BSD 3 Paragraph', $profile->getCopyright());

		// A forced type is honored, and each decodes back.
		foreach (['text', 'desc', 'mluc'] as $type) {
			$profile->setTagText('cprt', "Copyright $type", $type);
			self::assertSame($type, $profile->getTagType('cprt'), $type);
			self::assertSame("Copyright $type", $profile->getCopyright(), $type);
		}

		// A version 2 desc holds 7-bit ASCII, so other characters become '?' -- one per
		// character, not one per byte.
		$profile->setTagText('desc', 'Prófîl — ✓', 'desc');
		self::assertSame('Pr?f?l ? ?', $profile->getDescription());

		// A mluc carries the text intact.
		$profile->setTagText('desc', 'Prófîl — ✓', 'mluc');
		self::assertSame('Prófîl — ✓', $profile->getDescription());

		$round = TICCProfile::parse($profile->toBinary());
		self::assertSame('Prófîl — ✓', $round->getDescription());
	}

	public function testTextTagRejectsAnUnknownType()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::expectException(TInvalidDataValueException::class);
		$profile->setTagText('desc', 'x', 'sig ');
	}

	public function testLocalizedTexts()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		$texts = ['enUS' => 'Colour profile', 'frFR' => 'Profil couleur', 'jaJP' => 'カラープロファイル'];
		$profile->setLocalizedTexts('desc', $texts);

		$round = TICCProfile::parse($profile->toBinary());
		self::assertSame($texts, $round->getLocalizedTexts('desc'));
		// The English record is the one a plain read prefers.
		self::assertSame('Colour profile', $round->getDescription());

		// Astral characters survive the surrogate pairing both ways.
		$profile->setLocalizedTexts('desc', ['enUS' => 'Emoji 🎨 profile']);
		self::assertSame('Emoji 🎨 profile', TICCProfile::parse($profile->toBinary())->getDescription());

		// A tag of another type is not a localized text.
		self::assertNull($round->getLocalizedTexts('wtpt'));
		self::assertNull($round->getLocalizedTexts('nope'));
	}

	public function testXyzAndCurveTagsAreWritable()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());

		$profile->setWhitePoint([0.9505, 1.0, 1.0890]);
		self::assertEqualsWithDelta([0.9505, 1.0, 1.0890], (array) $profile->getWhitePoint(), 0.0001);
		self::assertEqualsWithDelta([0.9505, 1.0, 1.0890], (array) $profile->getTagXYZ('wtpt'), 0.0001);

		// A multi-value XYZ tag, and the single-triplet shorthand.
		$profile->setTagXYZ('chrm', [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);
		self::assertCount(2, (array) $profile->getTagXYZAll('chrm'));
		self::assertEqualsWithDelta([0.1, 0.2, 0.3], (array) $profile->getTagXYZ('chrm'), 0.0001);
		self::assertNull($profile->getTagXYZAll('rTRC'));
		self::assertNull($profile->getTagXYZ('nope'));

		// Negative values keep their sign through the fixed-point encoding.
		$profile->setTagXYZ('bkpt', [-0.25, 0.5, 0.0]);
		self::assertEqualsWithDelta([-0.25, 0.5, 0.0], (array) $profile->getTagXYZ('bkpt'), 0.0001);

		// Curves in each form.
		$profile->setTagCurve('rTRC', 2.2);
		$gamma = (array) $profile->decodeCurve('rTRC');
		self::assertSame('gamma', $gamma['type']);
		self::assertEqualsWithDelta(2.2, $gamma['gamma'], 0.01);

		$profile->setTagCurve('gTRC', []);
		self::assertSame(['type' => 'identity'], $profile->decodeCurve('gTRC'));

		$profile->setTagCurve('bTRC', [0.0, 0.25, 1.0]);
		$table = (array) $profile->decodeCurve('bTRC');
		self::assertSame('table', $table['type']);
		self::assertEqualsWithDelta([0.0, 0.25, 1.0], $table['samples'], 0.0001);

		$profile->setTagParametricCurve('kTRC', 3, [2.4, 1 / 1.055, 0.055 / 1.055, 1 / 12.92, 0.04045]);
		$parametric = (array) $profile->decodeCurve('kTRC');
		self::assertSame('parametric', $parametric['type']);
		self::assertSame(3, $parametric['function']);
		self::assertEqualsWithDelta(2.4, $parametric['parameters'][0], 0.0001);
	}

	public function testParametricCurveChecksItsParameterCount()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::expectException(TInvalidDataValueException::class);
		$profile->setTagParametricCurve('rTRC', 3, [2.4, 1.0]);   // type 3 takes five
	}

	public function testParametricCurveRejectsAnUnknownFunction()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::expectException(TInvalidDataValueException::class);
		$profile->setTagParametricCurve('rTRC', 9, [1.0]);
	}

	public function testS15Fixed16ArrayTag()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		$chad = [1.0478, 0.0229, -0.0502, 0.0295, 0.9905, -0.0171, -0.0092, 0.0150, 0.7521];
		$profile->setTagS15Fixed16Array('chad', $chad);

		$round = TICCProfile::parse($profile->toBinary());
		self::assertEqualsWithDelta($chad, (array) $round->getTagS15Fixed16Array('chad'), 0.0001);
		self::assertSame('sf32', $round->getTagType('chad'));

		$profile->setTagS15Fixed16Array('chad', []);
		self::assertSame([], $profile->getTagS15Fixed16Array('chad'));
		self::assertNull($profile->getTagS15Fixed16Array('wtpt'));
		self::assertNull($profile->getTagS15Fixed16Array('nope'));
	}

	public function testTagRemovalAliasingAndSharing()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::assertCount(9, $profile->getTags());

		// The sRGB fixture writes one curve into all three channels.
		self::assertTrue($profile->sharesData('rTRC', 'gTRC'));
		self::assertFalse($profile->sharesData('rTRC', 'wtpt'));
		self::assertFalse($profile->sharesData('rTRC', 'nope'));

		$profile->setTagCurve('rTRC', 1.8);
		self::assertFalse($profile->sharesData('rTRC', 'gTRC'));
		$profile->aliasTag('gTRC', 'rTRC');
		$profile->aliasTag('bTRC', 'rTRC');
		self::assertTrue($profile->sharesData('gTRC', 'bTRC'));

		// Shared content is written once.
		$bytes = $profile->toBinary();
		self::assertSame(1, substr_count($bytes, (string) $profile->getTag('rTRC')));

		self::assertTrue($profile->removeTag('cprt'));
		self::assertFalse($profile->removeTag('cprt'));
		self::assertNull($profile->getCopyright());
		self::assertCount(8, $profile->getTags());
	}

	public function testAliasRejectsAnAbsentTarget()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		self::expectException(TInvalidDataValueException::class);
		$profile->aliasTag('gTRC', 'nope');
	}

	public function testMatrixAndToneCurvesAreWritable()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());

		// Adobe RGB's D50-relative primaries, written through the matrix accessor.
		$matrix = [
			[0.6097, 0.2052, 0.1492],
			[0.3111, 0.6257, 0.0632],
			[0.0195, 0.0609, 0.7448],
		];
		$profile->setMatrix($matrix);
		$profile->setToneCurves(2.19921875);

		$round = TICCProfile::parse($profile->toBinary());
		self::assertTrue($round->getIsMatrixShaper());
		foreach ($matrix as $row => $values) {
			self::assertEqualsWithDelta($values, ((array) $round->getMatrix())[$row], 0.0001, "row $row");
		}
		$curves = (array) $round->getToneCurves();
		self::assertCount(3, $curves);
		self::assertSame('gamma', $curves[0]['type']);
		self::assertTrue($round->sharesData('rTRC', 'bTRC'), 'identical curves share one block');

		// Curves that differ per channel go through setTagCurve(), which cannot be
		// confused with one sampled curve.
		$profile->setTagCurve('rTRC', 1.8);
		$profile->setTagCurve('gTRC', 2.0);
		$profile->setTagCurve('bTRC', 2.2);
		$perChannel = (array) TICCProfile::parse($profile->toBinary())->getToneCurves();
		self::assertEqualsWithDelta(1.8, $perChannel[0]['gamma'], 0.01);
		self::assertEqualsWithDelta(2.0, $perChannel[1]['gamma'], 0.01);
		self::assertEqualsWithDelta(2.2, $perChannel[2]['gamma'], 0.01);
		self::assertFalse($profile->sharesData('rTRC', 'gTRC'));

		// The identity and a sampled curve also apply to all three.
		$profile->setToneCurves([]);
		self::assertSame(['type' => 'identity'], $profile->decodeCurve('bTRC'));
	}

	public function testAProfileCanBeBuiltFromNothingAndTransformed()
	{
		// The point of the coder: author a working profile rather than embed a blob.
		$profile = TICCProfile::parse(ICCProfileBuilder::build([]));
		self::assertSame([], $profile->getTagSignatures());
		self::assertFalse($profile->getIsMatrixShaper());

		$profile->setDeviceClass(TICCProfile::ClassDisplay);
		$profile->setColorSpace(TICCProfile::SpaceRgb);
		$profile->setConnectionSpace(TICCProfile::SpaceXyz);
		$profile->setDescription('Authored wide gamut');
		$profile->setCopyright('Public Domain');
		$profile->setWhitePoint([0.9642, 1.0, 0.8249]);
		$profile->setMatrix([
			[0.6097, 0.2052, 0.1492],
			[0.3111, 0.6257, 0.0632],
			[0.0195, 0.0609, 0.7448],
		]);
		$profile->setToneCurves(2.19921875);
		$profile->computeProfileId();

		$round = TICCProfile::parse($profile->toBinary());
		self::assertSame('Authored wide gamut', $round->getDescription());
		self::assertTrue($round->getIsMatrixShaper());
		self::assertNotNull($round->getProfileId());

		// And it drives a real transform: sRGB red lands on the published Adobe RGB value.
		$transform = TICCTransform::between(TICCProfile::parse(ICCProfileBuilder::sRgb()), $round);
		self::assertInstanceOf(TICCTransform::class, $transform);
		$converted = $transform->rgbPixels("\xFF\x00\x00");
		self::assertEqualsWithDelta(219, ord($converted[0]), 2);
		self::assertSame(0, ord($converted[1]));
	}

	public function testLocalizedTextPrefersEnglishAndFallsBackToTheFirstRecord()
	{
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());

		// No English record at all: the first stored record answers.
		$profile->setLocalizedTexts('desc', ['frFR' => 'Profil de test', 'deDE' => 'Testprofil']);
		$reparsed = TICCProfile::parse($profile->toBinary());
		self::assertSame('Profil de test', $reparsed->getDescription());
		self::assertSame(['frFR' => 'Profil de test', 'deDE' => 'Testprofil'], $reparsed->getLocalizedTexts('desc'));

		// An English record is preferred wherever it sits, whatever its region.
		$profile->setLocalizedTexts('desc', ['frFR' => 'Profil de test', 'enGB' => 'Test profile']);
		self::assertSame('Test profile', TICCProfile::parse($profile->toBinary())->getDescription());
	}

	public function testMalformedMlucTagsDecodeToNull()
	{
		// Too short to hold the record count and size.
		$short = TICCProfile::parse(ICCProfileBuilder::build(['desc' => 'mluc' . str_repeat("\0", 8)]));
		self::assertNull($short->getDescription());
		self::assertNull($short->getLocalizedTexts('desc'));

		// One record whose string runs past the end of the tag.
		$overrun = 'mluc' . "\0\0\0\0" . pack('N2', 1, 12) . 'enUS' . pack('N2', 4096, 28) . 'text';
		$profile = TICCProfile::parse(ICCProfileBuilder::build(['desc' => $overrun]));
		self::assertNull($profile->getDescription());
		self::assertNull($profile->getLocalizedTexts('desc'));
	}

	public function testBrokenUnicodeBecomesTheReplacementCharacter()
	{
		// A lone surrogate in the stored UTF-16BE has no counterpart to pair with.
		$lone = 'mluc' . "\0\0\0\0" . pack('N2', 1, 12) . 'enUS' . pack('N2', 2, 28) . "\xD8\x3D";
		self::assertSame(
			"\u{FFFD}",
			TICCProfile::parse(ICCProfileBuilder::build(['desc' => $lone]))->getDescription(),
		);
		$trailing = 'mluc' . "\0\0\0\0" . pack('N2', 1, 12) . 'enUS' . pack('N2', 4, 28) . "\xDC\x00\x00\x41";
		self::assertSame(
			"\u{FFFD}A",
			TICCProfile::parse(ICCProfileBuilder::build(['desc' => $trailing]))->getDescription(),
		);

		// And text that is not valid UTF-8 is encoded as replacement characters rather
		// than rejected: a stray byte, a truncated sequence, a broken continuation, an
		// encoded surrogate, and a code point above the Unicode range.
		$profile = TICCProfile::parse(ICCProfileBuilder::wideGamut());
		$profile->setLocalizedTexts('desc', [
			'enUS' => "\xFF",
			'frFR' => "A\xC3",
			'deDE' => "\xE2\x28\xA1",
			'esES' => "\xED\xA0\x80",
			'itIT' => "\xF4\x90\x80\x80",
		]);
		self::assertSame([
			'enUS' => "\u{FFFD}",
			'frFR' => "A\u{FFFD}",
			'deDE' => "\u{FFFD}(\u{FFFD}",
			'esES' => "\u{FFFD}",
			'itIT' => "\u{FFFD}",
		], TICCProfile::parse($profile->toBinary())->getLocalizedTexts('desc'));

		// A valid astral character still round-trips through the surrogate pair.
		$profile->setLocalizedTexts('cprt', ['enUS' => "sign \u{1F4F7}"]);
		self::assertSame("sign \u{1F4F7}", TICCProfile::parse($profile->toBinary())->getCopyright());
	}

	public function testTextDescriptionWithoutAWorkingRegularExpression()
	{
		// The ASCII projection of a version 2 'desc' is a preg_replace; when PCRE cannot
		// run at all (here, an exhausted backtrack limit), the description degrades to
		// empty instead of writing a null into the tag.
		$profile = TICCProfile::parse(ICCProfileBuilder::sRgb());
		$limit = ini_get('pcre.backtrack_limit');
		try {
			ini_set('pcre.backtrack_limit', '0');
			$profile->setDescription("Caf\u{00E9} description");
		} finally {
			ini_set('pcre.backtrack_limit', $limit);
		}

		self::assertSame('desc', $profile->getTagType('desc'));
		self::assertSame('', $profile->getDescription());
		self::assertSame('', TICCProfile::parse($profile->toBinary())->getDescription());

		// With PCRE working again the same text is stored, non-ASCII projected to '?'.
		$profile->setDescription("Caf\u{00E9} description");
		self::assertSame('Caf? description', TICCProfile::parse($profile->toBinary())->getDescription());
	}

	public function testFromStreamAndWriteTo()
	{
		$bytes = ICCProfileBuilder::sRgb();

		$stream = fopen('php://memory', 'r+b');
		fwrite($stream, $bytes);
		rewind($stream);
		$profile = TICCProfile::fromStream($stream);
		self::assertInstanceOf(TICCProfile::class, $profile);
		self::assertSame('sRGB built for tests', $profile->getDescription());
		fclose($stream);

		$out = fopen('php://memory', 'r+b');
		self::assertSame(strlen($profile->toBinary()), $profile->writeTo($out));
		rewind($out);
		self::assertSame(bin2hex($profile->toBinary()), bin2hex(stream_get_contents($out)));
		fclose($out);

		self::assertNull(TICCProfile::fromStream('not a profile'));
	}
}
