<?php

declare(strict_types=1);

namespace App\Services\Provisioning;

use App\Models\HostingAccount;
use App\Models\PanelAccount;
use Illuminate\Support\Facades\Log;

final class VmGuestCredentialStore
{
    /**
     * @return array{username:?string,password:?string}
     */
    public function read(PanelAccount $account): array
    {
        $username = trim((string) ($account->guest_username ?? ''));
        $password = $account->guest_password_encrypted;

        if (is_string($password)) {
            $password = trim($password);
        } else {
            $password = null;
        }

        return [
            'username' => $username !== '' ? $username : null,
            'password' => $password !== null && $password !== '' ? $password : null,
        ];
    }

    public function store(PanelAccount $account, ?string $username, ?string $password): void
    {
        $data = [];
        if ($username !== null) {
            $trimmed = trim($username);
            if ($trimmed !== '') {
                $data['guest_username'] = $trimmed;
            }
        }
        if ($password !== null && $password !== '') {
            $data['guest_password_encrypted'] = $password;
        }

        if ($data !== []) {
            $account->update($data);
        }
    }

    /**
     * Mirror the guest credentials into this account's RDP console config
     * (reveal endpoint + .rdp download embed the password), when that optional
     * module is installed and the row already exists. Never creates a row and
     * never throws: the password reset already succeeded inside the guest.
     */
    public function syncRdpConsole(HostingAccount $account, ?string $username, ?string $password): void
    {
        if (! class_exists(\Modules\RdpConsole\Models\RdpConsoleConfig::class)) {
            return;
        }

        try {
            $configClass = \Modules\RdpConsole\Models\RdpConsoleConfig::class;
            $existing = $configClass::where('hosting_account_id', $account->id)->first();

            if ($existing === null) {
                return;
            }

            $update = [];
            if ($username !== null && trim($username) !== '') {
                $update['username'] = trim($username);
            }
            if ($password !== null && $password !== '') {
                // Encrypted cast on the module model encrypts on write.
                $update['password_encrypted'] = $password;
            }

            if ($update !== []) {
                $existing->update($update);
            }
        } catch (\Throwable $e) {
            Log::warning('RDP console sync skipped', [
                'hosting_account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
