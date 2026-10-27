<?php

namespace App\Models;

use App\Models\Concerns\TouchesWorkspace;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int|null $head_person_id
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, TouchesWorkspace;

    protected $fillable = ['name', 'head_person_id'];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'head_person_id');
    }

    /**
     * @return HasMany<Person, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }
}
