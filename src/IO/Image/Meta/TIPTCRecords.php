<?php

/**
 * TIPTCRecords class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image\Meta;

use Prado\TEnumerable;

/**
 * TIPTCRecords class.
 *
 * Enumerates the IPTC IIM record numbers that group datasets, used as the left side of a
 * `record#dataset` tag identifier (see {@see TIPTCTags}).
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TIPTCRecords extends TEnumerable
{
	public const Envelope = 1;
	public const Application = 2;
	public const NewsPhoto = 3;
	public const PreObjectData = 7;
	public const ObjectData = 8;
	public const PostObjectData = 9;
	public const FotoStation = 240;
}
