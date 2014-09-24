<?php

namespace Nbsock\Test;

class IntegrationTest extends \PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnect($uri, $options) {
        $sock = \Nbsock\connect($uri, $options)->wait();
        $this->assertTrue(is_resource($sock));
    }

    public function provideConnectArgs() {
        return [
            ['www.google.com:80', []],
            ['www.yahoo.com:80', []]
        ];
    }

    /**
     * @dataProvider provideCryptoConnectArgs
     */
    public function testCryptoConnect($uri, $options) {
        $sock = \Nbsock\cryptoConnect($uri, $options)->wait();
        $this->assertTrue(is_resource($sock));
    }

    public function provideCryptoConnectArgs() {
        return [
            ['www.google.com:443', []],
            ['github.com:443', []],
            ['raw.githubusercontent.com:443', []]
        ];
    }
}
