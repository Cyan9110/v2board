<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());

            if (!$verify) {
                Log::error("支付回调校验失败: Method: {$method}, UUID: {$uuid}");
                abort(500, 'verify error');
            }

            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }

            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error("支付回调主入口异常: " . $e->getMessage());
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            Log::error("订单不存在: {$tradeNo}");
            return false;
        }

        if ($order->status !== 0) return true;

        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        // 支付成功后发送通知，使用 Try-Catch 保证通知失败不影响回调结果
        try {
            $this->sendTelegram($order);
        } catch (\Exception $e) {
            Log::error("Telegram通知逻辑异常: " . $e->getMessage());
        }

        return true;
    }

    private function sendTelegram($order)
    {
        // 1. 关联数据查询 (使用绝对路径规避命名空间问题)
        $user = \App\Models\User::find($order->user_id);
        $plan = \App\Models\Plan::find($order->plan_id);
        $payment = \App\Models\Payment::find($order->payment_id);

        // 2. 优惠券 Code 转换
        $couponCode = '未使用';
        if ($order->coupon_id) {
            $coupon = \App\Models\Coupon::find($order->coupon_id);
            $couponCode = $coupon ? $coupon->code : '已失效';
        }

        // 3. 邀请人信息
        $inviterEmail = '无';
        if ($order->invite_user_id) {
            $inviter = \App\Models\User::find($order->invite_user_id);
            $inviterEmail = $inviter ? $inviter->email : '无';
        }

        // 4. 今日收入统计 (单位：元)
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayRevenue = Order::where('paid_at', '>=', $todayStart)
            ->whereIn('status', [1, 3])
            ->sum('total_amount') / 100;

        // 5. 周期文本映射
        $periodMap = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '两年付',
            'three_year_price' => '三年付',
            'onetime_price' => '一次性',
            'reset_price' => '流量重置包'
        ];

        // 6. 构建并发送消息
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰 成功收款 %.2f 元\n" .
            "———————————————\n" .
            "🔌 支付接口: %s\n" .
            "💳 支付渠道: %s\n" .
            "📧 用户邮箱: `%s`\n" .
            "📦 购买套餐: %s\n" .
            "⏳ 套餐周期: %s\n" .
            "🎫 优惠券: `%s`\n" .
            "👤 邀请人: `%s`\n" .
            "📑 订单号: `%s`\n" .
            "📅 注册日期: %s\n" .
            "———————————————\n" .
            "📈 今日总收入: %.2f 元",
            $order->total_amount / 100,
            $payment ? $payment->name : '未知',
            $payment ? $payment->payment : '在线支付',
            $user ? $user->email : '未知用户',
            $plan ? $plan->name : ($order->period === 'reset_price' ? '流量重置包' : '余额充值'),
            $periodMap[$order->period] ?? $order->period,
            $couponCode,
            $inviterEmail,
            $order->trade_no,
            $user ? date('Y-m-d', $user->created_at) : '未知',
            $todayRevenue
        );

        $telegramService->sendMessageWithAdmin($message);
    }
}
