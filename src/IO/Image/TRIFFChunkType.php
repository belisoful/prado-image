<?php

/**
 * TRIFFChunkType class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image;

use Prado\TEnumerable;

/**
 * TRIFFChunkType class.
 *
 * The four-character ids of the RIFF chunks this library knows by name — the generic
 * container ids and the WebP form's chunks — as a single vocabulary in place of scattered
 * string literals.  The values are the on-disk ids (with the trailing space a short id
 * carries, such as `VP8 ` and `XMP `), so a constant is interchangeable with the raw
 * string a {@see TImageChunk} carries.
 *
 * This is a **vocabulary of the known ids, not a closed type**: a RIFF container may hold
 * any chunk id, and {@see TRIFF}/{@see TWebP} preserve unknown ones byte-faithfully by
 * keeping the chunk id a raw string.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://developers.google.com/speed/webp/docs/riff_container
 */
class TRIFFChunkType extends TEnumerable
{
	/** The outer RIFF container id. */
	public const Riff = 'RIFF';

	/** A LIST sub-chunk. */
	public const RiffList = 'LIST';

	// WebP bitstream chunks.
	public const Vp8 = 'VP8 ';
	public const Vp8Lossless = 'VP8L';
	public const Vp8Extended = 'VP8X';
	public const Alpha = 'ALPH';

	// WebP animation chunks.
	public const Animation = 'ANIM';
	public const AnimationFrame = 'ANMF';

	// WebP metadata chunks.
	public const ICCProfile = 'ICCP';
	public const Exif = 'EXIF';
	public const Xmp = 'XMP ';
}
