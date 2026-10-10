<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;

/**
 * Regression guard for the software authenticator's EC coordinate handling.
 *
 * OpenSSL strips a leading 0x00 from an EC coordinate. Left unpadded, a COSE key
 * built from such a key is rejected by the library ("Invalid length for x/y
 * coordinate"), which surfaced as a ~1/44 flaky failure in the ceremony tests.
 */
class SoftwareAuthenticatorTest extends TestCase
{
    public function test_coordinates_are_always_padded_to_the_p256_length(): void
    {
        // 300 fresh keys: h ~ 1/128 chance per key of hitting a short coordinate,
        // so this reliably exercises the padding path.
        for ($i = 0; $i < 300; $i++) {
            $auth = new SoftwareAuthenticator($this->rpId(), $this->origin());

            $reflection = new \ReflectionObject($auth);

            $x = $reflection->getProperty('x')->getValue($auth);
            $y = $reflection->getProperty('y')->getValue($auth);

            $this->assertSame(32, strlen($x), "x coordinate was not 32 bytes on iteration {$i}");
            $this->assertSame(32, strlen($y), "y coordinate was not 32 bytes on iteration {$i}");
        }
    }

    public function test_attestation_payload_has_the_shape_the_server_expects(): void
    {
        $auth = new SoftwareAuthenticator($this->rpId(), $this->origin());

        $credential = $auth->attest(random_bytes(32));

        $this->assertSame('public-key', $credential['type']);
        $this->assertArrayHasKey('rawId', $credential);
        $this->assertArrayHasKey('clientDataJSON', $credential['response']);
        $this->assertArrayHasKey('attestationObject', $credential['response']);
        $this->assertSame('platform', $credential['authenticatorAttachment']);
    }

    public function test_a_broken_signature_differs_from_a_valid_one(): void
    {
        $auth = new SoftwareAuthenticator($this->rpId(), $this->origin());

        $challenge = random_bytes(32);
        $handle = random_bytes(32);

        $valid = $auth->assert($challenge, $handle);
        $broken = $auth->assertWithBrokenSignature($challenge, $handle);

        $this->assertNotSame($valid['response']['signature'], $broken['response']['signature']);
    }
}
