#!/usr/bin/env php
<?php

$checkFaceComRate = new CheckFaceComRate();
$checkFaceComRate->parseOptions();
$checkFaceComRate->check();

/**
 * nagios plugin for face.com rate limit monitoring
 * requires curl
 */
class CheckFaceComRate {
	const EXIT_OK = 0;
	const EXIT_WARN = 1;
	const EXIT_CRIT = 2;
	const EXIT_UNKNOWN = 3;

	const API_SERVER = 'http://api.face.com/';
	const API_TIMEOUT = 2;
	const API_TIME_MAX_DIFF = 5;

	/**
	 * @var array
	 */
	private $options = array(
		'key:',
		'secret:',
		'crit:',
	);

	/**
	 * @var array
	 */
	private $arguments = array();

	/**
	 * parse command line options
	 */
	public function parseOptions() {
		// get options
		$this->arguments = getopt('', $this->options);

		foreach ($this->options as $option) {
			$option = str_replace(':', '', $option);
			if (empty($this->arguments[$option])) {
				$this->quit(self::EXIT_WARN, 'option "'.$option.'" not set or empty');
			}
		}

		if (empty($this->arguments)) {
			$this->quit(self::EXIT_WARN, 'error parsing options');
		}
	}

	/**
	 * get data from face.com api and check state
	 */
	public function check() {
		$authParams = array(
			'api_key' => $this->arguments['key'],
			'api_secret' => $this->arguments['secret'],
		);
    	$params = http_build_query($authParams);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, self::API_SERVER.'account/limits.json');
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_TIMEOUT, self::API_TIMEOUT);
		$rawData = curl_exec($curl);

		if (CURLE_OK !== curl_errno($curl)) {
			$this->quit(self::EXIT_WARN, 'curl error: '.curl_error($curl));
		}

		curl_close($curl);

		$result = json_decode($rawData, true);

		$this->validateResult($result);
		$this->checkUsage($result['usage']);
	}

	/**
	 * @param int $code
	 * @param string $message
	 * @param array $params
	 */
	private function quit($code, $message = '', array $params = array()) {
		echo $message;
		if (!empty($params)) {
			echo '|';
			foreach ($params as $key => $value) {
				$params[$key] = $key.'='.$value;
			}
			echo implode(';', $params);
		}
		exit($code);
	}

	/**
	 * @param array $result
	 */
	private function validateResult(array $result) {
		$message = 'face.com error';

		// error response
		if ('failure' === $result['status'] || !isset($result['usage'])) {
			if (isset($result['error_message'])) {
				$message .= ': '.$result['error_message'];
			}
			$this->quit(self::EXIT_WARN, $message);
		}

		// check required values
		if (!isset($result['usage']['used']) || !is_int($result['usage']['used']) || 0 > $result['usage']['used']) {
			$message .= ': invalid value "used"';
			$this->quit(self::EXIT_WARN, $message);
		}
		if (!isset($result['usage']['remaining']) || !is_int($result['usage']['remaining']) || 0 > $result['usage']['remaining']) {
			$message .= ': invalid value "remaining"';
			$this->quit(self::EXIT_WARN, $message);
		}
		if (!isset($result['usage']['limit']) || !is_int($result['usage']['limit']) || 0 > $result['usage']['limit']) {
			$message .= ': invalid value "limit"';
			$this->quit(self::EXIT_WARN, $message);
		}
		if (!isset($result['usage']['reset_time']) || !is_int($result['usage']['reset_time']) || $_SERVER['REQUEST_TIME'] - self::API_TIME_MAX_DIFF > $result['usage']['reset_time']) {
			$message .= ': invalid value "reset_time"';
			$this->quit(self::EXIT_WARN, $message);
		}
	}

	/**
	 * @param array $usage
	 */
	private function checkUsage(array $usage) {
		$remainingSeconds = $usage['reset_time'] - $_SERVER['REQUEST_TIME'];
		if ($remainingSeconds >= 3600) {
			$averageUsage = 0;
		}
		else {
			$averageUsage = round($usage['used'] / (3600 - $remainingSeconds), 2);
		}

		if ($usage['remaining'] <= (int)($usage['limit'] * $this->arguments['crit'] / 100)) {
			$this->quit(self::EXIT_CRIT, 'critical limit reached, '.$usage['remaining'].' remaining', array('remaining' => $usage['remaining'], 'usage' => $averageUsage));
		}
		else {
			$this->quit(self::EXIT_OK, 'all fine, '.$usage['remaining'].' remaining', array('remaining' => $usage['remaining'], 'usage' => $averageUsage));
		}
	}
}
