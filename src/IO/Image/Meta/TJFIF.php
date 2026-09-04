<?php

/**
 * TJFIF class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image\Meta;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\TStream;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\TComponent;
use Psr\Http\Message\StreamInterface;

/**
 * TJFIF class.
 *
 * Parses, holds, and writes the JFIF data of a JPEG APP0 segment: the version, the pixel
 * density and its units, and an optional uncompressed RGB thumbnail of up to 255x255
 * pixels.  {@see parse()} reads a binary APP0 payload and {@see toBinary()} writes it
 * back, so {@see \Prado\IO\Image\TJPEG} can read and rewrite JFIF metadata.
 *
 * ```php
 * $jfif = $jpeg->getJFIF();
 * [$jfif->getXDensity(), $jfif->getYDensity(), $jfif->getUnits()];
 * $jfif->setImage($gdThumbnail);   // embed a thumbnail (<= 255x255)
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://www.w3.org/Graphics/JPEG/jfif3.pdf Official standard
 */
class TJFIF extends TComponent
{
	public const IDENTIFIER = "JFIF\0";
	public const JFXX_IDENTIFIER = "JFXX\0";
	public const VERSION_11 = 1;
	public const VERSION_12 = 2;

	public const UNITS_ASPECT = 0;
	public const UNITS_PPI = 1;
	public const UNITS_PPCM = 2;

	/** @var int The number of header bytes before the thumbnail. */
	private const HEADER_SIZE = 14;

	/** @var int The major version number. */
	private int $_versionMajor = 1;

	/** @var int The minor version number. */
	private int $_versionMinor = 1;

	/** @var int The pixel-density units (aspect ratio, ppi, or ppcm). */
	private int $_units = self::UNITS_PPCM;

	/** @var int The X pixel density in the current units. */
	private int $_xDensity = 56;

	/** @var int The Y pixel density in the current units. */
	private int $_yDensity = 56;

	/** @var int The thumbnail width in pixels (0-255). */
	private int $_xThumbnail = 0;

	/** @var int The thumbnail height in pixels (0-255). */
	private int $_yThumbnail = 0;

	/** @var string The uncompressed RGB thumbnail data. */
	private string $_thumbnail = '';

	/**
	 * Indicates whether the data begins with the JFIF identifier.
	 * @param string $data The candidate APP0 payload.
	 * @return bool Whether the data is JFIF.
	 */
	public static function isJFIF(string $data): bool
	{
		return strncmp($data, self::IDENTIFIER, 5) === 0;
	}

	/**
	 * Indicates whether the data begins with the JFXX identifier.
	 * @param string $data The candidate APP0 payload.
	 * @return bool Whether the data is a JFXX extension.
	 */
	public static function isJFXX(string $data): bool
	{
		return strncmp($data, self::JFXX_IDENTIFIER, 5) === 0;
	}




	/**
	 * Drains a JFIF byte source: a string passes through, and a {@see StreamInterface} or
	 * PHP stream resource (wrapped without taking ownership) is read from its current
	 * position to the end.  It matches {@see \Prado\IO\Image\TStreamIOTrait::sourceBytes()},
	 * which this class does not use because it has no {@see toBinary()} to write back.
	 * @param mixed $data The string, stream, or stream resource.
	 * @throws TInvalidDataTypeException When the data is none of those.
	 * @return string The bytes.
	 */
	protected static function parseBytes(mixed $data): string
	{
		if (is_string($data)) {
			return $data;
		}
		if (is_resource($data)) {
			$data = TStream::fromResource($data, false);
		}
		if ($data instanceof StreamInterface) {
			return $data->getContents();
		}
		throw new TInvalidDataTypeException('streamio_source_invalid', get_debug_type($data));
	}

	/**
	 * Parses a JFIF APP0 payload into a populated instance.
	 * @param mixed $data The JFIF binary data as a string, a {@see StreamInterface}, or a
	 *   PHP stream resource (wrapped without taking ownership).
	 * @throws TInvalidDataTypeException When the data is none of those.
	 * @return false|TJFIF The parsed JFIF, or false when the data is not JFIF.
	 */
	public static function parse(mixed $data): false|TJFIF
	{
		$bytes = static::parseBytes($data);
		if (strlen($bytes) < self::HEADER_SIZE || !self::isJFIF($bytes)) {
			return false;
		}
		$fields = unpack('Cmaj/Cmin/Cunits/nxd/nyd/Csx/Csy', substr($bytes, 5, 9));
		$jfif = new TJFIF();
		$jfif->_versionMajor = $fields['maj'];
		$jfif->_versionMinor = $fields['min'];
		$jfif->_units = $fields['units'];
		$jfif->_xDensity = $fields['xd'];
		$jfif->_yDensity = $fields['yd'];
		$jfif->_xThumbnail = $fields['sx'];
		$jfif->_yThumbnail = $fields['sy'];
		$jfif->_thumbnail = substr($bytes, self::HEADER_SIZE, 3 * $fields['sx'] * $fields['sy']);
		return $jfif;
	}

	/**
	 * Returns the major version number.
	 * @return int The major version.
	 */
	public function getVersionMajor(): int
	{
		return $this->_versionMajor;
	}

	/**
	 * Sets the major version number.
	 * @param int $value The major version.
	 */
	public function setVersionMajor(int $value): void
	{
		$this->_versionMajor = $value;
	}

	/**
	 * Returns the minor version number.
	 * @return int The minor version.
	 */
	public function getVersionMinor(): int
	{
		return $this->_versionMinor;
	}

	/**
	 * Sets the minor version number.
	 * @param int $value The minor version.
	 */
	public function setVersionMinor(int $value): void
	{
		$this->_versionMinor = $value;
	}

	/**
	 * Returns the pixel-density units.
	 * @return int A UNITS_* constant.
	 */
	public function getUnits(): int
	{
		return $this->_units;
	}

	/**
	 * Sets the pixel-density units, converting the densities between ppi and ppcm.
	 * @param int $value A UNITS_* constant (clamped to 0-2).
	 */
	public function setUnits(int $value): void
	{
		$value = min(2, max(0, $value));
		if ($this->_units === self::UNITS_PPI && $value === self::UNITS_PPCM) {
			$this->setXDensity((int) round($this->getXDensity() / 2.54));
			$this->setYDensity((int) round($this->getYDensity() / 2.54));
		} elseif ($this->_units === self::UNITS_PPCM && $value === self::UNITS_PPI) {
			$this->setXDensity((int) round($this->getXDensity() * 2.54));
			$this->setYDensity((int) round($this->getYDensity() * 2.54));
		}
		$this->_units = $value;
	}

	/**
	 * Returns the X pixel density.
	 * @return int The X density in the current units.
	 */
	public function getXDensity(): int
	{
		return $this->_xDensity;
	}

	/**
	 * Sets the X pixel density.
	 * @param int $value The X density in the current units.
	 */
	public function setXDensity(int $value): void
	{
		$this->_xDensity = $value;
	}

	/**
	 * Returns the Y pixel density.
	 * @return int The Y density in the current units.
	 */
	public function getYDensity(): int
	{
		return $this->_yDensity;
	}

	/**
	 * Sets the Y pixel density.
	 * @param int $value The Y density in the current units.
	 */
	public function setYDensity(int $value): void
	{
		$this->_yDensity = $value;
	}

	/**
	 * Returns the thumbnail width.
	 * @return int The thumbnail width in pixels.
	 */
	public function getXThumbnail(): int
	{
		return $this->_xThumbnail;
	}

	/**
	 * Returns the thumbnail height.
	 * @return int The thumbnail height in pixels.
	 */
	public function getYThumbnail(): int
	{
		return $this->_yThumbnail;
	}

	/**
	 * Returns the uncompressed RGB thumbnail data.
	 * @return string The thumbnail bytes ('' when none).
	 */
	public function getThumbnail(): string
	{
		return $this->_thumbnail;
	}

	/**
	 * Indicates whether the JFIF carries a thumbnail image.
	 * @return bool Whether a thumbnail is present.
	 */
	public function hasImage(): bool
	{
		return $this->getXThumbnail() > 0 && $this->getYThumbnail() > 0 && $this->getThumbnail() !== '';
	}

	/**
	 * Clears the thumbnail.
	 */
	public function clearThumbnail(): void
	{
		$this->_xThumbnail = 0;
		$this->_yThumbnail = 0;
		$this->_thumbnail = '';
	}

	/**
	 * Builds an image from the thumbnail, in the requested graphics library.
	 * @param ?string $mode The {@see TImageGraphicsMode} to build in; null for the default.
	 * @return false|\GdImage|\Imagick The thumbnail image, or false when there is none.
	 */
	public function getImage(?string $mode = null): false|\GdImage|\Imagick
	{
		if (!$this->hasImage()) {
			return false;
		}
		return TImageGraphics::fromRgbPixels($this->getThumbnail(), $this->getXThumbnail(), $this->getYThumbnail(), $mode);
	}

	/**
	 * Sets (or clears, when null) the thumbnail from a GD or Imagick image.
	 * @param null|\GdImage|\Imagick $image The thumbnail image, or null to clear it.
	 * @throws TInvalidDataValueException When a dimension exceeds 255 pixels.
	 */
	public function setImage(\GdImage|\Imagick|null $image): void
	{
		if ($image === null) {
			$this->clearThumbnail();
			return;
		}
		[$sx, $sy] = TImageGraphics::getSize($image);
		if ($sx > 255 || $sy > 255) {
			throw new TInvalidDataValueException('jfif_thumbnail_over_max', $sx, $sy);
		}
		$this->_xThumbnail = $sx;
		$this->_yThumbnail = $sy;
		$this->_thumbnail = TImageGraphics::rgbPixels($image);
	}

	/**
	 * Writes the JFIF as a binary APP0 payload.
	 * @throws TInvalidDataValueException When the thumbnail is oversized or malformed.
	 * @return string The JFIF binary data.
	 */
	public function toBinary(): string
	{
		$width = $this->getXThumbnail();
		$height = $this->getYThumbnail();
		$thumbnail = $this->getThumbnail();
		if ($width > 255 || $height > 255) {
			throw new TInvalidDataValueException('jfif_thumbnail_over_max', $width, $height);
		}
		if (strlen($thumbnail) !== 3 * $width * $height) {
			throw new TInvalidDataValueException('jfif_thumbnail_invalid_data');
		}
		return pack(
			'a5CCCnnCC',
			self::IDENTIFIER,
			$this->getVersionMajor(),
			$this->getVersionMinor(),
			$this->getUnits(),
			$this->getXDensity(),
			$this->getYDensity(),
			$width,
			$height,
		) . $thumbnail;
	}
}
