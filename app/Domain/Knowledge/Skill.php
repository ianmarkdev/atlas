<?php

namespace App\Domain\Knowledge;

use App\Domain\Tenancy\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A skill in the candidate's library (a `tags` row of kind `skill`). Resumes choose and group them.
 *
 * @property int $id
 * @property string $ulid
 * @property int $workspace_id
 * @property string $kind
 * @property string $name
 * @property string $normalized_name
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('tags')]
#[Fillable(['name', 'created_by'])]
#[UseFactory(SkillFactory::class)]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['kind' => 'skill'];

    protected static function booted(): void
    {
        static::addGlobalScope('skills', fn (Builder $query) => $query->where('kind', 'skill'));

        static::saving(function (Skill $skill): void {
            $skill->normalized_name = self::normalizeName($skill->name);
        });
    }

    /**
     * "Next.js", "next.js" and "Next JS" count as the same skill.
     */
    public static function normalizeName(string $name): string
    {
        return Str::of($name)->lower()->replaceMatches('/[\s.\-_]+/u', '')->toString();
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function alphabetical(Builder $query): void
    {
        $query->orderByRaw('lower(name)');
    }
}
