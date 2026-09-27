<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * 建立內部人員帳號（正式環境用；seeder 的測試帳號密碼公開，不可在正式環境執行）。
 * 密碼以互動方式輸入，不留在 shell 歷史紀錄。
 */
class CreateStaffUser extends Command
{
    protected $signature = 'staff:create {email} {name}';

    protected $description = '建立內部人員（老闆、兒子、工讀生）的後台帳號';

    private const MIN_PASSWORD_LENGTH = 10;

    public function handle(): int
    {
        $data = ['email' => $this->argument('email'), 'name' => $this->argument('name')];
        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'unique:users,email'],
            'name' => ['required', 'string', 'max:50'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $password = (string) $this->secret('密碼（至少 '.self::MIN_PASSWORD_LENGTH.' 碼，輸入時不會顯示）');
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('密碼至少需要 '.self::MIN_PASSWORD_LENGTH.' 碼');

            return self::FAILURE;
        }
        if ($this->secret('再輸入一次密碼') !== $password) {
            $this->error('兩次輸入的密碼不同');

            return self::FAILURE;
        }

        User::create($data + ['password' => $password]);
        $this->info("已建立帳號 {$data['email']}（{$data['name']}）");

        return self::SUCCESS;
    }
}
