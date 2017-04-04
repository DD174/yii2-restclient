<?php

/*
 * Tools to use API as ActiveRecord for Yii2
 *
 * @link      https://github.com/apexwire/yii2-restclient
 * @package   yii2-restclient
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2016, ApexWire
 */

namespace apexwire\restclient;

use Closure;
use GuzzleHttp\Client as Handler;
use GuzzleHttp\Psr7\Response;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\Json;
use Yii;
use yii\helpers\ArrayHelper;

/**
 * Class Connection
 * @package apexwire\restclient
 *
 * Example configuration:
 * ```php
 * 'components' => [
 *     'restclient' => [
 *         'class' => 'apexwire\restclient\Connection',
 *         'config' => [
 *             'base_uri' => 'https://api.site.com/',
 *         ],
 *     ],
 * ],
 * ```
 */
class Connection extends Component
{
    /**  */
    const EVENT_AFTER_OPEN = 'afterOpen';

    /**
     * @var array Config
     */
    public $config = [];

    /**
     * @var Handler
     */
    protected static $_handler = null;

    /**
     * @var array authorization config
     */
    protected $_auth = [];

    /**
     * @var Closure Callback to test if API response has error
     * The function signature: `function ($response)`
     * Must return `null`, if the response does not contain an error.
     */
    protected $_errorChecker;

    /** @type Response */
    protected $_response;


    public function setAuth($auth)
    {
        $this->_auth = $auth;
    }

    public function getAuth()
    {
        if ($this->_auth instanceof Closure) {
            $this->_auth = call_user_func($this->_auth, $this);
        }

        return $this->_auth;
    }

    /**
     * {@inheritdoc}
     * @throws InvalidConfigException
     */
    public function init()
    {
        if (!$this->config['base_uri']) {
            throw new InvalidConfigException('The `base_uri` config option must be set');
        }
    }

    /**
     * Closes the connection when this component is being serialized.
     * @return array
     */
    public function __sleep()
    {
        return array_keys(get_object_vars($this));
    }

    /**
     * Returns the name of the DB driver for the current [[dsn]].
     *
     * @return string name of the DB driver
     */
    public static function getDriverName()
    {
        return 'restclient';
    }

    /**
     * Creates a command for execution.
     *
     * @param array $config the configuration for the Command class
     *
     * @return Command the DB command
     */
    public function createCommand($config = [])
    {
        $config['db'] = $this;
        $command = new Command($config);

        return $command;
    }

    /**
     * Creates new query builder instance.
     *
     * @return QueryBuilder
     */
    public function getQueryBuilder()
    {
        return new QueryBuilder($this);
    }

    /**
     * Performs GET HTTP request.
     * @param string $url URL
     * @param array $query query options
     * @param string $body request body
     * @param bool $raw if response body contains JSON and should be decoded
     * @throws \yii\base\InvalidConfigException
     * @return mixed response
     */
    public function get($url, $query = [], $body = null, $raw = false)
    {
        return $this->makeRequest('GET', $url, $query, $body, $raw);
    }

    /**
     * Performs HEAD HTTP request.
     * @param string $url URL
     * @param array $query query options
     * @param string $body request body
     * @throws \yii\base\InvalidConfigException
     * @return mixed response
     */
    public function head($url, $query = [], $body = null)
    {
        $this->makeRequest('HEAD', $url, $query, $body);

        return $this->_response->getHeaders();
    }

    /**
     * Performs POST HTTP request.
     * @param string $url URL
     * @param array $query query options
     * @param string $body request body
     * @param bool $raw if response body contains JSON and should be decoded
     * @throws \yii\base\InvalidConfigException
     * @return mixed response
     */
    public function post($url, $query = [], $body = null, $raw = false)
    {
        return $this->makeRequest('POST', $url, $query, $body, $raw);
    }

    /**
     * Performs PUT HTTP request.
     * @param string $url URL
     * @param array $query query options
     * @param string $body request body
     * @param bool $raw if response body contains JSON and should be decoded
     * @throws \yii\base\InvalidConfigException
     * @return mixed response
     */
    public function put($url, $query = [], $body = null, $raw = false)
    {
        return $this->makeRequest('PUT', $url, $query, $body, $raw);
    }

    /**
     * Performs DELETE HTTP request.
     * @param string $url URL
     * @param array $query query options
     * @param string $body request body
     * @param bool $raw if response body contains JSON and should be decoded
     * @throws \yii\base\InvalidConfigException
     * @return mixed response
     */
    public function delete($url, $query = [], $body = null, $raw = false)
    {
        return $this->makeRequest('DELETE', $url, $query, $body, $raw);
    }

    /**
     * Make request and check for error.
     * @param string $method
     * @param string $url URL
     * @param array $query query options, (GET parameters)
     * @param string $body request body, (POST parameters)
     * @param bool $raw if response body contains JSON and should be decoded
     * @throws \yii\base\InvalidConfigException
     * @return mixed response
     */
    public function makeRequest($method, $url, $query = [], $body = null, $raw = false)
    {
        return $this->handleRequest($method, $this->prepareUrl($url, $query, $body), $body, $raw);
    }

    /**
     * Creates URL.
     * @param mixed $path path
     * @param array $query query options
     * @param null|array $body
     * @return string
     */
    private function prepareUrl($path, array $query = [], $body = null)
    {
        //        $url = $path;
        $url = $this->replaceAttributeInUrl($path, $query, $body);
        $query = array_merge($this->getAuth(), $query);
        if (!empty($query)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        return $url;
    }

    /**
     * Заменяем имя трибута в URL на его значение. В приоритете значения из $query
     * `users/{user_id}/phones` -> `users/123/phones`
     * @param string $url
     * @param array $query
     * @param null|array $body
     * @return string
     * @throws \Exception
     */
    private function replaceAttributeInUrl($url, array $query = [], $body = null)
    {
        $queryNew = [];
        if (!empty($query)) {
            array_walk($query, function($value, $key) use (&$queryNew) {
                if (is_array($value)) {
                    $queryNew = array_merge($queryNew, $value);
                } else {
                    $queryNew[preg_replace('#^.*\[|\].*$#', '', $key)] = $value;
                }
            });
        }
        if (!is_array($body)) {
            $body = [];
        }
        $isError = false;
        if (preg_match_all('/{(\w+)}/', $url, $matches) && isset($matches[1])) {
            foreach ($matches[1] as $attribute) {
                $value1 = ArrayHelper::getValue($queryNew, $attribute);
                $value2 = ArrayHelper::getValue($body, $attribute);
                if ($value1 !== null) {
                    $url = str_replace('{' . $attribute . '}', $value1, $url);
                } elseif ($value2 !== null) {
                    $url = str_replace('{' . $attribute . '}', $value2, $url);
                } else {
                    $isError = true;
                }
            }
            if ($isError) {
                throw new \Exception('Not found attribute for url: ' . $url);
            }
        }

        return $url;
    }

    /**
     * Handles the request with handler.
     * Returns array or raw response content, if $raw is true.
     *
     * @param string $method POST, GET, etc
     * @param string $url the URL for request, not including proto and site
     * @param array|string $body the request body. When array - will be sent as POST params, otherwise - as RAW body.
     * @param bool $raw Whether to decode data, when response is decodeable (JSON).
     * @return array|string
     */
    protected function handleRequest($method, $url, $body = null, $raw = false)
    {
        $method = strtoupper($method);
        $profile = $method . ' ' . $url . '#' . (is_array($body) ? http_build_query($body) : $body);
        $options = [(is_array($body) ? 'form_params' : 'body') => $body];
        Yii::beginProfile($profile, __METHOD__);
        $this->_response = $this->getHandler()->request($method, $url, $options);
        Yii::endProfile($profile, __METHOD__);

        $res = $this->_response->getBody()->getContents();
        if (!$raw && preg_grep('|application/json|i', $this->_response->getHeader('Content-Type'))) {
            $res = Json::decode($res);
        }

        return $res;
    }

    /**
     * Returns the request handler (Guzzle client for the moment).
     * Creates and setups handler if not set.
     * @return Handler
     */
    public function getHandler()
    {
        if (static::$_handler === null) {
            static::$_handler = new Handler($this->config);
        }

        return static::$_handler;
    }
}
