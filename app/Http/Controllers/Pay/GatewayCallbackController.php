<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pay;

use App\Models\GatewayOrder;
use App\Services\Gateways\OnlineGatewayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GatewayCallbackController
{
    private const STATUSES = [
        'PAID' => [
            'kind' => 'ok',
            'title' => 'پرداخت موفق',
            'msg' => 'پرداخت شما تایید شد و سفارش تکمیل شد. ✅',
        ],
        'REVIEW' => [
            'kind' => 'wait',
            'title' => 'پرداخت ثبت شد',
            'msg' => 'پرداخت شما تایید شد؛ سفارش در حال بررسی توسط پشتیبانی است.',
        ],
        'PENDING' => [
            'kind' => 'wait',
            'title' => 'در انتظار تایید',
            'msg' => 'پرداخت هنوز تایید نشده است. اگر پرداخت کرده‌اید، چند دقیقه بعد دوباره بررسی کنید.',
        ],
        'CANCELED' => [
            'kind' => 'fail',
            'title' => 'پرداخت لغو شد',
            'msg' => 'پرداخت لغو شد یا ناموفق بود. مبلغی از حساب شما کسر نشده است (در صورت کسر، تا ۳۰ دقیقه بازگردانده می‌شود).',
        ],
        'EXPIRED' => [
            'kind' => 'fail',
            'title' => 'مهلت پرداخت تمام شد',
            'msg' => 'مهلت پرداخت این سفارش تمام شده است. لطفاً دوباره سفارش بدهید.',
        ],
        'FAILED' => [
            'kind' => 'fail',
            'title' => 'پرداخت ناموفق',
            'msg' => 'پرداخت ناموفق بود.',
        ],
    ];

    private function extractGateway(string $gw): string
    {
        return preg_replace('/[^a-z].*$/', '', strtolower($gw));
    }

    private function extractToken(string $token): string
    {
        $clean = preg_replace('/[^a-f0-9].*$/', '', strtolower($token));
        return substr($clean, 0, 64);
    }

    private function renderPage(string $kind, string $title, string $msg): Response
    {
        $color = match ($kind) {
            'ok' => '#16a34a',
            'wait' => '#d97706',
            default => '#dc2626',
        };
        $icon = match ($kind) {
            'ok' => '✅',
            'wait' => '⏳',
            default => '❌',
        };

        $html = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.htmlspecialchars($title).'</title>'
            .'<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f3f4f6;font-family:Tahoma,Vazirmatn,sans-serif;color:#1f2937}.c{background:#fff;border-radius:20px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:32px 24px;max-width:380px;width:90%;text-align:center}.i{font-size:56px}h1{font-size:20px;margin:12px 0;color:'.$color.'}p{line-height:1.9;font-size:14px;color:#4b5563}</style></head><body>'
            .'<div class="c"><div class="i">'.$icon.'</div><h1>'.htmlspecialchars($title).'</h1><p>'.htmlspecialchars($msg).'</p>'
            .'<p style="font-size:12px;color:#9ca3af">می‌توانید این صفحه را ببندید و به ربات تلگرام برگردید.</p></div></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function __invoke(Request $request, OnlineGatewayService $service): Response
    {
        $gateway = $this->extractGateway((string)$request->query('gw', ''));
        $token = $this->extractToken((string)$request->query('t', ''));
        $rawQuery = (string)$request->server('QUERY_STRING', '');

        foreach (['trans_id', 'Authority', 'Status'] as $k) {
            if ($request->query($k) === null && preg_match('/[?&]' . preg_quote($k, '/') . '=([^&]+)/', $rawQuery, $mm)) {
                $request->merge([$k => urldecode($mm[1])]);
            }
        }

        $order = GatewayOrder::query()->where('token', $token)->first();
        if (!$order || $order->gateway !== $gateway) {
            if ($request->isMethod('post')) {
                return response('not found', 404)->header('Content-Type', 'text/plain; charset=utf-8');
            }

            return $this->renderPage('fail', 'سفارش پیدا نشد', 'این لینک معتبر نیست یا منقضی شده است.')->setStatusCode(404);
        }

        if ($gateway === 'nowpayments' && $request->isMethod('post')) {
            $body = (array)$request->json()->all();
            $res = $service->handleNowPaymentsIpn((int)$order->id, $token, $body);
            return response($res['text'] ?? 'ok', (int)$res['http'] ?? 200)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        if ($gateway === 'nowpayments') {
            if ($request->query('cancel')) {
                return $this->renderPage('fail', 'پرداخت لغو شد', 'اگر هنوز پرداخت نکرده‌اید، از ربات دوباره سفارش بدهید.');
            }
            $cur = GatewayOrder::query()->find((int)$order->id);
            if ($cur && $cur->status === 'PAID') {
                return $this->renderPage('ok', 'پرداخت تایید شد', 'سفارش شما تکمیل شد و پیام آن در ربات ارسال شده است.');
            }

            return $this->renderPage('wait', 'در انتظار تایید شبکه', 'پرداخت کریپتو پس از تایید شبکه به‌صورت خودکار ثبت می‌شود (معمولاً چند دقیقه). نتیجه در ربات برایتان ارسال می‌شود.');
        }

        if ($gateway === 'zarinpal') {
            if (!hash_equals((string)$order->authority, (string)$request->query('Authority', ''))) {
                return $this->renderPage('fail', 'پرداخت نامعتبر', 'اطلاعات بازگشتی از درگاه با سفارش شما مطابقت ندارد.');
            }
            if (strtoupper((string)$request->query('Status', '')) !== 'OK') {
                $service->markFailed((int)$order->id, 'CANCELED');
                return $this->renderPage('fail', 'پرداخت انجام نشد', 'پرداخت لغو شد یا ناموفق بود. مبلغی از حساب شما کسر نشده است (در صورت کسر، تا ۳۰ دقیقه بازگردانده می‌شود).');
            }
        } elseif ($gateway === 'nextpay') {
            if (!hash_equals((string)$order->authority, (string)$request->query('trans_id', ''))) {
                return $this->renderPage('fail', 'پرداخت نامعتبر', 'اطلاعات بازگشتی از درگاه با سفارش شما مطابقت ندارد.');
            }
        } else {
            return $this->renderPage('fail', 'درگاه نامعتبر', 'این درگاه پشتیبانی نمی‌شود.');
        }

        $result = $service->verifyAndFinalize((int)$order->id);
        $st = self::STATUSES[$result['status']] ?? self::STATUSES['FAILED'];

        return $this->renderPage($st['kind'], $st['title'], $result['message'] ?: $st['msg']);
    }
}