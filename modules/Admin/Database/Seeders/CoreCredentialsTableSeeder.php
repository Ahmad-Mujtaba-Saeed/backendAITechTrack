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
                'key' => 'mail.default',
                'value' => 'smtp',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false,
            ],
            [
                'key' => 'mail.host',
                'value' => '',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false,
            ],
            [
                'key' => 'mail.username',
                'value' => '',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false,
            ],
            [
                'key' => 'mail.password',
                'value' => '',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => true,
            ],
            [
                'key' => 'mail.port',
                'value' => '465',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false,
            ],
            [
                'key' => 'mail.encryption',
                'value' => 'ssl',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false,
            ],
            [
                'key' => 'mail.from.address',
                'value' => '',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false,
            ],
            [
                'key' => 'mail.from.name',
                'value' => 'Admin',
                'type' => 'string',
                'group' => 'mail',
                'is_encrypted' => false,
            ],
        ];

        foreach ($credentials as $credential) {
            DB::table('core_credentials')->updateOrInsert(
                ['key' => $credential['key']],
                [
                    'value' => $credential['value'],
                    'type' => $credential['type'],
                    'group' => $credential['group'],
                    'is_encrypted' => $credential['is_encrypted'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}