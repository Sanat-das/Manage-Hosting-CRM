<?php

namespace App\Support;

use Illuminate\Http\Request;

class MathCaptcha
{
    /**
     * Generate a new arithmetic challenge and store it in session.
     *
     * @return array{question:string,answer:int}
     */
    public static function generate(Request $request): array
    {
        $a = random_int(1, 20);
        $b = random_int(1, 20);
        $operator = random_int(0, 1) === 0 ? '+' : '-';

        // Ensure subtraction does not go negative for better UX (optional but keeps answers >= 0).
        // If we subtract and b > a, swap so result is non-negative.
        if ($operator === '-' && $b > $a) {
            [$a, $b] = [$b, $a];
        }

        $answer = $operator === '+' ? $a + $b : $a - $b;
        $question = sprintf('%d %s %d = ?', $a, $operator, $b);

        $request->session()->put('math_captcha_answer', $answer);
        $request->session()->put('math_captcha_question', $question);
        $request->session()->put('math_captcha_generated_at', now()->timestamp);

        return [
            'question' => $question,
            'answer' => $answer,
        ];
    }

    /**
     * Verify user input against the stored answer.
     *
     * When the captcha feature is disabled via settings, verification is skipped
     * and always returns true. Otherwise the stored answer is compared and then
     * forgotten to prevent replay.
     */
    public static function verify(Request $request, mixed $input): bool
    {
        if (! AppSettings::bool('security_math_captcha_enabled', false)) {
            return true;
        }

        // Fortify may call authenticateUsing twice in one request (auth + 2FA pipeline).
        // If already verified in this request, succeed even if session was consumed.
        if ($request->attributes->get('math_captcha_verified') === true) {
            return true;
        }

        $expected = $request->session()->get('math_captcha_answer');

        if ($expected === null) {
            return false;
        }

        // Trim and compare as integers; tolerant of surrounding whitespace.
        $given = is_string($input) ? trim($input) : $input;

        if ($given === null || $given === '') {
            return false;
        }

        $result = (int) $given === (int) $expected;

        if ($result) {
            // Mark verified for this request so a second authenticateUsing call in same request succeeds.
            $request->attributes->set('math_captcha_verified', true);
            // Keep answer for potential second call in same request, but schedule forget after response.
            // We forget only after the request completes via a deferred callback, or keep it for next request's replay check.
            // For now, keep session intact for the duplicate call and forget on next request's generate.
        } else {
            // On failure, forget to force regeneration.
            $request->session()->forget(['math_captcha_answer', 'math_captcha_question', 'math_captcha_generated_at']);
        }

        // On success, we also clear after successful auth on next generate, but for test isolation
        // we keep it until the request ends — the next withSession in tests will override.
        if ($result) {
            // Defer forget until after authentication pipeline completes; keep for duplicate call.
            // We will forget via a terminating callback registered on the request.
            // Simple: forget immediately but keep verified flag allows second call to pass.
            $request->session()->forget(['math_captcha_answer', 'math_captcha_question', 'math_captcha_generated_at']);
        }

        return $result;
    }

    /**
     * Return the current question, generating a new one if none exists.
     */
    public static function question(Request $request): string
    {
        $question = $request->session()->get('math_captcha_question');

        if (is_string($question) && $question !== '') {
            // Ensure answer still exists; if answer was forgotten but question remains, regenerate.
            if ($request->session()->has('math_captcha_answer')) {
                return $question;
            }
        }

        return self::generate($request)['question'];
    }
}
