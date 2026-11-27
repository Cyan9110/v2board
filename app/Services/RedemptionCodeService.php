<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class RedemptionCodeService
{
    public $coupon;
    public $planId;
    public $userId;
    public $period;

    public function __construct($code)
    {
        $this->coupon = Coupon::where('code', $code)
            ->lockForUpdate()
            ->first();
    }

    public function use(Order $order): bool
    {
        $this->setPlanId($order->plan_id);
        $this->setUserId($order->user_id);
        $this->setPeriod($order->period);
        $this->check();
        switch ($this->coupon->type) {
            case 1:
                $order->discount_amount = $this->coupon->value;
                break;
            case 2:
                $order->discount_amount = $order->total_amount * ($this->coupon->value / 100);
                break;
        }
        if ($order->discount_amount > $order->total_amount) {
            $order->discount_amount = $order->total_amount;
        }
        if ($this->coupon->limit_use !== NULL) {
            if ($this->coupon->limit_use <= 0)
                return false;
            $this->coupon->limit_use = $this->coupon->limit_use - 1;
            if (!$this->coupon->save()) {
                return false;
            }
        }
        return true;
    }

    public function getId()
    {
        return $this->coupon->id;
    }

    public function getCoupon()
    {
        return $this->coupon;
    }

    public function setPlanId($planId)
    {
        $this->planId = $planId;
    }

    public function setUserId($userId)
    {
        $this->userId = $userId;
    }

    public function setPeriod($period)
    {
        $this->period = $period;
    }

    // 检查用户是否超过了优惠券的使用次数限制
    public function checkLimitUseWithUser(): bool
    {
        $usedCount = Order::where('coupon_id', $this->coupon->id)
            ->where('user_id', $this->userId)
            ->whereNotIn('status', [0, 2])
            ->count();
        if ($usedCount >= $this->coupon->limit_use_with_user)
            return false;
        return true;
    }

    // 检查优惠券是否可用
    public function check()
    {
        // 检查优惠券是否存在且可见
        if (!$this->coupon || !$this->coupon->show) {
            abort(500, __('Code does not exist'));
        }

        // 检查优惠券名称是否以 'dhm' 开头
        if (strpos($this->coupon->name, 'dhm') !== 0) {
            abort(500, __('The coupon name does not start with dhm'));
        }

        // 检查优惠券是否只能使用一次
        if ($this->coupon->limit_use !== 1) {
            abort(500, __('This coupon can only be used once'));
        }

        // 检查优惠券是否只能用于一个特定计划
        if (!is_array($this->coupon->limit_plan_ids) || count($this->coupon->limit_plan_ids) !== 1) {
            abort(500, __('This coupon can only be used once'));
        }

        // 检查优惠券是否只能用于一个特定周期
        if (!is_array($this->coupon->limit_period) || count($this->coupon->limit_period) !== 1) {
            abort(500, __('This coupon can only be used once'));
        }

        // 检查优惠券是否已达到使用次数上限
        if ($this->coupon->limit_use <= 0 && $this->coupon->limit_use !== NULL) {
            abort(500, __('This redemption code is no longer valid'));
        }

        // 检查优惠券是否在有效期内
        if (time() < $this->coupon->started_at) {
            abort(500, __('This coupon has not yet started'));
        }
        if (time() > $this->coupon->ended_at) {
            abort(500, __('This coupon has expired'));
        }

        // 检查优惠券是否适用于当前计划.
        if ($this->coupon->limit_plan_ids && $this->planId) {
            if (!in_array($this->planId, $this->coupon->limit_plan_ids)) {
                abort(500, __('The coupon code cannot be used for this subscription'));
            }
        }

        // 检查优惠券是否适用于当前周期
        if ($this->coupon->limit_period && $this->period) {
            if (!in_array($this->period, $this->coupon->limit_period)) {
                abort(500, __('The coupon code cannot be used for this period'));
            }
        }

        // 检查用户是否超过了优惠券的使用次数限制
        if ($this->coupon->limit_use_with_user !== NULL && $this->userId) {
            if (!$this->checkLimitUseWithUser()) {
                abort(500, __('The coupon can only be used :limit_use_with_user per person', [
                    'limit_use_with_user' => $this->coupon->limit_use_with_user
                ]));
            }
        }
    }
}
