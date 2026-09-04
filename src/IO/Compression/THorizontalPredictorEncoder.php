<?php

/**
 * THorizontalPredictorEncoder class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Compression;

/**
 * THorizontalPredictorEncoder class.
 *
 * The incremental encoder half of the horizontal predictor: it applies TIFF horizontal
 * differencing (Predictor 2) to complete rows as they arrive.  See
 * {@see THorizontalPredictorCodec} for the row-buffering behavior and
 * {@see THorizontalPredictor} for the transform.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class THorizontalPredictorEncoder extends THorizontalPredictorCodec
{
	/**
	 * Differences complete rows.
	 * @param string $rows The row bytes.
	 * @return string The differenced bytes.
	 */
	protected function transform(string $rows): string
	{
		return THorizontalPredictor::encode($rows, $this->_columns, $this->_samples);
	}
}
