<?php

/**
 * TTIFF class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image;

use Prado\IO\Compression\TCCITTFaxCompressor;
use Prado\IO\Compression\THorizontalPredictor;
use Prado\IO\Compression\TLZWCompressor;
use Prado\IO\Compression\TPackBitsCompressor;
use Prado\IO\Image\Meta\TEXIF;
use Prado\IO\Image\TIFF\TTIFFDataType;
use Prado\IO\Image\TIFF\TTIFFDocument;
use Prado\IO\Image\TIFF\TTIFFRaster;
use Prado\IO\Stream\TFreeSpaceStream;
use Prado\IO\Stream\TReservedSpaceMode;
use Prado\IO\Stream\TReservedSpaceStream;
use Prado\IO\TStream;
use Prado\Prado;

/**
 * TTIFF class.
 *
 * Reads a TIFF image file: the pixel dimensions from IFD0's ImageWidth/ImageLength,
 * the full EXIF-family metadata through {@see getEXIF()} (every IFD, the GPS and
 * makernote data, the embedded XMP and Photoshop IRB), the {@see getIPTC() IPTC}
 * record set from tag 33723 (falling back to the IRB's 0x0404 resource), and the
 * {@see getICCProfile() ICC profile} from tag 34675.
 *
 * The container is **read-write**: the strip/tile pixel data every IFD references is
 * captured on parse and re-emitted with recomputed offsets on {@see toBinary()}/
 * {@see save()}, so metadata edits through {@see getEXIF()}, {@see setIPTC()}, and
 * {@see setICCProfile()} rewrite the file with the image data intact.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TTIFF extends TImageFile
{
	/** The IFD0 ImageWidth tag. */
	public const WidthTag = 256;

	/** The IFD0 ImageLength tag. */
	public const HeightTag = 257;

	/** The IFD0 ICC profile (InterColorProfile) tag. */
	public const ICCTag = 34675;

	/** No compression (TIFF Compression 1). */
	public const CompressionNone = 1;

	/** CCITT Modified Huffman RLE (TIFF Compression 2). */
	public const CompressionCcittRle = 2;

	/** CCITT Group 3 / T.4 (TIFF Compression 3). */
	public const CompressionGroup3 = 3;

	/** CCITT Group 4 / T.6 (TIFF Compression 4). */
	public const CompressionGroup4 = 4;

	/** LZW (TIFF Compression 5). */
	public const CompressionLzw = 5;

	/** PackBits (TIFF Compression 32773). */
	public const CompressionPackBits = 32773;

	/** @var ?TEXIF The parsed metadata. */
	private ?TEXIF $_exif = null;

	/**
	 * Indicates whether the bytes begin with a TIFF byte-order mark and magic number.
	 * @param string $data The candidate bytes.
	 * @return bool Whether the data is a TIFF.
	 */
	public static function isTIFF(string $data): bool
	{
		return strlen($data) >= 4
			&& (str_starts_with($data, "II\x2A\x00") || str_starts_with($data, "MM\x00\x2A"));
	}

	/**
	 * Returns the format name.
	 * @return string Always 'TIFF'.
	 */
	public function getFormat(): string
	{
		return 'TIFF';
	}

	/**
	 * Returns the parsed EXIF-family metadata.
	 * @return ?TEXIF The metadata, or null when parsing failed.
	 */
	public function getEXIF(): ?TEXIF
	{
		return $this->_exif;
	}

	/**
	 * Returns the underlying TIFF structure.
	 * @return ?TTIFFDocument The document, or null when parsing failed.
	 */
	public function getTiff(): ?TTIFFDocument
	{
		return $this->_exif?->getTiff();
	}

	/**
	 * Parses the TIFF structure, dimensions, IPTC, and ICC profile.
	 */
	protected function parse(): void
	{
		$this->_exif = TEXIF::fromTiffString($this->getBytesDirect());
		$ifd0 = $this->_exif->getIfd0();
		$width = $ifd0->getTagValue(self::WidthTag);
		$height = $ifd0->getTagValue(self::HeightTag);
		$this->setWidthDirect(is_int($width) ? $width : null);
		$this->setHeightDirect(is_int($height) ? $height : null);
		$this->setIptcDirect($this->_exif->getIPTC() ?? $this->_exif->getIRB()?->getIPTC());
		$icc = $ifd0->getTag(self::ICCTag);
		if ($icc !== null) {
			$data = $icc->getValues();
			$this->setICCProfileDirect(is_array($data) ? implode('', array_map('chr', $data)) : (string) $data);
		}
	}

	/**
	 * Decodes the raster into a graphics-library image through {@see TTIFFRaster}:
	 * strips or tiles, either planar configuration, 1 to 16 bits per sample, either
	 * fill order, the uncompressed/PackBits/LZW/CCITT codings, and the grayscale, RGB,
	 * palette, CMYK, YCbCr, and L*a*b* photometrics.
	 * @param ?string $mode The {@see TImageGraphicsMode} to build in; null for the default.
	 * @return false|\GdImage|\Imagick The image, or false when the raster form is unsupported.
	 */
	public function getImage(?string $mode = null): false|\GdImage|\Imagick
	{
		$ifd0 = $this->_exif?->getIfd0();
		$rgb = $ifd0 === null ? null : TTIFFRaster::toRgb($ifd0, (int) $this->getWidth(), (int) $this->getHeight());
		if ($rgb === null) {
			return false;
		}
		return TImageGraphics::fromRgbPixels($rgb, $this->getWidth(), $this->getHeight(), $mode);
	}

	/**
	 * Builds a new TIFF from a graphics-library image.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $compression A Compression* constant. The CCITT modes reduce the image
	 *   to black-and-white; the others store 8-bit RGB. Default {@see CompressionLzw}.
	 * @return static The composed TIFF.
	 */
	public static function fromImage(\GdImage|\Imagick $image, int $compression = self::CompressionLzw): static
	{
		$tiff = Prado::createComponent(static::class);
		$exif = new TEXIF();
		$exif->setSignature('');
		$tiff->_exif = $exif;
		$tiff->encodeImage($image, $compression);
		$tiff->load($tiff->compose());
		return $tiff;
	}

	/**
	 * Replaces the raster with an image, keeping the other metadata tags.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $compression A Compression* constant. Default {@see CompressionLzw}.
	 */
	public function setImage(\GdImage|\Imagick $image, int $compression = self::CompressionLzw): void
	{
		if ($this->_exif === null) {
			$this->_exif = new TEXIF();
			$this->_exif->setSignature('');
		}
		$this->encodeImage($image, $compression);
		$this->setBytesDirect($this->compose());
		$this->setWidthDirect($this->_exif->getIfd0()->getTagValue(self::WidthTag));
		$this->setHeightDirect($this->_exif->getIfd0()->getTagValue(self::HeightTag));
	}

	/**
	 * Encodes an image into IFD0's raster tags and strip data.
	 * @param \GdImage|\Imagick $image The source image.
	 * @param int $compression A Compression* constant.
	 */
	protected function encodeImage(\GdImage|\Imagick $image, int $compression): void
	{
		[$width, $height] = TImageGraphics::getSize($image);
		$ifd0 = $this->_exif->getIfd0();
		$bilevel = $compression === self::CompressionCcittRle || $compression === self::CompressionGroup3 || $compression === self::CompressionGroup4;

		if ($bilevel) {
			$mono = TImageGraphics::monoPixels($image, true);
			$rowBytes = intdiv($width + 7, 8);
			$data = str_repeat("\0", $rowBytes * $height);
			for ($y = 0; $y < $height; $y++) {
				for ($x = 0; $x < $width; $x++) {
					if ($mono[$y * $width + $x] === "\x00") {   // black pixel: set bit
						$i = $y * $rowBytes + ($x >> 3);
						$data[$i] = chr(ord($data[$i]) | (0x80 >> ($x & 7)));
					}
				}
			}
			$mode = match ($compression) {
				self::CompressionCcittRle => TCCITTFaxCompressor::ModifiedHuffman,
				self::CompressionGroup3 => TCCITTFaxCompressor::Group3,
				default => TCCITTFaxCompressor::Group4,
			};
			$strip = (new TCCITTFaxCompressor($width, $mode))->encode($data);
			$bits = [1];
			$samples = 1;
			$photometric = 0;   // white is zero: a set bit is black
		} else {
			$data = TImageGraphics::rgbPixels($image);
			$strip = match ($compression) {
				self::CompressionPackBits => TPackBitsCompressor::compress($data),
				self::CompressionLzw => TLZWCompressor::compress(THorizontalPredictor::encode($data, $width, 3)),
				default => $data,
			};
			$bits = [8, 8, 8];
			$samples = 3;
			$photometric = 2;
		}

		$ifd0->setTagValues(self::WidthTag, TTIFFDataType::ULong, [$width]);
		$ifd0->setTagValues(self::HeightTag, TTIFFDataType::ULong, [$height]);
		$ifd0->setTagValues(258, TTIFFDataType::UShort, $bits);
		$ifd0->setTagValues(259, TTIFFDataType::UShort, [$compression]);
		$ifd0->setTagValues(262, TTIFFDataType::UShort, [$photometric]);
		$ifd0->setTagValues(277, TTIFFDataType::UShort, [$samples]);
		$ifd0->setTagValues(278, TTIFFDataType::ULong, [$height]);
		if ($compression === self::CompressionLzw) {
			$ifd0->setTagValues(317, TTIFFDataType::UShort, [2]);
		} else {
			$ifd0->removeTag(317);
		}
		$offsets = $ifd0->setTagValues(273, TTIFFDataType::ULong, [0]);
		$ifd0->setTagValues(279, TTIFFDataType::ULong, [strlen($strip)]);
		$offsets->setExternalData([$strip]);
	}

	/**
	 * Recomposes the TIFF: the live IPTC and ICC profile are synced into their tags,
	 * every offset is recomputed, and the captured strip/tile data is re-emitted.
	 * @return string The composed TIFF bytes.
	 */
	/**
	 * Returns the XMP packet text of IFD0's tag 700.
	 * @return ?string The packet text, or null when absent.
	 */
	public function getXmpText(): ?string
	{
		return $this->_exif?->getXmpText();
	}

	/**
	 * Sets (or removes, when null) the XMP packet in IFD0's tag 700.
	 * @param ?string $xmp The packet text, or null to drop the tag.
	 */
	public function setXmpText(?string $xmp): void
	{
		if ($this->_exif === null) {
			if ($xmp === null) {
				return;
			}
			$this->_exif = new TEXIF();
			$this->_exif->setSignature('');
		}
		$this->_exif->setXmpText($xmp);
	}

	/**
	 * Returns the XMP packet parsed as a {@see \Prado\IO\Image\Meta\TXMP} DOM.
	 * @return ?\Prado\IO\Image\Meta\TXMP The XMP, or null when absent or unparsable.
	 */
	public function getXMP(): ?\Prado\IO\Image\Meta\TXMP
	{
		return $this->_exif?->getXMP();
	}

	/**
	 * Sets (or removes, when null) the XMP packet.
	 * @param ?\Prado\IO\Image\Meta\TXMP $xmp The XMP, or null to drop the tag.
	 */
	public function setXMP(?\Prado\IO\Image\Meta\TXMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns the Photoshop image-resource block of IFD0's tag 34377.
	 * @return ?TPhotoshopIRB The resource block, or null when absent.
	 */
	public function getPhotoshopIRB(): ?TPhotoshopIRB
	{
		return $this->_exif?->getIRB();
	}

	/**
	 * Sets (or removes, when null) the Photoshop image-resource block.
	 * @param ?TPhotoshopIRB $irb The resource block, or null to drop the tag.
	 */
	public function setPhotoshopIRB(?TPhotoshopIRB $irb): void
	{
		if ($this->_exif === null) {
			if ($irb === null) {
				return;
			}
			$this->_exif = new TEXIF();
			$this->_exif->setSignature('');
		}
		$this->_exif->setIRB($irb);
	}

	/**
	 * Returns the reserved private spaces within {@see toBinary()}: the maker note and any
	 * other {@see \Prado\IO\Image\TIFF\TTIFFTag::setPreserveOffset() pinned} value area, as
	 * `[offset, length]` pairs — the same ranges the writer lays every other field around.
	 * @return array<int, array{0: int, 1: int}> The reserved [offset, length] pairs.
	 */
	public function getReservedSpaces(): array
	{
		return $this->_exif?->getReservedSpaces() ?? [];
	}

	/**
	 * Returns the composed TIFF as a {@see TReservedSpaceStream}: the file stays
	 * addressable at its own offsets while the reserved private spaces are protected on
	 * write according to $mode.
	 * @param string $mode A {@see TReservedSpaceMode} constant. Default Clip.
	 * @return TReservedSpaceStream The reserved-space view of the composed bytes.
	 */
	public function toReservedSpaceStream(string $mode = TReservedSpaceMode::Clip): TReservedSpaceStream
	{
		$bytes = $this->toBinary();
		return new TReservedSpaceStream(TStream::fromString($bytes), $this->getReservedSpaces(), $mode);
	}

	/**
	 * Returns the composed TIFF as a {@see TFreeSpaceStream}: only the non-reserved bytes
	 * appear as one contiguous stream, so a consumer edits around the maker notes without
	 * seeing them.
	 * @return TFreeSpaceStream The free-space view of the composed bytes.
	 */
	public function toFreeSpaceStream(): TFreeSpaceStream
	{
		$bytes = $this->toBinary();
		return new TFreeSpaceStream(TStream::fromString($bytes), $this->getReservedSpaces());
	}

	/**
	 * Extends {@see clearPrivateData()} to the EXIF document itself.  A TIFF file *is* its
	 * EXIF structure — there is no separate `setEXIF()` for the shared fan-out to write
	 * back through — so the live document is scrubbed in place here; the XMP, IRB, and
	 * IPTC it carries are reached by the shared carriers.
	 * @param int $types The {@see TPrivacyCategory} flags to remove.
	 * @return int The number of tags and directories removed.
	 */
	protected function clearFormatPrivateData(int $types): int
	{
		return $this->getEXIF()?->clearPrivateData($types) ?? 0;
	}

	protected function compose(): string
	{
		if ($this->_exif === null) {
			return $this->getBytesDirect();
		}
		$this->_exif->setIPTC($this->getIptcDirect());
		$icc = $this->getICCProfileDirect();
		if ($icc === null) {
			$this->_exif->getIfd0()->removeTag(self::ICCTag);
		} else {
			$this->_exif->getIfd0()->setTagValues(self::ICCTag, TTIFFDataType::Undefined, $icc);
		}
		return $this->_exif->toBinary();
	}
}
