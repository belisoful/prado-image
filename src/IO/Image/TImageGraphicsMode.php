<?php

/**
 * TImageGraphicsMode class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\IO\Image;

use Prado\TEnumerable;

/**
 * TImageGraphicsMode class.
 *
 * Enumerates the graphics libraries {@see TImageGraphics} converts raster data through:
 * `GD` (ext-gd, `\GdImage`, {@see TImageGraphicsGD}) and `Imagick` (ext-imagick,
 * `\Imagick`, {@see TImageGraphicsImagick}).  A null mode in the {@see TImageGraphics}
 * methods selects the {@see TImageGraphics::getDefaultMode() default}, which prefers GD
 * and falls back to Imagick.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TImageGraphicsMode extends TEnumerable
{
	public const GD = 'GD';
	public const Imagick = 'Imagick';
}
