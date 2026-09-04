<?php

/**
 * THorizontalPredictorDecoder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * THorizontalPredictorDecoder class.
 *
 * The incremental decoder half of the horizontal predictor: it reverses TIFF horizontal
 * differencing (Predictor 2) over complete rows as they arrive.  See
 * {@see THorizontalPredictorCodec} for the row-buffering behavior and
 * {@see THorizontalPredictor} for the transform.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class THorizontalPredictorDecoder extends THorizontalPredictorCodec
{
	/**
	 * Accumulates complete rows, undoing the differencing.
	 * @param string $rows The differenced row bytes.
	 * @return string The raw sample bytes.
	 */
	protected function transform(string $rows): string
	{
		return THorizontalPredictor::decode($rows, $this->_columns, $this->_samples);
	}
}
