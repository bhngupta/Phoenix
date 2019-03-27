<?php
namespace App\Models;

use App\Models\Group;
use App\Models\Common;
use Illuminate\Support\Facades\Hash;

final class User extends Common
{
    const CREATED_AT = 'added_on';
    const UPDATED_AT = 'updated_on';
    protected $table = 'User';
    public $timestamps = true;
    protected $hidden = ['pivot'];
    protected $fillable = ['email','mad_email','phone','name','sex','password','password_hash','address','bio','source','birthday','city_id','credit','applied_role','status','user_type', 'joined_on', 'added_on', 'left_on'];

    public function groups()
    {
        $groups = $this->belongsToMany('App\Models\Group', 'UserGroup', 'user_id', 'group_id')->wherePivot('year',$this->year)->select('Group.id','Group.vertical_id', 'Group.name', 'Group.type');
        $groups->orderByRaw("FIELD(Group.type, 'executive', 'national', 'strat', 'fellow', 'volunteer')");
        return $groups->get();
    }

    public function city()
    {
         $city = $this->belongsTo('App\Models\City', 'city_id');
         return $city->first();
    }

    public function search($data)
    {
        $q = app('db')->table($this->table);

        $q->select("User.id","User.name","User.email","User.phone","User.mad_email","User.credit","User.joined_on","User.left_on",
                    "User.user_type","User.address","User.sex", "User.status", "User.city_id", app('db')->raw("City.name AS city_name"));
        $q->join("City", "City.id", '=', 'User.city_id');

        if(!isset($data['status'])) $data['status'] = 1;
        if($data['status'] !== false) $q->where('User.status', $data['status']); // Setting status as '0' gets you even the deleted users
        
        if(isset($data['city_id']) and $data['city_id'] != 0) $q->where('User.city_id', $data['city_id']);
        
        if(empty($data['user_type'])) $data['user_type'] = 'volunteer';
        if(!empty($data['not_user_type'])) {
            $q->whereNotIn('User.user_type', $data['not_user_type']);
        } else if($data['user_type'] !== false) {
            $q->where('User.user_type', $data['user_type']);
        }

        if(!empty($data['id'])) $q->where('User.id', $data['id']);
        if(!empty($data['user_id'])) $q->where('User.id', $data['user_id']);
        if(!empty($data['name'])) $q->where('User.name', 'like', '%' . $data['name'] . '%');
        if(!empty($data['phone'])) $q->where('User.phone', $data['phone']);
        
        if(!empty($data['email'])) $q->where('User.email', $data['email']);
        if(!empty($data['mad_email'])) $q->where('User.mad_email', $data['mad_email']);
        if(!empty($data['any_email'])) $q->where('User.email', $data['any_email'])->orWhere("User.mad_email", $data['any_email']);

        if(!empty($data['identifier'])) {
            $q->where(function($query) use ($data) {
                $query->where('User.email', $data['identifier'])
                    ->orWhere("User.mad_email", $data['identifier'])
                    ->orWhere("User.phone", $data['identifier'])
                    ->orWhere("User.id", $data['identifier']);
            });
        }

        if(!empty($data['left_on'])) $q->where('DATE_FORMAT(User.left_on, "%Y-%m")', date('Y-m', strtotime($data['left_on'])));
        
        if(!empty($data['user_group'])) {
            if(!is_array($data['user_group'])) $data['user_group'] = array($data['user_group']);
            $q->join('UserGroup', 'User.id', '=', 'UserGroup.user_id');
            $q->whereIn('UserGroup.group_id', $data['user_group']);
            $q->where('UserGroup.year', $this->year);
        }
        if(!empty($data['user_group_type'])) {
            $q->join('UserGroup', 'User.id', '=', 'UserGroup.user_id');
            $q->join('Group', 'Group.id', '=', 'UserGroup.group_id');
            $q->where('Group.type', $data['user_group_type']);
            $q->where('UserGroup.year', $this->year);
        }
        if(!empty($data['vertical_id'])) {
            $q->join('UserGroup', 'User.id', '=', 'UserGroup.user_id');
            $q->join('Group', 'Group.id', '=', 'UserGroup.group_id');
            $q->where('Group.vertical_id', $data['vertical_id']);
            $q->where('UserGroup.year', $this->year);
        }
        if(!empty($data['center_id'])) {
            $mentor_group_id = 8; // :HARDCODE:

            if(isset($data['user_group']) and in_array($mentor_group_id, $data['user_group'])) { // Find the mentors
                $q->join("Batch", 'User.id', '=', 'Batch.batch_head_id');
                $q->where('Batch.center_id', $data['center_id']);
                $q->where('Batch.year', $this->year);
                if(isset($data['project_id'])) $q->where('Batch.project_id', $data['project_id']);

            } else { // Find the teachers
                $q->join('UserClass', 'User.id', '=', 'UserClass.user_id');
                $q->join('Class', 'Class.id', '=', 'UserClass.class_id');
                $q->join('Level', 'Class.level_id', '=', 'Level.id');
                $q->where('Level.center_id', $data['center_id']);
                if(isset($data['project_id'])) $q->where('Level.project_id', $data['project_id']);
            }
            $q->distinct();
        }

        if(!empty($data['batch_id'])) {
            $q->join('UserBatch', 'User.id', '=', 'UserBatch.user_id');
            $q->addSelect("UserBatch.level_id");
            // $q->join('Batch', 'UserBatch.batch_id', '=', 'Batch.id');
            $q->where('UserBatch.batch_id', $data['batch_id']);
        }

        // Sorting
        if(!empty($data['user_type'])) {
            if($data['user_type'] == 'applicant') {
                $q->orderBy('User.joined_on','desc');
            } elseif($data['user_type'] == 'let_go') {
                $q->orderBy('User.left_on','desc');
            }
        }
        $q->orderby('User.name');

        // :TODO: Pagination

        // dd($q->toSql(), $q->getBindings(), $data);

        $results = $q->get();

        // Add groups to each volunter that was returned.
        for($i=0; $i<count($results); $i++) {
            $results[$i]->groups = User::fetch($results[$i]->id)->groups;
        }

        // dd($results);
        
        return $results;
    }

    public function inCity($city_id) {
        return $this->search(['city_id' => $city_id]);
    }

    public function fetch($user_id, $only_volunteers = true) {
        if(!$user_id) return false;

        $user = User::select('id', 'name', 'email', 'mad_email','phone', 'sex', 'photo', 'joined_on', 'address', 'birthday', 'left_on', 
                                'reason_for_leaving', 'user_type', 'status', 'credit', 'city_id')->where('status','1');
        if($only_volunteers) $user = $user->where('user_type', 'volunteer');

        $data = $user->find($user_id);
        if(!$data) return false;

        $data->groups = $data->groups();
        $data->city = $data->city()->name;

        return $data;
    }

    public function add($data)
    {
        $user = User::create([
            'email'     => $data['email'],
            'mad_email' => isset($data['mad_email']) ? $data['mad_email'] : '',
            'phone'     => User::correctPhoneNumber($data['phone']),
            'name'      => $data['name'],
            'sex'       => isset($data['sex']) ? $data['sex'] : 'f',
            'password'  => Hash::make($data['password']),
            'address'   => isset($data['address']) ? $data['address'] : '',
            'bio'       => isset($data['bio']) ? $data['bio'] : '',
            'source'    => isset($data['source']) ? $data['source'] : 'other',
            'birthday'  => isset($data['birthday']) ? $data['birthday'] : '',
            'city_id'   => $data['city_id'],
            'applied_role'=>isset($data['profile']) ? $data['profile'] : '',
            'credit'    => isset($data['credit']) ? $data['credit'] : '3',
            'status'    => isset($data['status']) ? $data['status'] : '1',
            'user_type' => isset($data['user_type']) ? $data['user_type'] : 'applicant',
            'joined_on' => isset($data['joined_on']) ? $data['joined_on'] : date('Y-m-d H:i:s')
        ]);

        // if($user) {
        //     // Send Data to Zoho
        //     $all_sexes = [
        //         'm'     => 'Male',
        //         'f'     => 'Female',
        //         'u'     => 'Other'
        //     ];
        //     $response = load('https://creator.zoho.com/api/jithincn1/json/recruitment-management/form/Registration/record/add', [
        //         'method'    => 'post',
        //         'post_data' => [
        //             'authtoken'         => '205aee93fdc5f6d2d61b5833625f86ce',
        //             'scope'             => 'creatorapi',
        //             'campaign_id'       => isset($data['campaign']) ? $data['campaign'] : '',
        //             'Applicant_Name'    => $data['name'],
        //             'Gender'            => $all_sexes[$data['sex']],
        //             'City'              => $data['cities'][$data['city_id']],
        //             'Date_of_Birth'     => date('d-M-Y', strtotime($data['birthday'])),
        //             'Email'             => $data['email'],
        //             'Address_for_correspondence'    => $data['address'],
        //             'Mobile_Number'     => $data['phone'],
        //             'Occupation'        => $data['job_status'],
        //             'Reason_for_choosing_to_volunteer_at_MAD'   => $data['why_mad'],
        //             'Latitude'          => "0002",  // :HARDCODE: - Researved for future update.
        //             'Longitude'         => "00002",
        //             'Role_Type'         => "Teaching",
        //             'First_Priority'    => "Fundraising Volunteer",
        //             'Second_Priority'   => "Aftercare ASV",
        //             'Third_Priority'    => "Aftercare Wingmen",
        //             'MAD_Applicant_Id'  => $status['id'],   // 'Unique_Applicant_ID'    => $status['id'],
        //         ]
        //     ]);
        //     $zoho_response = json_decode($response);
        //     $zoho_user_id = @$zoho_response->formname[1]->operation[1]->values->ID;

        //     if($zoho_user_id and $user->id) {
        //         $user->zoho_user_id = $zoho_user_id;
        //         $user->save();
        //     }
        // } 

        return $user;
    }

    public function edit($data, $user_id = false)
    {
        $this->chain($user_id);

        foreach ($this->fillable as $key) {
            if(!isset($data[$key])) continue;

            if($key == 'phone') $data[$key] = $this->correctPhoneNumber($data[$key]);
            if($key == 'password') $data[$key] = Hash::make($data[$key]);

            $this->item->$key = $data[$key];
        }
        $this->item->save();

        return $this->item;
    }

    public function addGroup($group_id, $user_id = false)
    {
        $this->chain($user_id);
        
        // Check if the user has the group already.
        $existing_groups = $this->groups();
        $group_found = false;
        foreach ($existing_groups as $grp) {
            if($grp->id == $group_id) {
                $group_found = true;
                break;
            }
        }

        if($group_found) return false;

        app('db')->table("UserGroup")->insert([
            'user_id'   => $this->id,
            'group_id'  => $group_id,
            'year'      => $this->year
        ]);

        return $this->groups();
    }

    public function removeGroup($group_id, $user_id = false)
    {
        $this->chain($user_id);
        
        // Check if the user has the group.
        $existing_groups = $this->groups();
        $group_found = false;
        foreach ($existing_groups as $grp) {
            if($grp->id == $group_id) {
                $group_found = true;
                break;
            }
        }

        if(!$group_found) return false;

        app('db')->table("UserGroup")->where([
            'user_id'   => $this->id,
            'group_id'  => $group_id,
            'year'      => $this->year
        ])->delete();

        return $this->groups();
    }

    public function setCredit($credit, $user_id = false)
    {
        $this->chain($user_id);
        
        $this->item->credit = $credit; 
        return $this->item->save();
    }

    public function editCredit($new_credit, $credit_assigned_by_user_id, $reason, $user_id = false)
    {
        $this->chain($user_id);

        app('db')->table('UserCredit')->insert([
            'user_id'   => $this->id,
            'credit'    => $new_credit,
            'credit_assigned_by_user_id' => $credit_assigned_by_user_id,
            'comment'   => $reason,
            'added_on'  => date('Y-m-d H:i:s'),
            'year'      => $this->year
        ]);

        return $this->find($this->id)->setCredit($new_credit);
    }

    public function login($email_or_phone, $password, $auth_token='')
    {
        $user = User::where('status', '1')->where('user_type','volunteer');
        $user->where(function($q) use ($email_or_phone) {
            $q->where('email', $email_or_phone)->orWhere('phone', $email_or_phone)->orWhere('mad_email', $email_or_phone);
        });
        $data = $user->first();

        if($data) {
            $is_correct = false;
            if($password) {
                $is_correct = Hash::check($password, $data->password_hash);
            } elseif($auth_token) {
                $is_correct = ($data->auth_token == $auth_token);
            }

            if(!$is_correct) { // Incorrect password / Auth key
                $data = null;
                $this->errors[] = "Incorrect password provided";
            } else {
                $user_id = $data->getKey();
                return $this->fetch($user_id);
            }

        } else {
            $this->errors[] = "Can't find any user with the given email/phone";
        }
        return false;
    }
    
    /// Changes the phone number format from +91976063565 to 9746063565. Remove the 91 at the starting.
    private function correctPhoneNumber($phone) {
        if(strlen($phone) > 10) {
            return preg_replace('/^\+?91\D?/', '', $phone);
        }
        return $phone;
    }
}
