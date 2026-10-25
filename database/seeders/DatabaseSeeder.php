<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $customer = Customer::create([
            'name' => 'Test Customer',
            'surname' => 'Test Surname',
            'email' => 'test@example.com',
            'phone' => '+90 555 555 55 55',
            'password' => bcrypt('password')
        ]);

        $customer->addresses()->create([
            'address' => 'Test Address',
            'city' => 'Istanbul',
            'country' => 'Türkiye',
            'zip_code' => '12345',
        ]);

        $this->command->info('Customer created with email: ' . $customer->email);
    }
}
