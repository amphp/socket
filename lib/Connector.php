<?php

namespace Nbsock;

use Amp\Reactor;
use Amp\Future;
use Amp\Failure;
use Amp\Dns\Client;
use Amp\Dns\Resolver;
use Amp\Dns\AddressModes;

class Connector {
    const OP_BIND_IP_ADDRESS = 'bind_to';
    const OP_MS_CONNECT_TIMEOUT = 'connect_timeout';
    const OP_DISABLE_SNI_HACK = 'disable_sni_hack';

    private $reactor;
    private $dnsResolver;
    private $options = [
        self::OP_BIND_IP_ADDRESS => '',
        self::OP_MS_CONNECT_TIMEOUT => 30000,
        self::OP_DISABLE_SNI_HACK => false
    ];

    public function __construct(Reactor $reactor = null, Resolver $dnsResolver = null) {
        $this->reactor = $reactor ?: \Amp\getReactor();
        $this->dnsResolver = $dnsResolver ?: new Resolver(new Client($reactor));
    }

    /**
     * Make a socket connection to the specified URI
     *
     * If a scheme is not specified in the $uri parameter, TCP is assumed. Allowed schemes include:
     * [tcp, udp, unix, udg].
     *
     * @param string $uri
     * @param array $options
     * @return \Amp\Promise
     */
    public function connect($uri, array $options = []) {
        if (stripos($uri, 'unix://') === 0 || stripos($uri, 'udg://') === 0) {
            list($scheme, $path) = explode('://', $uri, 2);
            return $this->doUnixConnect($scheme, $path, $options);
        } else {
            return $this->doInetConnect($uri, $options);
        }
    }

    private function doUnixConnect($scheme, $path, $options) {
        $struct = new ConnectorStruct;
        $struct->scheme = $scheme;
        $struct->uri = "{$scheme}:///" . ltrim($path, '/');
        $struct->options = $options ? array_merge($this->options, $options) : $this->options;
        $struct->future = new Future;
        $this->doConnect($struct);

        return $struct->future->promise();
    }

    private function doInetConnect($uri, $options) {
        // TCP/UDP host names are always case-insensitive
        if (!$uriParts = @parse_url(strtolower($uri))) {
            return new Failure(new \DomainException(
                sprintf('Invalid URI: %s', $uri)
            ));
        }

        extract($uriParts);

        $scheme = empty($scheme) ? 'tcp' : $scheme;
        if (!($scheme === 'tcp' || $scheme === 'udp')) {
            return new Failure(new \DomainException(
                sprintf('Invalid URI scheme (%s); tcp, udp, unix or udg scheme expected', $scheme)
            ));
        }
        if (empty($host) || empty($port)) {
            return new Failure(new \DomainException(
                'Invalid URI (%s); host and port components required'
            ));
        }

        $struct = new ConnectorStruct;
        $struct->scheme = $scheme;
        $struct->host = $host;
        $struct->port = $port;
        $struct->uri = "{$scheme}://{$host}:{$port}";
        $struct->options = $options ? array_merge($this->options, $options) : $this->options;
        $struct->future = new Future;

        if (!$inAddr = @inet_pton($host)) {
            $this->dnsResolver->resolve($host)->when(function($error, $result) use ($struct) {
                if ($error) {
                    return $struct->future->fail($error);
                }
                list($addr, $type) = $result;
                $struct->resolvedAddress = ($type === AddressModes::INET6_ADDR)
                    ? "[{$addr}]:{$struct->port}"
                    : "{$addr}:{$struct->port}";
                $this->doConnect($struct);
            });
        } else {
            $isIpv6 = isset($inAddr[15]);
            $struct->resolvedAddress = $isIpv6 ? "[{$host}]:{$port}" : "{$host}:{$port}";
            $this->doConnect($struct);
        }

        return $struct->future->promise();
    }

    private function doConnect(ConnectorStruct $struct) {
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;
        $timeout = 42; // <--- timeout not applicable for async connections
        $disableSniHack = $struct->options[self::OP_DISABLE_SNI_HACK];
        $scheme = $struct->scheme;
        $isUnixSock = ($scheme === 'unix' || $scheme === 'udg');

        if (PHP_VERSION_ID < 50600 && empty($disableSniHack) && $scheme === 'tcp') {
            // Prior to PHP 5.6 the SNI_server_name only registers if assigned to the stream
            // context at the time the socket is first connected (NOT with stream_socket_enable_crypto()).
            // So we always add the necessary ctx option here along with our own custom SNI_nb_hack
            // key to communicate our intent to the CryptoBroker if it's subsequently used
            $contextOptions = ['ssl' => [
                'SNI_server_name' => $struct->host,
                'SNI_nb_hack' => true // PHP TLS hates you before 5.6
            ]];
        } else {
            $contextOptions = [];
        }

        if (!$isUnixSock && ($bindToIp = $struct->options[self::OP_BIND_IP_ADDRESS])) {
            $contextOptions['socket']['bindto'] = $bindToIp;
        }
        $ctx = stream_context_create($contextOptions);
        $addr = $isUnixSock ? $struct->uri : $struct->resolvedAddress;

        if ($socket = @stream_socket_client($addr, $errno, $errstr, $timeout, $flags, $ctx)) {
            $struct->socket = $socket;
            $this->initializePendingSocket($struct);
        } else {
            $struct->future->fail(new SocketException(
                sprintf(
                    'Connection to %s failed: [Error #%d] %s',
                    $struct->uri,
                    $errno,
                    $errstr
                )
            ));
        }
    }

    private function initializePendingSocket(ConnectorStruct $struct) {
        $socket = $struct->socket;
        $socketId = (int) $socket;
        stream_set_blocking($socket, false);

        $timeout = $struct->options[self::OP_MS_CONNECT_TIMEOUT];
        if ($timeout > 0) {
            $struct->timeoutWatcher = $this->reactor->once(function() use ($struct) {
                $this->timeoutSocket($struct);
            }, $timeout);
        }

        $struct->connectWatcher = $this->reactor->onWritable($socket, function() use ($struct) {
            $this->fulfillSocket($struct);
        });
    }

    private function timeoutSocket(ConnectorStruct $struct) {
        $this->reactor->cancel($struct->connectWatcher);
        $this->reactor->cancel($struct->timeoutWatcher);
        $timeout = $struct->options[self::OP_MS_CONNECT_TIMEOUT];
        $struct->future->fail(new SocketException(
            sprintf('Connect timeout exceeded (%d ms): %s', $timeout, $struct->uri)
        ));
    }

    private function fulfillSocket(ConnectorStruct $struct) {
        $this->reactor->cancel($struct->connectWatcher);
        if ($struct->timeoutWatcher !== null) {
            $this->reactor->cancel($struct->timeoutWatcher);
        }

        $struct->future->succeed($struct->socket);
    }

    /**
     * Set socket connector options
     *
     * @param mixed $option
     * @param mixed $value
     * @throws \DomainException on unknown option key
     * @return self
     */
    public function setOption($option, $value) {
        switch ($option) {
            case self::OP_MS_CONNECT_TIMEOUT:
                $this->options[self::OP_MS_CONNECT_TIMEOUT] = (int) $value;
                break;
            case self::OP_BIND_IP_ADDRESS:
                $this->options[self::OP_BIND_IP_ADDRESS] = (string) $value;
                break;
            case self::OP_DISABLE_SNI_HACK:
                $this->options[self::OP_DISABLE_SNI_HACK] = (bool) $value;
                break;
            default:
                throw new \DomainException(
                    sprintf('Unknown option: %s', $option)
                );
        }

        return $this;
    }
}
