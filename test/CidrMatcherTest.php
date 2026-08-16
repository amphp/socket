<?php declare(strict_types=1);

namespace Amp\Socket;

use PHPUnit\Framework\TestCase;

class CidrMatcherTest extends TestCase
{
    public function provideInvalidCidrs(): array
    {
        return [
            'empty prefix' => ['10.0.0.0/'],
            'ipv4 prefix too large' => ['127.0.0.1/33'],
            'ipv6 prefix too large' => ['::1/129'],
            'leading zero prefix' => ['10.0.0.0/08'],
            'negative prefix' => ['10.0.0.0/-1'],
            'non-numeric prefix' => ['10.0.0.0/ 8'],
            'invalid address' => ['not-an-ip/24'],
        ];
    }

    /**
     * @dataProvider provideInvalidCidrs
     */
    public function testInvalidCidrIsRejected(string $cidr)
    {
        $this->expectException(\ValueError::class);

        new CidrMatcher($cidr);
    }

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

    public function testMatches()
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
