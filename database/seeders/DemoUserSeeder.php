<?php

namespace Database\Seeders;

use App\Demo\DemoData;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * One user per demo persona in database/demo/personas.json, all with the
 * password "password". Roles that require two-factor authentication get a
 * fixed demo secret so the sign-in page can show the current code.
 *
 * Never runs in production.
 */
class DemoUserSeeder extends Seeder
{
    /** Base32 demo secrets, public by design. Demo environments only. */
    private const TWO_FACTOR_SECRETS = [
        'sam@lumenco.test' => 'HIVEDEMOSAMOKAFR',
        'priya@lumenco.test' => 'HIVEDEMOPRIYARAM',
        'grace@lumenco.test' => 'HIVEDEMOGRACEADE',
        'leah@lumenco.test' => 'HIVEDEMOLEAHFITZ',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo users are for local, staging and test environments only.');
        }

        foreach (app(DemoData::class)->raw('personas') as $persona) {
            $user = User::query()->updateOrCreate(['email' => $persona['email']], [
                'name' => $persona['name'],
                'password' => config('hive.demo.password'),
                'person_id' => $persona['person_id'],
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()]);

            if ($secret = self::TWO_FACTOR_SECRETS[$persona['email']] ?? null) {
                $user->forceFill([
                    'two_factor_secret' => encrypt($secret),
                    'two_factor_recovery_codes' => encrypt(json_encode(['demo-recovery-1', 'demo-recovery-2', 'demo-recovery-3'])),
                    'two_factor_confirmed_at' => now(),
                ]);
            }

            $user->save();
            $user->syncRoles($persona['roles']);
        }
    }
}
