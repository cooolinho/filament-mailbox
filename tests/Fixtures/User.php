<?php

namespace Cooolinho\FilamentMailbox\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public static function make(string $name = 'Test User'): self
    {
        return self::create([
            'name' => $name,
            'email' => str($name)->slug().'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }
}
