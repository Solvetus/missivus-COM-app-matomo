<?php

/**
 * Missivus — send Matomo email through the Microsoft Graph API.
 *
 * @link    https://github.com/Solvetus/missivus-COM-app-matomo
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace PHPUnit\Framework;

/**
 * A minimal stand-in for PHPUnit's TestCase, defined only when PHPUnit is absent.
 *
 * It implements exactly the assertions this suite uses and nothing else. Under Matomo's own
 * `./console tests:run Missivus` the real PHPUnit is already loaded and this file is inert, so
 * there is one set of test files rather than two.
 */
if (!class_exists('PHPUnit\\Framework\\TestCase', false)) {
    class AssertionFailedError extends \Exception
    {
    }

    abstract class TestCase
    {
        /** @var string|null */
        private $expectedException = null;

        /** @var string|null */
        private $expectedExceptionMessage = null;

        protected function setUp(): void
        {
        }

        /**
         * @param string $class
         * @return void
         */
        public function expectException($class)
        {
            $this->expectedException = $class;
        }

        /**
         * @param string $substring
         * @return void
         */
        public function expectExceptionMessage($substring)
        {
            $this->expectedExceptionMessage = $substring;
        }

        /**
         * Called by the runner after the test body. Turns "expected a throw that never came" into
         * a failure instead of a silent pass.
         *
         * @return void
         */
        public function assertExpectedExceptionWasThrown()
        {
            if ($this->expectedException !== null) {
                $this->fail('Expected ' . $this->expectedException . ' but nothing was thrown');
            }
        }

        /**
         * The runner hands exceptions back here so an expected one can be matched.
         *
         * @param \Throwable $e
         * @return bool True when the exception satisfied an expectation.
         */
        public function matchesExpectedException($e)
        {
            if ($this->expectedException === null || !($e instanceof $this->expectedException)) {
                return false;
            }

            if (
                $this->expectedExceptionMessage !== null
                && strpos($e->getMessage(), $this->expectedExceptionMessage) === false
            ) {
                return false;
            }

            $this->expectedException = null;
            $this->expectedExceptionMessage = null;

            return true;
        }

        public function assertSame($expected, $actual, $message = '')
        {
            if ($expected !== $actual) {
                $this->fail($message ?: 'Failed asserting identity.'
                    . "\n  expected: " . self::describe($expected)
                    . "\n  actual:   " . self::describe($actual));
            }
        }

        public function assertEquals($expected, $actual, $message = '')
        {
            if ($expected != $actual) {
                $this->fail($message ?: 'Failed asserting equality.'
                    . "\n  expected: " . self::describe($expected)
                    . "\n  actual:   " . self::describe($actual));
            }
        }

        public function assertNotEquals($expected, $actual, $message = '')
        {
            if ($expected == $actual) {
                $this->fail($message ?: 'Expected values to differ, both were ' . self::describe($actual));
            }
        }

        public function assertTrue($condition, $message = '')
        {
            if ($condition !== true) {
                $this->fail($message ?: 'Expected true, got ' . self::describe($condition));
            }
        }

        public function assertFalse($condition, $message = '')
        {
            if ($condition !== false) {
                $this->fail($message ?: 'Expected false, got ' . self::describe($condition));
            }
        }

        public function assertNull($value, $message = '')
        {
            if ($value !== null) {
                $this->fail($message ?: 'Expected null, got ' . self::describe($value));
            }
        }

        public function assertNotNull($value, $message = '')
        {
            if ($value === null) {
                $this->fail($message ?: 'Expected a value, got null');
            }
        }

        public function assertCount($expected, $actual, $message = '')
        {
            $count = is_array($actual) || $actual instanceof \Countable ? count($actual) : -1;

            if ($count !== $expected) {
                $this->fail($message ?: 'Expected ' . $expected . ' items, got ' . $count);
            }
        }

        public function assertArrayHasKey($key, $array, $message = '')
        {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                $this->fail($message ?: 'Expected key "' . $key . '" in ' . self::describe($array));
            }
        }

        public function assertArrayNotHasKey($key, $array, $message = '')
        {
            if (is_array($array) && array_key_exists($key, $array)) {
                $this->fail($message ?: 'Did not expect key "' . $key . '" in ' . self::describe($array));
            }
        }

        public function assertStringContainsString($needle, $haystack, $message = '')
        {
            if (strpos((string) $haystack, (string) $needle) === false) {
                $this->fail($message ?: 'Expected to find "' . $needle . '" in ' . self::describe($haystack));
            }
        }

        public function assertStringNotContainsString($needle, $haystack, $message = '')
        {
            if (strpos((string) $haystack, (string) $needle) !== false) {
                $this->fail($message ?: 'Did not expect "' . $needle . '" in ' . self::describe($haystack));
            }
        }

        public function assertGreaterThan($expected, $actual, $message = '')
        {
            if (!($actual > $expected)) {
                $this->fail($message ?: 'Expected a value greater than ' . self::describe($expected)
                    . ', got ' . self::describe($actual));
            }
        }

        public function fail($message = '')
        {
            throw new AssertionFailedError($message);
        }

        private static function describe($value)
        {
            if (is_string($value)) {
                return strlen($value) > 300 ? '"' . substr($value, 0, 300) . '…"' : '"' . $value . '"';
            }

            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if ($value === null) {
                return 'null';
            }

            if (is_array($value)) {
                $encoded = json_encode($value);
                return strlen($encoded) > 300 ? substr($encoded, 0, 300) . '…' : $encoded;
            }

            return is_object($value) ? get_class($value) : (string) $value;
        }
    }
}
