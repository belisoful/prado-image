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
 * row, required) and `samples` (channels per pixel, default 1).  The filter carries at
 * most one partial row between chunks; a trailing partial row transforms at close.
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

	/** @var bool Whether this instance accumulates (set from the filter name). */
	private bool $_decode = false;

	/** @var int The pixels per row. */
	private int $_columns = 0;

	/** @var int The interleaved channels per pixel. */
	private int $_samples = 1;

	/** @var string The buffered partial row awaiting more input. */
	private string $_carry = '';

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
	 * Picks the direction from the filter name and the row geometry from the attach-time
	 * parameters when PHP creates the filter.
	 * @return bool Whether `columns` (and any `samples`) form a valid positive geometry.
	 */
	public function onCreate(): bool
	{
		$this->_decode = ($this->filtername === static::DecodeName);
		$params = is_array($this->params) ? $this->params : [];
		$this->_columns = (int) ($params['columns'] ?? 0);
		$this->_samples = (int) ($params['samples'] ?? 1);
		return $this->_columns >= 1 && $this->_samples >= 1;
	}

	/**
	 * Transforms the whole rows the carry now holds, keeping any partial row.
	 * @param string $data The input chunk.
	 * @return string The transformed bytes of the completed rows.
	 */
	protected function process(string $data): string
	{
		$this->_carry .= $data;
		$rowBytes = $this->_columns * $this->_samples;
		$whole = intdiv(strlen($this->_carry), $rowBytes) * $rowBytes;
		if ($whole === 0) {
			return '';
		}
		$rows = substr($this->_carry, 0, $whole);
		$this->_carry = substr($this->_carry, $whole);
		return $this->transform($rows);
	}

	/**
	 * Transforms the trailing partial row when the stream closes.
	 * @return string The transformed partial row, or '' when none is pending.
	 */
	protected function finish(): string
	{
		if ($this->_carry === '') {
			return '';
		}
		$rows = $this->_carry;
		$this->_carry = '';
		return $this->transform($rows);
	}

	/**
	 * Runs the configured direction over complete rows.
	 * @param string $rows The row bytes to transform.
	 * @return string The transformed bytes.
	 */
	private function transform(string $rows): string
	{
		return $this->_decode
			? THorizontalPredictor::decode($rows, $this->_columns, $this->_samples)
			: THorizontalPredictor::encode($rows, $this->_columns, $this->_samples);
	}
}
