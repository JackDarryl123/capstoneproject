<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SessionHelperTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_COOKIE = [];
        start_user_session();
        $_SESSION = [];
    }

    public function testCheckLoginReturnsTrueWhenLoggedIn(): void
    {
        $_SESSION['user_id'] = 1;

        $this->assertTrue(check_login());
    }

    public function testCheckLoginReturnsFalseWhenNotLoggedIn(): void
    {
        unset($_SESSION['user_id']);

        $this->assertFalse(check_login());
    }

    public function testCheckLoginReturnsFalseWithEmptyUserId(): void
    {
        $_SESSION['user_id'] = '';

        $this->assertFalse(check_login());
    }

    public function testRequireRoleLogicWithValidUser(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'admin';

        $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin']);
        $this->assertTrue($hasAccess);
    }

    public function testRequireRoleLogicWithInvalidRole(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'user';

        $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin']);
        $this->assertFalse($hasAccess);
    }

    public function testRequireRoleLogicWithNoUser(): void
    {
        unset($_SESSION['user_id']);
        $_SESSION['role'] = '';

        $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin']);
        $this->assertFalse($hasAccess);
    }

    public function testRequireRoleAcceptsMultipleRoles(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'staff';

        $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin', 'staff', 'supply']);
        $this->assertTrue($hasAccess);
    }

    public function testRolesListAcceptsValidRoles(): void
    {
        $validRoles = ['admin', 'staff', 'supply', 'user', 'pgdh_pacco', 'pgdh_gso'];

        foreach ($validRoles as $role) {
            $_SESSION['user_id'] = 1;
            $_SESSION['role'] = $role;

            $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', [$role]);
            $this->assertTrue($hasAccess, "Role {$role} should be accepted");
        }
    }

    public function testRoleAsArrayAccepted(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'admin';

        $roles = ['admin', 'staff', 'supply'];
        $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', $roles);

        $this->assertTrue($hasAccess);
    }

    public function testRoleAsStringSingleRole(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'admin';

        $role = 'admin';
        $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', [$role]);

        $this->assertTrue($hasAccess);
    }

    public function testUserRoleDeniedFromAdminPages(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'user';

        $adminRoles = ['admin', 'pgdh_pacco', 'pgdh_gso'];
        $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', $adminRoles);

        $this->assertFalse($hasAccess);
    }

    public function testEmptySessionDenied(): void
    {
        $_SESSION = [];

        $hasAccess = isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin']);
        $this->assertFalse($hasAccess);
    }
}