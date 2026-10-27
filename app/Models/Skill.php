<?php

namespace App\Models;

use App\Models\Concerns\TouchesWorkspace;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $category
 */
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory, TouchesWorkspace;

    protected $fillable = ['name', 'category'];
}
