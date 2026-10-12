<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 測試用的使用者；預設是沒有團隊的 engineer，行銷角色要用 marketing() 指定團隊
 * （users_team_check 擋下沒有團隊的行銷帳號）。
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $passwordHash;

    public function definition(): array
    {
        return [
            'email' => strtolower(fake()->unique()->safeEmail()),
            // bcrypt cost 12，與 docs/auth-and-roles.md〈密碼〉相同
            'password_hash' => static::$passwordHash ??= password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]),
            'role' => 'engineer',
            'team_id' => null,
        ];
    }

    public function marketing(int $teamId, string $role = 'marketing'): static
    {
        return $this->state(fn () => ['role' => $role, 'team_id' => $teamId]);
    }
}
