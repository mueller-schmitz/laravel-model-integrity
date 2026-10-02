<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Shredding;

use Illuminate\Database\Eloquent\Model;

/**
 * Crypto-shredding of data subjects (GDPR art. 17): dropping a subject's key
 * makes its personal data in every recorded version unreadable, while
 * hashes, chains and anchors stay valid. The application anonymizes the
 * current rows itself; verification reports personal data left in them.
 */
class Subjects
{
    public function __construct(
        private readonly SubjectKeys $keys,
    ) {}

    /**
     * @param  Model|string  $subject  the subject model, or its `<morph class>:<key>`
     * @return string|null the id of the dropped key, null if there was none or it was shredded before
     */
    public function shred(Model|string $subject, ?string $reason = null): ?string
    {
        return $this->keys->shred($this->name($subject), $reason);
    }

    /**
     * Whether the subject ever had a key (or a tombstone).
     */
    public function exists(Model|string $subject): bool
    {
        return $this->keys->exists($this->name($subject));
    }

    public function isShredded(Model|string $subject): bool
    {
        return $this->keys->isShredded($this->name($subject));
    }

    private function name(Model|string $subject): string
    {
        return $subject instanceof Model ? SubjectName::of($subject) : $subject;
    }
}
