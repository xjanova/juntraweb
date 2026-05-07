<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@xn--82c4af5bzdj.online'],
            [
                'name' => 'แม่หมอจันทรา',
                'password' => Hash::make('chantra-admin-' . substr(md5(config('app.key') ?: 'default'), 0, 8)),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );
    }
}
