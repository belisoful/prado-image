<?php

/**
 * THorizontalPredictorFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

use Prado\IO\Filter\TStreamCodecFilter;

/**
 * THorizontalPredictorFilter class.
 *
 * Streams TIFF horizontal differencing (Predictor 2) as a PHP stream filter, producing
 * the same bytes as {@see THorizontalPredictor} without buffering the whole stream.  It
 * registers under two names so the direction is chosen at attach time: {@see EncodeName}
 * differences and {@see DecodeName} accumulates.  {@see registerOnce()} registers both.
 *
 * The row geometry is passed as the attach-time parameter array: `columns` (pixels per
 * row, required) and `samples` (channels per pixel, default 1).  The filter holds no
 * algorithm of its own: it drives the same incremental
 * {@see THorizontalPredictorEncoder}/{@see THorizontalPredictorDecoder} engine that
 * {@see THorizontalPredictor} uses, so it carries at most one partial row between chunks
 * and transforms a trailing partial row at close, exactly as the whole-string form does.
 *
 * ```php
 * THorizontalPredictorFilter::registerOnce();
 * $s = TStream::fromFile('strip.raw', 'rb');
 * $s->appendFilter(THorizontalPredictorFilter::EncodeName, STREAM_FILTER_READ,
 *     ['columns' => 640, 'samples' => 3]);
 * $differenced = $s->getContents();
 * ```
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class THorizontalPredictorFilter extends TStreamCodecFilter
{
	/** @var string The filter name that differences. */
	public const EncodeName = 'prado.tiffpredictor.encode';

	/** @var string The filter name that accumulates (undoes the differencing). */
	public const DecodeName = 'prado.tiffpredictor.decode';

	/** @var IStreamCodec The engine for this instance's direction and geometry. */
	private IStreamCodec $_codec;

	/**
	 * Returns the default (encode) filter name.
	 * @return string The encode filter name.
	 */
	public static function getFilterName(): string
	{
		return static::EncodeName;
	}

	/**
	 * Registers both the encode and decode filter names, each only once.
	 * @param ?string $name A specific name to register; null registers both.
	 */
	public static function registerOnce(?string $name = null): void
	{
		if ($name !== null) {
			parent::registerOnce($name);
			return;
		}
		parent::registerOnce(static::EncodeName);
		parent::registerOnce(static::DecodeName);
	}

	/**
	 * Picks the engine from the filter name and the row geometry from the attach-time
	 * parameters when PHP creates the filter.  Invalid geometry fails the attach rather
	 * than throwing, so the geometry is validated here before the engine is built.
	 * @return bool Whether `columns` (and any `samples`) form a valid positive geometry.
	 */
	public function onCreate(): bool
	{
		$params = is_array($this->params) ? $this->params : [];
		$columns = (int) ($params['columns'] ?? 0);
		$samples = (int) ($params['samples'] ?? 1);
		if ($columns < 1 || $samples < 1) {
			return false;
		}
		$this->_codec = ($this->filtername === static::DecodeName)
			? THorizontalPredictor::decoder($columns, $samples)
			: THorizontalPredictor::encoder($columns, $samples);
		return true;
	}

	/**
	 * Feeds a chunk to the engine.
	 * @param string $data The input chunk.
	 * @return string The transformed bytes produced from this chunk.
	 */
	protected function process(string $data): string
	{
		return $this->_codec->add($data);
	}

	/**
	 * Flushes the engine's trailing partial row when the stream closes.
	 * @return string The final bytes.
	 */
	protected function finish(): string
	{
		return $this->_codec->finish();
	}
}
