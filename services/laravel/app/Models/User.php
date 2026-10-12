<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

// 對應業務的 users 表（docs/data-model.md〈users〉）：密碼欄位是 password_hash，
// 沒有 remember_token 與 email_verified_at，也沒有 updated_at
#[Fillable(['email', 'password_hash', 'role', 'team_id'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }
}
