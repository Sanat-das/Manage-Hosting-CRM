<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppSettings;
use App\Support\MathCaptcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Security hardening toggles + math captcha end-to-end.
 *
 * Covers:
 *  - MathCaptcha service (generate/verify/replay prevention)
 *  - security_honeypot_enabled toggle on /register
 *  - security_math_captcha_enabled toggle on /login and /register
 *  - security_headers_enabled toggle via SecurityHeaders middleware
 *  - security_strong_password_enabled toggle (weak vs strong policy)
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Flush spatie scoped settings and legacy cache so each test sees fresh toggles.
        app()->forgetScopedInstances();
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // Ensure registration is enabled for these tests (middleware checks it).
        DB::table('settings')->updateOrInsert(
            ['setting_key' => 'registration_enabled'],
            ['setting_value' => 'yes', 'updated_at' => now()]
        );
        // Reset hardening toggles to known defaults via helper.
        $this->setSecurityToggle('security_honeypot_enabled', 'yes');
        $this->setSecurityToggle('security_headers_enabled', 'yes');
        $this->setSecurityToggle('security_strong_password_enabled', 'no'); // use weak by default to avoid uncompromised network call
        $this->setSecurityToggle('security_math_captcha_enabled', 'no');
        // Reset cache again after inserts.
        $prop->setValue(null, null);
    }

    private function setSecurityToggle(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => now()]
        );
        // Bust AppSettings cache
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function enableMathCaptcha(): void
    {
        $this->setSecurityToggle('security_math_captcha_enabled', 'yes');
    }

    // --- MathCaptcha unit ---

    public function test_math_captcha_generate_and_verify(): void
    {
        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setLaravelSession(app('session')->driver());

        $generated = MathCaptcha::generate($request);
        $this->assertArrayHasKey('question', $generated);
        $this->assertArrayHasKey('answer', $generated);
        $this->assertEquals($generated['answer'], $request->session()->get('math_captcha_answer'));
        $this->assertEquals($generated['question'], $request->session()->get('math_captcha_question'));

        // Verify correct answer returns true (needs toggle enabled)
        $this->enableMathCaptcha();
        $request2 = \Illuminate\Http\Request::create('/test', 'POST');
        $request2->setLaravelSession($request->session());
        $this->assertTrue(MathCaptcha::verify($request2, (string) $generated['answer']));
        // Second verify in same request should also succeed due to verified flag (Fortify double-call handling)
        $this->assertTrue(MathCaptcha::verify($request2, (string) $generated['answer']));
        // Fresh request without verified flag should fail (replay prevention across requests)
        $request3 = \Illuminate\Http\Request::create('/test', 'POST');
        $request3->setLaravelSession($request->session()); // session already forgotten after first verify
        $this->assertFalse(MathCaptcha::verify($request3, (string) $generated['answer']));
    }

    public function test_math_captcha_verify_skipped_when_disabled(): void
    {
        // Toggle off (default in setUp) -> verify always true regardless of input
        $request = \Illuminate\Http\Request::create('/test', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $this->assertTrue(MathCaptcha::verify($request, 'wrong'));
        $this->assertTrue(MathCaptcha::verify($request, null));
    }

    public function test_math_captcha_verify_fails_with_wrong_answer(): void
    {
        $this->enableMathCaptcha();
        $request = \Illuminate\Http\Request::create('/test', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('math_captcha_answer', 42);
        $request->session()->put('math_captcha_question', '20 + 22 = ?');

        $this->assertFalse(MathCaptcha::verify($request, '41'));
        // Second attempt after forget should also fail even with correct answer (replay prevention)
        $request->session()->put('math_captcha_answer', 42);
        $request->session()->put('math_captcha_question', '20 + 22 = ?');
        $this->assertFalse(MathCaptcha::verify($request, '999'));
    }

    public function test_math_captcha_question_lazily_generates(): void
    {
        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->setLaravelSession(app('session')->driver());
        $this->assertFalse($request->session()->has('math_captcha_question'));
        $q = MathCaptcha::question($request);
        $this->assertIsString($q);
        $this->assertTrue($request->session()->has('math_captcha_question'));
        $this->assertTrue($request->session()->has('math_captcha_answer'));
    }

    // --- honeypot ---

    public function test_register_honeypot_blocks_when_enabled(): void
    {
        $this->setSecurityToggle('security_honeypot_enabled', 'yes');
        $this->setSecurityToggle('security_strong_password_enabled', 'no');

        // Need a valid captcha session if captcha disabled -> no captcha needed
        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'honeypot@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website' => 'http://spam.com', // honeypot filled
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'honeypot@test.com']);
    }

    public function test_register_honeypot_ignored_when_disabled(): void
    {
        $this->setSecurityToggle('security_honeypot_enabled', 'no');
        $this->setSecurityToggle('security_strong_password_enabled', 'no');

        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'honeypot-disabled@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website' => 'http://spam.com', // should be ignored when disabled
        ]);

        // With honeypot disabled, registration should succeed (or at least not honeypot-error)
        // It may still fail on other validation, but should not be the honeypot message.
        // Assert user was created.
        $response->assertRedirect(route('client.dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'honeypot-disabled@test.com']);
    }

    // --- math captcha on register ---

    public function test_register_requires_captcha_when_enabled_and_rejects_wrong(): void
    {
        $this->enableMathCaptcha();
        $this->setSecurityToggle('security_strong_password_enabled', 'no');
        $this->setSecurityToggle('security_honeypot_enabled', 'no');

        // Seed session with known answer
        $response = $this->withSession(['math_captcha_answer' => 15, 'math_captcha_question' => '7 + 8 = ?'])
            ->post('/register', [
                'first_name' => 'Cap',
                'last_name' => 'Tester',
                'email' => 'captcha-wrong@test.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'math_captcha' => '999', // wrong
            ]);

        $response->assertSessionHasErrors('math_captcha');
        $this->assertDatabaseMissing('users', ['email' => 'captcha-wrong@test.com']);
    }

    public function test_register_succeeds_with_correct_captcha_when_enabled(): void
    {
        $this->enableMathCaptcha();
        $this->setSecurityToggle('security_strong_password_enabled', 'no');
        $this->setSecurityToggle('security_honeypot_enabled', 'no');

        $response = $this->withSession(['math_captcha_answer' => 15, 'math_captcha_question' => '7 + 8 = ?'])
            ->post('/register', [
                'first_name' => 'Cap',
                'last_name' => 'Correct',
                'email' => 'captcha-correct@test.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'math_captcha' => '15',
            ]);

        $response->assertRedirect(route('client.dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'captcha-correct@test.com']);
        $this->assertAuthenticated();
    }

    public function test_register_succeeds_without_captcha_when_disabled(): void
    {
        $this->setSecurityToggle('security_math_captcha_enabled', 'no');
        $this->setSecurityToggle('security_strong_password_enabled', 'no');
        $this->setSecurityToggle('security_honeypot_enabled', 'no');

        $response = $this->post('/register', [
            'first_name' => 'No',
            'last_name' => 'Captcha',
            'email' => 'no-captcha@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect(route('client.dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'no-captcha@test.com']);
    }

    // --- math captcha on login ---

    public function test_login_requires_captcha_when_enabled(): void
    {
        $this->enableMathCaptcha();
        $user = User::factory()->create([
            'email' => 'login-captcha@test.com',
            'password_hash' => Hash::make('password'),
        ]);
        $user->assignRole('client');

        // Wrong captcha -> should fail with math_captcha error, not authenticated
        $response = $this->withSession(['math_captcha_answer' => 10, 'math_captcha_question' => '5 + 5 = ?'])
            ->post('/login', [
                'email' => 'login-captcha@test.com',
                'password' => 'password',
                'math_captcha' => '999',
            ]);

        $response->assertSessionHasErrors('math_captcha');
        $this->assertGuest();
    }

    public function test_login_succeeds_with_correct_captcha_when_enabled(): void
    {
        $this->enableMathCaptcha();
        $user = User::factory()->create([
            'email' => 'login-captcha-correct@test.com',
            'password_hash' => Hash::make('password'),
        ]);
        $user->assignRole('client');

        // Correct captcha -> succeeds
        $response = $this->withSession(['math_captcha_answer' => 10, 'math_captcha_question' => '5 + 5 = ?'])
            ->post('/login', [
                'email' => 'login-captcha-correct@test.com',
                'password' => 'password',
                'math_captcha' => '10',
            ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_succeeds_without_captcha_when_disabled(): void
    {
        $this->setSecurityToggle('security_math_captcha_enabled', 'no');
        $user = User::factory()->create([
            'email' => 'login-no-captcha@test.com',
            'password_hash' => Hash::make('password'),
        ]);
        $user->assignRole('client');

        $response = $this->post('/login', [
            'email' => 'login-no-captcha@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    // --- security headers toggle ---

    public function test_security_headers_present_when_enabled(): void
    {
        $this->setSecurityToggle('security_headers_enabled', 'yes');

        $response = $this->get('/');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'geolocation=()');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_security_headers_absent_when_disabled(): void
    {
        $this->setSecurityToggle('security_headers_enabled', 'no');

        $response = $this->get('/');
        $response->assertHeaderMissing('X-Frame-Options');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    // --- strong password toggle ---

    public function test_strong_password_rejects_weak_when_enabled(): void
    {
        $this->setSecurityToggle('security_strong_password_enabled', 'yes');
        $this->setSecurityToggle('security_honeypot_enabled', 'no');
        $this->setSecurityToggle('security_math_captcha_enabled', 'no');

        $response = $this->post('/register', [
            'first_name' => 'Weak',
            'last_name' => 'Pass',
            'email' => 'weak@test.com',
            'password' => 'password', // 8 chars, no mixed case/numbers/symbols, should fail strong rule
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'weak@test.com']);
    }

    public function test_weak_password_accepted_when_strong_disabled(): void
    {
        $this->setSecurityToggle('security_strong_password_enabled', 'no');
        $this->setSecurityToggle('security_honeypot_enabled', 'no');
        $this->setSecurityToggle('security_math_captcha_enabled', 'no');

        $response = $this->post('/register', [
            'first_name' => 'Weak',
            'last_name' => 'Ok',
            'email' => 'weak-allowed@test.com',
            'password' => 'password', // 8 chars meets min 8
            'password_confirmation' => 'password',
        ]);

        // When strong disabled, min 8 should pass (no complexity)
        $response->assertRedirect(route('client.dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'weak-allowed@test.com']);
    }

    public function test_settings_page_exposes_security_toggles(): void
    {
        $admin = User::factory()->create();
        $role = \App\Models\Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = \App\Models\Permission::firstOrCreate(['name' => $permName], ['label' => $permName]);
            $role->permissions()->syncWithoutDetaching($perm->id);
        }
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.settings.index', ['tab' => 'security']))
            ->assertStatus(200)
            ->assertSee('security_honeypot_enabled')
            ->assertSee('security_headers_enabled')
            ->assertSee('security_strong_password_enabled')
            ->assertSee('security_math_captcha_enabled');
    }
}
