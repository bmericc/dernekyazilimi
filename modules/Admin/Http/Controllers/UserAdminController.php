<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

use App\Models\User;
use App\Support\Anonymizer;
use BahriCanli\TcKimlik;

class UserAdminController extends Controller
{
    public function users() {

        $users = User::where("status", 1)->get();

        return view('admin::users', ["users" => $users]);
    }

    public function setManagerRole($user_id) {

        if (! Auth::user()->isOwner()) {
            return Redirect::route('admin.users')->with("danger-status", trans("panel.unauthorized_process"));
        }

        $user = User::where("id", $user_id)->first();
        if($user==null) {
            $this->set_log("other", "Kullanıcı yok");
        }
        else {
            $user->role = 2;
            if($user->save()) {
                $this->set_log("change", $user->name." ".$user->surname. " kullanıcısının rolü yönetici yapıldı");
                return Redirect::route('admin.users')->with("success-status", trans("panel.set_manager_role_success"));
            }

            return Redirect::route('admin.users')->with("danger-status", trans("panel.set_manager_role_failed"));
        }

    }

    public function setOwnerRole($user_id) {

        if (! Auth::user()->isOwner()) {
            return Redirect::route('admin.users')->with("danger-status", trans("panel.unauthorized_process"));
        }

        $user = User::where("id", $user_id)->first();
        if($user==null) {
            $this->set_log("other", "Kullanıcı yok");
        }
        else {
            $user->role = 1;
            if($user->save()) {
                $this->set_log("change", $user->name." ".$user->surname. " kullanıcısının rolü sahip yapıldı");
                return Redirect::route('admin.users')->with("success-status", trans("panel.set_owner_role_success"));
            }

            return Redirect::route('admin.users')->with("danger-status", trans("panel.set_owner_role_failed"));
        }

    }

    public function setUserRole($user_id) {

        if (! Auth::user()->isOwner()) {
            return Redirect::route('admin.users')->with("danger-status", trans("panel.unauthorized_process"));
        }

        $user = User::where("id", $user_id)->first();
        if($user==null) {
            $this->set_log("other", "Kullanıcı yok");
        }
        else {
            $user->role = 3;
            if($user->save()) {
                $this->set_log("change", $user->name." ".$user->surname. " kullanıcısının rolü kullanıcı yapıldı");
                return Redirect::route('admin.users')->with("success-status", trans("panel.set_user_role_success"));
            }

            return Redirect::route('admin.users')->with("danger-status", trans("panel.set_user_role_failed"));
        }

    }

    /**
     * Removes the person's personal data the same way an approved KVKK
     * deletion does (App\Support\Anonymizer): rows stay, nothing identifies
     * them, audit history is scrubbed, modules clean up on ContactAnonymized.
     */
    public function removeUser($user_id, Anonymizer $anonymizer) {

        if (! Auth::user()->isOwner()) {
            return Redirect::route('admin.users')->with("danger-status", trans("panel.unauthorized_process"));
        }

        $user = User::find($user_id);
        if ($user === null || $user->contact === null) {
            return Redirect::route('admin.users')->with("danger-status", trans("panel.remove_user_failed"));
        }
        if ($user->is(Auth::user())) {
            return Redirect::route('admin.users')->with("danger-status", trans("panel.remove_user_self"));
        }

        $anonymizer->anonymize($user->contact, 'yönetici tarafından kullanıcı kaldırma');

        return Redirect::route('admin.users')->with("success-status", trans("panel.remove_user_success"));
    }

    public function tcKimlikDogrula($user_id) {

        $user = User::where("id", $user_id)->first();
        if($user==null) {
            return Redirect::route('admin.users')->with("danger-status", trans("panel.unauthorized_process"));
        }

        $birth_year = "";
        if(!empty($user->birthday)) {
            $birth_year = date("Y", strtotime($user->birthday));
        }

        $request_data = [
            'tcno' => $user->national_id,
            'isim' => $this->tr_ucwords($user->name),
            'soyisim' => $this->tr_ucwords($user->surname),
            'dogumyili' => $birth_year,
        ];

        try {
            $result = TcKimlik::validate($request_data);
        } catch (\Throwable) {
            return Redirect::route('admin.users')->with('danger-status', 'TC Kimlik doğrulama servisine ulaşılamadı.');
        }

        return Redirect::route('admin.users')->with(
            $result ? 'success-status' : 'danger-status',
            $result ? 'TC Kimlik doğrulaması başarılı.' : 'TC Kimlik doğrulaması başarısız.'
        );
    }

}
