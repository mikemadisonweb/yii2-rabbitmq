<?php

namespace mikemadisonweb\rabbitmq\components;

class AbstractConnectionFactory
{
    /** @var \ReflectionClass */
    private $_class;

    /** @var array */
    private $_parameters = [
        'url'                => '',
        'host'               => 'localhost',
        'port'               => 5672,
        'user'               => 'guest',
        'password'           => 'guest',
        'vhost'              => '/',
        'connection_timeout' => 3,
        'read_write_timeout' => 3,
        'ssl_context'        => null,
        'keepalive'          => false,
        'heartbeat'          => 0,
    ];

    /**
     * Constructor
     *
     * @param string $class      FQCN of AMQPConnection class to instantiate.
     * @param array  $parameters Map containing parameters resolved by Extension.
     */
    public function __construct($class, array $parameters)
    {
        $this->_class = $class;
        $this->_parameters = array_merge($this->_parameters, $parameters);
        $this->_parameters = $this->parseUrl($this->_parameters);
    }

    /**
     * @return mixed
     */
    public function createConnection()
    {
        return new $this->_class(
            $this->_parameters['host'],
            $this->_parameters['port'],
            $this->_parameters['user'],
            $this->_parameters['password'],
            $this->_parameters['vhost'],
            false,      // insist
            'AMQPLAIN', // login_method
            null,       // login_response
            'ru_RU',    // locale
            $this->_parameters['connection_timeout'],
            $this->_parameters['read_write_timeout'],
            $this->_parameters['ssl_context'],
            $this->_parameters['keepalive'],
            $this->_parameters['heartbeat']
        );
    }

    /**
     * Parse connection defined by url, e.g. 'amqp://guest:password@localhost:5672/vhost?lazy=1&connection_timeout=6'
     * @param $parameters
     * @return array
     */
    private function parseUrl($parameters)
    {
        if (!$parameters['url']) {
            return $parameters;
        }
        $url = parse_url($parameters['url']);
        if ($url === false || !isset($url['scheme']) || $url['scheme'] !== 'amqp') {
            throw new \InvalidArgumentException('Malformed parameter "url".');
        }
        // See https://www.rabbitmq.com/uri-spec.html
        if (isset($url['host'])) {
            $parameters['host'] = urldecode($url['host']);
        }
        if (isset($url['port'])) {
            $parameters['port'] = (int)$url['port'];
        }
        if (isset($url['user'])) {
            $parameters['user'] = urldecode($url['user']);
        }
        if (isset($url['pass'])) {
            $parameters['password'] = urldecode($url['pass']);
        }
        if (isset($url['path'])) {
            $parameters['vhost'] = urldecode(ltrim($url['path'], '/'));
        }
        if (isset($url['query'])) {
            $query = [];
            parse_str($url['query'], $query);
            $parameters = array_merge($parameters, $query);
        }
        unset($parameters['url']);

        return $parameters;
    }
}
