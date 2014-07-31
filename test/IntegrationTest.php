<?php

use Alert\NativeReactor;

class IntegrationTest extends PHPUnit_Framework_TestCase {

    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnectSync($uri, $options) {
        $sock = Acesync\connectSync($uri, $options);
        $this->assertTrue(is_resource($sock));
    }

    /**
     * @dataProvider provideConnectArgs
     */
    public function testConnect($uri, $options) {
        (new NativeReactor)->run(function($reactor) use ($uri, $options) {
            $promise = Acesync\connect($reactor, $uri, $options);
            $promise->onResolve(function($error, $sock) use ($reactor) {
                $reactor->stop();
                $this->assertNull($error);
                $this->assertTrue(is_resource($sock));
            });
        });
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
        (new NativeReactor)->run(function($reactor) use ($uri, $options) {
            $promise = Acesync\cryptoConnect($reactor, $uri, $options);
            $promise->onResolve(function($error, $sock) use ($reactor) {
                $reactor->stop();
                $this->assertNull($error);
                $this->assertTrue(is_resource($sock));
            });
        });
    }

    /**
     * @dataProvider provideCryptoConnectArgs
     */
    public function testCryptoConnectSync($uri, $options) {
        $sock = Acesync\cryptoConnectSync($uri, $options);
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
