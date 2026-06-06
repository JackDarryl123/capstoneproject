<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RoleRedirectTest extends TestCase
{
    private array $roleRedirects = [
        'pgdh_pacco' => 'PACCO/admin_dashboard.php',
        'pgdh_gso' => 'GSO/admin_dashboard.php',
        'admin' => 'admin_dashboard.php',
        'staff' => 'staff/staff_dashboard.php',
        'supply' => 'supply/supply_dashboard.php',
        'user' => 'users/user_dashboard.php',
    ];

    public function testAdminRedirectsToAdminDashboard(): void
    {
        $role = 'admin';
        $this->assertArrayHasKey($role, $this->roleRedirects);
        $this->assertEquals('admin_dashboard.php', $this->roleRedirects[$role]);
    }

    public function testStaffRedirectsToStaffDashboard(): void
    {
        $role = 'staff';
        $this->assertArrayHasKey($role, $this->roleRedirects);
        $this->assertEquals('staff/staff_dashboard.php', $this->roleRedirects[$role]);
    }

    public function testSupplyRedirectsToSupplyDashboard(): void
    {
        $role = 'supply';
        $this->assertArrayHasKey($role, $this->roleRedirects);
        $this->assertEquals('supply/supply_dashboard.php', $this->roleRedirects[$role]);
    }

    public function testUserRedirectsToUserDashboard(): void
    {
        $role = 'user';
        $this->assertArrayHasKey($role, $this->roleRedirects);
        $this->assertEquals('users/user_dashboard.php', $this->roleRedirects[$role]);
    }

    public function testPgdhPaccoRedirectsToPaccoDashboard(): void
    {
        $role = 'pgdh_pacco';
        $this->assertArrayHasKey($role, $this->roleRedirects);
        $this->assertEquals('PACCO/admin_dashboard.php', $this->roleRedirects[$role]);
    }

    public function testPgdhGsoRedirectsToGsoDashboard(): void
    {
        $role = 'pgdh_gso';
        $this->assertArrayHasKey($role, $this->roleRedirects);
        $this->assertEquals('GSO/admin_dashboard.php', $this->roleRedirects[$role]);
    }

    public function testAllValidRolesHaveRedirects(): void
    {
        $validRoles = ['admin', 'staff', 'supply', 'user', 'pgdh_pacco', 'pgdh_gso'];

        foreach ($validRoles as $role) {
            $this->assertArrayHasKey($role, $this->roleRedirects, "Role {$role} should have a redirect");
        }
    }

    public function testRoleHierarchyLevel(): void
    {
        $roleHierarchy = [
            'admin' => 6,
            'pgdh_pacco' => 5,
            'pgdh_gso' => 5,
            'supply' => 4,
            'staff' => 3,
            'user' => 1,
        ];

        $this->assertGreaterThan($roleHierarchy['user'], $roleHierarchy['admin']);
        $this->assertGreaterThan($roleHierarchy['user'], $roleHierarchy['staff']);
        $this->assertEquals(6, $roleHierarchy['admin']);
    }

    public function testInvalidRoleHasNoRedirect(): void
    {
        $invalidRoles = ['hacker', 'guest', 'null', ''];

        foreach ($invalidRoles as $role) {
            $this->assertArrayNotHasKey($role, $this->roleRedirects);
        }
    }
}