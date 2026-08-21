<?php

/**
 * TIPTC class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image\Meta;

use Prado\Collections\TMap;
use Prado\Exceptions\TInvalidDataValueException;
use Prado\IO\Image\IPrivacyScrubbable;
use Prado\IO\Image\TImageGraphics;
use Prado\IO\Image\TImageGraphicsMode;
use Prado\IO\Image\TPhotoshop8BIM;
use Prado\IO\Image\TPrivacyCategory;
use Prado\IO\Stream\TLimitStream;
use Prado\IO\TStream;
use Prado\Prado;
use Prado\Util\Helpers\TEscCharsetConverter;
use Prado\Util\TUtf8Converter;
use Psr\Http\Message\StreamInterface;

/**
 * TIPTC class
 *
 * Implements the IPTC IIM 4.1 metadata standard as a {@see TMap}.  Records are reached
 * like an array, by either the `record#dataset` identifier (e.g. '2#025') or the tag
 * name (e.g. 'Keywords'); see {@see TIPTCTags} for the identifiers.  Repeatable datasets
 * hold an array of values.  Strings are filtered to the characters each dataset permits,
 * dates and times are coerced to the IPTC formats, and numeric datasets are packed as
 * fixed-width integers.
 *
 * ```php
 * $iptc = TIPTC::parse($app13);                       // from a JPEG APP13 segment
 * $caption  = $iptc[TIPTCTags::CaptionAbstract];
 * $keywords = $iptc[TIPTCTags::Keywords];             // array
 * $iptc['By-line'] = 'A. Photographer';
 * $binary = $iptc->toBinary($charset, true);          // re-encode, wrapped in 8BIM
 * ```
 *
 * The common-metadata accessors report whether a kind of metadata exists within the
 * record set and read or write its dataset: {@see hasIPTC()}/{@see getIPTC()}/{@see setIPTC()}
 * (the set itself), {@see hasEXIF()}/{@see getEXIF()}/{@see setEXIF()} (ExifCameraInfo),
 * {@see hasXMP()}/{@see getXMP()}/{@see setXMP()} (IIM carries no XMP), and
 * {@see hasICCProfile()}/{@see getICCProfile()}/{@see setICCProfile()}.  The
 * {@see getRasterizedCaptionImage()}/{@see setRasterizedCaptionImage()} pair converts the
 * 1-bit 460x128 RasterizedCaption dataset to and from a GD image.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @see https://exiftool.org/TagNames/IPTC.html
 * @see https://www.iptc.org/std/IIM/4.1/specification/IIMV4.1.pdf
 */
class TIPTC extends TMap implements IPrivacyScrubbable
{
	use \Prado\IO\Image\TStreamIOTrait;

	public const TAGID_REGEX = '/^(240|[123789])#((?:0?|1)(?:0?|[1-9])\d|2(?:[0-4]\d|5[0-5]))$/';
	public const DATEVALUE_REGEX = '/^\d{4}(?:0[\d]|1[012])(?:[012]\d|3[01])$/i'; // "CCYYMMDD"
	/**
	 * @var string The date written into the mandatory `DateSent` envelope field after a
	 *   {@see clearPrivateData()} scrub of {@see TPrivacyCategory::Timestamp}: IIM requires
	 *   the field, so it cannot be dropped, and refilling it with today's date (as
	 *   {@see validate()} otherwise does) would stamp a fresh timestamp onto a file being
	 *   scrubbed.  Year 0001 is legal per {@see DATEVALUE_REGEX} and obviously synthetic.
	 */
	public const ScrubbedDate = '00010101';

	public const TIMEVALUE_REGEX = '/^(?:[01]\d|2[0-3])[0-5]\d[0-5]\d[-+](?:0\d|1[012])[0-5]\d$/i'; // "HHMMSS±HHMM"

	public const TYPE_UINT8 = 1 << 0;
	public const TYPE_UINT16 = 1 << 1;
	public const TYPE_UINT32 = 1 << 2;
	public const TYPE_STRING = 1 << 3;
	public const TYPE_UNDEF = 1 << 4;

	public const RECORD_KEY = 0;
	public const TAGID_KEY = 1;
	public const TAG_KEY = 2;
	public const TYPE_KEY = 3;
	public const MIN_KEY = 4;
	public const MAX_KEY = 5;
	public const PROPERTY_KEY = 6;

	public const PROP_MANDATORY = 1 << 0;
	public const PROP_REPEATABLE = 1 << 1;
	public const PROP_NUMERIC = 1 << 2;
	public const PROP_ALPHABET = 1 << 3;
	public const PROP_GRAPHICCHAR = 1 << 4;
	public const PROP_SPACE = 1 << 5;
	public const PROP_OBJECTNAME = 1 << 6;
	public const PROP_CRLF = 1 << 7;
	public const PROP_DATE = 1 << 8;
	public const PROP_TIME = 1 << 9;
	public const PROP_LEFT_ZERO = 1 << 10;

	/** @var array<string, array{0: int, 1: int, 2: string, 3: int, 4: ?int, 5: ?int, 6: int}> Map of tag id to [Record, TagId, TagName, Type, Min, Max, Properties]. */
	public const TAG_MAP = [
		// IPTC EnvelopeRecord Tags #1
		TIPTCTags::EnvelopeRecordVersion => [TIPTCRecords::Envelope, 0, 'EnvelopeRecordVersion', self::TYPE_UINT16, null, null, self::PROP_MANDATORY],
		TIPTCTags::Destination => [TIPTCRecords::Envelope, 5, 'Destination', self::TYPE_STRING, 0, 1024, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR],
		TIPTCTags::FileFormat => [TIPTCRecords::Envelope, 20, 'FileFormat', self::TYPE_UINT16, null, null, self::PROP_MANDATORY],
		TIPTCTags::FileVersion => [TIPTCRecords::Envelope, 22, 'FileVersion', self::TYPE_UINT16, null, null, self::PROP_MANDATORY],
		TIPTCTags::ServiceIdentifier => [TIPTCRecords::Envelope, 30, 'ServiceIdentifier', self::TYPE_STRING, 0, 10, self::PROP_MANDATORY | self::PROP_GRAPHICCHAR],
		TIPTCTags::EnvelopeNumber => [TIPTCRecords::Envelope, 40, 'EnvelopeNumber', self::TYPE_STRING, 8, null, self::PROP_MANDATORY | self::PROP_NUMERIC | self::PROP_LEFT_ZERO],
		TIPTCTags::ProductID => [TIPTCRecords::Envelope, 50, 'ProductID', self::TYPE_STRING, 0, 32, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR],
		TIPTCTags::EnvelopePriority => [TIPTCRecords::Envelope, 60, 'EnvelopePriority', self::TYPE_STRING, 1, null, self::PROP_NUMERIC],
		TIPTCTags::DateSent => [TIPTCRecords::Envelope, 70, 'DateSent', self::TYPE_STRING, 8, null, self::PROP_MANDATORY | self::PROP_DATE | self::PROP_NUMERIC],
		TIPTCTags::TimeSent => [TIPTCRecords::Envelope, 80, 'TimeSent', self::TYPE_STRING, 11, null, self::PROP_TIME],
		TIPTCTags::CodedCharacterSet => [TIPTCRecords::Envelope, 90, 'CodedCharacterSet', self::TYPE_STRING, 0, 32, 0],
		TIPTCTags::UniqueObjectName => [TIPTCRecords::Envelope, 100, 'UniqueObjectName', self::TYPE_STRING, 14, 80, self::PROP_GRAPHICCHAR | self::PROP_OBJECTNAME],
		TIPTCTags::ARMIdentifier => [TIPTCRecords::Envelope, 120, 'ARMIdentifier', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::ARMVersion => [TIPTCRecords::Envelope, 122, 'ARMVersion', self::TYPE_UINT16, null, null, 0],

		// IPTC ApplicationRecord Tags #2
		TIPTCTags::ApplicationRecordVersion => [TIPTCRecords::Application, 0, 'ApplicationRecordVersion', self::TYPE_UINT16, null, null, self::PROP_MANDATORY],
		TIPTCTags::ObjectTypeReference => [TIPTCRecords::Application, 3, 'ObjectTypeReference', self::TYPE_STRING, 3, 67, self::PROP_GRAPHICCHAR],
		TIPTCTags::ObjectAttributeReference => [TIPTCRecords::Application, 4, 'ObjectAttributeReference', self::TYPE_STRING, 4, 68, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR],
		TIPTCTags::ObjectName => [TIPTCRecords::Application, 5, 'ObjectName', self::TYPE_STRING, 0, 64, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::EditStatus => [TIPTCRecords::Application, 7, 'EditStatus', self::TYPE_STRING, 0, 64, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::EditorialUpdate => [TIPTCRecords::Application, 8, 'EditorialUpdate', self::TYPE_STRING, 2, null, self::PROP_NUMERIC],
		TIPTCTags::Urgency => [TIPTCRecords::Application, 10, 'Urgency', self::TYPE_STRING, 1, null, self::PROP_NUMERIC],
		TIPTCTags::SubjectReference => [TIPTCRecords::Application, 12, 'SubjectReference', self::TYPE_STRING, 13, 236, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::Category => [TIPTCRecords::Application, 15, 'Category', self::TYPE_STRING, 0, 3, self::PROP_ALPHABET],
		TIPTCTags::SupplementalCategories => [TIPTCRecords::Application, 20, 'SupplementalCategories', self::TYPE_STRING, 0, 32, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::FixtureIdentifier => [TIPTCRecords::Application, 22, 'FixtureIdentifier', self::TYPE_STRING, 0, 32, self::PROP_GRAPHICCHAR],
		TIPTCTags::Keywords => [TIPTCRecords::Application, 25, 'Keywords', self::TYPE_STRING, 0, 64, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::ContentLocationCode => [TIPTCRecords::Application, 26, 'ContentLocationCode', self::TYPE_STRING, 3, null, self::PROP_REPEATABLE | self::PROP_ALPHABET],
		TIPTCTags::ContentLocationName => [TIPTCRecords::Application, 27, 'ContentLocationName', self::TYPE_STRING, 0, 64, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::ReleaseDate => [TIPTCRecords::Application, 30, 'ReleaseDate', self::TYPE_STRING, 8, null, self::PROP_DATE | self::PROP_NUMERIC],
		TIPTCTags::ReleaseTime => [TIPTCRecords::Application, 35, 'ReleaseTime', self::TYPE_STRING, 11, null, self::PROP_TIME],
		TIPTCTags::ExpirationDate => [TIPTCRecords::Application, 37, 'ExpirationDate', self::TYPE_STRING, 8, null, self::PROP_DATE | self::PROP_NUMERIC],
		TIPTCTags::ExpirationTime => [TIPTCRecords::Application, 38, 'ExpirationTime', self::TYPE_STRING, 11, null, self::PROP_TIME],
		TIPTCTags::SpecialInstructions => [TIPTCRecords::Application, 40, 'SpecialInstructions', self::TYPE_STRING, 0, 256, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::ActionAdvised => [TIPTCRecords::Application, 42, 'ActionAdvised', self::TYPE_STRING, 2, null, self::PROP_NUMERIC | self::PROP_LEFT_ZERO],
		TIPTCTags::ReferenceService => [TIPTCRecords::Application, 45, 'ReferenceService', self::TYPE_STRING, 0, 10, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR],
		TIPTCTags::ReferenceDate => [TIPTCRecords::Application, 47, 'ReferenceDate', self::TYPE_STRING, 8, null, self::PROP_REPEATABLE | self::PROP_DATE | self::PROP_NUMERIC],
		TIPTCTags::ReferenceNumber => [TIPTCRecords::Application, 50, 'ReferenceNumber', self::TYPE_STRING, 8, null, self::PROP_REPEATABLE | self::PROP_NUMERIC | self::PROP_LEFT_ZERO],
		TIPTCTags::DateCreated => [TIPTCRecords::Application, 55, 'DateCreated', self::TYPE_STRING, 8, null, self::PROP_DATE | self::PROP_NUMERIC],
		TIPTCTags::TimeCreated => [TIPTCRecords::Application, 60, 'TimeCreated', self::TYPE_STRING, 11, null, self::PROP_TIME],
		TIPTCTags::DigitalCreationDate => [TIPTCRecords::Application, 62, 'DigitalCreationDate', self::TYPE_STRING, 8, null, self::PROP_DATE | self::PROP_NUMERIC],
		TIPTCTags::DigitalCreationTime => [TIPTCRecords::Application, 63, 'DigitalCreationTime', self::TYPE_STRING, 11, null, self::PROP_TIME],
		TIPTCTags::OriginatingProgram => [TIPTCRecords::Application, 65, 'OriginatingProgram', self::TYPE_STRING, 0, 32, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::ProgramVersion => [TIPTCRecords::Application, 70, 'ProgramVersion', self::TYPE_STRING, 0, 10, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::ObjectCycle => [TIPTCRecords::Application, 75, 'ObjectCycle', self::TYPE_STRING, 1, null, self::PROP_ALPHABET],
		TIPTCTags::ByLine => [TIPTCRecords::Application, 80, 'By-line', self::TYPE_STRING, 0, 32, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::ByLineTitle => [TIPTCRecords::Application, 85, 'By-lineTitle', self::TYPE_STRING, 0, 32, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::City => [TIPTCRecords::Application, 90, 'City', self::TYPE_STRING, 0, 32, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::SubLocation => [TIPTCRecords::Application, 92, 'Sub-location', self::TYPE_STRING, 0, 32, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::ProvinceState => [TIPTCRecords::Application, 95, 'Province-State', self::TYPE_STRING, 0, 32, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::CountryPrimaryLocationCode => [TIPTCRecords::Application, 100, 'Country-PrimaryLocationCode', self::TYPE_STRING, 3, null, self::PROP_ALPHABET],
		TIPTCTags::CountryPrimaryLocationName => [TIPTCRecords::Application, 101, 'Country-PrimaryLocationName', self::TYPE_STRING, 0, 64, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::OriginalTransmissionReference => [TIPTCRecords::Application, 103, 'OriginalTransmissionReference', self::TYPE_STRING, 0, 32, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::Headline => [TIPTCRecords::Application, 105, 'Headline', self::TYPE_STRING, 0, 256, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::Credit => [TIPTCRecords::Application, 110, 'Credit', self::TYPE_STRING, 0, 32, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::Source => [TIPTCRecords::Application, 115, 'Source', self::TYPE_STRING, 0, 32, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::CopyrightNotice => [TIPTCRecords::Application, 116, 'CopyrightNotice', self::TYPE_STRING, 0, 128, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::Contact => [TIPTCRecords::Application, 118, 'Contact', self::TYPE_STRING, 0, 128, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::CaptionAbstract => [TIPTCRecords::Application, 120, 'Caption-Abstract', self::TYPE_STRING, 0, 2000, self::PROP_GRAPHICCHAR | self::PROP_SPACE | self::PROP_CRLF],
		TIPTCTags::LocalCaption => [TIPTCRecords::Application, 121, 'LocalCaption', self::TYPE_STRING, 0, 256, self::PROP_GRAPHICCHAR | self::PROP_SPACE | self::PROP_CRLF],
		TIPTCTags::WriterEditor => [TIPTCRecords::Application, 122, 'Writer-Editor', self::TYPE_STRING, 0, 32, self::PROP_REPEATABLE | self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::RasterizedCaption => [TIPTCRecords::Application, 125, 'RasterizedCaption', self::TYPE_UNDEF, 7360, null, 0],
		TIPTCTags::ImageType => [TIPTCRecords::Application, 130, 'ImageType', self::TYPE_STRING, 2, null, self::PROP_ALPHABET | self::PROP_NUMERIC],
		TIPTCTags::ImageOrientation => [TIPTCRecords::Application, 131, 'ImageOrientation', self::TYPE_STRING, 1, null, self::PROP_ALPHABET],
		TIPTCTags::LanguageIdentifier => [TIPTCRecords::Application, 135, 'LanguageIdentifier', self::TYPE_STRING, 2, 3, self::PROP_ALPHABET],
		TIPTCTags::AudioType => [TIPTCRecords::Application, 150, 'AudioType', self::TYPE_STRING, 2, null, self::PROP_ALPHABET | self::PROP_NUMERIC],
		TIPTCTags::AudioSamplingRate => [TIPTCRecords::Application, 151, 'AudioSamplingRate', self::TYPE_STRING, 6, null, self::PROP_NUMERIC | self::PROP_LEFT_ZERO],
		TIPTCTags::AudioSamplingResolution => [TIPTCRecords::Application, 152, 'AudioSamplingResolution', self::TYPE_STRING, 2, null, self::PROP_NUMERIC | self::PROP_LEFT_ZERO],
		TIPTCTags::AudioDuration => [TIPTCRecords::Application, 153, 'AudioDuration', self::TYPE_STRING, 6, null, self::PROP_NUMERIC | self::PROP_LEFT_ZERO],
		TIPTCTags::AudioOutcue => [TIPTCRecords::Application, 154, 'AudioOutcue', self::TYPE_STRING, 0, 64, self::PROP_GRAPHICCHAR | self::PROP_SPACE],
		TIPTCTags::JobID => [TIPTCRecords::Application, 184, 'JobID', self::TYPE_STRING, 0, 64, 0],
		TIPTCTags::MasterDocumentID => [TIPTCRecords::Application, 185, 'MasterDocumentID', self::TYPE_STRING, 0, 256, 0],
		TIPTCTags::ShortDocumentID => [TIPTCRecords::Application, 186, 'ShortDocumentID', self::TYPE_STRING, 0, 64, 0],
		TIPTCTags::UniqueDocumentID => [TIPTCRecords::Application, 187, 'UniqueDocumentID', self::TYPE_STRING, 0, 128, 0],
		TIPTCTags::OwnerID => [TIPTCRecords::Application, 188, 'OwnerID', self::TYPE_STRING, 0, 128, 0],
		TIPTCTags::ObjectPreviewFileFormat => [TIPTCRecords::Application, 200, 'ObjectPreviewFileFormat', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::ObjectPreviewFileVersion => [TIPTCRecords::Application, 201, 'ObjectPreviewFileVersion', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::ObjectPreviewData => [TIPTCRecords::Application, 202, 'ObjectPreviewData', self::TYPE_UNDEF, 0, 256000, 0],
		TIPTCTags::Prefs => [TIPTCRecords::Application, 221, 'Prefs', self::TYPE_STRING, 0, 64, 0],
		TIPTCTags::ClassifyState => [TIPTCRecords::Application, 225, 'ClassifyState', self::TYPE_STRING, 0, 64, 0],
		TIPTCTags::SimilarityIndex => [TIPTCRecords::Application, 228, 'SimilarityIndex', self::TYPE_STRING, 0, 32, 0],
		TIPTCTags::DocumentNotes => [TIPTCRecords::Application, 230, 'DocumentNotes', self::TYPE_STRING, 0, 1024, self::PROP_GRAPHICCHAR | self::PROP_SPACE | self::PROP_CRLF],
		TIPTCTags::DocumentHistory => [TIPTCRecords::Application, 231, 'DocumentHistory', self::TYPE_STRING, 0, 256, self::PROP_GRAPHICCHAR | self::PROP_SPACE | self::PROP_CRLF],
		TIPTCTags::ExifCameraInfo => [TIPTCRecords::Application, 232, 'ExifCameraInfo', self::TYPE_UNDEF, 0, 4096, 0],
		TIPTCTags::CatalogSets => [TIPTCRecords::Application, 255, 'CatalogSets', self::TYPE_STRING, 0, 256, self::PROP_REPEATABLE],

		// IPTC NewsPhoto Tags #3
		TIPTCTags::NewsPhotoVersion => [TIPTCRecords::NewsPhoto, 0, 'NewsPhotoVersion', self::TYPE_UINT16, null, null, self::PROP_MANDATORY],
		TIPTCTags::IPTCPictureNumber => [TIPTCRecords::NewsPhoto, 10, 'IPTCPictureNumber', self::TYPE_STRING, 16, null, 0],
		TIPTCTags::IPTCImageWidth => [TIPTCRecords::NewsPhoto, 20, 'IPTCImageWidth', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::IPTCImageHeight => [TIPTCRecords::NewsPhoto, 30, 'IPTCImageHeight', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::IPTCPixelWidth => [TIPTCRecords::NewsPhoto, 40, 'IPTCPixelWidth', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::IPTCPixelHeight => [TIPTCRecords::NewsPhoto, 50, 'IPTCPixelHeight', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::SupplementalType => [TIPTCRecords::NewsPhoto, 55, 'SupplementalType', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::ColorRepresentation => [TIPTCRecords::NewsPhoto, 60, 'ColorRepresentation', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::InterchangeColorSpace => [TIPTCRecords::NewsPhoto, 64, 'InterchangeColorSpace', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::ColorSequence => [TIPTCRecords::NewsPhoto, 65, 'ColorSequence', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::ICC_Profile => [TIPTCRecords::NewsPhoto, 66, 'ICC_Profile', self::TYPE_UNDEF, 0, 512000, 0],
		TIPTCTags::ColorCalibrationMatrix => [TIPTCRecords::NewsPhoto, 70, 'ColorCalibrationMatrix', self::TYPE_UNDEF, null, null, 0],
		TIPTCTags::LookupTable => [TIPTCRecords::NewsPhoto, 80, 'LookupTable', self::TYPE_UNDEF, 0, 131072, 0],
		TIPTCTags::NumIndexEntries => [TIPTCRecords::NewsPhoto, 84, 'NumIndexEntries', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::ColorPalette => [TIPTCRecords::NewsPhoto, 85, 'ColorPalette', self::TYPE_UNDEF, 0, 524288, 0],
		TIPTCTags::IPTCBitsPerSample => [TIPTCRecords::NewsPhoto, 86, 'IPTCBitsPerSample', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::SampleStructure => [TIPTCRecords::NewsPhoto, 90, 'SampleStructure', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::ScanningDirection => [TIPTCRecords::NewsPhoto, 100, 'ScanningDirection', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::IPTCImageRotation => [TIPTCRecords::NewsPhoto, 102, 'IPTCImageRotation', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::DataCompressionMethod => [TIPTCRecords::NewsPhoto, 110, 'DataCompressionMethod', self::TYPE_UINT32, null, null, 0],
		TIPTCTags::QuantizationMethod => [TIPTCRecords::NewsPhoto, 120, 'QuantizationMethod', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::EndPoints => [TIPTCRecords::NewsPhoto, 125, 'EndPoints', self::TYPE_UNDEF, 0, -1, 0],
		TIPTCTags::ExcursionTolerance => [TIPTCRecords::NewsPhoto, 130, 'ExcursionTolerance', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::BitsPerComponent => [TIPTCRecords::NewsPhoto, 135, 'BitsPerComponent', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::MaximumDensityRange => [TIPTCRecords::NewsPhoto, 140, 'MaximumDensityRange', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::GammaCompensatedValue => [TIPTCRecords::NewsPhoto, 145, 'GammaCompensatedValue', self::TYPE_UINT16, null, null, 0],

		// IPTC PreObjectData Tags #7
		TIPTCTags::SizeMode => [TIPTCRecords::PreObjectData, 10, 'SizeMode', self::TYPE_UINT8, null, null, 0],
		TIPTCTags::MaxSubfileSize => [TIPTCRecords::PreObjectData, 20, 'MaxSubfileSize', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::ObjectSizeAnnounced => [TIPTCRecords::PreObjectData, 90, 'ObjectSizeAnnounced', self::TYPE_UINT16, null, null, 0],
		TIPTCTags::MaximumObjectSize => [TIPTCRecords::PreObjectData, 95, 'MaximumObjectSize', self::TYPE_UINT16, null, null, 0],

		// IPTC ObjectData Tags #8
		TIPTCTags::SubFile => [TIPTCRecords::ObjectData, 10, 'SubFile', self::TYPE_UNDEF, null, null, self::PROP_REPEATABLE],

		// IPTC PostObjectData Tags #9
		TIPTCTags::ConfirmedObjectSize => [TIPTCRecords::PostObjectData, 10, 'ConfirmedObjectSize', self::TYPE_UINT16, null, null, 0],
	];

	/** @var array<string, string> Alternative tag names mapped to their tag id. */
	public const ALT_TAGS = [
		'Author' => '2#080',
		'AuthorTitle' => '2#085',
		'Copyright' => '2#116',
		'Description' => '2#120',
		'CreationDate' => '2#055',
		'CreationTime' => '2#060',
		'Software' => '2#065',
	];

	public const IPTC_TAG_MARKER = "\x1C";

	/**
	 * Formats a Unix timestamp as an IPTC date ("CCYYMMDD").
	 * @param ?int $unixtime The timestamp, or null for the current date.
	 * @return string The IPTC date.
	 */
	public static function formatIPTCDate(?int $unixtime = null): string
	{
		return date('Ymd', $unixtime ?? time());
	}

	/**
	 * Formats a Unix timestamp as an IPTC time ("HHMMSS±HHMM").
	 * @param ?int $unixtime The timestamp, or null for the current time.
	 * @return string The IPTC time.
	 */
	public static function formatIPTCTime(?int $unixtime = null): string
	{
		return date('HisO', $unixtime ?? time());
	}

	/**
	 * Formats a record and dataset number as a `record#dataset` tag id.
	 * @param int|string $record The record number.
	 * @param int|string $tagid The dataset number.
	 * @return string The tag id, the dataset zero-padded to three digits.
	 */
	public static function formatTag(string|int $record, string|int $tagid): string
	{
		return $record . '#' . str_pad((string) $tagid, 3, '0', STR_PAD_LEFT);
	}

	/**
	 * Parses an IPTC block and returns a populated instance on success.
	 * @param mixed &$iptc_block The data, a stream, or [source, length, offset].
	 * @param ?string $charset The fallback charset; null uses the application globalization or 'ASCII'.
	 * @return false|TIPTC The parsed metadata, or false on failure.
	 */
	public static function parse(mixed &$iptc_block, ?string $charset = null): false|TIPTC
	{
		$iptc = new TIPTC();
		if ($iptc->read($iptc_block, $charset)) {
			return $iptc;
		}
		return false;
	}

	/**
	 * Parses an IPTC block, including a Photoshop-embedded one (alias of {@see parse()}).
	 * @param mixed $iptc_block The data, stream, or [source, length, offset].
	 * @param ?string $charset The fallback charset; null uses the application globalization or 'ASCII'.
	 * @return false|TIPTC The parsed metadata, or false on failure.
	 */
	public static function iptcparse(mixed $iptc_block, ?string $charset = null): false|TIPTC
	{
		return self::parse($iptc_block, $charset);
	}

	/**
	 * Builds a map of tag names to tag ids.
	 * @param bool $lower Lower-case the name keys. Default true.
	 * @param bool $altTags Include the alternative names. Default true.
	 * @return array<string, string> Tag names to tag ids.
	 */
	public static function getIPTCTagKeys(bool $lower = true, bool $altTags = true): array
	{
		static $namesToKey = [];

		$lk = $lower ? 1 : 0;
		$ak = $altTags ? 1 : 0;
		if (!isset($namesToKey[$lk][$ak])) {
			$map = [];
			foreach (self::TAG_MAP as $id => $data) {
				$name = $lower ? strtolower($data[self::TAG_KEY]) : $data[self::TAG_KEY];
				$map[$name] = $id;
			}
			if ($altTags) {
				foreach (self::ALT_TAGS as $name => $id) {
					$map[$lower ? strtolower($name) : $name] = $id;
				}
			}
			$namesToKey[$lk][$ak] = $map;
		}
		return $namesToKey[$lk][$ak];
	}

	/**
	 * Resolves a tag id or tag name to a canonical `record#dataset` tag id (the dataset
	 * zero-padded to three digits, such as `2#025`).  The arguments accept several forms:
	 *
	 * | Call                            | Result  | Form                                                  |
	 * |---------------------------------|---------|-------------------------------------------------------|
	 * | `mapToIPTCTagId('Keywords')`    | `2#025` | tag name (case-insensitive)                           |
	 * | `mapToIPTCTagId('Description')` | `2#120` | alternative tag name                                  |
	 * | `mapToIPTCTagId('2#025')`       | `2#025` | canonical id (returned as-is)                         |
	 * | `mapToIPTCTagId('2#25')`        | `2#025` | id with an unpadded dataset (normalized)              |
	 * | `mapToIPTCTagId(2, 25)`         | `2#025` | record number and dataset number                      |
	 * | `mapToIPTCTagId('2', '25')`     | `2#025` | record and dataset as numeric strings                 |
	 * | `mapToIPTCTagId(537)`           | `2#025` | one number packing `(record << 8) \| dataset`         |
	 * | `mapToIPTCTagId(0x0219)`        | `2#025` | the same packed number in hexadecimal                 |
	 * | `mapToIPTCTagId(null)`          | `null`  | a null tag                                            |
	 * | `mapToIPTCTagId(2)`             | `null`  | a bare record number is ambiguous                     |
	 * | `mapToIPTCTagId('NotATag')`     | `null`  | an unknown name or malformed id                       |
	 *
	 * @param null|int|string $tag A tag name, a canonical or unpadded `record#dataset` id, a
	 *   record number (paired with $tag2), or one number packing `(record << 8) | dataset`.
	 * @param null|int|string $tag2 The dataset number when $tag is a record number; null otherwise.
	 * @return ?string The canonical `record#dataset` tag id, or null when invalid.
	 */
	public static function mapToIPTCTagId(null|int|string $tag, null|int|string $tag2 = null): ?string
	{
		if (is_numeric($tag)) {
			$tag = (int) $tag;
			if ($tag > 0xFF && $tag2 === null) {
				return self::formatTag($tag >> 8, $tag & 0xFF);
			}
			if ($tag2 !== null) {
				return self::formatTag($tag, $tag2);
			}
			return null;
		}
		if ($tag === null) {
			return null;
		}
		$tagKeys = self::getIPTCTagKeys(true);
		$tag = strtolower($tag);
		if (array_key_exists($tag, $tagKeys)) {
			return $tagKeys[$tag];
		}
		if (!preg_match(self::TAGID_REGEX, $tag, $matches)) {
			return null;
		}
		if (strlen($matches[2]) === 3) {
			return $tag;
		}
		return self::formatTag($matches[1], $matches[2]);
	}

	/**
	 * Resolves a tag id or tag name to its canonical tag name.
	 * @param string $tag The tag id or tag name.
	 * @return ?string The tag name, or null when invalid.
	 */
	public static function mapToIPTCTagName(string $tag): ?string
	{
		if (array_key_exists($tag, self::TAG_MAP)) {
			return self::TAG_MAP[$tag][self::TAG_KEY];
		}
		if (preg_match(self::TAGID_REGEX, $tag, $matches)) {
			$tag = self::formatTag($matches[1], $matches[2]);
			return self::TAG_MAP[$tag][self::TAG_KEY] ?? null;
		}
		$names = self::getIPTCTagKeys(true);
		$id = $names[strtolower($tag)] ?? null;
		return $id === null ? null : (self::TAG_MAP[$id][self::TAG_KEY] ?? null);
	}

	/**
	 * Computes the EnvelopeNumber from the service id and date sent (crc32 mod 1e8).
	 * @param string $serviceId The service identifier.
	 * @param string $dateSent The IPTC date sent.
	 * @return string The eight-digit envelope number.
	 */
	public static function computeEnvelopeNumber(string $serviceId, string $dateSent): string
	{
		return str_pad((string) (crc32($serviceId . $dateSent) % 100000000), 8, '0', STR_PAD_LEFT);
	}

	/**
	 * Resolves the fallback charset from the application globalization, else 'ASCII'.
	 * @return string The charset name.
	 */
	protected static function defaultCharset(): string
	{
		$app = Prado::getApplication();
		if ($app !== null && ($globalization = $app->getGlobalization()) !== null) {
			return $globalization->getCharset();
		}
		return 'ASCII';
	}

	/**
	 * Seeds the CodedCharacterSet unless the encoding is empty.
	 * @param string $encoding The default encoding. Default 'UTF-8'.
	 */
	public function __construct(string $encoding = 'UTF-8')
	{
		parent::__construct();
		if ($encoding !== '') {
			$this[TIPTCTags::CodedCharacterSet] = $encoding;
		}
	}

	/**
	 * Reads IPTC datasets from a block, decoding any Photoshop 8BIM wrapper first.  A
	 * {@see StreamInterface} source (or a PHP stream resource, wrapped without taking
	 * ownership) is windowed through a {@see TLimitStream} to the
	 * [source, length, offset] region, so only that slice is read (a null length reads to the
	 * end).  A seekable stream is read non-destructively: its position is restored afterward,
	 * so an inline block (such as the IPTC segment within a JPEG) is read without disturbing
	 * the surrounding parse.
	 * @param mixed &$iptc_block The data, stream, or [source, length, offset].
	 * @param ?string $charset The fallback charset; null uses the application globalization or 'ASCII'.
	 * @return bool Whether the block parsed successfully.
	 */
	public function read(mixed &$iptc_block, ?string $charset = null): bool
	{
		$size = null;
		$offset = null;
		if (is_array($iptc_block)) {
			$offset = $iptc_block[2] ?? $iptc_block['offset'] ?? null;
			$size = $iptc_block[1] ?? $iptc_block['length'] ?? null;
			$iptc_block = $iptc_block[0] ?? $iptc_block['source'] ?? null;
		}
		$position = max(0, (int) ($offset ?? 0));
		$source = $iptc_block;
		if (is_resource($source)) {   // a PHP stream resource is wrapped without taking ownership
			$source = TStream::fromResource($source, false);
		}
		if ($source instanceof StreamInterface) {
			$stream = $source;
			$restore = $stream->isSeekable() ? $stream->tell() : null;
			// Window the source so only the [offset, length] region is read; a null length reads
			// the remainder.  TLimitStream seeks (or read-discards) to the offset and caps the read.
			// Without an explicit offset the window starts where the stream already is, so an
			// inline segment reads from where the surrounding parse left off.
			$start = $offset === null ? ($restore ?? 0) : $position;
			$iptc_block = (new TLimitStream($stream, $size === null ? -1 : (int) $size, $start))->getContents();
			if ($restore !== null) {
				$stream->seek($restore);   // an inline read leaves the surrounding parse position intact
			}
			$position = 0;
		}
		$charset ??= self::defaultCharset();

		if (!is_string($iptc_block)) {
			return false;
		}
		if ($size === null) {
			$size = strlen($iptc_block) - $position;
		}
		if (($psLength = TPhotoshop8BIM::iptcDecode($iptc_block)) !== false && $psLength !== null) {
			$size = $psLength;
			$position = 0;
		}

		$length = strlen($iptc_block);
		$read = function (int $count) use (&$iptc_block, &$position, $length): string|false {
			if ($position >= $length) {
				return false;
			}
			$d = substr($iptc_block, $position, $count);
			$position += strlen($d);
			return $d;
		};
		$end = $position + (int) $size;

		$this->clear();
		$first = true;
		while ($position < $end) {
			if ($read(1) !== self::IPTC_TAG_MARKER) {
				if ($first) {
					return false;
				}
				break; // trailing even-length padding after the last dataset
			}
			$first = false;
			$record = ord((string) $read(1));
			$tagid = ord((string) $read(1));
			if (($data = $read(2)) === false || strlen($data) !== 2) {
				return false;
			}
			$len = unpack('n', $data)[1];
			if ($len === 0x8004) {
				if (($data = $read(4)) === false || strlen($data) !== 4) {
					return false;
				}
				$len = unpack('N', $data)[1];
			}
			$value = $len === 0 ? '' : $read($len);
			if ($value === false) {
				return false;
			}

			$tag = self::formatTag($record, $tagid);
			if (!array_key_exists($tag, self::TAG_MAP)) {
				return false;
			}
			$value = $this->decodeValue($tag, $value, $record, $tagid, $charset);

			if (self::TAG_MAP[$tag][self::PROPERTY_KEY] & self::PROP_REPEATABLE) {
				$values = $this[$tag] ?? [];
				$values[] = $value;
				$this[$tag] = $values;
			} elseif (!$this->contains($tag)) {
				$this[$tag] = $value;
			}
		}
		return true;
	}

	/**
	 * Decodes a raw dataset value to its typed form, converting charsets for strings.
	 * @param string $tag The canonical tag id.
	 * @param string $value The raw value bytes.
	 * @param int $record The record number.
	 * @param int $tagid The dataset number.
	 * @param string &$charset The active charset, updated when a CodedCharacterSet is read.
	 * @return int|string The decoded value.
	 */
	private function decodeValue(string $tag, string $value, int $record, int $tagid, string &$charset): int|string
	{
		$metaType = self::TAG_MAP[$tag][self::TYPE_KEY];
		if ($metaType === self::TYPE_UINT8) {
			return ord($value);
		}
		if ($metaType === self::TYPE_UINT16) {
			return (ord($value[0]) << 8) | ord($value[1]);
		}
		if ($metaType === self::TYPE_UINT32) {
			return (ord($value[0]) << 24) | (ord($value[1]) << 16) | (ord($value[2]) << 8) | ord($value[3]);
		}
		if ($record === 1 && $tagid === 90) { // CodedCharacterSet
			return $charset = (string) TEscCharsetConverter::decodeEscapeCharset($value);
		}
		if ($record > 1 && $metaType === self::TYPE_STRING) {
			return TUtf8Converter::toUTF8($value, $charset);
		}
		return $value;
	}

	/**
	 * Ensures mandatory envelope tags are present and removes unneeded version tags.
	 */
	public function validate(): void
	{
		$this[TIPTCTags::EnvelopeRecordVersion] ??= 4;
		$this[TIPTCTags::FileFormat] ??= 1;
		$this[TIPTCTags::FileVersion] ??= 4;
		$this[TIPTCTags::ServiceIdentifier] ??= 'PRADO' . substr(Prado::getVersion(), 0, 5);
		$this[TIPTCTags::DateSent] ??= self::formatIPTCDate();
		$this[TIPTCTags::EnvelopeNumber] ??= self::computeEnvelopeNumber($this[TIPTCTags::ServiceIdentifier], $this[TIPTCTags::DateSent]);

		$hasApp = $hasNews = false;
		foreach ($this->toArray() as $id => $value) {
			if (strncmp($id, '2#', 2) === 0 && $id !== '2#000') {
				$hasApp = true;
			} elseif (strncmp($id, '3#', 2) === 0 && $id !== '3#000') {
				$hasNews = true;
			}
		}
		if ($hasApp) {
			$this[TIPTCTags::ApplicationRecordVersion] ??= 4;
		} elseif ($this->contains(TIPTCTags::ApplicationRecordVersion)) {
			unset($this[TIPTCTags::ApplicationRecordVersion]);
		}
		if ($hasNews) {
			$this[TIPTCTags::NewsPhotoVersion] ??= 4;
		} elseif ($this->contains(TIPTCTags::NewsPhotoVersion)) {
			unset($this[TIPTCTags::NewsPhotoVersion]);
		}
	}

	/**
	 * Serializes the datasets to an IPTC binary block.
	 * @param null|bool|string $charset The fallback charset, or a bool standing in for $photoshopEncode.
	 * @param bool $photoshopEncode Whether to wrap the result in a Photoshop 8BIM block (true for JPEG).
	 * @return string The IPTC binary block.
	 */
	public function toBinary(null|bool|string $charset = null, bool $photoshopEncode = true): string
	{
		if (is_bool($charset)) {
			$photoshopEncode = $charset;
			$charset = null;
		}
		$charset ??= self::defaultCharset();
		$this->validate();

		$result = '';
		foreach ($this->toArray() as $id => $values) {
			$singleValue = (self::TAG_MAP[$id][self::PROPERTY_KEY] & self::PROP_REPEATABLE) === 0;
			foreach (is_array($values) ? $values : [$values] as $value) {
				if ($id === TIPTCTags::CodedCharacterSet) {
					$charset = $value;
					$value = TEscCharsetConverter::encodeEscapeCharset($value);
				}
				$result .= self::tagBinary($id, $value, $charset);
				if ($singleValue) {
					break;
				}
			}
		}
		return $photoshopEncode ? TPhotoshop8BIM::iptcEncode($result) : $result;
	}

	/**
	 * Builds the binary form of a single dataset, encoding strings from UTF-8 for record > 1.
	 * @param string $id The tag id.
	 * @param int|string $value The value.
	 * @param string $charset The target charset for record > 1 strings.
	 * @return string The dataset binary, or '' when the id is unknown.
	 */
	public static function tagBinary(string $id, int|string $value, string $charset = 'ASCII'): string
	{
		if (!array_key_exists($id, self::TAG_MAP)) {
			return '';
		}
		[$record, $tagid] = explode('#', $id);
		$metaType = self::TAG_MAP[$id][self::TYPE_KEY];
		if ($metaType === self::TYPE_UINT32) {
			$value = pack('N', $value);
		} elseif ($metaType === self::TYPE_UINT16) {
			$value = pack('n', $value);
		} elseif ($metaType === self::TYPE_UINT8) {
			$value = pack('C', $value);
		} elseif ($record !== '1') {
			$value = TUtf8Converter::fromUTF8((string) $value, $charset);
		}
		$len = strlen((string) $value);
		if ($len < 0x8000) {
			return self::IPTC_TAG_MARKER . chr((int) $record) . chr((int) $tagid) . pack('n', $len) . $value;
		}
		return self::IPTC_TAG_MARKER . chr((int) $record) . chr((int) $tagid) . pack('nN', 0x8004, $len) . $value;
	}

	/**
	 * Pads or trims a value to its dataset's size bounds.
	 * @param string &$value The value to adjust.
	 * @param ?int $lower The minimum length, or null for no bound.
	 * @param ?int $upper The maximum length, or null to match the minimum.
	 * @param bool $leadingZero Whether to left-pad with '0' (else right-pad with space).
	 * @return bool Whether the value was adjusted.
	 */
	protected static function fixBoundary(string &$value, ?int $lower, ?int $upper, bool $leadingZero = false): bool
	{
		if ($lower === null) {
			return false;
		}
		$upper ??= $lower;
		$len = strlen($value);
		if ($len < $lower) {
			$value = str_pad($value, $lower, $leadingZero ? '0' : ' ', $leadingZero ? STR_PAD_LEFT : STR_PAD_RIGHT);
			return true;
		}
		if ($upper !== -1 && $len > $upper) {
			$value = substr($value, 0, $upper);
			return true;
		}
		return false;
	}

	/**
	 * Returns the value at a key, resolving tag names to their tag id.
	 * @param mixed $key The tag id or tag name.
	 * @return mixed The value, or null when absent.
	 */
	public function itemAt($key): mixed
	{
		if ($key = self::mapToIPTCTagId($key)) {
			return parent::itemAt($key);
		}
		return null;
	}

	/**
	 * Returns an iterator over the datasets, ordered by tag id.
	 * @return \Iterator The iterator.
	 */
	public function getIterator(): \Iterator
	{
		ksort($this->_d, SORT_NATURAL);
		return new \ArrayIterator($this->_d);
	}

	/**
	 * Returns the datasets as an array, ordered by tag id.
	 * @return array The datasets.
	 */
	public function toArray(): array
	{
		ksort($this->_d, SORT_NATURAL);
		return $this->_d;
	}

	/**
	 * Adds a dataset, resolving names, coercing dates/times/integers, and filtering strings.
	 * @param mixed $key The tag id or tag name.
	 * @param mixed $value The value (or array for repeatable datasets).
	 * @throws TInvalidDataValueException When the key is not a valid tag.
	 * @return mixed The previous value at the key.
	 */
	public function add($key, $value): mixed
	{
		if (!($mapped = self::mapToIPTCTagId($okey = $key))) {
			throw new TInvalidDataValueException('iptc_not_a_valid_tagid', $okey);
		}
		$key = $mapped;
		$metaType = self::TAG_MAP[$key][self::TYPE_KEY];
		$metaProperty = self::TAG_MAP[$key][self::PROPERTY_KEY];
		$metaMin = self::TAG_MAP[$key][self::MIN_KEY];
		$metaMax = self::TAG_MAP[$key][self::MAX_KEY];

		if ($metaProperty & self::PROP_TIME) {
			$value = $this->coerceTime($value);
		} elseif ($metaProperty & self::PROP_DATE) {
			$value = is_array($value) ? array_map([$this, 'coerceDate'], $value) : $this->coerceDate($value);
		} elseif ($metaType === self::TYPE_STRING) {
			$filter = fn ($s) => $this->filterString($s, $metaProperty, $metaMin, $metaMax);
			$value = is_array($value) ? array_map($filter, $value) : $filter($value);
		} elseif ($metaType === self::TYPE_UINT32) {
			$value = (int) $value;
		} elseif ($metaType === self::TYPE_UINT16) {
			$value = max(min((int) $value, 0xFFFF), 0);
		} elseif ($metaType === self::TYPE_UINT8) {
			$value = max(min((int) $value, 0xFF), 0);
		} elseif ($metaType === self::TYPE_UNDEF && is_string($value)) {
			self::fixBoundary($value, $metaMin, $metaMax);
		}

		$this->updateEnvelopeNumber($key, $value);
		return parent::add($key, $value);
	}

	/**
	 * Coerces a value to an IPTC time string.
	 * @param mixed $value The time value.
	 * @return string The IPTC time.
	 */
	private function coerceTime(mixed $value): string
	{
		if (is_int($value) || !preg_match(self::TIMEVALUE_REGEX, (string) $value)) {
			$value = is_numeric($value) ? (int) $value : strtotime((string) ($value ?? ''));
			return self::formatIPTCTime($value === false ? null : $value);
		}
		return (string) $value;
	}

	/**
	 * Coerces a value to an IPTC date string.
	 * @param mixed $value The date value.
	 * @return string The IPTC date.
	 */
	private function coerceDate(mixed $value): string
	{
		if (is_int($value) || !preg_match(self::DATEVALUE_REGEX, (string) $value)) {
			$value = is_numeric($value) ? (int) $value : strtotime((string) $value);
			return self::formatIPTCDate($value === false ? null : (int) $value);
		}
		return (string) $value;
	}

	/**
	 * Filters a string to the characters its dataset permits, then bounds its length.
	 * @param ?string $s The value.
	 * @param int $property The dataset property flags.
	 * @param ?int $min The minimum length.
	 * @param ?int $max The maximum length.
	 * @return ?string The filtered value.
	 */
	private function filterString(?string $s, int $property, ?int $min, ?int $max): ?string
	{
		if ($s === null) {
			return null;
		}
		$opt = '';
		$chars = '';
		if ($property & self::PROP_GRAPHICCHAR) {
			$opt = 'u';
			$chars .= '\pL\pN\pM\pS\pP';
		}
		if ($property & self::PROP_NUMERIC) {
			$chars .= '\d';
		}
		if ($property & self::PROP_ALPHABET) {
			$chars .= 'a-zA-Z';
		}
		if ($property & self::PROP_SPACE) {
			$chars .= '\p{Zs}\t';
		}
		if ($property & self::PROP_CRLF) {
			$chars .= '\r\n';
		}
		if ($chars !== '') {
			$s = (string) preg_replace('/[^' . $chars . ']/' . $opt, '', $s);
		}
		if ($property & self::PROP_OBJECTNAME) {
			$s = (string) preg_replace('/[\*\?]/u', '', $s);
		}
		self::fixBoundary($s, $min, $max, ($property & self::PROP_LEFT_ZERO) !== 0);
		return $s;
	}

	/**
	 * Recomputes the EnvelopeNumber when the service id or date sent changes.
	 * @param string $key The tag id being set.
	 * @param mixed $value The new value.
	 */
	private function updateEnvelopeNumber(string $key, mixed $value): void
	{
		if ($key !== TIPTCTags::ServiceIdentifier && $key !== TIPTCTags::DateSent) {
			return;
		}
		if (!$this->contains(TIPTCTags::ServiceIdentifier) || !$this->contains(TIPTCTags::DateSent)) {
			return;
		}
		$oldEnvNum = self::computeEnvelopeNumber($this[TIPTCTags::ServiceIdentifier], $this[TIPTCTags::DateSent]);
		if (!$this->contains(TIPTCTags::EnvelopeNumber) || $this[TIPTCTags::EnvelopeNumber] === $oldEnvNum) {
			$serviceId = $key === TIPTCTags::ServiceIdentifier ? $value : $this[TIPTCTags::ServiceIdentifier];
			$dateSent = $key === TIPTCTags::DateSent ? $value : $this[TIPTCTags::DateSent];
			$this[TIPTCTags::EnvelopeNumber] = self::computeEnvelopeNumber($serviceId, $dateSent);
		}
	}

	/**
	 * Removes a dataset, resolving names; recomputes EnvelopeNumber when it is the target.
	 * @param mixed $key The tag id or tag name.
	 * @return mixed The removed value, or null when absent.
	 */
	public function remove($key): mixed
	{
		if ($key = self::mapToIPTCTagId($key)) {
			if ($key === TIPTCTags::EnvelopeNumber && $this->contains(TIPTCTags::ServiceIdentifier) && $this->contains(TIPTCTags::DateSent)) {
				$oldValue = $this[TIPTCTags::EnvelopeNumber];
				$this[TIPTCTags::EnvelopeNumber] = self::computeEnvelopeNumber($this[TIPTCTags::ServiceIdentifier], $this[TIPTCTags::DateSent]);
				return $oldValue;
			}
			return parent::remove($key);
		}
		return null;
	}

	//
	// ─── Privacy ─────────────────────────────────────────────────────────────
	//

	/**
	 * @var array<int, array<int, string>> The IIM datasets each {@see TPrivacyCategory}
	 *   flag removes — the same facts the EXIF and XMP scrubs remove, in their IIM homes.
	 *   The record-structure datasets (record versions, file format, character set, and
	 *   the picture-describing NewsPhoto fields) are never listed.
	 */
	protected const PrivacyDatasets = [
		TPrivacyCategory::Location => [
			TIPTCTags::City, TIPTCTags::SubLocation, TIPTCTags::ProvinceState,
			TIPTCTags::CountryPrimaryLocationCode, TIPTCTags::CountryPrimaryLocationName,
			TIPTCTags::ContentLocationCode, TIPTCTags::ContentLocationName,
		],
		TPrivacyCategory::Author => [
			TIPTCTags::ByLine, TIPTCTags::ByLineTitle, TIPTCTags::Credit, TIPTCTags::Source,
			TIPTCTags::CopyrightNotice, TIPTCTags::Contact, TIPTCTags::WriterEditor,
			TIPTCTags::Destination, TIPTCTags::ProductID,
		],
		TPrivacyCategory::Description => [
			TIPTCTags::ObjectName, TIPTCTags::Headline, TIPTCTags::CaptionAbstract, TIPTCTags::LocalCaption,
			TIPTCTags::Keywords, TIPTCTags::SpecialInstructions, TIPTCTags::SubjectReference,
			TIPTCTags::Category, TIPTCTags::SupplementalCategories, TIPTCTags::EditStatus,
			TIPTCTags::FixtureIdentifier, TIPTCTags::DocumentNotes, TIPTCTags::DocumentHistory,
		],
		TPrivacyCategory::CameraModel => [
			TIPTCTags::ExifCameraInfo,
		],
		TPrivacyCategory::SerialNumber => [
			TIPTCTags::JobID, TIPTCTags::MasterDocumentID, TIPTCTags::ShortDocumentID,
			TIPTCTags::UniqueDocumentID, TIPTCTags::OwnerID, TIPTCTags::UniqueObjectName,
			TIPTCTags::OriginalTransmissionReference,
			TIPTCTags::ReferenceService, TIPTCTags::ReferenceNumber,
		],
		TPrivacyCategory::Timestamp => [
			TIPTCTags::DateCreated, TIPTCTags::TimeCreated,
			TIPTCTags::DigitalCreationDate, TIPTCTags::DigitalCreationTime,
			TIPTCTags::TimeSent,
			TIPTCTags::ReleaseDate, TIPTCTags::ReleaseTime,
			TIPTCTags::ExpirationDate, TIPTCTags::ExpirationTime, TIPTCTags::ReferenceDate,
		],
		TPrivacyCategory::Software => [
			TIPTCTags::OriginatingProgram, TIPTCTags::ProgramVersion,
		],
		TPrivacyCategory::Thumbnail => [
			TIPTCTags::ObjectPreviewData, TIPTCTags::ObjectPreviewFileFormat, TIPTCTags::ObjectPreviewFileVersion,
			TIPTCTags::RasterizedCaption,
		],
	];

	/**
	 * Removes identifying information from the record set by category — the IIM homes of
	 * the same facts the EXIF and XMP scrubs remove: location, by-line and credit,
	 * captions and keywords, the camera info, document and job identifiers, dates and
	 * times, the originating program, and embedded previews.  The record-structure
	 * datasets (record versions, file format, character set) and the picture-describing
	 * NewsPhoto fields are left, so the block stays a valid, useful IIM record set.  The
	 * mandatory envelope date and number cannot be dropped, so an existing `DateSent` is
	 * replaced by {@see ScrubbedDate} and the `EnvelopeNumber` re-derived from it — a
	 * scrub never mints a fresh timestamp or identifier.
	 * @param int $types The {@see TPrivacyCategory} flags to remove. Default {@see TPrivacyCategory::All}.
	 * @return int The number of datasets removed or replaced.
	 */
	public function clearPrivateData(int $types = TPrivacyCategory::All): int
	{
		$removed = 0;
		foreach (self::PrivacyDatasets as $flag => $datasets) {
			if (($types & $flag) === 0) {
				continue;
			}
			foreach ($datasets as $dataset) {
				if ($this->contains($dataset)) {
					parent::remove($dataset);   // bypass the EnvelopeNumber recompute: a scrub must not regenerate an identifier
					$removed++;
				}
			}
		}
		// IIM mandates DateSent, ServiceIdentifier, and EnvelopeNumber in the envelope, and
		// validate() refills any that are missing on the next write with today's date, a
		// PRADO service id, and an envelope number derived from them.  A scrub must not
		// leave a real timestamp or an identifier derived from one behind, so an existing
		// envelope date is replaced by a fixed, obviously synthetic sentinel and an existing
		// envelope number is re-derived from it -- replaced, never minted: a record set that
		// has no envelope yet is left for the writer to stamp as freshly sent, which it is.
		if (($types & TPrivacyCategory::Timestamp) && $this->contains(TIPTCTags::DateSent) && $this[TIPTCTags::DateSent] !== self::ScrubbedDate) {
			$this[TIPTCTags::DateSent] = self::ScrubbedDate;
			$removed++;
		}
		if (($types & (TPrivacyCategory::Timestamp | TPrivacyCategory::SerialNumber | TPrivacyCategory::Author)) && $this->contains(TIPTCTags::EnvelopeNumber)) {
			$this[TIPTCTags::ServiceIdentifier] ??= 'PRADO' . substr(Prado::getVersion(), 0, 5);
			$envelope = self::computeEnvelopeNumber($this[TIPTCTags::ServiceIdentifier], $this[TIPTCTags::DateSent] ?? self::ScrubbedDate);
			if ($this[TIPTCTags::EnvelopeNumber] !== $envelope) {
				$this[TIPTCTags::EnvelopeNumber] = $envelope;
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * Indicates whether a dataset is present, resolving tag names.
	 * @param mixed $key The tag id or tag name.
	 * @return bool Whether the dataset exists.
	 */
	public function contains($key): bool
	{
		if ($key = self::mapToIPTCTagId($key)) {
			return parent::contains($key);
		}
		return false;
	}

	/**
	 * Returns the NewsPhoto IPTCImageWidth dataset.
	 * @return ?int The width, or null when absent.
	 */
	public function getWidth(): ?int
	{
		return $this[TIPTCTags::IPTCImageWidth];
	}

	/**
	 * Sets (or clears, when null) the NewsPhoto IPTCImageWidth dataset.
	 * @param ?int $value The width, or null to remove it.
	 */
	public function setWidth(?int $value): void
	{
		if ($value !== null) {
			$this[TIPTCTags::IPTCImageWidth] = $value;
		} else {
			unset($this[TIPTCTags::IPTCImageWidth]);
		}
	}

	/**
	 * Returns the NewsPhoto IPTCImageHeight dataset.
	 * @return ?int The height, or null when absent.
	 */
	public function getHeight(): ?int
	{
		return $this[TIPTCTags::IPTCImageHeight];
	}

	/**
	 * Sets (or clears, when null) the NewsPhoto IPTCImageHeight dataset.
	 * @param ?int $value The height, or null to remove it.
	 */
	public function setHeight(?int $value): void
	{
		if ($value !== null) {
			$this[TIPTCTags::IPTCImageHeight] = $value;
		} else {
			unset($this[TIPTCTags::IPTCImageHeight]);
		}
	}

	/**
	 * Indicates whether the NewsPhoto ICC_Profile dataset is present.
	 * @return bool Whether an ICC profile dataset exists.
	 */
	public function hasICCProfile(): bool
	{
		return $this->contains(TIPTCTags::ICC_Profile);
	}

	/**
	 * Returns the NewsPhoto ICC_Profile dataset.
	 * @return ?string The ICC profile, or null when absent.
	 */
	public function getICCProfile(): ?string
	{
		return $this[TIPTCTags::ICC_Profile];
	}

	/**
	 * Sets (or clears, when null) the NewsPhoto ICC_Profile dataset.
	 * @param ?string $value The ICC profile, or null to remove it.
	 */
	public function setICCProfile(?string $value): void
	{
		if ($value !== null) {
			$this[TIPTCTags::ICC_Profile] = $value;
		} else {
			unset($this[TIPTCTags::ICC_Profile]);
		}
	}

	/**
	 * Indicates whether IPTC metadata is present; a record set is its own IPTC, so
	 * this is always true.
	 * @return bool Whether IPTC metadata is present.
	 */
	public function hasIPTC(): bool
	{
		return true;
	}

	/**
	 * Returns the IPTC record set, which is this instance itself.
	 * @return static The IPTC record set.
	 */
	public function getIPTC(): static
	{
		return $this;
	}

	/**
	 * Replaces the record set: clears every dataset, then reads a binary IPTC block or
	 * merges another record set or traversable of datasets.  A null empties the set.
	 * @param null|array|self|string|\Traversable $value The replacement IPTC data.
	 * @return bool Whether the replacement was stored (a binary block may fail to parse).
	 */
	public function setIPTC(null|string|self|array|\Traversable $value): bool
	{
		$this->clear();
		if (is_string($value)) {
			return $this->read($value);
		} elseif ($value) {
			$this->mergeWith($value);
		}
		return true;
	}

	/**
	 * Indicates whether the Application ExifCameraInfo dataset is present.
	 * @return bool Whether EXIF camera information exists.
	 */
	public function hasEXIF(): bool
	{
		return $this->contains(TIPTCTags::ExifCameraInfo);
	}

	/**
	 * Returns the Application ExifCameraInfo dataset.
	 * @return ?string The EXIF camera information, or null when absent.
	 */
	public function getEXIF(): ?string
	{
		return $this[TIPTCTags::ExifCameraInfo];
	}

	/**
	 * Sets (or clears, when null) the Application ExifCameraInfo dataset.
	 * @param ?string $value The EXIF camera information, or null to remove it.
	 * @return bool Whether the value was stored.
	 */
	public function setEXIF(?string $value): bool
	{
		if ($value !== null) {
			$this[TIPTCTags::ExifCameraInfo] = $value;
		} else {
			unset($this[TIPTCTags::ExifCameraInfo]);
		}
		return true;
	}

	/**
	 * Indicates whether XMP metadata is present; IPTC IIM has no XMP dataset, so this
	 * is always false.
	 * @return bool Whether XMP metadata is present.
	 */
	public function hasXMP(): bool
	{
		return false;
	}

	/**
	 * Returns the XMP metadata; IPTC IIM has no XMP dataset, so this is always null.
	 * @return ?string The XMP metadata, or null when absent.
	 */
	public function getXMP(): ?string
	{
		return null;
	}

	/**
	 * Declines to store XMP metadata; IPTC IIM has no XMP dataset.
	 * @param ?string $value The XMP metadata; ignored.
	 * @return bool Whether the value was stored; always false.
	 */
	public function setXMP(?string $value): bool
	{
		return false;
	}

	/**
	 * Decodes the Application RasterizedCaption dataset into an image in the requested
	 * graphics library.  The dataset is a 1-bit 460x128 raster packed column-major,
	 * bottom to top, 7360 bytes in all.
	 * @param ?string $mode The {@see TImageGraphicsMode} to build in; null for the default.
	 * @return null|false|\GdImage|\Imagick The caption image, false when the dataset is
	 *   malformed, or null when absent.
	 */
	public function getRasterizedCaptionImage(?string $mode = null): null|false|\GdImage|\Imagick
	{
		if (!$this->contains(TIPTCTags::RasterizedCaption)) {
			return null;
		}
		$rasterData = $this[TIPTCTags::RasterizedCaption];
		if (strlen($rasterData) !== 7360) {
			return false;
		}
		$rgb = str_repeat("\0", 460 * 128 * 3);
		for ($byteIndex = 0; $byteIndex < 7360; $byteIndex++) {
			$byte = ord($rasterData[$byteIndex]);
			for ($bitIndex = 0, $pixelIndex = $byteIndex * 8; $bitIndex < 8; $bitIndex++, $pixelIndex++) {
				if ($byte & (1 << $bitIndex)) {
					$x = intdiv($pixelIndex, 128);
					$y = 127 - ($pixelIndex % 128);
					$offset = ($y * 460 + $x) * 3;
					$rgb[$offset] = "\xFF";
					$rgb[$offset + 1] = "\xFF";
					$rgb[$offset + 2] = "\xFF";
				}
			}
		}
		return TImageGraphics::fromRgbPixels($rgb, 460, 128, $mode);
	}

	/**
	 * Encodes a GD or Imagick image into the Application RasterizedCaption dataset,
	 * resampling it to the 1-bit 460x128 raster the dataset requires.
	 * @param \GdImage|\Imagick $image The caption image.
	 * @param bool $dither Whether to dither the black-and-white reduction. Default true.
	 * @return bool Whether the caption was encoded and stored.
	 */
	public function setRasterizedCaptionImage(\GdImage|\Imagick $image, bool $dither = true): bool
	{
		$innerImage = TImageGraphics::resampled($image, 460, 128);
		if ($innerImage === false) {
			return false;
		}
		$mono = TImageGraphics::monoPixels($innerImage, $dither);
		if ($mono === false) {
			return false;
		}
		$pixelData = '';
		$byte = 0;
		$bitIndex = 0;
		for ($x = 0; $x < 460; $x++) {
			for ($y = 127; $y >= 0; $y--) {
				$byte |= ord($mono[$y * 460 + $x]) << $bitIndex;
				$bitIndex++;

				if ($bitIndex === 8) {
					$pixelData .= chr($byte);
					$byte = 0;
					$bitIndex = 0;
				}
			}
		}
		$this[TIPTCTags::RasterizedCaption] = $pixelData;
		return true;
	}
}
