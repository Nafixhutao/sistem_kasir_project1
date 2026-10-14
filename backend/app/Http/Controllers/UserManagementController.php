<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Models\ActivityLog;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
class UserManagementController extends Controller
{
    private function transaction(callable $operation)
    {
        try { return DB::transaction($operation); }
        catch (ValidationException | \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface | \Illuminate\Database\Eloquent\ModelNotFoundException $e) { throw $e; }
        catch (\Throwable $e) { return response()->json(['message'=>'Operasi akun gagal. Tidak ada perubahan parsial disimpan.'],503); }
    }
    private function fields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()),$allowed)) throw ValidationException::withMessages(['request'=>'Field tambahan tidak diperbolehkan.']);
    }
    private function owner(Request $request): User
    {
        $owner = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($owner->role === 'admin' && $owner->is_active,403);
        return $owner;
    }
    private function log(User $actor, User $target, string $action): void
    {
        ActivityLog::create(['user_name'=>$actor->username,'module'=>'Users','action'=>$action,'description'=>'Manajemen akun Kasir.','properties'=>['user_id'=>$target->id,'username'=>$target->username,'is_active'=>$target->is_active]]);
    }
    public function index()
    {
        return response()->json(User::where('role','kasir')->orderBy('id')->get(['id','name','username','role','is_active']));
    }
    public function store(Request $request)
    {
        $this->fields($request,['name','username','password','password_confirmation']);
        $data = $request->validate(['name'=>'required|string|max:50','username'=>'required|string|max:50|unique:users,username','password'=>PasswordPolicy::rules(),'password_confirmation'=>'required|string']);
        return $this->transaction(function () use ($request,$data) {
            $owner = $this->owner($request);
            $user = new User(['name'=>$data['name'],'username'=>$data['username'],'password'=>Hash::make($data['password'])]);
            $user->role = 'kasir'; $user->is_active = true; $user->save();
            $this->log($owner,$user,'create-cashier');
            return response()->json($user->only(['id','name','username','role','is_active']),201);
        });
    }
    public function status(Request $request, int $id)
    {
        $this->fields($request,['is_active']);
        $data = $request->validate(['is_active'=>'required|boolean']);
        return $this->transaction(function () use ($request,$id,$data) {
            $owner = $this->owner($request);
            $target = User::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($target->role === 'kasir',403);
            $target->is_active = (bool)$data['is_active']; $target->save();
            if (!$target->is_active) $target->tokens()->delete();
            $this->log($owner,$target,$target->is_active ? 'activate-cashier' : 'deactivate-cashier');
            return response()->json($target->only(['id','name','username','role','is_active']));
        });
    }
    public function resetPassword(Request $request, int $id)
    {
        $this->fields($request,['current_password','password','password_confirmation']);
        $data = $request->validate(['current_password'=>'required|string','password'=>PasswordPolicy::rules(),'password_confirmation'=>'required|string']);
        return $this->transaction(function () use ($request,$id,$data) {
            $owner = $this->owner($request);
            if (!Hash::check($data['current_password'],$owner->password)) throw ValidationException::withMessages(['current_password'=>'Password Admin tidak cocok.']);
            $target = User::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($target->role === 'kasir',403);
            if (Hash::check($data['password'],$target->password)) throw ValidationException::withMessages(['password'=>'Password baru harus berbeda.']);
            $target->password = Hash::make($data['password']); $target->save(); $target->tokens()->delete();
            $this->log($owner,$target,'reset-cashier-password');
            return response()->json(['message'=>'Password Kasir diperbarui dan seluruh token Kasir dicabut.']);
        });
    }
}
