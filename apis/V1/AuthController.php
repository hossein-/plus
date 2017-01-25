<?php

namespace App\Http\Controllers\APIs\V1;

use App\Handler\SendMessage;
use App\Http\Controllers\Controller;
use App\Models\AuthToken;
use App\Models\ImUser;
use App\Models\LoginRecord;
use App\Models\User;
use App\Models\VerifyCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Zhuzhichao\IpLocationZh\Ip;

class AuthController extends Controller
{
    /**
     * 发送手机验证码.
     *
     * @param Request $request 请求对象
     *
     * @return Response 返回对象
     *
     * @author Seven Du <shiweidu@outlook.com>
     * @homepage http://medz.cn
     */
    public function sendPhoneCode(Request $request)
    {
        $vaildSecond = 1;
        $phone = $request->input('phone');
        $verify = VerifyCode::byAccount($phone)->byValid($vaildSecond)->orderByDesc()->first();

        if ($verify) {
            return response()->json([
                'status'  => false,
                'code'    => 1008,
                'message' => null,
                'data'    => $verify->makeSurplusSecond($vaildSecond),
            ])->setStatusCode(403);
        }

        $verify = new VerifyCode();
        $verify->account = $phone;
        $verify->makeVerifyCode();
        $verify->save();

        $type = 'phone';
        $message = new SendMessage($verify, $type);

        return $message->send();
    }

    /**
     * 用户登录.
     *
     * @Author   Wayne[qiaobin@zhiyicx.com]
     * @DateTime 2016-12-27T16:57:18+0800
     *
     * @param Request $request 请求对象
     *
     * @return Response 响应对象
     */
    public function login(Request $request)
    {
        $user = $request->attributes->get('user');
        $deviceCode = $request->input('device_code');
        $token = new AuthToken();
        $token->token = md5($deviceCode.str_random(32));
        $token->refresh_token = md5($deviceCode.str_random(32));
        $token->user_id = $user->id;
        $token->expires = 0;
        $token->state = 1;

        // 登录记录
        $clientIp = $request->getClientIp();
        $loginrecord = new LoginRecord();
        $loginrecord->ip = $clientIp;

        // 保留测试ip
        // $location = (array)Ip::find($clientIp);
        $location = (array) Ip::find('61.139.2.69');
        array_filter($location);
        $loginrecord->address = trim(implode(' ', $location));
        $loginrecord->device_system = $request->input('device_system');
        $loginrecord->device_name = $request->input('device_name');
        $loginrecord->device_model = $request->input('device_model');
        $loginrecord->device_code = $deviceCode;

        DB::transaction(function () use ($token, $user, $loginrecord) {
            $user->tokens()->update(['state' => 0]);
            $user->tokens()->delete();
            $token->save();
            $user->loginRecords()->save($loginrecord);
        });

        //返回数据
        $data = [
            'token'         => $token->token,
            'refresh_token' => $token->refresh_token,
            'created_at'    => $token->created_at->getTimestamp(),
            'expires'       => $token->expires,
            'user_id'       => $user->id,
        ];
        //IM账号信息同步
        // 先注销，开发人先自己思考下～这是作产品，不是完成某个项目，不是说交了就完事了～
        // $ImUser = new ImUser();
        // $ImUser->usersPost(['uid' => $user->id, 'name' => $user->name]);

        return response()->json([
            'status'  => true,
            'code'    => 0,
            'message' => '登录成功',
            'data'    => $data,
        ])->setStatusCode(201);
    }

    /**
     * 重置token.
     *
     * @param Request $request 请求对象
     *
     * @return Response 返回对象
     *
     * @author Seven Du <shiweidu@outlook.com>
     * @homepage http://medz.cn
     */
    public function resetToken(Request $request)
    {
        $shutDownState = 0;
        $refresh_token = $request->input('refresh_token');

        if (!$refresh_token || !($token = AuthToken::withTrashed()->byRefreshToken($refresh_token)->orderByDesc()->first())) {
            return response()
                ->json([
                    'status'  => false,
                    'code'    => 1016,
                    'message' => '操作失败',
                    'data'    => null,
                ])->setStatusCode(404);
        } elseif ($token->state === $shutDownState) {
            return response()->json([
                'status'  => false,
                'code'    => 1013,
                'message' => '请重新登录',
                'data'    => null,
            ]);
        }

        DB::transaction(function () use ($token, $shutDownState) {
            $token->state = $shutDownState;
            $token->save();
        });

        $request->attributes->set('user', $token->user);

        return $this->login($request);
    }

    /**
     * 注册用户.
     *
     * @param Request $request 请求对象
     *
     * @return Response 返回对象
     *
     * @author Seven Du <shiweidu@outlook.com>
     * @homepage http://medz.cn
     */
    public function register(Request $request)
    {
        $name = $request->input('name');
        $phone = $request->input('phone');
        $password = $request->input('password', '');
        $user = new User();
        $user->name = $name;
        $user->phone = $phone;
        $user->createPassword($password);
        $user->save();

        $request->attributes->set('user', $user);

        return $this->login($request);
    }

    /**
     * 找回密码控制器.
     *
     * @param Request $request 请求对象
     *
     * @return any 返回对象
     *
     * @author Seven Du <shiweidu@outlook.com>
     * @homepage http://medz.cn
     */
    public function forgotPassword(Request $request)
    {
        $password = $request->input('password', '');

        $user = $request->attributes->get('user');
        $user->createPassword($password);
        $user->save();

        return response()->json([
            'status'  => true,
            'code'    => 0,
            'message' => '重置密码成功',
            'data'    => null,
        ]);
    }
}
