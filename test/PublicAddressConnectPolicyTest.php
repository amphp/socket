<?php declare(strict_types=1);

namespace Amp\Socket;

use PHPUnit\Framework\TestCase;

class PublicAddressConnectPolicyTest extends TestCase
{
    public function provideNonPublicAddresses(): array
    {
        return \array_map(static fn (string $address) => [$address], [
            'this network' => '0.0.0.1',
            'unspecified' => '0.0.0.0',
            'private 10/8' => '10.1.2.3',
            'shared address space' => '100.64.0.1',
            'shared address space, end' => '100.127.255.255',
            'loopback' => '127.0.0.1',
            'loopback, end' => '127.255.255.255',
            'link-local' => '169.254.169.254',
            'private 172.16/12' => '172.16.0.1',
            'private 172.16/12, end' => '172.31.255.255',
            'protocol assignments' => '192.0.0.192',
            'documentation 192.0.2/24' => '192.0.2.1',
            '6to4 relay' => '192.88.99.1',
            'private 192.168/16' => '192.168.1.1',
            'benchmarking' => '198.18.0.1',
            'documentation 198.51.100/24' => '198.51.100.1',
            'documentation 203.0.113/24' => '203.0.113.1',
            'multicast' => '224.0.0.1',
            'reserved' => '240.0.0.1',
            'broadcast' => '255.255.255.255',

            'mapped loopback' => '::ffff:127.0.0.1',
            'mapped loopback in hex' => '::ffff:7f00:1',
            'mapped private' => '::ffff:10.0.0.1',
            'mapped link-local' => '::ffff:169.254.169.254',
            'mapped shared address space' => '::ffff:100.64.0.1',

            'ipv6 unspecified' => '::',
            'ipv6 loopback' => '::1',
            'compatible' => '::127.0.0.1',
            'compatible in hex' => '::7f00:1',
            'translated' => '::ffff:0:7f00:1',
            'dummy prefix' => '100:0:0:1::1',
            'nat64' => '64:ff9b::a9fe:a9fe',
            'nat64 local-use' => '64:ff9b:1::1',
            'discard-only' => '100::1',
            'teredo' => '2001:0:a00:1::1',
            'benchmarking ipv6' => '2001:2::1',
            'documentation ipv6' => '2001:db8::1',
            '6to4' => '2002:a00:1::1',
            'documentation ipv6, 3fff' => '3fff::1',
            'segment routing' => '5f00::1',
            'unique local fc' => 'fc00::1',
            'unique local fd' => 'fd00:ec2::254',
            'link-local ipv6' => 'fe80::1',
            'site-local' => 'fec0::1',
            'multicast ipv6' => 'ff02::1',
        ]);
    }

    /**
     * @dataProvider provideNonPublicAddresses
     */
    public function testNonPublicAddressesAreDenied(string $address): void
    {
        self::assertFalse((new PublicAddressConnectPolicy)->isAllowed(new InternetAddress($address, 80)));
    }

    public function providePublicAddresses(): array
    {
        return \array_map(static fn (string $address) => [$address], [
            'public' => '8.8.8.8',
            'before 10/8' => '9.255.255.255',
            'after 10/8' => '11.0.0.0',
            'before shared address space' => '100.63.255.255',
            'after shared address space' => '100.128.0.0',
            'after 172.16/12' => '172.32.0.0',
            'mapped public' => '::ffff:8.8.8.8',
            'mapped public in hex' => '::ffff:808:808',
            'public ipv6' => '2001:4860:4860::8888',
        ]);
    }

    /**
     * @dataProvider providePublicAddresses
     */
    public function testPublicAddressesAreAllowed(string $address): void
    {
        self::assertTrue((new PublicAddressConnectPolicy)->isAllowed(new InternetAddress($address, 80)));
    }

    public function testUnixSocketsAreDenied(): void
    {
        self::assertFalse((new PublicAddressConnectPolicy)->isAllowed(new UnixAddress('/run/amphp.sock')));
    }

    public function provideAdditionalRanges(): array
    {
        return [
            'ipv4 range' => ['168.63.129.0/24', '168.63.129.16'],
            'single ipv4 address' => ['168.63.129.16', '168.63.129.16'],
            'ipv4 range, mapped address' => ['168.63.129.0/24', '::ffff:168.63.129.16'],
            'ipv6 range' => ['2001:4860::/32', '2001:4860:4860::8888'],
        ];
    }

    /**
     * @dataProvider provideAdditionalRanges
     */
    public function testAdditionalRangesAreDenied(string $range, string $address): void
    {
        $address = new InternetAddress($address, 80);

        self::assertTrue((new PublicAddressConnectPolicy)->isAllowed($address));
        self::assertFalse((new PublicAddressConnectPolicy([$range]))->isAllowed($address));
    }

    public function testInvalidAdditionalRangeIsRejected(): void
    {
        $this->expectException(\ValueError::class);

        new PublicAddressConnectPolicy(['not-an-ip/24']);
    }

    public function testConnectorDeniesNonPublicAddresses(): void
    {
        $connector = new DnsSocketConnector(connectPolicy: new PublicAddressConnectPolicy);

        foreach (['tcp://127.0.0.1:80', 'tcp://[::ffff:127.0.0.1]:80', 'tcp://[::1]:80', 'unix:///run/amphp.sock'] as $uri) {
            try {
                $connector->connect($uri);
                self::fail('The connection should have been denied: ' . $uri);
            } catch (ConnectException $e) {
                self::assertStringEndsWith('failed: denied by the connect policy', $e->getMessage());
            }
        }
    }
}
