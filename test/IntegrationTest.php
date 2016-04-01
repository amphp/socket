<?php

namespace Amp\Socket\Test;

use Amp as amp;
use Amp\Socket as socket;

class IntegrationTest extends \PHPUnit_Framework_TestCase {

    protected function setUp() {
        if (amp\info()["state"]) {
            amp\stop();
        }
    }

    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnect($uri, $options) {
        $promise = socket\connect($uri, $options);
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
        $promise = socket\cryptoConnect($uri, $options);
        $sock = \Amp\wait($promise);
        $this->assertTrue(is_resource($sock));
    }

    public function provideCryptoConnectArgs() {
        return [
            ['stackoverflow:443', []],
            ['github.com:443', []],
            ['raw.githubusercontent.com:443', []]
        ];
    }

    public function testRenegotiation() {
        $this->markTestSkipped("Expected failure: proper renegotiation does not work yet");

        $promise = \Amp\socket\cryptoConnect('www.google.com:443', []);
        $sock = \Amp\wait($promise);
        $promise = \Amp\socket\cryptoEnable($sock, ["verify_peer" => false]); // force renegotiation by different option...
        $sock = \Amp\wait($promise);
        $this->assertTrue(is_resource($sock));
    }
}
