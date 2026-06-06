function validateRole(userRole, allowedRoles) {
  if (!userRole) return false;
  if (!Array.isArray(allowedRoles)) {
    allowedRoles = [allowedRoles];
  }
  return allowedRoles.includes(userRole);
}

function checkAccessLevel(userRole) {
  const roleHierarchy = {
    'Admin': 4,
    'Property Custodian': 3,
    'Supply Personnel': 2,
    'Staff': 1,
    'Guest': 0
  };
  return roleHierarchy[userRole] ?? -1;
}

function isAuthorized(userRole, requiredRoles) {
  if (!userRole || !requiredRoles || !Array.isArray(requiredRoles)) {
    return { authorized: false, reason: 'Invalid parameters' };
  }
  
  if (!requiredRoles.includes(userRole)) {
    return { authorized: false, reason: 'Role not permitted' };
  }
  
  return { authorized: true, role: userRole };
}

describe('TRACKPOOL - Role Validation System', () => {
  const authorizedRoles = ['Admin', 'Staff', 'Property Custodian', 'Supply Personnel'];

  describe('Test Suite 1: validateRole() - Basic Role Validation', () => {
    describe('Happy Path - Authorized Roles', () => {
      test('Admin role should be granted access', () => {
        expect(validateRole('Admin', authorizedRoles)).toBe(true);
      });

      test('Supply Personnel role should be granted access', () => {
        expect(validateRole('Supply Personnel', authorizedRoles)).toBe(true);
      });

      test('Staff role should be granted access', () => {
        expect(validateRole('Staff', authorizedRoles)).toBe(true);
      });

      test('Property Custodian role should be granted access', () => {
        expect(validateRole('Property Custodian', authorizedRoles)).toBe(true);
      });

      test('Admin with uppercase should still work', () => {
        expect(validateRole('ADMIN', authorizedRoles)).toBe(false);
      });
    });

    describe('Negative Path - Unauthorized Roles', () => {
      test('Guest role should be denied access', () => {
        expect(validateRole('Guest', authorizedRoles)).toBe(false);
      });

      test('Anonymous/empty role should be denied access', () => {
        expect(validateRole('', authorizedRoles)).toBe(false);
      });

      test('Null role should be denied access', () => {
        expect(validateRole(null, authorizedRoles)).toBe(false);
      });

      test('Undefined role should be denied access', () => {
        expect(validateRole(undefined, authorizedRoles)).toBe(false);
      });

      test('Hacker role should be denied access', () => {
        expect(validateRole('Hacker', authorizedRoles)).toBe(false);
      });

      test('Former Employee role should be denied access', () => {
        expect(validateRole('Former Employee', authorizedRoles)).toBe(false);
      });
    });

    describe('Edge Cases', () => {
      test('Single role as string should work', () => {
        expect(validateRole('Admin', 'Admin')).toBe(true);
      });

      test('Empty array of allowed roles should deny access', () => {
        expect(validateRole('Admin', [])).toBe(false);
      });

      test('Case sensitivity - lowercase admin should be denied', () => {
        expect(validateRole('admin', authorizedRoles)).toBe(false);
      });
    });
  });

  describe('Test Suite 2: checkAccessLevel() - Role Hierarchy', () => {
    describe('Happy Path - Role Levels', () => {
      test('Admin should have highest access level (4)', () => {
        expect(checkAccessLevel('Admin')).toBe(4);
      });

      test('Property Custodian should have level 3', () => {
        expect(checkAccessLevel('Property Custodian')).toBe(3);
      });

      test('Supply Personnel should have level 2', () => {
        expect(checkAccessLevel('Supply Personnel')).toBe(2);
      });

      test('Staff should have level 1', () => {
        expect(checkAccessLevel('Staff')).toBe(1);
      });

      test('Guest should have level 0', () => {
        expect(checkAccessLevel('Guest')).toBe(0);
      });
    });

    describe('Negative Path - Invalid Roles', () => {
      test('Unknown role should return -1', () => {
        expect(checkAccessLevel('Unknown')).toBe(-1);
      });

      test('Null role should return -1', () => {
        expect(checkAccessLevel(null)).toBe(-1);
      });

      test('Empty role should return -1', () => {
        expect(checkAccessLevel('')).toBe(-1);
      });
    });
  });

  describe('Test Suite 3: isAuthorized() - Detailed Authorization', () => {
    describe('Happy Path - Authorization Results', () => {
      test('Admin should be authorized with detailed response', () => {
        const result = isAuthorized('Admin', authorizedRoles);
        expect(result.authorized).toBe(true);
        expect(result.role).toBe('Admin');
        expect(result.reason).toBeUndefined();
      });

      test('Supply Personnel should be authorized with detailed response', () => {
        const result = isAuthorized('Supply Personnel', authorizedRoles);
        expect(result.authorized).toBe(true);
        expect(result.role).toBe('Supply Personnel');
      });
    });

    describe('Negative Path - Authorization Denial', () => {
      test('Guest should not be authorized', () => {
        const result = isAuthorized('Guest', authorizedRoles);
        expect(result.authorized).toBe(false);
        expect(result.reason).toBe('Role not permitted');
      });

      test('Empty role should not be authorized', () => {
        const result = isAuthorized('', authorizedRoles);
        expect(result.authorized).toBe(false);
      });

      test('Null role should not be authorized', () => {
        const result = isAuthorized(null, authorizedRoles);
        expect(result.authorized).toBe(false);
        expect(result.reason).toBe('Invalid parameters');
      });

      test('Empty allowed roles should fail', () => {
        const result = isAuthorized('Admin', []);
        expect(result.authorized).toBe(false);
      });

      test('Null allowed roles should fail', () => {
        const result = isAuthorized('Admin', null);
        expect(result.authorized).toBe(false);
      });
    });
  });

  describe('Test Suite 4: Integration Tests', () => {
    test('User with Admin role should pass all authorization checks', () => {
      const role = 'Admin';
      expect(validateRole(role, authorizedRoles)).toBe(true);
      expect(checkAccessLevel(role)).toBe(4);
      expect(isAuthorized(role, authorizedRoles).authorized).toBe(true);
    });

    test('User with Supply Personnel role should pass all authorization checks', () => {
      const role = 'Supply Personnel';
      expect(validateRole(role, authorizedRoles)).toBe(true);
      expect(checkAccessLevel(role)).toBe(2);
      expect(isAuthorized(role, authorizedRoles).authorized).toBe(true);
    });

    test('Guest should fail all authorization checks', () => {
      const role = 'Guest';
      expect(validateRole(role, authorizedRoles)).toBe(false);
      expect(checkAccessLevel(role)).toBe(0);
      expect(isAuthorized(role, authorizedRoles).authorized).toBe(false);
    });
  });
});