<?php

declare(strict_types=1);

namespace App\Security\Admin;

final readonly class AdminIdentity
{
    public function __construct(
        public string $id,
        public string $username,
        public AdminRole $role,
        public int $authVersion,
    ) {
    }

    /** @return array{id:string,username:string,role:string,authVersion:int} */
    public function toSession(): array
    {
        return ['id' => $this->id, 'username' => $this->username, 'role' => $this->role->value, 'authVersion' => $this->authVersion];
    }
}
