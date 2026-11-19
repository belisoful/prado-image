<?php

use Prado\Exceptions\TException;

/**
 * The framework formats a message a file defines as an ICU MessageFormat pattern, where a
 * single quote before a brace turns the placeholder into literal text: `'{0}'` prints
 * `{0}` and the argument is lost.  Every message of the extension is rendered here with
 * its arguments, so one written that way is caught.
 */
class TErrorMessagesTest extends PHPUnit\Framework\TestCase
{
	/** @return array<string, string> The messages of the extension, by error code. */
	private function messages(): array
	{
		$messages = [];
		foreach (file(__DIR__ . '/../../../../config/errorMessages.txt', FILE_IGNORE_NEW_LINES) as $line) {
			if (preg_match('/^(\w+)\s*=\s*(.+)$/', $line, $match) === 1) {
				$messages[$match[1]] = $match[2];
			}
		}
		return $messages;
	}

	public function testEveryMessageShowsTheArgumentsItIsGiven()
	{
		$messages = $this->messages();
		self::assertGreaterThan(80, count($messages));

		foreach ($messages as $code => $pattern) {
			preg_match_all('/\{(\d)\}/', $pattern, $found);
			$arguments = [];
			foreach (array_unique($found[1]) as $index) {
				$arguments[(int) $index] = "<argument $index>";
			}
			ksort($arguments);

			$message = (new TException($code, ...$arguments))->getMessage();
			self::assertDoesNotMatchRegularExpression('/\{\d\}/', $message, "$code leaves a placeholder unfilled");
			foreach ($arguments as $argument) {
				self::assertStringContainsString($argument, $message, "$code drops an argument");
			}
		}
	}

	public function testAQuotedArgumentKeepsItsQuotes()
	{
		self::assertSame("Image file '/a/b.jpg' cannot be written.", (new TException('imagefile_unwritable', '/a/b.jpg'))->getMessage());
		self::assertSame("LZW data contains code '300' but the dictionary extends only to code '258'.", (new TException('lzwcompressor_code_invalid', 300, 258))->getMessage());
	}
}
