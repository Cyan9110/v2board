<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V1\User\CouponController;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\User\OrderSave;
use App\Services\AuthService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ApiController extends Controller
{
    public function register(Request $request)
    {
        return $this->respond(function () use ($request) {
            $code = $this->couponCode($request);
            $registration = app(AuthController::class)->register(
                $this->validatedRequest(AuthRegister::class, $request, $request->only([
                    'email', 'password', 'invite_code', 'email_code', 'recaptcha_data',
                ]))
            )->getData(true);

            return ['registration' => $registration] + $this->placeOrder(
                $request, $code, $this->authenticatedUser($registration['data']['auth_data'])
            );
        });
    }

    public function validateCouponAndSaveOrder(Request $request)
    {
        return $this->respond(function () use ($request) {
            $authorization = $request->header('Authorization');
            if (!$authorization) {
                abort(400, '缺少 Authorization 头');
            }

            return $this->placeOrder(
                $request, $this->couponCode($request), $this->authenticatedUser($authorization)
            );
        });
    }

    private function couponCode(Request $request): string
    {
        $code = $request->input('code');
        if (!is_string($code) || $code === '') {
            abort(400, '缺少有效的优惠码 code');
        }

        return $code;
    }

    private function authenticatedUser($authorization): array
    {
        $user = AuthService::decryptAuthData($authorization);
        if (!$user) {
            abort(403, '未登录或登陆已过期');
        }

        return $user;
    }

    private function placeOrder(Request $request, string $code, array $user): array
    {
        $couponRequest = Request::create('/', 'POST', ['code' => $code, 'user' => $user]);
        $couponCheck = json_decode(app(CouponController::class)->check($couponRequest)->getContent(), true);
        $planId = $couponCheck['data']['limit_plan_ids'][0] ?? null;
        $period = $couponCheck['data']['limit_period'][0] ?? null;
        if ((!is_int($planId) && !is_string($planId)) || filter_var($planId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) === false || !is_string($period) || $period === 'deposit') {
            abort(422, '优惠码未指定有效的套餐和周期');
        }

        $orders = app(OrderController::class);
        $orderSave = json_decode($orders->save($this->validatedRequest(OrderSave::class, $request, [
            'user' => $user,
            'plan_id' => $planId,
            'period' => $period,
            'coupon_code' => $code,
        ]))->getContent(), true);

        $checkoutRequest = Request::create($request->getSchemeAndHttpHost() . '/', 'POST', [
            'user' => $user,
            'trade_no' => $orderSave['data'],
        ] + $request->only(['method', 'token']));
        $orderCheckout = json_decode($orders->checkout($checkoutRequest)->getContent(), true);

        return compact('couponCheck', 'orderSave', 'orderCheckout');
    }

    private function validatedRequest(string $type, Request $source, array $input): FormRequest
    {
        $request = $type::create('/', 'POST', $input, [], [], [
            'REMOTE_ADDR' => $source->ip(),
            'HTTP_USER_AGENT' => $source->userAgent(),
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }

    private function respond(callable $action)
    {
        try {
            return response()->json(['status' => 'success'] + $action());
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'details' => ['errors' => $e->errors()],
                'http_status' => $e->status,
            ], $e->status);
        } catch (HttpExceptionInterface $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'http_status' => $e->getStatusCode(),
            ], $e->getStatusCode(), $e->getHeaders());
        }
    }
}
