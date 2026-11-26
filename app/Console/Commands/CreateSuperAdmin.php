<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\ActivityLogger;
use App\Services\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create-super {--name=} {--email=} {--password=}';

    protected $description = 'Create the first Super Admin account';

    public function handle(PasswordPolicy $policy, ActivityLogger $activity): int
    {
        $role = Role::query()->where('slug', 'super-admin')->first();
        if ($role === null) {
            $this->error('Roles are missing. Run "php artisan db:seed" first.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?: $this->ask('Name'),
            'email' => $this->option('email') ?: $this->ask('Email'),
            'password' => $this->option('password') ?: $this->secret('Password ('.$policy->describe().')'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:admin_users,email'],
            'password' => ['required', $policy->rule()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new AdminUser(['name' => $data['name'], 'email' => $data['email'], 'role_id' => $role->id]);
        $user->password = $data['password'];
        $user->status = AdminUser::STATUS_ACTIVE;
        $user->save();

        $activity->log('create', 'staff', $user, $user->name, ['role' => $role->name, 'via' => 'command line'], $user);
        $this->info("Super Admin {$user->email} created. Sign in at /admin/login.");

        return self::SUCCESS;
    }
}
