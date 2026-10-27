<?php

namespace App\Models;

use App\Models\Concerns\TouchesWorkspace;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $name
 * @property string $address
 * @property string $city
 * @property string $country
 * @property int|null $capacity
 * @property string|null $access_notes
 * @property string|null $power_notes
 */
class Venue extends Model
{
    /** @use HasFactory<VenueFactory> */
    use HasFactory, LogsActivity, TouchesWorkspace;

    protected $fillable = ['name', 'address', 'city', 'country', 'capacity', 'access_notes', 'power_notes'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('directory')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'address' => $this->address, 'city' => $this->city, 'country' => $this->country,
            'capacity' => $this->capacity, 'access_notes' => $this->access_notes, 'power_notes' => $this->power_notes,
        ];
    }
}
