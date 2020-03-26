<?php

namespace Amp\Socket\Socks;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Socket\ConnectContext;
use Amp\Socket\Connector;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Internal\UpgradedSocket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use function Amp\call;
use function Amp\Socket\connector;

final class Socks5Connector implements Connector
{
    /**
     * @see https://tools.ietf.org/html/rfc1928#section-6
     */
    private const ERRORS = [
        1 => 'General SOCKS server failure',
        2 => 'Connection not allowed by ruleset',
        3 => 'Network unreachable',
        4 => 'Host unreachable',
        5 => 'Connection refused',
        6 => 'TTL expired',
        7 => 'Command not supported',
        8 => 'Address type not supported',
    ];

    private const ADDR_TYPE_IPV4 = "\x01";
    private const ADDR_TYPE_IPV6 = "\x04";
    private const ADDR_TYPE_DOMAIN_NAME = "\x03";

    /** @var string */
    private $target;

    /** @var Connector */
    private $connector;

    /** @var Socks5Authenticator[] */
    private $authenticators;

    public function __construct(string $target)
    {
        $this->target = $target;
        $this->connector = connector();
        $this->authenticators = [
            new class implements Socks5Authenticator {
                public function getIdentifier(): int
                {
                    return 0;
                }
            },
        ];
    }

    public function connect(string $uri, ?ConnectContext $context = null, ?CancellationToken $token = null): Promise
    {
        return call(function () use ($uri, $context, $token) {
            /** @var EncryptableSocket $socket */
            $socket = yield $this->connector->connect($this->target, $context, $token);

            $authMethods = \implode('', \array_map(static function ($authMethod) {
                return \chr($authMethod);
            }, \array_keys($this->authenticators)));

            yield $socket->write("\x05" . \chr(\count($this->authenticators)) . $authMethods);

            $buffer = '';

            while (\strlen($buffer) < 2) {
                $chunk = yield $socket->read();
                if ($chunk === null) {
                    throw new SocketException('Failed to connect to SOCKS5 proxy: ' . $this->target);
                }

                $buffer .= $chunk;
            }

            if ($buffer[0] !== "\x05") {
                throw new SocketException('The SOCKS5 proxy response is invalid, unexpected version 0x' . \bin2hex($buffer[0]));
            }

            $serverAuthMethod = \ord($buffer[1]);
            if ($serverAuthMethod === 255) {
                throw new SocketException('The SOCKS5 proxy rejected all available authentication methods: ' . $this->target);
            }

            if (!isset($this->authenticators[$serverAuthMethod])) {
                throw new SocketException('The SOCKS5 proxy selected an authentication method not configured in this connector: 0x' . \bin2hex($buffer[1]));
            }

            $socketAddress = SocketAddress::fromSocketName(\str_replace(['tcp://', 'udp://'], '', $uri));
            if ($socketAddress === null) {
                throw new SocketException('Connection via SOCKS5 is not possible with invalid address: ' . $uri);
            }

            if ($socketAddress->getPort() === null) {
                throw new SocketException('Connection via SOCKS5 is not possible without port: ' . $uri);
            }

            $addr = @\inet_pton($socketAddress->getHost());
            $port = \pack('n', $socketAddress->getPort());

            if ($addr === false) {
                $addrType = self::ADDR_TYPE_DOMAIN_NAME;
                $addr = \chr(\strlen($socketAddress->getHost())) . $socketAddress->getHost();
            } elseif (isset($addr[4])) {
                $addrType = self::ADDR_TYPE_IPV6;
            } else {
                $addrType = self::ADDR_TYPE_IPV4;
            }

            yield $socket->write("\x05\x01\x00{$addrType}{$addr}{$port}");

            $buffer = \substr($buffer, 2);

            while (\strlen($buffer) < 5) {
                $chunk = yield $socket->read();
                if ($chunk === null) {
                    throw new SocketException('Failed to connect to SOCKS5 proxy: ' . $this->target);
                }

                $buffer .= $chunk;
            }

            if ($buffer[0] !== "\x05") {
                throw new SocketException('Failed to connect to SOCKS5 proxy, unexpected response 0x' . \bin2hex($buffer[0]));
            }

            if ($buffer[1] !== "\x00") {
                $error = self::ERRORS[\ord($buffer[1])] ?? ('unknown error (0x' . \bin2hex($buffer[1]) . ')');
                throw new SocketException('Failed to connect to SOCKS5 proxy, ' . $error);
            }

            if ($buffer[3] === self::ADDR_TYPE_IPV4) {
                $length = 10;
            } elseif ($buffer[3] === self::ADDR_TYPE_IPV6) {
                $length = 22;
            } elseif ($buffer[3] === self::ADDR_TYPE_DOMAIN_NAME) {
                $length = 7 + \ord($buffer[4]);
            } else {
                throw new SocketException('Failed to connect to SOCKS5 proxy, unexpected address type 0x' . \bin2hex($buffer[3]));
            }

            while (\strlen($buffer) < $length) {
                $chunk = yield $socket->read();
                if ($chunk === null) {
                    throw new SocketException('Failed to connect to SOCKS5 proxy: ' . $this->target);
                }

                $buffer .= $chunk;
            }

            return new UpgradedSocket($socket, \substr($buffer, $length));
        });
    }
}
