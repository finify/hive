<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $name
 * @property string|null $role
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 */
class Contact extends Model
{
    protected $fillable = ['contactable_type', 'contactable_id', 'name', 'role', 'email', 'phone', 'is_primary'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return ['name' => $this->name, 'role' => $this->role, 'email' => $this->email, 'phone' => $this->phone, 'is_primary' => $this->is_primary];
    }
}
