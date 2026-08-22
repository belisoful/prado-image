<?php

use Prado\Exceptions\TConfigurationException;
use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\IImageGraphicsLibrary;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsGD;
use Prado\IO\Image\TImageGraphicsImagick;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\Meta\TJFIF;
use Prado\IO\Image\Meta\TJFXX;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\Meta\TIPTCTags;

class TImageGraphicsTest extends PHPUnit\Framework\TestCase
{
	protected function tearDown(): void
	{
		TImageGraphics::setDefaultMode(null);
	}

	private function requireImagick(): void
	{
		if (!TImageGraphics::hasImagick()) {
			self::markTestSkipped('ext-imagick is not loaded.');
		}
	}

	private function gdImage(int $w, int $h, array $rgb = [10, 120, 200]): \GdImage
	{
		$im = imagecreatetruecolor($w, $h);
		imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, ...$rgb));
		return $im;
	}

	public function testModeDetection()
	{
		self::assertSame(extension_loaded('gd'), TImageGraphics::hasGd());
		self::assertSame(extension_loaded('imagick'), TImageGraphics::hasImagick());
		self::assertSame(TImageGraphics::hasGd(), TImageGraphics::hasMode(TImageGraphicsMode::GD));
		self::assertSame(TImageGraphics::hasImagick(), TImageGraphics::hasMode(TImageGraphicsMode::Imagick));
		self::assertSame(TImageGraphics::hasGd() || TImageGraphics::hasImagick(), TImageGraphics::hasMode());
		self::assertFalse(TImageGraphics::hasMode('bogus'));
	}

	public function testDefaultMode()
	{
		self::assertSame(TImageGraphicsMode::GD, TImageGraphics::getDefaultMode());

		TImageGraphics::setDefaultMode(TImageGraphicsMode::GD);
		self::assertSame(TImageGraphicsMode::GD, TImageGraphics::getDefaultMode());

		if (TImageGraphics::hasImagick()) {
			TImageGraphics::setDefaultMode(TImageGraphicsMode::Imagick);
			self::assertSame(TImageGraphicsMode::Imagick, TImageGraphics::getDefaultMode());
		} else {
			try {
				TImageGraphics::setDefaultMode(TImageGraphicsMode::Imagick);
				self::fail('setDefaultMode accepted an unloaded library');
			} catch (TConfigurationException $e) {
			}
		}
		TImageGraphics::setDefaultMode(null);
		self::assertSame(TImageGraphicsMode::GD, TImageGraphics::getDefaultMode());

		self::expectException(TInvalidDataValueException::class);
		TImageGraphics::setDefaultMode('bogus');
	}

	public function testIsImageAndModeOf()
	{
		$im = $this->gdImage(2, 2);
		self::assertTrue(TImageGraphics::isImage($im));
		self::assertFalse(TImageGraphics::isImage('image'));
		self::assertFalse(TImageGraphics::isImage(null));
		self::assertSame(TImageGraphicsMode::GD, TImageGraphics::getModeOf($im));
	}

	public function testRgbRoundTripGd()
	{
		$rgb = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF" . "\x10\x20\x30";
		$image = TImageGraphics::fromRgbPixels($rgb, 2, 2, TImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $image);
		self::assertSame([2, 2], TImageGraphics::getSize($image));
		self::assertSame($rgb, TImageGraphics::rgbPixels($image));
	}

	public function testRgbPixelsPaletteGd()
	{
		$im = imagecreate(2, 1);
		imagecolorallocate($im, 255, 0, 0);
		$white = imagecolorallocate($im, 255, 255, 255);
		imagesetpixel($im, 1, 0, $white);
		self::assertSame("\xFF\x00\x00\xFF\xFF\xFF", TImageGraphics::rgbPixels($im));
	}

	public function testDecodeAndEncodeJpegGd()
	{
		$jpeg = TImageGraphics::encodeJpeg($this->gdImage(8, 6), 90);
		self::assertSame("\xFF\xD8", substr($jpeg, 0, 2));

		$decoded = TImageGraphics::decode($jpeg, TImageGraphicsMode::GD);
		self::assertInstanceOf(\GdImage::class, $decoded);
		self::assertSame([8, 6], TImageGraphics::getSize($decoded));

		self::assertFalse(TImageGraphics::decode('not an image'));
	}

	public function testResampledGd()
	{
		$resampled = TImageGraphics::resampled($this->gdImage(40, 20), 20, 10);
		self::assertInstanceOf(\GdImage::class, $resampled);
		self::assertSame([20, 10], TImageGraphics::getSize($resampled));
	}

	public function testMonoPixelsGd()
	{
		$im = imagecreatetruecolor(4, 2);
		imagefilledrectangle($im, 0, 0, 1, 1, imagecolorallocate($im, 0, 0, 0));
		imagefilledrectangle($im, 2, 0, 3, 1, imagecolorallocate($im, 255, 255, 255));
		$mono = TImageGraphics::monoPixels($im, false);
		self::assertSame("\x00\x00\x01\x01\x00\x00\x01\x01", $mono);
	}

	public function testPaletteQuantizeGd()
	{
		$im = imagecreatetruecolor(4, 2);
		imagefilledrectangle($im, 0, 0, 1, 1, imagecolorallocate($im, 255, 0, 0));
		imagefilledrectangle($im, 2, 0, 3, 1, imagecolorallocate($im, 0, 0, 255));
		[$palette, $pixels] = TImageGraphics::paletteQuantize($im);
		self::assertSame(768, strlen($palette));
		self::assertSame(8, strlen($pixels));
		// Every pixel's palette entry approximates its original color (GD's quantizer
		// shifts channels slightly even below the color budget).
		$expected = [[255, 0, 0], [255, 0, 0], [0, 0, 255], [0, 0, 255]];
		for ($i = 0; $i < 8; $i++) {
			$this->assertNearColor($expected[$i % 4], substr($palette, ord($pixels[$i]) * 3, 3), "pixel $i");
		}
	}

	private function assertNearColor(array $expected, string $entry, string $message, int $tolerance = 8): void
	{
		foreach ($expected as $channel => $value) {
			self::assertLessThanOrEqual($tolerance, abs($value - ord($entry[$channel])), "$message channel $channel");
		}
	}

	public function testLibraryResolution()
	{
		$gd = TImageGraphics::getLibrary(TImageGraphicsMode::GD);
		self::assertInstanceOf(TImageGraphicsGD::class, $gd);
		self::assertInstanceOf(IImageGraphicsLibrary::class, $gd);
		self::assertSame(TImageGraphicsMode::GD, $gd->getMode());
		self::assertTrue($gd->getIsAvailable());

		// The implementations are stateless and shared, and a null mode takes the default.
		self::assertSame($gd, TImageGraphics::getLibrary(TImageGraphicsMode::GD));
		self::assertSame($gd, TImageGraphics::getLibrary());

		// An image routes to its own library.
		$image = $this->gdImage(2, 2);
		self::assertSame($gd, TImageGraphics::getLibraryOf($image));
		self::assertSame(TImageGraphicsMode::GD, TImageGraphics::getModeOf($image));

		self::assertTrue($gd->isImage($image));
		self::assertFalse($gd->isImage('image'));

		self::expectException(TInvalidDataValueException::class);
		TImageGraphics::getLibrary('bogus');
	}

	public function testCapabilities()
	{
		// GD's format support follows how the extension was built.
		self::assertSame(function_exists('imagejpeg'), TImageGraphics::supports(IImageGraphicsLibrary::CapabilityJpeg, TImageGraphicsMode::GD));
		self::assertSame(function_exists('imagewebp'), TImageGraphics::supports(IImageGraphicsLibrary::CapabilityWebP, TImageGraphicsMode::GD));
		self::assertSame(function_exists('imagepng'), TImageGraphics::supports(IImageGraphicsLibrary::CapabilityPng, TImageGraphicsMode::GD));
		self::assertTrue(TImageGraphics::supports(IImageGraphicsLibrary::CapabilityPalette, TImageGraphicsMode::GD));

		// The abilities GD gets in software: separating CMYK and the matrix/TRC transform.
		self::assertTrue(TImageGraphics::supports(IImageGraphicsLibrary::CapabilityCmyk, TImageGraphicsMode::GD));
		self::assertTrue(TImageGraphics::supports(IImageGraphicsLibrary::CapabilityICCTransform, TImageGraphicsMode::GD));

		// The two that need the library itself: carrying a profile, and deep samples.
		foreach ([IImageGraphicsLibrary::CapabilityICCEmbed, IImageGraphicsLibrary::CapabilityHighBitDepth] as $capability) {
			self::assertFalse(TImageGraphics::supports($capability, TImageGraphicsMode::GD), $capability);
		}
		self::assertFalse(TImageGraphics::supports('no-such-capability', TImageGraphicsMode::GD));

		// A null mode asks the default library; an unavailable or unknown one answers
		// false instead of throwing, so a caller may ask about a library it lacks.
		self::assertSame(
			TImageGraphics::supports(IImageGraphicsLibrary::CapabilityPalette, TImageGraphics::getDefaultMode()),
			TImageGraphics::supports(IImageGraphicsLibrary::CapabilityPalette),
		);
		self::assertFalse(TImageGraphics::supports(IImageGraphicsLibrary::CapabilityPalette, 'bogus'));
		if (!TImageGraphics::hasImagick()) {
			self::assertFalse(TImageGraphics::supports(IImageGraphicsLibrary::CapabilityCmyk, TImageGraphicsMode::Imagick));
		}
	}

	public function testCapabilitiesImagick()
	{
		$this->requireImagick();
		$imagick = TImageGraphics::getLibrary(TImageGraphicsMode::Imagick);
		self::assertInstanceOf(TImageGraphicsImagick::class, $imagick);
		self::assertSame(TImageGraphicsMode::Imagick, $imagick->getMode());
		self::assertTrue($imagick->getIsAvailable());

		// The color forms GD cannot hold.
		self::assertTrue($imagick->supports(IImageGraphicsLibrary::CapabilityCmyk));
		self::assertTrue($imagick->supports(IImageGraphicsLibrary::CapabilityICCEmbed));
		self::assertTrue($imagick->supports(IImageGraphicsLibrary::CapabilityICCTransform));
		self::assertSame(\Imagick::queryFormats('PNG') !== [], $imagick->supports(IImageGraphicsLibrary::CapabilityPng));
		self::assertTrue($imagick->supports(IImageGraphicsLibrary::CapabilityPalette));
		self::assertSame(\Imagick::queryFormats('JPEG') !== [], $imagick->supports(IImageGraphicsLibrary::CapabilityJpeg));
		self::assertSame(\Imagick::queryFormats('WEBP') !== [], $imagick->supports(IImageGraphicsLibrary::CapabilityWebP));
		self::assertSame(((int) \Imagick::getQuantumDepth()['quantumDepthLong']) > 8, $imagick->supports(IImageGraphicsLibrary::CapabilityHighBitDepth));
		self::assertFalse($imagick->supports('no-such-capability'));

		self::assertTrue($imagick->isImage(TImageGraphics::fromRgbPixels("\x00\x00\x00", 1, 1, TImageGraphicsMode::Imagick)));
		self::assertFalse($imagick->isImage($this->gdImage(1, 1)));
	}

	public function testLibraryRejectsAnotherLibrarysImage()
	{
		$this->requireImagick();
		// The facade routes by image type, so a mismatch only reaches a library when it
		// is called directly: a programming error, not a conversion.
		$imagick = TImageGraphics::getLibrary(TImageGraphicsMode::Imagick);
		self::expectException(TInvalidDataTypeException::class);
		$imagick->getSize($this->gdImage(2, 2));
	}

	public function testGdLibraryRejectsImagickImage()
	{
		$this->requireImagick();
		$image = TImageGraphics::fromRgbPixels("\x00\x00\x00", 1, 1, TImageGraphicsMode::Imagick);
		self::expectException(TInvalidDataTypeException::class);
		TImageGraphics::getLibrary(TImageGraphicsMode::GD)->rgbPixels($image);
	}

	/**
	 * Builds a minimal but valid ICC v2 RGB matrix/TRC display profile, so the profile
	 * paths are exercised without a binary fixture.
	 */
	private function iccProfile(): string
	{
		$s15 = fn (float $v): string => pack('N', (int) round($v * 65536));
		$xyz = fn (float $x, float $y, float $z): string => 'XYZ ' . "\0\0\0\0" . $s15($x) . $s15($y) . $s15($z);
		$curv = fn (float $gamma): string => 'curv' . "\0\0\0\0" . pack('N', 1) . pack('n', (int) round($gamma * 256));

		$tags = [
			'desc' => 'desc' . "\0\0\0\0" . pack('N', 13) . "Minimal sRGB\0" . str_repeat("\0", 78),
			'wtpt' => $xyz(0.9642, 1.0, 0.8249),
			'rXYZ' => $xyz(0.4360, 0.2225, 0.0139),
			'gXYZ' => $xyz(0.3851, 0.7169, 0.0971),
			'bXYZ' => $xyz(0.1431, 0.0606, 0.7141),
			'rTRC' => $curv(2.2),
			'gTRC' => $curv(2.2),
			'bTRC' => $curv(2.2),
			'cprt' => 'text' . "\0\0\0\0" . "Public Domain\0",
		];
		$offset = 132 + count($tags) * 12;
		$table = pack('N', count($tags));
		$data = '';
		foreach ($tags as $signature => $blob) {
			$table .= $signature . pack('N', $offset) . pack('N', strlen($blob));
			$padding = (4 - strlen($blob) % 4) % 4;
			$data .= $blob . str_repeat("\0", $padding);
			$offset += strlen($blob) + $padding;
		}
		$body = $table . $data;
		// The 128-byte header: size, CMM, version 2.3.0, class, data and connection
		// spaces, date, 'acsp', platform, flags, maker, model, attributes and intent,
		// the D50 illuminant, creator, then the profile id and reserved bytes.
		$header = pack('N', 128 + strlen($body)) . '    ' . pack('N', 0x02300000) . 'mntr' . 'RGB ' . 'XYZ '
			. pack('nnnnnn', 2026, 1, 1, 0, 0, 0) . 'acsp' . 'APPL' . pack('N', 0)
			. '    ' . '    ' . str_repeat("\0", 12) . $s15(0.9642) . $s15(1.0) . $s15(0.8249)
			. '    ' . str_repeat("\0", 44);
		return $header . $body;
	}

	public function testEncodeFormatsGd()
	{
		$image = $this->gdImage(8, 6);
		self::assertSame("\xFF\xD8", substr((string) TImageGraphics::encode($image, IImageGraphicsLibrary::FormatJpeg, 90), 0, 2));
		self::assertSame("\x89PNG", substr((string) TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng), 0, 4));

		// Every format the build carries decodes back to the same dimensions.
		foreach ([IImageGraphicsLibrary::FormatJpeg, IImageGraphicsLibrary::FormatPng, IImageGraphicsLibrary::FormatWebP] as $format) {
			$encoded = TImageGraphics::encode($image, $format);
			if (!TImageGraphics::supports($format, TImageGraphicsMode::GD)) {
				self::assertFalse($encoded, "$format should be unsupported");
				continue;
			}
			self::assertIsString($encoded, $format);
			self::assertSame([8, 6], TImageGraphics::getSize(TImageGraphics::decode($encoded, TImageGraphicsMode::GD)), $format);
		}

		// PNG is lossless: the quality moves GD's compression level, never the pixels.
		$source = TImageGraphics::rgbPixels($image);
		foreach ([10, 100] as $quality) {
			$decoded = TImageGraphics::decode((string) TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng, $quality), TImageGraphicsMode::GD);
			self::assertInstanceOf(\GdImage::class, $decoded, "quality $quality");
			self::assertSame(bin2hex($source), bin2hex(TImageGraphics::rgbPixels($decoded)), "quality $quality");
		}

		self::assertFalse(TImageGraphics::encode($image, 'tiff'));
		self::assertFalse(TImageGraphics::encode($image, ''));

		// The JPEG convenience is the same encoding.
		self::assertSame("\xFF\xD8", substr(TImageGraphics::encodeJpeg($image, 90), 0, 2));
	}

	public function testEncodeFormatsImagick()
	{
		$this->requireImagick();
		$image = TImageGraphics::fromRgbPixels(str_repeat("\x0A\x78\xC8", 48), 8, 6, TImageGraphicsMode::Imagick);
		self::assertSame("\xFF\xD8", substr((string) TImageGraphics::encode($image, IImageGraphicsLibrary::FormatJpeg, 90), 0, 2));

		foreach ([IImageGraphicsLibrary::FormatJpeg, IImageGraphicsLibrary::FormatPng, IImageGraphicsLibrary::FormatWebP] as $format) {
			$encoded = TImageGraphics::encode($image, $format);
			if (!TImageGraphics::supports($format, TImageGraphicsMode::Imagick)) {
				self::assertFalse($encoded, "$format should be unsupported");
				continue;
			}
			self::assertIsString($encoded, $format);
			self::assertSame([8, 6], TImageGraphics::getSize(TImageGraphics::decode($encoded, TImageGraphicsMode::Imagick)), $format);
		}
		self::assertSame("\x89PNG", substr((string) TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng), 0, 4));
		self::assertFalse(TImageGraphics::encode($image, 'not-a-format'));

		// Encoding leaves the source untouched.
		self::assertSame([8, 6], TImageGraphics::getSize($image));
		self::assertSame(str_repeat("\x0A\x78\xC8", 48), TImageGraphics::rgbPixels($image));
	}

	public function testGdCannotCarryAnEmbeddedProfile()
	{
		$image = $this->gdImage(2, 2);
		self::assertFalse(TImageGraphics::supports(IImageGraphicsLibrary::CapabilityICCEmbed, TImageGraphicsMode::GD));
		self::assertNull(TImageGraphics::getICCProfile($image));
		self::assertFalse(TImageGraphics::setICCProfile($image, $this->iccProfile()));
		self::assertFalse(TImageGraphics::setICCProfile($image, null));
	}

	public function testGdTransformsBetweenColorSpacesInSoftware()
	{
		// GD has no color management, so the conversion runs through TICCTransform.
		$sRgb = ICCProfileBuilder::sRgb();
		$wide = ICCProfileBuilder::wideGamut();

		$image = imagecreatetruecolor(3, 1);
		imagesetpixel($image, 0, 0, 0xFF0000);
		imagesetpixel($image, 1, 0, 0xFFFFFF);
		imagesetpixel($image, 2, 0, 0x808080);

		self::assertTrue(TImageGraphics::transformICCProfile($image, $sRgb, $wide));
		// The published Adobe RGB encoding of sRGB red; white stays white and the neutral
		// stays neutral.
		self::assertEqualsWithDelta(
			[219, 0, 0, 255, 255, 255, 127, 127, 127],
			array_map('ord', str_split(TImageGraphics::rgbPixels($image))),
			2,
		);

		// A profile it cannot evaluate is refused rather than approximated, and so is a
		// byte string that is not a profile at all.
		self::assertFalse(TImageGraphics::transformICCProfile($image, $sRgb, ICCProfileBuilder::cmykLut()));
		self::assertFalse(TImageGraphics::transformICCProfile($image, ICCProfileBuilder::cmykLut(), $sRgb));
		self::assertFalse(TImageGraphics::transformICCProfile($image, 'not a profile', $sRgb));
		self::assertFalse(TImageGraphics::transformICCProfile($image, $sRgb, 'not a profile'));
	}

	public function testImagickTransformsBetweenColorSpaces()
	{
		$this->requireImagick();
		$image = TImageGraphics::fromRgbPixels("\xFF\x00\x00\xFF\xFF\xFF", 2, 1, TImageGraphicsMode::Imagick);

		self::assertTrue(TImageGraphics::transformICCProfile($image, ICCProfileBuilder::sRgb(), ICCProfileBuilder::wideGamut()));
		// ImageMagick leaves the destination profile attached to the image.
		self::assertSame(bin2hex(ICCProfileBuilder::wideGamut()), bin2hex((string) TImageGraphics::getICCProfile($image)));

		// Converting again from the space it now carries is a no-op on the first profile.
		self::assertTrue(TImageGraphics::transformICCProfile($image, ICCProfileBuilder::wideGamut(), ICCProfileBuilder::sRgb()));
		self::assertFalse(TImageGraphics::transformICCProfile($image, ICCProfileBuilder::sRgb(), 'not a profile'));
	}

	public function testCmykPixelsRoundTrip()
	{
		foreach ([TImageGraphicsMode::GD, TImageGraphicsMode::Imagick] as $mode) {
			if (!TImageGraphics::hasMode($mode)) {
				continue;
			}
			$rgb = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF" . "\xFF\xFF\xFF" . "\x00\x00\x00" . "\x80\x40\xC0";
			$image = TImageGraphics::fromRgbPixels($rgb, 6, 1, $mode);

			$cmyk = TImageGraphics::cmykPixels($image);
			self::assertIsString($cmyk, $mode);
			self::assertSame(24, strlen($cmyk), $mode);

			$restored = TImageGraphics::fromCmykPixels($cmyk, 6, 1, $mode);
			self::assertTrue(TImageGraphics::isImage($restored), $mode);
			self::assertSame([6, 1], TImageGraphics::getSize($restored), $mode);

			// The separation is reversible to within the rounding of one 8-bit step.
			self::assertEqualsWithDelta(
				array_map('ord', str_split($rgb)),
				array_map('ord', str_split(TImageGraphics::rgbPixels($restored))),
				2,
				$mode,
			);
		}
	}

	public function testCmykSeparationOfKnownColors()
	{
		// The straightforward separation GD performs: no ink for white, black only for
		// black, and one full primary ink for each secondary.
		$image = TImageGraphics::fromRgbPixels("\xFF\xFF\xFF" . "\x00\x00\x00" . "\x00\xFF\xFF" . "\xFF\x00\xFF", 4, 1, TImageGraphicsMode::GD);
		self::assertSame(
			bin2hex("\x00\x00\x00\x00" . "\x00\x00\x00\xFF" . "\xFF\x00\x00\x00" . "\x00\xFF\x00\x00"),
			bin2hex((string) TImageGraphics::cmykPixels($image)),
		);
	}

	public function testGetCapableLibrary()
	{
		// The default library answers when it can do the job.
		self::assertSame(
			TImageGraphics::getLibrary(TImageGraphicsMode::GD),
			TImageGraphics::getCapableLibrary(IImageGraphicsLibrary::CapabilityPalette),
		);

		// For something GD cannot do, the other library answers -- or nothing does.
		$embedding = TImageGraphics::getCapableLibrary(IImageGraphicsLibrary::CapabilityICCEmbed);
		if (TImageGraphics::hasImagick()) {
			self::assertInstanceOf(TImageGraphicsImagick::class, $embedding);
			self::assertSame(
				TImageGraphics::getLibrary(TImageGraphicsMode::Imagick),
				TImageGraphics::getCapableLibrary(IImageGraphicsLibrary::CapabilityHighBitDepth),
			);
		} else {
			self::assertNull($embedding);
			self::assertNull(TImageGraphics::getCapableLibrary(IImageGraphicsLibrary::CapabilityHighBitDepth));
		}

		self::assertNull(TImageGraphics::getCapableLibrary('no-such-capability'));

		// The preference follows the default mode.
		TImageGraphics::setDefaultMode(TImageGraphicsMode::GD);
		self::assertInstanceOf(TImageGraphicsGD::class, TImageGraphics::getCapableLibrary(IImageGraphicsLibrary::CapabilityCmyk));
		if (TImageGraphics::hasImagick()) {
			TImageGraphics::setDefaultMode(TImageGraphicsMode::Imagick);
			self::assertInstanceOf(TImageGraphicsImagick::class, TImageGraphics::getCapableLibrary(IImageGraphicsLibrary::CapabilityCmyk));
		}
	}

	public function testICCProfileImagick()
	{
		$this->requireImagick();
		$profile = $this->iccProfile();
		$image = TImageGraphics::fromRgbPixels(str_repeat("\x40\x80\xC0", 4), 2, 2, TImageGraphicsMode::Imagick);

		// A fresh image carries no profile, and removing nothing is a no-op success.
		self::assertNull(TImageGraphics::getICCProfile($image));
		self::assertTrue(TImageGraphics::setICCProfile($image, null));

		self::assertTrue(TImageGraphics::setICCProfile($image, $profile));
		self::assertSame(bin2hex($profile), bin2hex((string) TImageGraphics::getICCProfile($image)));

		// Attaching over an existing profile is the ICC transform path.
		self::assertTrue(TImageGraphics::setICCProfile($image, $profile));

		self::assertTrue(TImageGraphics::setICCProfile($image, null));
		self::assertNull(TImageGraphics::getICCProfile($image));

		// An unusable profile fails rather than throwing.
		self::assertFalse(TImageGraphics::setICCProfile($image, 'not an ICC profile'));
	}

	public function testValidateModeErrors()
	{
		self::expectException(TInvalidDataValueException::class);
		TImageGraphics::fromRgbPixels("\x00\x00\x00", 1, 1, 'bogus');
	}

	public function testUnavailableModeThrows()
	{
		if (TImageGraphics::hasImagick()) {
			self::markTestSkipped('ext-imagick is loaded; the unavailable path cannot be exercised.');
		}
		self::expectException(TConfigurationException::class);
		TImageGraphics::fromRgbPixels("\x00\x00\x00", 1, 1, TImageGraphicsMode::Imagick);
	}

	public function testDefaultModeFollowsTheAvailableLibraries()
	{
		// The extension detection is the seam every resolution routes through, so a
		// subclass reporting a library missing stands in for an installation without it.
		if (TImageGraphics::hasImagick()) {
			self::assertSame(TImageGraphicsMode::Imagick, TNoGdImageGraphics::getDefaultMode());
			self::assertInstanceOf(TImageGraphicsImagick::class, TNoGdImageGraphics::getLibrary());
		} else {
			self::assertNull(TNoGdImageGraphics::getDefaultMode());
		}
		self::assertNull(TNoLibraryImageGraphics::getDefaultMode());
		self::assertFalse(TNoLibraryImageGraphics::hasMode());

		// An explicit default outranks the detection.
		TImageGraphics::setDefaultMode(TImageGraphicsMode::GD);
		self::assertSame(TImageGraphicsMode::GD, TNoLibraryImageGraphics::getDefaultMode());
	}

	public function testMissingLibrariesAreReportedNotGuessed()
	{
		try {
			TNoLibraryImageGraphics::getLibrary();
			self::fail('a library was resolved with none available');
		} catch (TConfigurationException $e) {
			self::assertSame('imagegraphics_library_required', $e->getErrorCode());
		}

		try {
			TNoGdImageGraphics::getLibrary(TImageGraphicsMode::GD);
			self::fail('an unloaded library was resolved by name');
		} catch (TConfigurationException $e) {
			self::assertSame('imagegraphics_mode_unavailable', $e->getErrorCode());
		}

		// Asking about a capability of a library that is not there is answered, not
		// thrown, and no library is capable of anything.
		self::assertFalse(TNoLibraryImageGraphics::supports(IImageGraphicsLibrary::CapabilityJpeg));
		self::assertFalse(TNoLibraryImageGraphics::supports(IImageGraphicsLibrary::CapabilityJpeg, TImageGraphicsMode::GD));
		self::assertNull(TNoLibraryImageGraphics::getCapableLibrary(IImageGraphicsLibrary::CapabilityJpeg));
	}

	public function testGdReportsAnImpossibleAllocationAsFailure()
	{
		// GD refuses an allocation whose size overflows; both entry points answer false
		// rather than handing back a broken image.
		$huge = 200000000;
		self::assertFalse(@TImageGraphics::fromRgbPixels('', $huge, $huge, TImageGraphicsMode::GD));
		self::assertFalse(@TImageGraphics::resampled($this->gdImage(4, 4), $huge, $huge));
	}

	public function testUnavailableImagickReportsNoCapabilities()
	{
		// Without the extension the library provides nothing at all, which is what lets
		// TImageGraphics::supports() ask about a library this installation lacks.
		$unavailable = new class () extends TImageGraphicsImagick {
			public function getIsAvailable(): bool
			{
				return false;
			}
		};
		self::assertFalse($unavailable->getIsAvailable());
		foreach ([
			IImageGraphicsLibrary::CapabilityJpeg,
			IImageGraphicsLibrary::CapabilityPng,
			IImageGraphicsLibrary::CapabilityWebP,
			IImageGraphicsLibrary::CapabilityCmyk,
			IImageGraphicsLibrary::CapabilityICCEmbed,
			IImageGraphicsLibrary::CapabilityHighBitDepth,
		] as $capability) {
			self::assertFalse($unavailable->supports($capability), $capability);
		}
	}

	public function testImagickFailuresAreContained()
	{
		$this->requireImagick();
		$library = TImageGraphics::getLibrary(TImageGraphicsMode::Imagick);

		// A wand holding no image cannot be encoded: the ImagickException is answered as
		// false, and the working copy is still released.
		self::assertFalse($library->encode(new \Imagick(), IImageGraphicsLibrary::FormatJpeg, 80));

		// A pixel array that does not fill the geometry is refused rather than padded.
		self::assertFalse($library->fromCmykPixels("\x01\x02\x03\x04", 4, 4));
		self::assertFalse($library->fromCmykPixels('', 2, 2));

		// The separation reports the same way when the conversion itself cannot run;
		// getSize() is what stands between an empty wand and the export, so overriding it
		// lets the empty wand reach the conversion.
		$stubbed = new class () extends TImageGraphicsImagick {
			public function getSize(\GdImage|\Imagick $image): array
			{
				return [1, 1];
			}
		};
		self::assertFalse($stubbed->cmykPixels(new \Imagick()));
	}

	public function testRgbRoundTripImagick()
	{
		$this->requireImagick();
		$rgb = "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF" . "\x10\x20\x30";
		$image = TImageGraphics::fromRgbPixels($rgb, 2, 2, TImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $image);
		self::assertSame(TImageGraphicsMode::Imagick, TImageGraphics::getModeOf($image));
		self::assertSame([2, 2], TImageGraphics::getSize($image));
		self::assertSame($rgb, TImageGraphics::rgbPixels($image));
	}

	public function testDecodeAndEncodeJpegImagick()
	{
		$this->requireImagick();
		$image = TImageGraphics::fromRgbPixels(str_repeat("\x0A\x78\xC8", 48), 8, 6, TImageGraphicsMode::Imagick);
		$jpeg = TImageGraphics::encodeJpeg($image, 90);
		self::assertSame("\xFF\xD8", substr($jpeg, 0, 2));

		$decoded = TImageGraphics::decode($jpeg, TImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $decoded);
		self::assertSame([8, 6], TImageGraphics::getSize($decoded));

		self::assertFalse(TImageGraphics::decode('not an image', TImageGraphicsMode::Imagick));
	}

	public function testResampledImagick()
	{
		$this->requireImagick();
		$image = TImageGraphics::fromRgbPixels(str_repeat("\x80\x80\x80", 800), 40, 20, TImageGraphicsMode::Imagick);
		$resampled = TImageGraphics::resampled($image, 20, 10);
		self::assertInstanceOf(\Imagick::class, $resampled);
		self::assertSame([20, 10], TImageGraphics::getSize($resampled));
	}

	public function testMonoPixelsImagick()
	{
		$this->requireImagick();
		$rgb = str_repeat("\x00\x00\x00", 2) . str_repeat("\xFF\xFF\xFF", 2)
			. str_repeat("\x00\x00\x00", 2) . str_repeat("\xFF\xFF\xFF", 2);
		$image = TImageGraphics::fromRgbPixels($rgb, 4, 2, TImageGraphicsMode::Imagick);
		$mono = TImageGraphics::monoPixels($image, false);
		self::assertSame("\x00\x00\x01\x01\x00\x00\x01\x01", $mono);
	}

	public function testMonoPixelsImagickFlatImage()
	{
		$this->requireImagick();
		// A flat image quantizes to one level, so the midpoint of the levels present says
		// nothing about it: an all-black canvas comes back all-white if the threshold
		// follows the quantized levels instead of a fixed mid-grey.
		$black = TImageGraphics::fromRgbPixels(str_repeat("\x00\x00\x00", 6), 3, 2, TImageGraphicsMode::Imagick);
		self::assertSame(str_repeat("\x00", 6), TImageGraphics::monoPixels($black, false));

		$white = TImageGraphics::fromRgbPixels(str_repeat("\xFF\xFF\xFF", 6), 3, 2, TImageGraphicsMode::Imagick);
		self::assertSame(str_repeat("\x01", 6), TImageGraphics::monoPixels($white, false));
	}

	public function testPaletteQuantizeImagick()
	{
		$this->requireImagick();
		$rgb = str_repeat("\xFF\x00\x00", 2) . str_repeat("\x00\x00\xFF", 2)
			. str_repeat("\xFF\x00\x00", 2) . str_repeat("\x00\x00\xFF", 2);
		$image = TImageGraphics::fromRgbPixels($rgb, 4, 2, TImageGraphicsMode::Imagick);
		[$palette, $pixels] = TImageGraphics::paletteQuantize($image);
		self::assertSame(768, strlen($palette));
		self::assertSame(8, strlen($pixels));
		for ($i = 0; $i < 8; $i++) {
			$expected = [ord($rgb[$i * 3]), ord($rgb[$i * 3 + 1]), ord($rgb[$i * 3 + 2])];
			$this->assertNearColor($expected, substr($palette, ord($pixels[$i]) * 3, 3), "pixel $i");
		}
	}

	public function testJfifCrossLibrary()
	{
		$this->requireImagick();
		$rgb = str_repeat("\x40\x80\xC0", 6 * 4);
		$thumb = TImageGraphics::fromRgbPixels($rgb, 6, 4, TImageGraphicsMode::Imagick);

		$jfif = new TJFIF();
		$jfif->setImage($thumb);
		self::assertSame(6, $jfif->getXThumbnail());
		self::assertSame(4, $jfif->getYThumbnail());
		self::assertSame($rgb, $jfif->getThumbnail());

		self::assertInstanceOf(\GdImage::class, $jfif->getImage(TImageGraphicsMode::GD));
		self::assertInstanceOf(\Imagick::class, $jfif->getImage(TImageGraphicsMode::Imagick));
	}

	public function testJfxxImagickColorThumb()
	{
		$this->requireImagick();
		$rgb = str_repeat("\x20\x40\x60", 8 * 8);
		$thumb = TImageGraphics::fromRgbPixels($rgb, 8, 8, TImageGraphicsMode::Imagick);

		$jfxx = new TJFXX();
		self::assertTrue($jfxx->setImage($thumb, TJFXX::COLOR_THUMB));
		self::assertSame($rgb, $jfxx->getThumbnail());

		$image = $jfxx->getImage(TImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $image);
		self::assertSame($rgb, TImageGraphics::rgbPixels($image));
	}

	public function testRasterizedCaptionImagick()
	{
		$this->requireImagick();
		$rgb = '';
		for ($y = 0; $y < 128; $y++) {
			for ($x = 0; $x < 460; $x++) {
				$rgb .= $x < 230 ? "\x00\x00\x00" : "\xFF\xFF\xFF";
			}
		}
		$caption = TImageGraphics::fromRgbPixels($rgb, 460, 128, TImageGraphicsMode::Imagick);

		$iptc = new TIPTC();
		self::assertTrue($iptc->setRasterizedCaptionImage($caption, false));
		$raster = $iptc[TIPTCTags::RasterizedCaption];
		self::assertSame(7360, strlen($raster));
		// The left half is black bits, the right half white bits (column-major packing).
		self::assertSame(str_repeat("\x00", 3680), substr($raster, 0, 3680));
		self::assertSame(str_repeat("\xFF", 3680), substr($raster, 3680));

		$image = $iptc->getRasterizedCaptionImage(TImageGraphicsMode::Imagick);
		self::assertInstanceOf(\Imagick::class, $image);
		self::assertSame($rgb, TImageGraphics::rgbPixels($image));
	}
}

/**
 * The facade with GD reported missing: the extension checks are the seam the resolution
 * routes through, so overriding them stands in for an installation without the extension.
 */
class TNoGdImageGraphics extends TImageGraphics
{
	public static function hasGd(): bool
	{
		return false;
	}
}

/**
 * The facade with neither graphics library available.
 */
class TNoLibraryImageGraphics extends TNoGdImageGraphics
{
	public static function hasImagick(): bool
	{
		return false;
	}
}
