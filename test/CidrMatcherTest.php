<?php declare(strict_types=1);

namespace Amp\Socket;

use PHPUnit\Framework\TestCase;

class CidrMatcherTest extends TestCase
{
    private array $tests = [
        [
            "cidr" => "192.30.252.0/22",
            "tests" => [
                "192.30.252.0" => true,
                "192.30.255.255" => true,
                "192.30.251.255" => false,
                "192.31.0.0" => false,
            ],
        ],
        [
            "cidr" => "::ffff:1.2.3.4/128",
            "tests" => [
                "1.2.3.4" => true,
                "1.2.3.5" => false,
                "4.3.2.1" => false,
                "::ffff:1.2.3.4" => true,
                "::1" => false,
            ],
        ],
    ];

    /**
     * @test
     */
    public function match()
    {
        foreach ($this->tests as $test) {
            $tests = $test["tests"];

            $matcher = new CidrMatcher($test['cidr']);

            foreach ($tests as $ip => $expectedResult) {
                $this->assertSame(
                    $expectedResult,
                    $matcher->match($ip),
                    "$ip against " . $test['cidr']
                );
            }
        }
    }
}
