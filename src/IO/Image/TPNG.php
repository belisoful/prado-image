<?php

/**
 * TPNG class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image;

use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Exceptions\TIOException;
use Prado\IO\Image\Meta\TIPTC;
use Prado\IO\Image\PNG\TAPNGFrame;
use Prado\IO\Image\PNG\TPNGChunkType;
use Prado\IO\TStream;
use Prado\IO\Util\TStreamHelper;
use Prado\Prado;
use Psr\Http\Message\StreamInterface;

/**
 * TPNG class.
 *
 * Reads and writes a PNG by walking its chunk sequence.  It reports the pixel dimensions
 * from the IHDR chunk and exposes every chunk through {@see getChunks()}/{@see getChunk()},
 * with {@see setChunk()}/{@see addChunk()}/{@see removeChunk()} maintaining the normative
 * {@see ChunkOrder} and {@see compose()} recomputing every CRC.
 *
 * The metadata carriers are read-write: {@see getICCProfile()}/{@see setICCProfile()} (the
 * deflated `iCCP` chunk), {@see getEXIF()}/{@see setEXIF()} (the `eXIf` chunk),
 * {@see getXMP()}/{@see setXMP()} (the `iTXt` packet), and
 * {@see getPhotoshopIRB()}/{@see setPhotoshopIRB()} with
 * {@see getIPTC()}/{@see setIPTC()} on top of it.  PNG defines no IPTC chunk, so the
 * resource block travels in the hex-encoded {@see IrbKeyword} text chunk that ImageMagick,
 * Photoshop, and ExifTool exchange.  The raster round-trips through
 * {@see getImage()}/{@see setImage()}, which carry {@see CarriedChunks} across a re-encode.
 *
 * ```php
 * $png = TPNG::fromFile('image.png');
 * [$w, $h] = [$png->getWidth(), $png->getHeight()];
 * $png->setICCProfile($profile);
 * $png->setEXIF($exif);
 * $png->save('image.png');
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TPNG extends TImageFile
{
	/** @var string The 8-byte PNG signature. */
	public const Signature = "\x89PNG\r\n\x1a\n";

	/** @var string The iTXt keyword under which PNG carries an XMP packet. */
	public const XmpKeyword = 'XML:com.adobe.xmp';

	/** @var string The profile name written in the iCCP chunk. */
	public const ICCProfileName = 'ICC Profile';

	/**
	 * @var string The text-chunk keyword under which the Photoshop image-resource block
	 *   (and so the IPTC records inside it) is carried.  PNG defines no IPTC chunk; this
	 *   hex-in-a-text-chunk convention is what ImageMagick and ExifTool read and write.
	 */
	public const IrbKeyword = 'Raw profile type 8bim';

	/** @var string The text-chunk keyword of a bare IIM block, read for interoperability. */
	public const IptcKeyword = 'Raw profile type iptc';

	/**
	 * @var string[] The chunk order the PNG specification requires: the header, the
	 *   colour-space chunks before any palette, the palette and its dependents, then the
	 *   ancillary and textual chunks, then the consecutive image data and the end marker.
	 *   {@see setChunk()}/{@see addChunk()} place a chunk by this ranking rather than
	 *   appending it, so a position-sensitive chunk authored fresh lands where its
	 *   specification requires — the APNG animation control before the image data, the
	 *   APNG frame data after it, and the colour-volume chunks before any palette.
	 */
	public const ChunkOrder = [
		TPNGChunkType::Header,
		TPNGChunkType::CodingIndependentCodePoints, TPNGChunkType::MasteringDisplayColorVolume, TPNGChunkType::ContentLightLevel,                       // PNG Third Edition colour-volume, before the palette
		TPNGChunkType::Chromaticities, TPNGChunkType::Gamma, TPNGChunkType::ICCProfile, TPNGChunkType::SignificantBits, TPNGChunkType::StandardRgb,
		TPNGChunkType::AnimationControl,                        // APNG acTL: before PLTE and the first IDAT
		TPNGChunkType::Palette, TPNGChunkType::BackgroundColor, TPNGChunkType::Histogram, TPNGChunkType::Transparency, TPNGChunkType::PhysicalDimensions, TPNGChunkType::SuggestedPalette, TPNGChunkType::ModificationTime, TPNGChunkType::Exif,
		TPNGChunkType::FrameControl,                       // the default image's fcTL sits before IDAT
		TPNGChunkType::Text, TPNGChunkType::CompressedText, TPNGChunkType::InternationalText,
		TPNGChunkType::ImageData,
		TPNGChunkType::FrameData,                          // APNG fdAT: the animation frames follow the default image
		TPNGChunkType::End,
	];

	/** @var string[] The textual chunk types, the only carriers PNG allows to repeat. */
	public const TextChunks = [TPNGChunkType::Text, TPNGChunkType::CompressedText, TPNGChunkType::InternationalText];

	/**
	 * @var string[] The chunk types {@see setImage()} carries onto re-encoded pixels: the
	 *   metadata and colour-space chunks, but not the palette or the pixel-dependent
	 *   chunks (PLTE, tRNS, bKGD, hIST, sBIT, sPLT), which belong to the old raster.
	 */
	public const CarriedChunks = [
		TPNGChunkType::Chromaticities, TPNGChunkType::Gamma, TPNGChunkType::ICCProfile, TPNGChunkType::StandardRgb, TPNGChunkType::PhysicalDimensions, TPNGChunkType::ModificationTime, TPNGChunkType::Exif, TPNGChunkType::Text, TPNGChunkType::CompressedText, TPNGChunkType::InternationalText,
	];

	/** @var array<int, TImageChunk> The chunks in file order. */
	private array $_chunks = [];

	/**
	 * Returns the format name.
	 * @return string Always 'PNG'.
	 */
	public function getFormat(): string
	{
		return 'PNG';
	}

	/**
	 * Indicates whether the bytes begin with the PNG signature.
	 * @param string $data The candidate image bytes.
	 * @return bool Whether the data is a PNG.
	 */
	public static function isPNG(string $data): bool
	{
		return strncmp($data, self::Signature, 8) === 0;
	}

	/**
	 * Returns the XMP packet text of the `iTXt` chunk keyed {@see XmpKeyword}.
	 * @return ?string The packet text, or null when absent.
	 */
	public function getXmpText(): ?string
	{
		return $this->getTextChunk(self::XmpKeyword);
	}

	/**
	 * Sets (or removes, when null) the XMP packet, written as the uncompressed `iTXt`
	 * chunk the XMP specification requires, placed before the image data.
	 * @param ?string $xmp The packet text, or null to drop the chunk.
	 */
	public function setXmpText(?string $xmp): void
	{
		$this->removeTextChunk(self::XmpKeyword);
		if ($xmp === null) {
			return;
		}
		$payload = self::XmpKeyword . "\0" . "\x00\x00" . "\0" . "\0" . $xmp;
		$this->addChunk(new TImageChunk(TPNGChunkType::InternationalText, strlen($payload), 0, $payload));
	}

	/**
	 * Returns the XMP packet parsed as a {@see \Prado\IO\Image\Meta\TXMP} DOM.
	 * @return ?\Prado\IO\Image\Meta\TXMP The XMP, or null when absent or unparsable.
	 */
	public function getXMP(): ?\Prado\IO\Image\Meta\TXMP
	{
		$text = $this->getXmpText();
		if ($text === null) {
			return null;
		}
		$xmp = \Prado\IO\Image\Meta\TXMP::parse($text);
		return $xmp === false ? null : $xmp;
	}

	/**
	 * Sets (or removes, when null) the XMP packet.
	 * @param ?\Prado\IO\Image\Meta\TXMP $xmp The XMP, or null to drop the chunk.
	 */
	public function setXMP(?\Prado\IO\Image\Meta\TXMP $xmp): void
	{
		$this->setXmpText($xmp?->toPacketText());
	}

	/**
	 * Returns all chunks in file order.
	 * @return array<int, TImageChunk> The chunks.
	 */
	public function getChunks(): array
	{
		return $this->_chunks;
	}

	/**
	 * Returns the first chunk of a given type.
	 * @param string $type The four-character chunk type (e.g. TPNGChunkType::Header, TPNGChunkType::Palette).
	 * @return ?TImageChunk The chunk, or null when absent.
	 */
	public function getChunk(string $type): ?TImageChunk
	{
		foreach ($this->getChunks() as $chunk) {
			if ($chunk->getType() === $type) {
				return $chunk;
			}
		}
		return null;
	}

	/**
	 * Replaces the whole chunk list.  Ordering is the caller's responsibility here; the
	 * chunk-at-a-time {@see setChunk()} and {@see addChunk()} maintain {@see ChunkOrder}
	 * instead.  {@see compose()} recomputes every CRC, so a chunk needs none.
	 * @param array<int, TImageChunk> $chunks The chunks in file order.
	 * @throws TInvalidDataTypeException When a value is not a {@see TImageChunk}.
	 */
	public function setChunks(array $chunks): void
	{
		foreach ($chunks as $chunk) {
			if (!$chunk instanceof TImageChunk) {
				throw new TInvalidDataTypeException('png_chunk_invalid', get_debug_type($chunk));
			}
		}
		$this->_chunks = array_values($chunks);
	}

	/**
	 * Stores a chunk: an existing chunk of the same type is replaced in place, otherwise
	 * the chunk is inserted where {@see ChunkOrder} puts it — PNG's chunk order is
	 * normative, so appending would produce a file with data after IEND.  A type absent
	 * from the ordering is placed before IEND, which is valid for any ancillary chunk.
	 * @param TImageChunk $chunk The chunk.
	 */
	public function setChunk(TImageChunk $chunk): void
	{
		foreach ($this->_chunks as $i => $existing) {
			if ($existing->getType() === $chunk->getType()) {
				$this->_chunks[$i] = $chunk;
				return;
			}
		}
		$this->addChunk($chunk);
	}

	/**
	 * Inserts a chunk at its {@see ChunkOrder} position even when one of the same type is
	 * present, for the types PNG allows more than once (IDAT and the text chunks).
	 * @param TImageChunk $chunk The chunk.
	 */
	public function addChunk(TImageChunk $chunk): void
	{
		$rank = array_search($chunk->getType(), self::ChunkOrder, true);
		if ($rank === false) {
			$rank = (int) array_search(TPNGChunkType::End, self::ChunkOrder, true);
		}
		foreach ($this->_chunks as $i => $existing) {
			$existingRank = array_search($existing->getType(), self::ChunkOrder, true);
			if ($existingRank !== false && $existingRank >= $rank) {
				array_splice($this->_chunks, $i, 0, [$chunk]);
				return;
			}
		}
		$this->_chunks[] = $chunk;
	}

	/**
	 * Removes every chunk of a type.
	 * @param string $type The four-character chunk type.
	 * @return bool Whether a chunk was removed.
	 */
	public function removeChunk(string $type): bool
	{
		$before = count($this->_chunks);
		$this->_chunks = array_values(array_filter($this->_chunks, fn (TImageChunk $c): bool => $c->getType() !== $type));
		return count($this->_chunks) !== $before;
	}

	/**
	 * Returns the ICC color profile, inflated from the `iCCP` chunk.
	 * @return ?string The ICC profile bytes, or null when absent or undecodable.
	 */
	public function getICCProfile(): ?string
	{
		$payload = $this->getChunk(TPNGChunkType::ICCProfile)?->getData();
		if ($payload === null) {
			return null;
		}
		$nul = strpos($payload, "\x00");
		if ($nul === false || $nul + 2 > strlen($payload)) {
			return null;
		}
		$profile = @gzuncompress(substr($payload, $nul + 2));   // skip the name, NUL, and method byte
		return $profile === false ? null : $profile;
	}

	/**
	 * Sets (or removes, when null) the ICC color profile, written as the deflated `iCCP`
	 * chunk before any palette or image data.
	 * @param ?string $profile The ICC profile bytes, or null to drop the chunk.
	 */
	public function setICCProfile(?string $profile): void
	{
		if ($profile === null) {
			$this->removeChunk(TPNGChunkType::ICCProfile);
			return;
		}
		$payload = self::ICCProfileName . "\x00" . "\x00" . gzcompress($profile);
		$this->setChunk(new TImageChunk(TPNGChunkType::ICCProfile, strlen($payload), 0, $payload));
	}

	/**
	 * Returns the EXIF metadata of the `eXIf` chunk, whose payload is bare TIFF bytes
	 * (PNG carries no `Exif\0\0` segment signature).
	 * @return ?\Prado\IO\Image\Meta\TEXIF The EXIF, or null when absent or unparsable.
	 */
	public function getEXIF(): ?\Prado\IO\Image\Meta\TEXIF
	{
		$data = $this->getChunk(TPNGChunkType::Exif)?->getData();
		if ($data === null || $data === '') {
			return null;
		}
		try {
			$exif = \Prado\IO\Image\Meta\TEXIF::fromTiffString($data);
		} catch (TIOException $e) {
			return null;
		}
		$exif->setSignature('');
		return $exif;
	}

	/**
	 * Sets (or removes, when null) the EXIF metadata as an `eXIf` chunk.
	 * @param ?\Prado\IO\Image\Meta\TEXIF $exif The EXIF, or null to drop the chunk.
	 */
	public function setEXIF(?\Prado\IO\Image\Meta\TEXIF $exif): void
	{
		if ($exif === null) {
			$this->removeChunk(TPNGChunkType::Exif);
			return;
		}
		$exif->setSignature('');   // the chunk holds no segment signature
		$data = $exif->toBinary();
		$this->setChunk(new TImageChunk(TPNGChunkType::Exif, strlen($data), 0, $data));
	}

	/**
	 * Returns the Photoshop image-resource block carried in the {@see IrbKeyword} text
	 * chunk.
	 * @return ?TPhotoshopIRB The resource block, or null when absent or undecodable.
	 */
	public function getPhotoshopIRB(): ?TPhotoshopIRB
	{
		$data = $this->getRawProfile(self::IrbKeyword);
		if ($data === null) {
			return null;
		}
		$irb = TPhotoshopIRB::parse($data);
		return $irb === false ? null : $irb;
	}

	/**
	 * Sets (or removes, when null) the Photoshop image-resource block, written as the
	 * hex-encoded {@see IrbKeyword} text chunk ImageMagick and ExifTool interoperate on.
	 * @param ?TPhotoshopIRB $irb The resource block, or null to drop it.
	 */
	public function setPhotoshopIRB(?TPhotoshopIRB $irb): void
	{
		$this->setRawProfile(self::IrbKeyword, '8bim', $irb?->toBinary());
	}

	/**
	 * Returns the IPTC record set from the Photoshop resource block, falling back to a
	 * bare IIM block in the {@see IptcKeyword} chunk that other writers produce.
	 * @return ?TIPTC The IPTC record set, or null when absent.
	 */
	public function getIPTC(): ?TIPTC
	{
		$iptc = $this->getPhotoshopIRB()?->getIPTC();
		if ($iptc !== null) {
			return $iptc;
		}
		$bare = $this->getRawProfile(self::IptcKeyword);
		if ($bare === null) {
			return null;
		}
		$parsed = TIPTC::parse($bare);
		return $parsed === false ? null : $parsed;
	}

	/**
	 * Sets (or removes, when null) the IPTC record set, stored inside the Photoshop
	 * resource block of the {@see IrbKeyword} chunk — PNG defines no IPTC chunk of its
	 * own, and this is the form ImageMagick, Photoshop, and ExifTool exchange.  Any bare
	 * {@see IptcKeyword} block is dropped so the two cannot disagree.
	 * @param ?TIPTC $iptc The IPTC record set, or null to drop it.
	 */
	public function setIPTC(?TIPTC $iptc): void
	{
		$this->setRawProfile(self::IptcKeyword, 'iptc', null);
		$irb = $this->getPhotoshopIRB();
		if ($iptc === null) {
			if ($irb === null) {
				return;
			}
			$irb->setIPTC(null);
			$this->setPhotoshopIRB($irb->getResources() === [] ? null : $irb);
			return;
		}
		$irb ??= new TPhotoshopIRB();
		$irb->setIPTC($iptc);
		$this->setPhotoshopIRB($irb);
	}

	/**
	 * Decodes a `Raw profile type <name>` text chunk: a newline, the profile name, a
	 * newline, the byte count, a newline, then the bytes in hexadecimal.  The hexadecimal
	 * is read tolerantly — any line breaking or padding a writer chose is ignored.
	 * @param string $keyword The text-chunk keyword.
	 * @return ?string The profile bytes, or null when the chunk is absent or malformed.
	 */
	protected function getRawProfile(string $keyword): ?string
	{
		$text = $this->getTextChunk($keyword);
		if ($text === null) {
			return null;
		}
		$parts = explode("\n", ltrim($text, "\n"), 3);
		if (count($parts) < 3) {
			return null;
		}
		$hex = preg_replace('/[^0-9A-Fa-f]/', '', $parts[2]) ?? '';
		if ($hex === '' || strlen($hex) % 2 !== 0) {
			return null;
		}
		$bytes = @hex2bin($hex);
		return $bytes === false ? null : $bytes;
	}

	/**
	 * Writes (or removes, when null) a `Raw profile type <name>` text chunk, deflated as
	 * `zTXt` the way a profile-bearing chunk is normally stored.
	 * @param string $keyword The text-chunk keyword.
	 * @param string $name The profile name written in the payload.
	 * @param ?string $bytes The profile bytes, or null to remove the chunk.
	 */
	protected function setRawProfile(string $keyword, string $name, ?string $bytes): void
	{
		$this->removeTextChunk($keyword);
		if ($bytes === null) {
			return;
		}
		$text = "\n" . $name . "\n" . sprintf('%8d', strlen($bytes)) . "\n"
			. rtrim(chunk_split(bin2hex($bytes), 72, "\n"), "\n") . "\n";
		$payload = $keyword . "\x00" . "\x00" . gzcompress($text);
		$this->addChunk(new TImageChunk(TPNGChunkType::CompressedText, strlen($payload), 0, $payload));
	}

	/**
	 * Returns the text of the first `tEXt`, `zTXt`, or `iTXt` chunk with a keyword,
	 * inflating a compressed one.
	 * @param string $keyword The chunk keyword.
	 * @return ?string The text, or null when absent or undecodable.
	 */
	protected function getTextChunk(string $keyword): ?string
	{
		$prefix = $keyword . "\x00";
		foreach ($this->_chunks as $chunk) {
			$type = $chunk->getType();
			$data = $chunk->getData();
			if (!in_array($type, self::TextChunks, true) || !str_starts_with($data, $prefix)) {
				continue;
			}
			$rest = substr($data, strlen($prefix));
			if ($type === TPNGChunkType::Text) {
				return $rest;
			}
			if ($type === TPNGChunkType::CompressedText) {
				$inflated = @gzuncompress(substr($rest, 1));   // one compression-method byte
				return $inflated === false ? null : $inflated;
			}
			$text = $this->iTxtText($rest);
			if ($text !== null) {
				return $text;
			}
		}
		return null;
	}

	/**
	 * Removes every `tEXt`, `zTXt`, or `iTXt` chunk with a keyword.
	 * @param string $keyword The chunk keyword.
	 */
	protected function removeTextChunk(string $keyword): void
	{
		$prefix = $keyword . "\x00";
		$this->_chunks = array_values(array_filter(
			$this->_chunks,
			fn (TImageChunk $c): bool => !in_array($c->getType(), self::TextChunks, true)
				|| !str_starts_with($c->getData(), $prefix),
		));
	}

	/**
	 * Extends {@see clearPrivateData()} to the carriers only a PNG has: the free-text
	 * `tEXt`/`zTXt`/`iTXt` chunks — Title, Author, Description, Comment, Software,
	 * Creation Time, Source, and any other keyword ({@see TPrivacyCategory::Description},
	 * with the Author/Software/Creation Time keywords also under their own flags).  The
	 * XMP packet's `iTXt` is not removed here; it is redacted in place by the XMP scrub so
	 * its non-identifying properties survive, and the `Raw profile type` chunks are
	 * reached through the IRB/IPTC scrubs the same way.
	 * @param int $types The {@see TPrivacyCategory} flags to remove.
	 * @return int The number of text chunks removed.
	 */
	protected function clearFormatPrivateData(int $types): int
	{
		$keywordFlags = [
			'Author' => TPrivacyCategory::Author, 'Copyright' => TPrivacyCategory::Author,
			'Software' => TPrivacyCategory::Software,
			'Creation Time' => TPrivacyCategory::Timestamp,
			'Source' => TPrivacyCategory::CameraModel | TPrivacyCategory::Software,
		];
		$removed = 0;
		foreach ($this->_chunks as $index => $chunk) {
			if (!in_array($chunk->getType(), self::TextChunks, true)) {
				continue;
			}
			$nul = strpos($chunk->getData(), "\0");
			$keyword = $nul === false ? '' : substr($chunk->getData(), 0, $nul);
			if ($keyword === self::XmpKeyword || str_starts_with($keyword, 'Raw profile type')) {
				continue;   // redacted by their own carrier scrubs, not dropped
			}
			$flags = ($keywordFlags[$keyword] ?? 0) | TPrivacyCategory::Description;
			if ($types & $flags) {
				unset($this->_chunks[$index]);
				$removed++;
			}
		}
		$this->_chunks = array_values($this->_chunks);
		return $removed;
	}

	/**
	 * Decodes the text of an `iTXt` payload after its keyword: a compression flag and
	 * method, the language tag, the translated keyword, then the text.
	 * @param string $rest The payload after the keyword and its NUL.
	 * @return ?string The text, or null when the payload is malformed.
	 */
	protected function iTxtText(string $rest): ?string
	{
		$compressed = ord($rest[0] ?? "\0");
		$position = strpos($rest, "\0", 2);
		if ($position === false) {
			return null;
		}
		$position = strpos($rest, "\0", $position + 1);
		if ($position === false) {
			return null;
		}
		$text = substr($rest, $position + 1);
		if ($compressed !== 1) {
			return $text;
		}
		$inflated = @gzuncompress($text);
		return $inflated === false ? null : $inflated;
	}

	/**
	 * Decodes the PNG image data into a graphics-library image.
	 * @param ?string $mode The {@see TImageGraphicsMode} to decode in; null for the default.
	 * @return false|\GdImage|\Imagick The image, or false when undecodable.
	 */
	public function getImage(?string $mode = null): false|\GdImage|\Imagick
	{
		return TImageGraphics::decode($this->getBytesDirect(), $mode);
	}

	/**
	 * Re-encodes the PNG from a graphics-library image, carrying the metadata and
	 * colour-space chunks ({@see CarriedChunks}) onto the new image data so an edit does
	 * not strip them.  The palette and the other pixel-dependent chunks belong to the old
	 * raster and are not carried.
	 * @param \GdImage|\Imagick $image The source image.
	 * @throws TIOException When the image's graphics library cannot write PNG.
	 */
	public function setImage(\GdImage|\Imagick $image): void
	{
		$bytes = TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng);
		if ($bytes === false) {
			throw new TIOException('png_encode_unavailable', TImageGraphics::getModeOf($image));
		}
		$carried = [];
		foreach ($this->_chunks as $chunk) {
			if (in_array($chunk->getType(), self::CarriedChunks, true)) {
				$carried[] = $chunk;
			}
		}
		$this->load($bytes);
		foreach ($carried as $chunk) {
			$this->carryChunk($chunk);
		}
		$this->setBytesDirect($this->compose());
	}

	/**
	 * Restores a chunk onto a freshly encoded PNG.  All but the textual chunks may appear
	 * only once, so they replace whatever the encoder wrote rather than joining it; a
	 * textual chunk is added, displacing only an earlier chunk of the same keyword.
	 * @param TImageChunk $chunk The chunk to carry over.
	 */
	protected function carryChunk(TImageChunk $chunk): void
	{
		if (!in_array($chunk->getType(), self::TextChunks, true)) {
			$this->setChunk($chunk);
			return;
		}
		$data = $chunk->getData();
		$nul = strpos($data, "\0");
		if ($nul !== false) {
			$this->removeTextChunk(substr($data, 0, $nul));
		}
		$this->addChunk($chunk);
	}

	/**
	 * Creates a PNG from a graphics-library image.
	 * @param \GdImage|\Imagick $image The source image.
	 * @throws TIOException When the image's graphics library cannot write PNG.
	 * @return static The new PNG.
	 */
	public static function fromImage(\GdImage|\Imagick $image): static
	{
		$bytes = TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng);
		if ($bytes === false) {
			throw new TIOException('png_encode_unavailable', TImageGraphics::getModeOf($image));
		}
		return static::fromString($bytes);
	}

	//
	// ─── Animated PNG (APNG) ─────────────────────────────────────────────────
	//

	/**
	 * Indicates whether the image is an animated PNG (it carries an `acTL` chunk).
	 * @return bool Whether the image is animated.
	 */
	public function getIsAnimated(): bool
	{
		return $this->getChunk(TPNGChunkType::AnimationControl) !== null;
	}

	/**
	 * Returns the animation's frame count from the `acTL` chunk.
	 * @return int The frame count, or 0 when the image is not animated.
	 */
	public function getFrameCount(): int
	{
		$data = $this->getChunk(TPNGChunkType::AnimationControl)?->getData();
		return $data !== null && strlen($data) >= 4 ? (int) unpack('N', substr($data, 0, 4))[1] : 0;
	}

	/**
	 * Returns the number of times the animation plays: 0 means loop forever.
	 * @return int The play count, or 0 when the image is not animated.
	 */
	public function getPlayCount(): int
	{
		$data = $this->getChunk(TPNGChunkType::AnimationControl)?->getData();
		return $data !== null && strlen($data) >= 8 ? (int) unpack('N', substr($data, 4, 4))[1] : 0;
	}

	/**
	 * Sets the play count of an existing animation (0 loops forever).
	 * @param int $value The play count.
	 */
	public function setPlayCount(int $value): void
	{
		$control = $this->getChunk(TPNGChunkType::AnimationControl);
		if ($control !== null) {
			$control->setData(pack('NN', $this->getFrameCount(), max(0, $value)));
		}
	}

	/**
	 * Returns the animation frames as {@see TAPNGFrame} objects, each with its `fcTL`
	 * geometry and operations and its joined image data.  The default image (the `IDAT`)
	 * is the first frame when an `fcTL` precedes it; frames are read, not composited, so
	 * their offsets and disposal survive.
	 * @return array<int, TAPNGFrame> The frames in file order (empty when not animated).
	 */
	public function getApngFrames(): array
	{
		$frames = [];
		/** @var ?TAPNGFrame $pending */
		$pending = null;
		$data = '';
		$sawImageData = false;
		$flush = static function () use (&$frames, &$pending, &$data): void {
			if ($pending !== null) {
				$pending->setData($data);
				$frames[] = $pending;
			}
			$pending = null;
			$data = '';
		};
		foreach ($this->_chunks as $chunk) {
			switch ($chunk->getType()) {
				case TPNGChunkType::FrameControl:
					$flush();
					$pending = (new TAPNGFrame())->loadFcTl($chunk->getData());
					$pending->setIsDefault(!$sawImageData);   // an fcTL before any IDAT describes the default image
					break;
				case TPNGChunkType::ImageData:
					$sawImageData = true;
					if ($pending !== null && $pending->getIsDefault()) {
						$data .= $chunk->getData();
					}
					break;
				case TPNGChunkType::FrameData:
					if ($pending !== null) {
						$data .= substr($chunk->getData(), 4);   // drop the four-byte sequence number
					}
					break;
			}
		}
		$flush();
		return $frames;
	}

	/**
	 * Decodes one animation frame's sub-image, reconstructing a standalone PNG from the
	 * frame's data and the header's colour fields.
	 * @param int $index The frame index.
	 * @param ?string $mode The {@see TImageGraphicsMode} to decode in; null for the default.
	 * @throws TIOException When the index is out of range.
	 * @return false|\GdImage|\Imagick The frame image, or false when undecodable.
	 */
	public function getApngFrameImage(int $index, ?string $mode = null): false|\GdImage|\Imagick
	{
		$frames = $this->getApngFrames();
		if (!isset($frames[$index])) {
			throw new TIOException('png_frame_unknown', $index);
		}
		return TImageGraphics::decode($this->frameToPng($frames[$index]), $mode);
	}

	/**
	 * Rebuilds the animation from a list of frames, writing the `acTL`, one `fcTL` per
	 * frame, the default image's `IDAT`, and the remaining frames' `fdAT` chunks with a
	 * single ascending sequence number across them all.  The header dimensions become the
	 * canvas the frame offsets sit on.  The first frame is the default image.
	 * @param array<int, TAPNGFrame> $frames The frames; the first is the default image.
	 * @param int $playCount The number of plays (0 loops forever). Default 0.
	 * @throws TIOException When the frame list is empty.
	 */
	public function setApngFrames(array $frames, int $playCount = 0): void
	{
		$frames = array_values($frames);
		if ($frames === []) {
			throw new TIOException('png_frames_empty');
		}

		// The canvas spans every frame's placed extent.
		$canvasWidth = 0;
		$canvasHeight = 0;
		foreach ($frames as $frame) {
			$canvasWidth = max($canvasWidth, $frame->getXOffset() + $frame->getWidth());
			$canvasHeight = max($canvasHeight, $frame->getYOffset() + $frame->getHeight());
		}
		$this->setIhdrSize($canvasWidth, $canvasHeight);
		$this->setWidthDirect($canvasWidth);
		$this->setHeightDirect($canvasHeight);

		// Drop the previous animation and image data; they are rebuilt below.
		foreach ([TPNGChunkType::AnimationControl, TPNGChunkType::FrameControl, TPNGChunkType::FrameData, TPNGChunkType::ImageData] as $type) {
			$this->removeChunk($type);
		}
		$this->setChunk(new TImageChunk(TPNGChunkType::AnimationControl, 8, 0, pack('NN', count($frames), max(0, $playCount))));

		// The interleaved frame region: fcTL/IDAT for frame 0, then fcTL/fdAT per frame,
		// with one ascending sequence number across every fcTL and fdAT.
		$region = [];
		$sequence = 0;
		foreach ($frames as $index => $frame) {
			$region[] = new TImageChunk(TPNGChunkType::FrameControl, 26, 0, pack('N', $sequence++) . $frame->fcTlFields());
			if ($index === 0) {
				$region[] = new TImageChunk(TPNGChunkType::ImageData, strlen($frame->getData()), 0, $frame->getData());
			} else {
				$payload = pack('N', $sequence++) . $frame->getData();
				$region[] = new TImageChunk(TPNGChunkType::FrameData, strlen($payload), 0, $payload);
			}
		}
		$this->spliceBeforeEnd($region);
		$this->setBytesDirect($this->compose());
	}

	/**
	 * Appends a frame built from an image, quantized/encoded to the frame's own data.
	 * The first frame added to a still PNG becomes the animation's default image.
	 * @param \GdImage|\Imagick $image The frame image.
	 * @param float $delaySeconds The display delay in seconds. Default 0.1.
	 * @param int $disposeOp A {@see TAPNGFrame} `Dispose*` constant. Default DisposeNone.
	 * @param int $blendOp A {@see TAPNGFrame} `Blend*` constant. Default BlendSource.
	 * @throws TIOException When the graphics library cannot write PNG.
	 */
	public function addApngFrame(\GdImage|\Imagick $image, float $delaySeconds = 0.1, int $disposeOp = TAPNGFrame::DisposeNone, int $blendOp = TAPNGFrame::BlendSource): void
	{
		[$width, $height] = TImageGraphics::getSize($image);
		$frame = new TAPNGFrame();
		$frame->setWidth($width);
		$frame->setHeight($height);
		$frame->setDelaySeconds($delaySeconds);
		$frame->setDisposeOp($disposeOp);
		$frame->setBlendOp($blendOp);
		$frame->setData($this->encodeFrameData($image));

		$frames = $this->getApngFrames();
		$frames[] = $frame;
		$this->setApngFrames($frames, $this->getPlayCount());
	}

	/**
	 * Builds an animated PNG from a list of images, each a full-canvas frame.
	 * @param array<int, \GdImage|\Imagick> $images The frame images.
	 * @param float $delaySeconds The per-frame display delay in seconds. Default 0.1.
	 * @param int $playCount The number of plays (0 loops forever). Default 0.
	 * @throws TIOException When the image list is empty or PNG cannot be written.
	 * @return static The animated PNG.
	 */
	public static function fromApngImages(array $images, float $delaySeconds = 0.1, int $playCount = 0): static
	{
		$images = array_values($images);
		if ($images === []) {
			throw new TIOException('png_frames_empty');
		}
		$png = static::fromImage($images[0]);
		$frames = [];
		foreach ($images as $image) {
			[$width, $height] = TImageGraphics::getSize($image);
			$frame = new TAPNGFrame();
			$frame->setWidth($width);
			$frame->setHeight($height);
			$frame->setDelaySeconds($delaySeconds);
			$frame->setData($png->encodeFrameData($image));
			$frames[] = $frame;
		}
		$png->setApngFrames($frames, $playCount);
		return $png;
	}

	/**
	 * Encodes an image and returns the joined `IDAT` payload — the frame data an `fdAT`
	 * or the default `IDAT` carries.
	 * @param \GdImage|\Imagick $image The image.
	 * @throws TIOException When the graphics library cannot write PNG.
	 * @return string The joined image data.
	 */
	protected function encodeFrameData(\GdImage|\Imagick $image): string
	{
		$bytes = TImageGraphics::encode($image, IImageGraphicsLibrary::FormatPng);
		if ($bytes === false) {
			throw new TIOException('png_encode_unavailable', TImageGraphics::getModeOf($image));
		}
		$data = '';
		foreach (static::fromString($bytes)->getChunks() as $chunk) {
			if ($chunk->getType() === TPNGChunkType::ImageData) {
				$data .= $chunk->getData();
			}
		}
		return $data;
	}

	/**
	 * Reconstructs a standalone single-image PNG for one frame: the header with the
	 * frame's dimensions but the original colour fields, the palette and transparency
	 * when present, the frame data as `IDAT`, and the end marker.
	 * @param TAPNGFrame $frame The frame.
	 * @return string The frame's PNG bytes.
	 */
	protected function frameToPng(TAPNGFrame $frame): string
	{
		$ihdr = (string) $this->getChunk(TPNGChunkType::Header)?->getData();
		// Keep the bit depth, colour type, compression, filter, and interlace; swap the size.
		$header = pack('NN', $frame->getWidth(), $frame->getHeight()) . substr(str_pad($ihdr, 13, "\0"), 8, 5);
		$png = self::Signature . static::rawChunk(TPNGChunkType::Header, $header);
		foreach ([TPNGChunkType::Palette, TPNGChunkType::Transparency] as $type) {
			$chunk = $this->getChunk($type);
			if ($chunk !== null) {
				$png .= static::rawChunk($type, $chunk->getData());
			}
		}
		return $png . static::rawChunk(TPNGChunkType::ImageData, $frame->getData()) . static::rawChunk(TPNGChunkType::End, '');
	}

	/**
	 * Packs a PNG chunk with its length and CRC.
	 * @param string $type The four-character chunk type.
	 * @param string $data The chunk data.
	 * @return string The chunk bytes.
	 */
	protected static function rawChunk(string $type, string $data): string
	{
		return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	}

	/**
	 * Writes the frame region just before the `IEND` marker (after all ancillary chunks),
	 * which is where the image data and the animation frames belong.
	 * @param array<int, TImageChunk> $region The ordered frame chunks.
	 */
	protected function spliceBeforeEnd(array $region): void
	{
		foreach ($this->_chunks as $index => $chunk) {
			if ($chunk->getType() === TPNGChunkType::End) {
				array_splice($this->_chunks, $index, 0, $region);
				return;
			}
		}
		$this->_chunks = array_merge($this->_chunks, $region);
	}

	/**
	 * Rewrites the width and height in the `IHDR` chunk (the animation canvas).
	 * @param int $width The canvas width.
	 * @param int $height The canvas height.
	 */
	protected function setIhdrSize(int $width, int $height): void
	{
		$ihdr = $this->getChunk(TPNGChunkType::Header);
		if ($ihdr !== null) {
			$ihdr->setData(pack('NN', $width, $height) . substr(str_pad($ihdr->getData(), 13, "\0"), 8));
		}
	}

	/**
	 * Walks the PNG chunks for the dimensions.
	 * @throws TIOException When the bytes lack the PNG signature.
	 */
	protected function parse(): void
	{
		$bytes = $this->getBytesDirect();
		$len = strlen($bytes);
		if (strncmp($bytes, self::Signature, 8) !== 0) {
			throw new TIOException('png_invalid', 'missing PNG signature');
		}

		$this->_chunks = [];
		$i = 8;
		while ($i + 8 <= $len) {
			$size = (int) unpack('N', substr($bytes, $i, 4))[1];
			$type = substr($bytes, $i + 4, 4);
			$payload = substr($bytes, $i + 8, $size);
			$this->_chunks[] = new TImageChunk($type, $size, $i + 8, $payload);
			if ($type === TPNGChunkType::Header && strlen($payload) >= 8) {
				$this->setWidthDirect((int) unpack('N', substr($payload, 0, 4))[1]);
				$this->setHeightDirect((int) unpack('N', substr($payload, 4, 4))[1]);
			}
			$i += 8 + $size + 4; // length + type + payload + CRC
			if ($type === TPNGChunkType::End) {
				break;
			}
		}
	}

	/**
	 * Lazily reads a PNG from a seekable stream: the chunk framing and the small metadata
	 * chunks are read, but each `IDAT` pixel chunk is kept as a deferred range into the
	 * still-open source rather than loaded, so a PNG far larger than memory opens for a
	 * metadata edit.  Pair it with {@see streamTo()}, which copies the deferred pixel
	 * chunks straight through; the source must stay open and seekable until then.
	 * @param mixed $stream The seekable {@see StreamInterface} or PHP stream resource.
	 * @throws TInvalidDataTypeException When the source is not a stream.
	 * @throws TIOException When the stream is not seekable or lacks the PNG signature.
	 * @return static The lazily parsed PNG.
	 */
	public static function fromStreamLazy(mixed $stream): static
	{
		if (is_resource($stream)) {
			$stream = TStream::fromResource($stream, false);
		}
		if (!$stream instanceof StreamInterface) {
			throw new TInvalidDataTypeException('streamio_source_invalid', get_debug_type($stream));
		}
		if ($stream->isSeekable()) {
			$stream->seek(0);
		}
		$image = Prado::createComponent(static::class);
		$image->parseStream($stream);
		return $image;
	}

	/**
	 * Walks the chunk framing of a seekable stream, deferring each `IDAT` payload.
	 * @param StreamInterface $stream The seekable stream, positioned at the PNG start.
	 * @throws TIOException When the stream is not seekable or lacks the PNG signature.
	 */
	protected function parseStream(StreamInterface $stream): void
	{
		if (!$stream->isSeekable()) {
			throw new TIOException('imagefile_stream_not_seekable');
		}
		if (TStreamHelper::copyToString($stream, 8) !== self::Signature) {
			throw new TIOException('png_invalid', 'missing PNG signature');
		}
		$this->_chunks = [];
		while (true) {
			$start = $stream->tell();
			$header = TStreamHelper::copyToString($stream, 8);   // 4-byte length + 4-byte type
			if (strlen($header) < 8) {
				break;   // the stream ended (a well-formed PNG has already broken at IEND)
			}
			$size = (int) unpack('N', substr($header, 0, 4))[1];
			$type = substr($header, 4, 4);
			if ($type === TPNGChunkType::ImageData) {
				// Defer the whole on-disk chunk (length + type + payload + CRC) and skip its bytes.
				$this->_chunks[] = TImageChunk::deferred($type, $size, $start + 8, $stream, $start, 8 + $size + 4);
				$stream->seek($start + 8 + $size + 4);
				continue;
			}
			$payload = TStreamHelper::copyToString($stream, $size);
			$stream->seek($stream->tell() + 4);   // skip the CRC
			$this->_chunks[] = new TImageChunk($type, $size, $start + 8, $payload);
			if ($type === TPNGChunkType::Header && strlen($payload) >= 8) {
				$this->setWidthDirect((int) unpack('N', substr($payload, 0, 4))[1]);
				$this->setHeightDirect((int) unpack('N', substr($payload, 4, 4))[1]);
			}
			if ($type === TPNGChunkType::End) {
				break;
			}
		}
	}

	/**
	 * Writes the PNG to a target, copying each deferred `IDAT` chunk straight from the
	 * source in bounded memory and rebuilding every other (loaded or edited) chunk.  A PNG
	 * opened with {@see fromStreamLazy()} is rewritten around a metadata edit without ever
	 * holding its pixels; a fully loaded PNG streams the same bytes {@see toBinary()} would.
	 * @param mixed $target A writable {@see StreamInterface} or PHP stream resource.
	 * @throws TInvalidDataTypeException When the target is neither.
	 * @throws TIOException When the target stops accepting bytes.
	 * @return int The number of bytes written.
	 */
	public function streamTo(mixed $target): int
	{
		if (is_resource($target)) {
			$target = TStream::fromResource($target, false);
		}
		if (!$target instanceof StreamInterface) {
			throw new TInvalidDataTypeException('streamio_target_invalid', get_debug_type($target));
		}
		$written = TStreamHelper::copyToStream(TStream::fromString(self::Signature), $target);
		foreach ($this->getChunks() as $chunk) {
			if ($chunk->getIsDeferred()) {
				$written += $chunk->copyDeferredTo($target);
				continue;
			}
			$type = $chunk->getType();
			$data = $chunk->getData();
			$written += TStreamHelper::copyToStream(TStream::fromString(pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data))), $target);
		}
		return $written;
	}

	/**
	 * Rebuilds the PNG from its signature and chunks, recomputing each chunk CRC.
	 * @return string The composed PNG bytes.
	 */
	protected function compose(): string
	{
		$out = self::Signature;
		foreach ($this->getChunks() as $chunk) {
			$type = $chunk->getType();
			$data = $chunk->getData();
			$out .= pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
		}
		return $out;
	}

}
