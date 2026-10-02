<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Shredding;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;

/**
 * Encrypts the personal attributes of a snapshot with the key of their data
 * subject. A value becomes {"@encrypted": {"k": key id, "c": ciphertext}}
 * (AES-256-GCM over its canonical JSON); null stays null. Hashes are taken
 * over the encrypted snapshot, so shredding the key leaves them valid.
 */
class PersonalData
{
    public const string MARKER = '@encrypted';

    private const string CIPHER = 'aes-256-gcm';

    public function __construct(
        private readonly SubjectKeys $keys,
        private readonly CanonicalSerializer $serializer,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot  canonical snapshot
     * @param  list<string>  $attributes  personal attributes
     * @param  array<string, mixed>  $anonymized  values allowed after the subject was shredded
     * @return array<string, mixed>
     *
     * @throws ShreddedSubjectException when a shredded subject's attribute holds other data
     */
    public function encrypt(array $snapshot, array $attributes, string $subject, array $anonymized = []): array
    {
        $unknown = array_values(array_diff($attributes, array_keys($snapshot)));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Personal attributes ['.implode(', ', $unknown).'] are not part of the snapshot.');
        }

        $values = array_filter(array_intersect_key($snapshot, array_flip($attributes)), fn (mixed $value): bool => $value !== null);

        if ($values === []) {
            return $snapshot;
        }

        if ($this->keys->isShredded($subject)) {
            $remaining = array_keys(array_filter(
                $values,
                fn (mixed $value, string $attribute): bool => ! array_key_exists($attribute, $anonymized) || $this->serializer->normalize($anonymized[$attribute]) !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($remaining !== []) {
                sort($remaining);

                throw ShreddedSubjectException::personalData($subject, $remaining);
            }

            // Only anonymized values: they are no personal data and stay readable.
            return $snapshot;
        }

        $key = $this->keys->keyFor($subject);
        $encrypter = new Encrypter($key->key, self::CIPHER);

        foreach ($values as $attribute => $value) {
            $snapshot[$attribute] = [self::MARKER => ['k' => $key->id, 'c' => $encrypter->encryptString($this->serializer->encode($value))]];
        }

        return $snapshot;
    }

    /**
     * Decrypts every encrypted attribute whose key still exists.
     *
     * @param  array<string, mixed>  $snapshot
     *
     * @throws DecryptException when a value does not decrypt with its key (it was tampered with or the key swapped)
     */
    public function reveal(array $snapshot): RevealedSnapshot
    {
        $shredded = [];

        foreach ($snapshot as $attribute => $value) {
            $encrypted = $this->encrypted($value);

            if ($encrypted === null) {
                continue;
            }

            $key = $this->keys->find($encrypted['k']);

            if ($key === null) {
                $snapshot[$attribute] = null;
                $shredded[] = $attribute;

                continue;
            }

            $plain = (new Encrypter($key, self::CIPHER))->decryptString($encrypted['c']);
            $snapshot[$attribute] = $this->serializer->normalize(json_decode($plain, true, flags: JSON_THROW_ON_ERROR));
        }

        sort($shredded);

        return new RevealedSnapshot($snapshot, $shredded);
    }

    /**
     * @return array{k: string, c: string}|null
     */
    public function encrypted(mixed $value): ?array
    {
        if (! is_array($value) || array_keys($value) !== [self::MARKER] || ! is_array($value[self::MARKER])) {
            return null;
        }

        $inner = $value[self::MARKER];

        return isset($inner['k'], $inner['c']) && is_string($inner['k']) && is_string($inner['c']) && count($inner) === 2
            ? ['k' => $inner['k'], 'c' => $inner['c']]
            : null;
    }
}
