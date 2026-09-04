<?php

/**
 * Common settings for all unit tests of the PRADO Image extension.
 *
 * Registers the extension's error message file so exception codes resolve, then
 * autoloads the framework and the extension via Composer's PSR-4 map.
 */

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/ICCProfileBuilder.php');
require_once(__DIR__ . '/PseudoRandomBytes.php');
require_once(__DIR__ . '/TChunkedWriteStream.php');

\Prado\Exceptions\TException::addMessageFile(__DIR__ . '/../../config/errorMessages.txt');
