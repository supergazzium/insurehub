<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EmailOtpMail;
use App\Models\EmailOtp;
use App\Models\EmailVerificationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailOtpController extends Controller
{
    private const OTP_TTL_MINUTES = 10;
    private const TOKEN_TTL_MINUTES = 30;
    private const MAX_ATTEMPTS = 5;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_SENDS_PER_EMAIL_PER_HOUR = 5;
    private const MAX_SENDS_PER_IP_PER_HOUR = 10;

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $email = strtolower(trim($data['email']));
        $ip = (string) $request->ip();

        // Cooldown check: last active OTP < 60s old is a resend abuse attempt.
        $latest = EmailOtp::query()
            ->where('email', $email)
            ->orderByDesc('id')
            ->first();
        if ($latest !== null) {
            $ageSeconds = Carbon::now()->diffInSeconds($latest->created_at, false);
            $ageSeconds = abs($ageSeconds);
            if ($ageSeconds < self::RESEND_COOLDOWN_SECONDS && $latest->consumed_at === null) {
                $retryAfter = self::RESEND_COOLDOWN_SECONDS - (int) $ageSeconds;
                return response()->json([
                    'message' => 'Please wait before requesting another code.',
                    'retryAfter' => $retryAfter,
                ], 429);
            }
        }

        // Hourly per-email limit.
        $sendsThisHourEmail = EmailOtp::query()
            ->where('email', $email)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();
        if ($sendsThisHourEmail >= self::MAX_SENDS_PER_EMAIL_PER_HOUR) {
            return response()->json([
                'message' => 'Too many verification requests for this email. Try again in an hour.',
            ], 429);
        }

        // Hourly per-IP limit — coarse abuse guard.
        $sendsThisHourIp = EmailOtp::query()
            ->where('ip', $ip)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();
        if ($sendsThisHourIp >= self::MAX_SENDS_PER_IP_PER_HOUR) {
            return response()->json([
                'message' => 'Too many verification requests from this network. Try again in an hour.',
            ], 429);
        }

        // Generate 6-digit code, store hash only.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailOtp::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(self::OTP_TTL_MINUTES),
            'attempts' => 0,
            'ip' => $ip,
        ]);

        try {
            Mail::to($email)->send(new EmailOtpMail($code, self::OTP_TTL_MINUTES));
        } catch (\Throwable $e) {
            Log::warning('email-otp.send.mail_failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            // Don't reveal SMTP errors to the client; the row still exists so
            // the code is verifiable if the mail was actually sent. In dev
            // with the log driver, the send always succeeds.
        }

        $response = [
            'sent' => true,
            'ttlMinutes' => self::OTP_TTL_MINUTES,
            'cooldownSeconds' => self::RESEND_COOLDOWN_SECONDS,
        ];
        // Dev-only convenience so the operator doesn't need to tail logs.
        // Never leaks in non-local environments.
        if (app()->environment('local') && (bool) config('app.debug')) {
            $response['devCode'] = $code;
        }

        return response()->json($response);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);
        $email = strtolower(trim($data['email']));
        $code = $data['code'];

        // Take the newest un-consumed, un-expired OTP for this email.
        $otp = EmailOtp::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('id')
            ->first();

        if ($otp === null) {
            return response()->json([
                'message' => 'The verification code has expired or was not sent. Please request a new one.',
            ], 422);
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->consumed_at = Carbon::now();
            $otp->save();
            return response()->json([
                'message' => 'Too many incorrect attempts. Please request a new code.',
            ], 429);
        }

        $otp->attempts = $otp->attempts + 1;

        if (!Hash::check($code, $otp->code_hash)) {
            $otp->save();
            $remaining = self::MAX_ATTEMPTS - $otp->attempts;
            return response()->json([
                'message' => 'Incorrect code.',
                'attemptsRemaining' => max(0, $remaining),
            ], 422);
        }

        $otp->consumed_at = Carbon::now();
        $otp->save();

        // Issue a short-lived verification token the register endpoint will
        // consume. The token is random (128-char) and stored server-side so
        // we can revoke without re-signing.
        $token = Str::random(64);
        EmailVerificationToken::create([
            'token' => $token,
            'email' => $email,
            'expires_at' => Carbon::now()->addMinutes(self::TOKEN_TTL_MINUTES),
        ]);

        return response()->json([
            'verified' => true,
            'emailOtpToken' => $token,
            'expiresInMinutes' => self::TOKEN_TTL_MINUTES,
        ]);
    }
}
