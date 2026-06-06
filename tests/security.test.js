const authSystem = {
  login: function(username, password, role, accountStatus, dbStatus, serverStatus) {
    if (dbStatus === 'offline') {
      return { success: false, message: 'Local Cache/Database Fallback Active' };
    }

    if (accountStatus === 'Inactive' || accountStatus === 'Deactivated') {
      return { success: false, message: 'Account is ' + accountStatus };
    }

    if (serverStatus === 500) {
      return { success: false, message: 'Network Connection Issue' };
    }

    const validRoles = ['Admin', 'Property Custodian', 'Supply Personnel'];
    if (!validRoles.includes(role)) {
      return { success: false, message: 'Invalid role' };
    }

    return { success: true, message: 'Login successful', role: role };
  }
};

describe('TRACKPOOL - Security and Resiliency Testing', () => {
  describe('Multi-Role Login', () => {
    test('logs in Admin successfully', () => {
      const result = authSystem.login('admin', 'pass123', 'Admin', 'Active', 'online', 200);
      expect(result.success).toBe(true);
      expect(result.message).toBe('Login successful');
      expect(result.role).toBe('Admin');
    });

    test('logs in Property Custodian successfully', () => {
      const result = authSystem.login('custodian', 'pass123', 'Property Custodian', 'Active', 'online', 200);
      expect(result.success).toBe(true);
      expect(result.message).toBe('Login successful');
      expect(result.role).toBe('Property Custodian');
    });

    test('logs in SUPPLY PERSONNEL successfully', () => {
      const result = authSystem.login('supply', 'pass123', 'Supply Personnel', 'Active', 'online', 200);
      expect(result.success).toBe(true);
      expect(result.message).toBe('Login successful');
      expect(result.role).toBe('Supply Personnel');
    });
  });

  describe('Account Security', () => {
    test('blocks Inactive account', () => {
      const result = authSystem.login('user', 'pass123', 'Admin', 'Inactive', 'online', 200);
      expect(result.success).toBe(false);
      expect(result.message).toBe('Account is Inactive');
    });

    test('blocks Deactivated account', () => {
      const result = authSystem.login('user', 'pass123', 'Admin', 'Deactivated', 'online', 200);
      expect(result.success).toBe(false);
      expect(result.message).toBe('Account is Deactivated');
    });

    test('allows Active account login', () => {
      const result = authSystem.login('user', 'pass123', 'Admin', 'Active', 'online', 200);
      expect(result.success).toBe(true);
    });
  });

  describe('Database Fallback', () => {
    test('handles offline database', () => {
      const result = authSystem.login('user', 'pass123', 'Admin', 'Active', 'offline', 200);
      expect(result.success).toBe(false);
      expect(result.message).toBe('Local Cache/Database Fallback Active');
    });

    test('works with online database', () => {
      const result = authSystem.login('user', 'pass123', 'Admin', 'Active', 'online', 200);
      expect(result.success).toBe(true);
    });
  });

  describe('Network Handling', () => {
    test('handles network errors in regular login', () => {
      const result = authSystem.login('user', 'pass123', 'Admin', 'Active', 'online', 500);
      expect(result.success).toBe(false);
      expect(result.message).toBe('Network Connection Issue');
    });

    test('handles network errors with different roles', () => {
      const result = authSystem.login('user', 'pass123', 'Property Custodian', 'Active', 'online', 500);
      expect(result.success).toBe(false);
      expect(result.message).toBe('Network Connection Issue');
    });

    test('works with successful server status', () => {
      const result = authSystem.login('user', 'pass123', 'Admin', 'Active', 'online', 200);
      expect(result.success).toBe(true);
    });
  });
});