<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateMyAccountRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

/**
 * Redmine本家の`/my/account`相当(`MyController#account`、GET/PUTとも
 * `require_login`のみでゲート、対象は常に自分自身)。`UserResource`を
 * そのまま流用(`UserController`のような管理者限定ゲートは適用しない
 * — 自分自身のレコードを見る/編集するのに管理者権限は不要)。本家は
 * PUTに加えてsudo mode要求・OAuthセッションでの変更禁止という追加制約を
 * 持つが、本アプリには既存のsudo mode/OAuth区別の仕組みがWeb側にもない
 * ため対象外。パスワード変更・2FA・通知設定・カスタムフィールドは
 * 対象外(既存Web UIの`profile/index.blade.php`の`updateProfile()`と
 * 同じくname/emailのみ)。既存の`GET /user`(生のモデルダンプ、認証疎通
 * 確認用の最小ルート)とは別物として残す — 混同を避けるためこちらが
 * 唯一の「マイアカウント」APIとして機能する。
 */
final class MyAccountController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(UpdateMyAccountRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user());
    }
}
