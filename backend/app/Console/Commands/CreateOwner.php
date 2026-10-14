<?php
namespace App\Console\Commands;
use App\Models\User;
use App\Models\ActivityLog;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
class CreateOwner extends Command
{
    protected $signature = 'user:create-owner';
    protected $description = 'Membuat akun Admin lokal dengan password tersembunyi.';
    public function handle(): int
    {
        try {
            if (!$this->input->isInteractive()) { $this->error('Input interaktif diperlukan.'); return self::FAILURE; }
            $name = $this->ask('Name'); $username = $this->ask('Username');
            $password = $this->secret('New password',false); $confirmation = $this->secret('Confirm password',false);
            $data = ['name'=>$name,'username'=>$username,'password'=>$password,'password_confirmation'=>$confirmation];
            if (Validator::make($data,['name'=>'required|string|max:50','username'=>'required|string|max:50|unique:users,username','password'=>PasswordPolicy::rules()])->fails()) {
                $this->error('Input tidak valid atau username sudah digunakan.'); return self::FAILURE;
            }
            DB::transaction(function () use ($data) {
                $user = new User(['name'=>$data['name'],'username'=>$data['username'],'password'=>Hash::make($data['password'])]);
                $user->role = 'admin'; $user->is_active = true; $user->save();
                ActivityLog::create(['user_name'=>'local-operator','module'=>'Users','action'=>'create-owner','description'=>'Pembuatan akun Admin lokal.','properties'=>['user_id'=>$user->id,'username'=>$user->username]]);
            });
            unset($data,$password,$confirmation);
            $this->info('Akun Admin berhasil dibuat.'); return self::SUCCESS;
        } catch (\Throwable $e) { $this->error('Pembuatan akun dibatalkan atau gagal.'); return self::FAILURE; }
    }
}
