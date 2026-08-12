<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoreCredentialsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $credentials = [
            [
                'key'=> 'mail.default',
                'value' => 'smtp',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false
            ],
            [
                'key' => 'mail.host',
                'value' => 'smtp.hostinger.com',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false
            ],
            [
                'key' => 'mail.username',
                'value' => 'jobtap@techtrack.online',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false
            ],
            [
                'key' => 'mail.password',
                'value' => encrypt('Jobtap2025!@'), // Encrypt the password here
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => true
            ],
            [
                'key' => 'mail.port',
                'value' => '465',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false
            ],
            [
                'key' => 'mail.encryption',
                'value' => 'ssl',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false
            ],
            [
                'key' => 'mail.from.address',
                'value' => 'jobtap@techtrack.online',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false
            ],
            [
                'key' => 'mail.from.name',
                'value' => 'AiBackendTechTrack',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false
            ]
        ];

        foreach ($credentials as $credential) {
            DB::table('core_credentials')->updateOrInsert(
                ['key' => $credential['key']], // match by key
                array_merge($credential, [
                    'updated_at' => now(),
                    'created_at' => now()
                ])
            );
        }
    }
}
