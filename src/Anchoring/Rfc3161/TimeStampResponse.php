<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161;

use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/**
 * A TimeStampResp (RFC 3161, section 2.4.2): the status and, if granted, the
 * time-stamp token, a CMS SignedData over a TSTInfo.
 *
 * info() reads the TSTInfo without checking the signature; verification
 * uses the content the signature check returns instead.
 */
final readonly class TimeStampResponse
{
    public const int GRANTED = 0;

    public const int GRANTED_WITH_MODS = 1;

    public const string SIGNED_DATA = '1.2.840.113549.1.7.2';

    public const string TST_INFO = '1.2.840.113549.1.9.16.1.4';

    public function __construct(
        public int $status,
        public ?string $statusText,
        /** the DER-encoded ContentInfo of the token */
        public ?string $token,
        /** the complete DER-encoded response */
        public string $encoded,
    ) {}

    public static function decode(string $der): self
    {
        $children = Der::decode($der)->expect(Der::SEQUENCE)->children();

        if ($children === [] || count($children) > 2) {
            throw new InvalidTimestampException('Not a time-stamp response.');
        }

        $statusInfo = $children[0]->expect(Der::SEQUENCE)->children();
        $text = null;

        foreach (array_slice($statusInfo, 1) as $element) {
            if ($element->tag === Der::SEQUENCE) {
                $text = implode('; ', array_map(fn (DerNode $line): string => $line->content, $element->children()));
            }
        }

        return new self($statusInfo[0]->integer(), $text, isset($children[1]) ? $children[1]->expect(Der::SEQUENCE)->encoded : null, $der);
    }

    public function granted(): bool
    {
        return $this->token !== null && in_array($this->status, [self::GRANTED, self::GRANTED_WITH_MODS], true);
    }

    /**
     * The TSTInfo of the token, read without checking the signature.
     */
    public function info(): TstInfo
    {
        if ($this->token === null) {
            throw new InvalidTimestampException('The response has no time-stamp token.');
        }

        $contentInfo = Der::decode($this->token)->children();

        if (count($contentInfo) !== 2 || $contentInfo[0]->oid() !== self::SIGNED_DATA) {
            throw new InvalidTimestampException('The token is no CMS SignedData.');
        }

        $signedData = $contentInfo[1]->expect(0xA0)->children()[0]->expect(Der::SEQUENCE)->children();
        $encapsulated = ($signedData[2] ?? throw new InvalidTimestampException('Malformed SignedData.'))->expect(Der::SEQUENCE)->children();

        if (count($encapsulated) !== 2 || $encapsulated[0]->oid() !== self::TST_INFO) {
            throw new InvalidTimestampException('The token does not contain a TSTInfo.');
        }

        return TstInfo::decode($encapsulated[1]->expect(0xA0)->children()[0]->expect(Der::OCTET_STRING)->content);
    }
}
