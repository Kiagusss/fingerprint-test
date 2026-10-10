<?php

declare(strict_types=1);

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\Encoder as CborEncoder;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Key\Ec2Key;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * A real software authenticator for the automated test suite.
 *
 * It performs genuine WebAuthn ceremonies with an ephemeral EC P-256 key: it
 * builds authenticatorData, clientDataJSON and a valid ES256 signature, and
 * outputs exactly the JSON shape a browser POSTs. The server-side validator
 * (web-auth/webauthn-lib) therefore accepts or rejects it for the real reason —
 * a wrong challenge, wrong origin, wrong RP ID, wrong user handle, or a replayed
 * or tampered signature fails cryptographically. This is not a mock.
 *
 * Credentials are discoverable (resident) with user verification, mirroring what
 * Windows Hello produces in this PoC.
 */
final class SoftwareAuthenticator
{
    /** AAGUID 00000000-... = "none / unknown authenticator model". */
    public const AAGUID_HEX = '00000000000000000000000000000000';

    private \OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private string $x;

    private string $y;

    private int $counter = 0;

    public function __construct(
        private readonly string $rpId,
        private readonly string $origin,
    ) {
        $this->key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]) ?: throw new \RuntimeException('Unable to generate an EC P-256 key.');

        $details = openssl_pkey_get_details($this->key);

        // OpenSSL returns the raw big-endian EC coordinates, but strips a leading
        // zero byte when the most-significant byte happens to be 0x00. For P-256
        // the COSE key requires exactly 32 bytes per coordinate, so pad both.
        // Without this, ~1 in 128 keys would be rejected with
        // "Invalid length for x/y coordinate".
        $this->x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $this->y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $this->credentialId = random_bytes(32);
    }

    public function credentialId(): string
    {
        return $this->credentialId;
    }

    /**
     * Registration (attestation) payload, exactly as the browser would POST it.
     *
     * @return array<string, mixed>
     */
    public function attest(string $challenge): array
    {
        $clientDataJSON = $this->clientData('webauthn.create', $challenge);

        $authData = $this->authDataBytes(0x45, $challenge !== null);

        $attestationObject = $this->cbor([
            'fmt' => 'none',
            'attStmt' => MapObject::create(),
            'authData' => ByteStringObject::create($authData),
        ]);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports' => ['internal', 'hybrid'],
            ],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /**
     * Registration payload built for a deliberately wrong origin.
     *
     * @return array<string, mixed>
     */
    public function attestWithOrigin(string $challenge, string $origin): array
    {
        $clientDataJSON = $this->clientData('webauthn.create', $challenge, $origin);
        $authData = $this->authDataBytes(0x45, true);

        $attestationObject = $this->cbor([
            'fmt' => 'none',
            'attStmt' => MapObject::create(),
            'authData' => ByteStringObject::create($authData),
        ]);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports' => ['internal', 'hybrid'],
            ],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /**
     * Assertion (login / confirmation) payload.
     *
     * @return array<string, mixed>
     */
    public function assert(string $challenge, string $userHandle): array
    {
        return $this->buildAssertion($challenge, $userHandle, breakSignature: false, origin: null);
    }

    /**
     * Assertion with one signature byte flipped — proves the signature is really
     * verified rather than trusted.
     *
     * @return array<string, mixed>
     */
    public function assertWithBrokenSignature(string $challenge, string $userHandle): array
    {
        return $this->buildAssertion($challenge, $userHandle, breakSignature: true, origin: null);
    }

    /**
     * Assertion whose clientDataJSON claims a foreign origin. The origin is part
     * of the signed data, so the server-side origin check must reject it.
     *
     * @return array<string, mixed>
     */
    public function assertWithOrigin(string $challenge, string $userHandle, string $origin): array
    {
        return $this->buildAssertion($challenge, $userHandle, breakSignature: false, origin: $origin);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAssertion(string $challenge, string $userHandle, bool $breakSignature, ?string $origin): array
    {
        $this->counter++;

        $clientDataJSON = $this->clientData('webauthn.get', $challenge, $origin);

        // UP (0x01) + UV (0x04); the AT bit is not set on assertions.
        $authData = $this->authDataBytes(0x05, false);

        $signature = $this->sign($authData.hash('sha256', $clientDataJSON, true));

        if ($breakSignature) {
            $signature[10] = chr(ord($signature[10]) ^ 0xFF);
        }

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJSON),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($userHandle),
            ],
            'authenticatorAttachment' => 'platform',
        ];
    }

    // ------------------------------------------------------------------ internals

    private function clientData(string $type, string $challenge, ?string $origin = null): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => $origin ?? $this->origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * authenticatorData = rpIdHash(32) || flags(1) || signCount(4, big endian)
     *                     [ || attestedCredentialData when AT is set ]
     */
    private function authDataBytes(int $flags, bool $includeAttested): string
    {
        $bytes = hash('sha256', $this->rpId, true)
            .chr($flags)
            .pack('N', $this->counter);

        if ($includeAttested) {
            $bytes .= hex2bin(self::AAGUID_HEX)
                .pack('n', strlen($this->credentialId))
                .$this->credentialId
                .$this->cosePublicKey();
        }

        return $bytes;
    }

    /**
     * COSE_Key (EC2 / ES256) for the P-256 public key, as raw CBOR bytes.
     *
     * Labels: 1 = kty, 3 = alg, -1 = crv, -2 = x, -3 = y. Real authenticators
     * (Windows Hello included) ship "alg" inside the credential public key, and
     * the library's algorithm check expects it.
     */
    private function cosePublicKey(): string
    {
        $map = MapObject::create();
        $map->add(UnsignedIntegerObject::create(Ec2Key::TYPE), UnsignedIntegerObject::create(Ec2Key::TYPE_EC2));
        $map->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(ES256::ID));
        $map->add(NegativeIntegerObject::create(Ec2Key::DATA_CURVE), UnsignedIntegerObject::create(Ec2Key::CURVE_P256));
        $map->add(NegativeIntegerObject::create(Ec2Key::DATA_X), ByteStringObject::create($this->x));
        $map->add(NegativeIntegerObject::create(Ec2Key::DATA_Y), ByteStringObject::create($this->y));

        return $this->cbor($map);
    }

    /** ES256 signature: OpenSSL DER converted to the raw 64-byte (r||s) form. */
    private function sign(string $data): string
    {
        $der = '';
        openssl_sign($data, $der, $this->key, OPENSSL_ALGO_SHA256);

        $offset = 2;
        $offset += (ord($der[1]) & 0x80) ? 2 : 0;

        if ($der[$offset] !== "\x02") {
            throw new \RuntimeException('Malformed DER signature (r).');
        }

        $rLen = ord($der[++$offset]);
        $r = substr($der, ++$offset, $rLen);
        $offset += $rLen;

        if ($der[$offset] !== "\x02") {
            throw new \RuntimeException('Malformed DER signature (s).');
        }

        $sLen = ord($der[++$offset]);
        $s = substr($der, ++$offset, $sLen);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            .str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private function cbor(mixed $data): string
    {
        return (new CborEncoder)->encode($data);
    }
}
