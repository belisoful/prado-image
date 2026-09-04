<?php

/**
 * THorizontalPredictorCodec class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

use Prado\Exceptions\TIOException;

/**
 * THorizontalPredictorCodec class.
 *
 * The shared base of the incremental horizontal-predictor engines (see
 * {@see THorizontalPredictor} for the transform).  A row is `columns * samples` bytes and
 * each row transforms independently, so the engine buffers at most one partial row between
 * chunks: {@see add()} transforms every whole row the carry holds and keeps the remainder,
 * and {@see finish()} transforms the trailing partial row — it is not discarded, because
 * the differencing is per-byte reversible within a row, so a short final row still decodes
 * to real samples.  {@see THorizontalPredictorEncoder} differences and
 * {@see THorizontalPredictorDecoder} accumulates.  The whole-string {@see THorizontalPredictor}
 * and the streaming {@see THorizontalPredictorFilter} both drive it.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
abstract class THorizontalPredictorCodec implements IStreamCodec
{
	/** @var int The pixels per row. */
	protected int $_columns;

	/** @var int The interleaved channels per pixel. */
	protected int $_samples;

	/** @var string The buffered partial row awaiting more input. */
	private string $_carry = '';

	/**
	 * @param int $columns The pixels per row.
	 * @param int $samples The interleaved channels per pixel. Default 1.
	 * @throws TIOException When columns or samples is not positive.
	 */
	public function __construct(int $columns, int $samples = 1)
	{
		if ($columns < 1 || $samples < 1) {
			throw new TIOException('horizontalpredictor_geometry_invalid', $columns, $samples);
		}
		$this->_columns = $columns;
		$this->_samples = $samples;
	}

	/**
	 * Transforms the whole rows the carry now holds, keeping any partial row.
	 * @param string $data The input chunk.
	 * @return string The transformed bytes of the completed rows.
	 */
	public function add(string $data): string
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
	public function finish(): string
	{
		if ($this->_carry === '') {
			return '';
		}
		$rows = $this->_carry;
		$this->_carry = '';
		return $this->transform($rows);
	}

	/**
	 * Runs the concrete direction over complete rows.
	 * @param string $rows The row bytes to transform.
	 * @return string The transformed bytes.
	 */
	abstract protected function transform(string $rows): string;
}
