<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $email
 * @property Role $role
 * @property string $token
 * @property int $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property-read User $inviter
 */
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['email', 'role', 'token', 'invited_by', 'expires_at', 'accepted_at'];

    protected $hidden = ['token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('identity')
            ->logOnly(['email', 'role', 'expires_at', 'accepted_at'])
            ->logOnlyDirty();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  Builder<Invitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
