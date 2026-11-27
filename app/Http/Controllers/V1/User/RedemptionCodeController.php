<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use App\Services\RedemptionCodeService;
use Illuminate\Http\Request;
use App\Models\Coupon;

/**
 * 兑换码控制器
 */
class RedemptionCodeController extends Controller
{
    /**
     * 检查兑换码
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function check(Request $request)
    {
        // 检查兑换码是否为空
        if (empty($request->input('code'))) {
            abort(500, __('Coupon cannot be empty'));
        }

        // 创建兑换码服务实例
        $couponService = new RedemptionCodeService($request->input('code'));

        // 设置计划ID
        $couponService->setPlanId($request->input('plan_id'));

        // 设置用户ID
        $couponService->setUserId($request->user['id']);

        // 检查兑换码
        $couponService->check();

        // 返回兑换码信息
        return response([
            'data' => $couponService->getCoupon()
        ]);
    }
}