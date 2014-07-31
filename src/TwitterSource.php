<?php
/**
 * TwitterDataSourceForCakePHP
 * 
 * Copyright (c) 2014 Tadahisa Motooka
 * 
 * Licensed under MIT License.
 * See the file "LICENSE" for more details.
 */

App::uses('HttpSocket', 'Network/Http');

class TwitterSource extends DataSource {
	
	public $description = "DataSource for TwitterAPI 1.1";
	
	// default configuration
	public $config = array(
		'api_url_base' => 'https://api.twitter.com/1.1/',
		'oauth_url_base' => 'https://api.twitter.com/oauth/',
		'oauth_consumer_key' => 'api-key',
		'api_secret' => 'secret',
		'user-agent' => 'Twitter Source'
	);
	
	// user specific setting : usually these variables should be changed dynamically.
	public $oauthToken = '';
	public $oauthTokenSecret = '';
	
	
	// debug setting
	public $withDebugLog = true;
	
	
	
	public function __construct($config) {
		parent::__construct($config);
		$this->Http = new HttpSocket();
	}
	
	// =========================================================
	// =========================================================
	// ===== required methods to implement DataSource
	// =========================================================
	// =========================================================
	
	public function describe($model) {
		return array();
	}
	
	public function listSources($data = null) {
		return null;
	}
	
	public function calculate($model, $func, $params) {
		return '';
	}
	
	
	// =========================================================
	// =========================================================
	// ===== high-level APIs
	// ===== before calling these methods, set $oauthToken & $oauthTokenSecret if required.
	// =========================================================
	// =========================================================
	public function getHomeTimeline($params) {
		// see https://dev.twitter.com/docs/api/1.1/get/statuses/home_timeline for contents of $params
		
		$oauthArgs = array(
			'oauth_token' => $this->oauthToken
		);
		$response = $this->sendRequest('GET', 'statuses/home_timeline.json', $params, $oauthArgs, $this->oauthTokenSecret, array());
		
		return $response;
	}
	
	public function tweet($params) {
		// see https://dev.twitter.com/docs/api/1.1/post/statuses/update for contents of $params
		// "status" is required
		
		$oauthArgs = array(
			'oauth_token' => $this->oauthToken
		);
		$response = $this->sendRequest('POST', 'statuses/update.json', array(), $oauthArgs, $this->oauthTokenSecret, $params);
		
		return $response;
	}
	
	public function requestToken($callbackURL) {
		$api_url_base = $this->config['api_url_base'];
		
		$uri = $this->config['oauth_url_base'] . 'request_token';
		$this->config['api_url_base'] = $uri;
		$oauthArgs = array(
			'oauth_callback' => rawurlencode($callbackURL)
		);
		$res = $this->sendRequest('POST', '', array(), $oauthArgs, '', array());
		
		$this->config['api_url_base'] = $api_url_base;
		
		return $res;
	}
	
	// =========================================================
	// =========================================================
	// ===== low-level API : sendRequest
	// =========================================================
	// =========================================================
	public function sendRequest($requestMethod, $resourceURI, $urlParameters = array(), $oauthArgs = array(), $oauthTokenSecret = '', $postParameters = array()) {
		$logPrefix = '[TwitterSource::sendRequest] ';
		
		// build URL
		$baseURL = $this->config['api_url_base'] . $resourceURI;
		$fullURL = $baseURL;
		if(!empty($urlParameters)) {
			$isFirstParam = true;
			foreach($urlParameters as $key => $val) {
				if($isFirstParam) {
					$fullURL .= '?';
					$isFirstParam = false;
				}
				else {
					$fullURL .= '&';
				}
				$fullURL .= rawurlencode($key) . '=' . rawurlencode($val);
			}
		}
		
		if($this->withDebugLog) {
			CakeLog::write(LOG_DEBUG, $logPrefix . "baseURL=$baseURL, fullURL=$fullURL");
		}
		
		// build body (if method is post/put)
		$body = '';
		if($requestMethod == 'POST' || $requestMethod == 'PUT') {
			$isFirstParam = true;
			foreach($postParameters as $key => $val) {
				if($isFirstParam) {
					$isFirstParam = false;
				}
				else {
					$body .= '&';
				}
				$body .= rawurlencode($key) . '=' . rawurlencode($val);
			}
		}
		
		
		
		// sign
		$oauth = array(
			'oauth_consumer_key' => $this->config['oauth_consumer_key'],
			'oauth_nonce' => $this->getNonce(),
			//'oauth_signature' => '',
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp' => time(),
			'oauth_version' => '1.0'
		);
		foreach($oauthArgs as $key => $val) {
			$oauth[$key] = $val;
		}
		$params = array_merge($urlParameters, $postParameters, $oauth);
		$oauth['oauth_signature'] = $this->getSignature($requestMethod, $baseURL, $params, $oauthTokenSecret);
		
		// build oauth header (DST)
		$DST = '';
		$isDST_First = true;
		ksort($oauth);
		foreach($oauth as $key => $val) {
			if($isDST_First) {
				$DST = 'OAuth ';
				$isDST_First = false;
			}
			else {
				$DST .= ', ';
			}
			$DST .= rawurlencode($key) . '="' . rawurlencode($val) . '"';
		}
		
		
		// fire
		$request = array(
			'method' => $requestMethod,
			'uri' => $fullURL,
			'auth' => array(),
			'version' => '1.1',
			'body' => $body,
			'header' => array(
				'Authorization' => $DST,
				'User-Agent' => $this->config['user-agent'],
			),
		);
		$response = $this->Http->request($request);
		return $response;
	}
	
	
	// =========================================================
	// =========================================================
	// ===== protected methods
	// =========================================================
	// =========================================================
	
	protected function getNonce() {
		$bin = '';
		
		for($i=0; $i<32; $i++) {
			$bin .= chr(rand(0, 255));
		}
		$base64 = base64_encode($bin);
		return preg_replace('/[^0-9a-zA-Z]/', '', $base64);
	}
	
	protected function getSignature($requestMethod, $baseURL, $params, $oauthTokenSecret) {
		$logPrefix = '[TwitterSource::getSignature] ';
		
		// get signing key
		$consumerSecret = $this->config['api_secret'];
		$signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($oauthTokenSecret);
		
		// build parameter string
		$parameterString = '';
		$encodedParams = array();
		foreach($params as $key => $val) {
			$encodedKey = rawurlencode($key);
			$encodedVal = rawurlencode($val);
			$encodedParams[$encodedKey] = $encodedVal;
		}
		ksort($encodedParams);
		$isFirst = true;
		foreach($encodedParams as $key => $val) {
			if($isFirst) {
				$isFirst = false;
			}
			else {
				$parameterString .= '&';
			}
			$parameterString .= $key . '=' . $val;
		}
		
		// build signature base
		$signatureBase = strtoupper($requestMethod);
		$signatureBase .= '&';
		$signatureBase .= rawurlencode($baseURL);
		$signatureBase .= '&';
		$signatureBase .= rawurlencode($parameterString);
		
		// sign with HMAC-SHA1
		$signedKeyRaw = hash_hmac('sha1', $signatureBase, $signingKey, true);
		$signedKey = base64_encode($signedKeyRaw);
		
		if($this->withDebugLog) {
			CakeLog::write(LOG_DEBUG, $logPrefix . "signatureBase=$signatureBase");
			CakeLog::write(LOG_DEBUG, $logPrefix . "signingKey=$signingKey");
			CakeLog::write(LOG_DEBUG, $logPrefix . "signedKey=$signedKey");
		}
		
		return $signedKey;
	}
}
