<?php

namespace Amp\Socket\Test;

use Amp as amp;
use Amp\Socket as socket;

class ClientTest extends \PHPUnit_Framework_TestCase {

    protected function setUp() {
        if (\stripos(PHP_OS, "win") === 0) {
            $this->markTestSkipped("cannot run in windows");
        } else {
            if (amp\info()["state"]) {
                amp\stop();
            }
        }
    }

    /**
     * @dataProvider provideInvalidLengthParameters
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid size; integer > 0 or null required
     */
    public function testReadFailsOnInvalidLengthParameter($badLen) {
        amp\run(function () use ($badLen) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            list($serverSock, $clientSock) = $sockets;
            $client = new socket\Client($clientSock);
            yield $client->read($badLen);
        });
    }

    public function provideInvalidLengthParameters() {
        return [
            [-1],
            [0],
            [true],
            [new \StdClass],
        ];
    }

    public function testReadLine() {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        list($serverSock, $clientSock) = $sockets;

        $expected = "woot!";
        $actual = null;

        // server
        $serverCoroutine = function () use ($serverSock, &$actual) {
            $client = new socket\Client($serverSock);
            while ($client->alive()) {
                $actual .= rtrim(yield $client->readLine());
            }
        };
        amp\resolve($serverCoroutine());

        // client
        $clientCoroutine = function () use ($clientSock, $expected) {
            $client = new socket\Client($clientSock);
            (yield $client->write($expected . "\n"));
            $client->close();
        };
        amp\resolve($clientCoroutine());

        amp\run();

        $this->assertSame($expected, $actual);
    }

    public function testUnbufferedRead() {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        list($serverSock, $clientSock) = $sockets;

        $expected = "foo";
        $actual = null;

        $serverCoroutine = function () use ($serverSock, &$actual) {
            $client = new socket\Client($serverSock);
            $actual = rtrim(yield $client->read());
        };
        amp\resolve($serverCoroutine());

        $clientCoroutine = function () use ($clientSock, $expected) {
            $client = new socket\Client($clientSock);
            (yield $client->write("foo"));
            $client->close();
        };
        amp\resolve($clientCoroutine());

        amp\run();

        $this->assertSame($expected, $actual);
    }

    public function testBufferedRead() {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        list($serverSock, $clientSock) = $sockets;

        $expected = "12345";
        $actual = null;

        $serverCoroutine = function () use ($serverSock, &$actual) {
            $client = new socket\Client($serverSock);
            $actual = rtrim(yield $client->read(5));
        };
        amp\resolve($serverCoroutine());

        $clientCoroutine = function () use ($clientSock, $expected) {
            $client = new socket\Client($clientSock);
            (yield $client->write("1"));
            (yield $client->write("2"));
            (yield $client->write("3"));
            (yield $client->write("4"));
            (yield $client->write("5"));
            $client->close();
        };
        amp\resolve($clientCoroutine());

        amp\run();

        $this->assertSame($expected, $actual);
    }

    public function testId() {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        list($serverSock, $clientSock) = $sockets;
        $client = new socket\Client($clientSock);
        $this->assertSame((int)$clientSock, $client->id());
    }

    public function testInfo() {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        list($serverSock, $clientSock) = $sockets;

        $expected = "12345";
        $actual = null;

        $serverCoroutine = function () use ($serverSock, &$actual) {
            $client = new socket\Client($serverSock);
            $this->assertSame(0, $client->info()["bytes_read"]);
            $actual = rtrim(yield $client->read(5));
            $this->assertSame(5, $client->info()["bytes_read"]);
        };
        amp\resolve($serverCoroutine());

        $clientCoroutine = function () use ($clientSock, $expected) {
            $client = new socket\Client($clientSock);
            (yield $client->write("1"));
            $this->assertSame(1, $client->info()["bytes_sent"]);
            (yield $client->write("2"));
            $this->assertSame(2, $client->info()["bytes_sent"]);
            (yield $client->write("3"));
            $this->assertSame(3, $client->info()["bytes_sent"]);
            (yield $client->write("4"));
            $this->assertSame(4, $client->info()["bytes_sent"]);
            (yield $client->write("5"));
            $this->assertSame(5, $client->info()["bytes_sent"]);
            $client->close();
            $this->assertSame(false, $client->info()["alive"]);
        };
        amp\resolve($clientCoroutine());

        amp\run();

        $this->assertSame($expected, $actual);
    }

    public function testEmptyWrite() {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        list($serverSock, $clientSock) = $sockets;
        fclose($serverSock);

        amp\run(function () use ($clientSock) {
            $client = new socket\Client($clientSock);
            $this->assertNull(yield $client->write("1"));
        });
    }
}
