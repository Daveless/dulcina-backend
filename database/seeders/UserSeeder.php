<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin',
                'email' => 'admin@arreglos.com',
                'password' => Hash::make('password123'),
            ],
            [
                'name' => 'María González',
                'email' => 'maria@arreglos.com',
                'password' => Hash::make('password123'),
            ],
            [
                'name' => 'Carlos Ramírez',
                'email' => 'carlos@arreglos.com',
                'password' => Hash::make('password123'),
            ],
        ];

        foreach ($users as $user) {
            User::create($user);
        }
    }
}
