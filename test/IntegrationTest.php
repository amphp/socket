<?php

namespace Amp\Socket\Test;

class IntegrationTest extends \PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnect($uri, $options) {
        $promise = \Amp\Socket\connect($uri, $options);
        $sock = \Amp\wait($promise);
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
        $promise = \Amp\Socket\cryptoConnect($uri, $options);
        $sock = \Amp\wait($promise);
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
