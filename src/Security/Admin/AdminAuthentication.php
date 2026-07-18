<?php

declare(strict_types=1);

namespace App\Security\Admin;

use App\Security\Application\RequestRateLimiter;
use App\Security\Application\SecurityEventLogger;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class AdminAuthentication
{
    private const SESSION_KEY = 'twenty_admin_identity';
    private const DUMMY_HASH = '$2y$12$K6m5N4XEr8hvOueJ3Q0.yOz8cPeZfgm9A5VdKUw7I6gK7j0B4K8bW';

    public function __construct(
        private AdminUserRepository $users,
        private AdminPasswordHasher $hasher,
        private RequestStack $requests,
        private SecurityEventLogger $securityLog,
        private RequestRateLimiter $rateLimiter,
    ) {
    }

    public function login(string $username, string $password, ?string $requestId = null): bool
    {
        $row = $this->users->findByUsername($username);
        $hash = is_array($row) ? (string) $row['password_hash'] : self::DUMMY_HASH;
        $valid = $this->hasher->verify($password, $hash);
        if (!$valid || !is_array($row) || (int) $row['is_active'] !== 1) {
            $this->securityLog->log('ADMIN_LOGIN_FAILED', [
                'requestId' => $requestId,
                'usernameFingerprint' => substr($this->rateLimiter->fingerprint(AdminUserRepository::normalizeUsername($username)), 0, 16),
            ]);

            return false;
        }

        if ($this->hasher->needsRehash($hash)) {
            $this->users->updatePassword((string) $row['id'], $this->hasher->hash($password));
            $row = $this->users->findById((string) $row['id']) ?? throw new \RuntimeException('Account amministrativo non disponibile dopo il rehash.');
        }

        $identity = new AdminIdentity((string) $row['id'], (string) $row['username'], AdminRole::from((string) $row['role']), (int) $row['auth_version']);
        $session = $this->requests->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_KEY, $identity->toSession());
        $this->users->updateLastLogin($identity->id);
        $this->securityLog->log('ADMIN_LOGIN_SUCCEEDED', [
            'requestId' => $requestId,
            'adminId' => $identity->id,
            'role' => $identity->role->value,
        ]);

        return true;
    }

    public function current(): ?AdminIdentity
    {
        $session = $this->requests->getSession();
        $stored = $session->get(self::SESSION_KEY);
        if (!is_array($stored) || !isset($stored['id'], $stored['authVersion'])) {
            return null;
        }

        $row = $this->users->findById((string) $stored['id']);
        if ($row === null || (int) $row['is_active'] !== 1 || (int) $row['auth_version'] !== (int) $stored['authVersion']) {
            $session->remove(self::SESSION_KEY);

            return null;
        }

        $identity = new AdminIdentity((string) $row['id'], (string) $row['username'], AdminRole::from((string) $row['role']), (int) $row['auth_version']);
        $session->set(self::SESSION_KEY, $identity->toSession());

        return $identity;
    }

    public function logout(?string $requestId = null): void
    {
        $identity = $this->current();
        $session = $this->requests->getSession();
        if ($identity !== null) {
            $this->securityLog->log('ADMIN_LOGOUT', ['requestId' => $requestId, 'adminId' => $identity->id]);
        }
        $session->invalidate();
    }
}
