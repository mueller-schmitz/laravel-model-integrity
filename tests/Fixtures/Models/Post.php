<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;

class Post extends Model
{
    use HasIntegrity;

    /** @var list<string> */
    protected array $integrityRelations = ['tags'];

    protected $guarded = [];

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
