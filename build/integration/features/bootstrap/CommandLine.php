<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

require __DIR__ . '/../../vendor/autoload.php';

trait CommandLine {
	/** @var int return code of last command */
	private $lastCode;
	/** @var string stdout of last command */
	private $lastStdOut;
	/** @var string stderr of last command */
	private $lastStdErr;

	/** @var string */
	protected $ocPath = '../..';

	/**
	 * Invokes an OCC command
	 *
	 * @param []string $args OCC command, the part behind "occ". For example: "files:transfer-ownership"
	 * @return int exit code
	 */
	public function runOcc($args = []) {
		$args = array_map(function($arg) {
			return escapeshellarg($arg);
		}, $args);
		$args[] = '--no-ansi';
		$args = implode(' ', $args);

		$descriptor = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open('php console.php ' . $args, $descriptor, $pipes, $this->ocPath);
		$this->lastStdOut = stream_get_contents($pipes[1]);
		$this->lastStdErr = stream_get_contents($pipes[2]);
		$this->lastCode = proc_close($process);

		// Clean opcode cache
		$client = new GuzzleHttp\Client();
		$request = $client->createRequest('GET', 'http://localhost:8080/apps/testing/clean_opcode_cache.php');
		$client->send($request);

		return $this->lastCode;
	}

	/**
	 * @Given /^invoking occ with "([^"]*)"$/
	 */
	public function invokingTheCommand($cmd) {
		$args = explode(' ', $cmd);
		$this->runOcc($args);
	}

	/**
	 * Find exception texts in stderr
	 */
	public function findExceptions() {
		$exceptions = [];
		$captureNext = false;
		// the exception text usually appears after an "[Exception"] row
		foreach (explode("\n", $this->lastStdErr) as $line) {
			if (preg_match('/\[Exception\]/', $line)) {
				$captureNext = true;
				continue;
			}
			if ($captureNext) {
				$exceptions[] = trim($line);
				$captureNext = false;
			}
		}

		return $exceptions;
	}

	/**
	 * Finds all lines containing the given text
	 *
	 * @param string $input stdout or stderr output
	 * @param string $text text to search for
	 * @return array array of lines that matched
	 */
	public function findLines($input, $text) {
		$results = [];
		// the exception text usually appears after an "[Exception"] row
		foreach (explode("\n", $input) as $line) {
			if (strpos($line, $text) >= 0) {
				$results[] = $line;
			}
		}

		return $results;
	}

	/**
	 * @Then /^the command was successful$/
	 */
	public function theCommandWasSuccessful() {
		$exceptions = $this->findExceptions();
		if ($this->lastCode !== 0) {
			$msg = 'The command was not successful, exit code was ' . $this->lastCode . '.';
			if (!empty($exceptions)) {
				$msg .= ' Exceptions: ' . implode(', ', $exceptions);
			}
			throw new \Exception($msg);
		} else if (!empty($exceptions)) {
			$msg = 'The command was successful but triggered exceptions: ' . implode(', ', $exceptions);
			throw new \Exception($msg);
		}
	}

	/**
	 * @Then /^the command failed with exit code ([0-9]+)$/
	 */
	public function theCommandFailedWithExitCode($exitCode) {
		if ($this->lastCode !== (int)$exitCode) {
			throw new \Exception('The command was expected to fail with exit code ' . $exitCode . ' but got ' . $this->lastCode);
		}
	}

	/**
	 * @Then /^the command failed with exception text "([^"]*)"$/
	 */
	public function theCommandFailedWithException($exceptionText) {
		$exceptions = $this->findExceptions();
		if (empty($exceptions)) {
			throw new \Exception('The command did not throw any exceptions');
		}

		if (!in_array($exceptionText, $exceptions)) {
			throw new \Exception('The command did not throw any exception with the text "' . $exceptionText . '"');
		}
	}

	/**
	 * @Then /^the command output contains the text "([^"]*)"$/
	 */
	public function theCommandOutputContainsTheText($text) {
		$lines = $this->findLines($this->lastStdOut, $text);
		if (empty($lines)) {
			throw new \Exception('The command did not output the expected text on stdout "' . $exceptionText . '"');
		}
	}

	/**
	 * @Then /^the command error output contains the text "([^"]*)"$/
	 */
	public function theCommandErrorOutputContainsTheText($text) {
		$lines = $this->findLines($this->lastStdErr, $text);
		if (empty($lines)) {
			throw new \Exception('The command did not output the expected text on stderr "' . $exceptionText . '"');
		}
	}
}
