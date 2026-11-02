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
        abort_unless($owner->peran === 'admin' && $owner->aktif,403);
        return $owner;
    }
    private function log(User $actor, User $target, string $action): void
    {
        ActivityLog::create(['nama_pengguna'=>$actor->username,'modul'=>'Users','aksi'=>$action,'keterangan'=>'Manajemen akun Kasir.','properti'=>['user_id'=>$target->id,'username'=>$target->username,'aktif'=>$target->aktif]]);
    }
    public function index()
    {
        return response()->json(User::where('peran','kasir')->orderBy('id')->get(['id','nama','username','peran','aktif']));
    }
    public function store(Request $request)
    {
        $this->fields($request,['nama','username','password','password_confirmation']);
        $data = $request->validate(['nama'=>'required|string|max:50','username'=>'required|string|max:50|unique:users,username','password'=>PasswordPolicy::rules(),'password_confirmation'=>'required|string']);
        return $this->transaction(function () use ($request,$data) {
            $owner = $this->owner($request);
            $user = new User(['nama'=>$data['nama'],'username'=>$data['username'],'password'=>Hash::make($data['password'])]);
            $user->peran = 'kasir'; $user->aktif = true; $user->save();
            $this->log($owner,$user,'create-cashier');
            return response()->json($user->only(['id','nama','username','peran','aktif']),201);
        });
    }
    public function status(Request $request, int $id)
    {
        $this->fields($request,['aktif']);
        $data = $request->validate(['aktif'=>'required|boolean']);
        return $this->transaction(function () use ($request,$id,$data) {
            $owner = $this->owner($request);
            $target = User::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($target->peran === 'kasir',403);
            $target->aktif = (bool)$data['aktif']; $target->save();
            if (!$target->aktif) $target->tokens()->delete();
            $this->log($owner,$target,$target->aktif ? 'activate-cashier' : 'deactivate-cashier');
            return response()->json($target->only(['id','nama','username','peran','aktif']));
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
            abort_unless($target->peran === 'kasir',403);
            if (Hash::check($data['password'],$target->password)) throw ValidationException::withMessages(['password'=>'Password baru harus berbeda.']);
            $target->password = Hash::make($data['password']); $target->save(); $target->tokens()->delete();
            $this->log($owner,$target,'reset-cashier-password');
            return response()->json(['message'=>'Password Kasir diperbarui dan seluruh token Kasir dicabut.']);
        });
    }
}
