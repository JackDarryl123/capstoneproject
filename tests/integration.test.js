const equipmentDB = {
  items: [],
  logs: [],
  documents: [],
  serviceRecords: [],
  notifications: [],
  users: [],

  reset: function() {
    this.items = [];
    this.logs = [];
    this.documents = [];
    this.serviceRecords = [];
    this.notifications = [];
    this.users = [];
  },

  addItem: function(item) {
    this.items.push(item);
  },

  getItem: function(pepoCode) {
    return this.items.find(item => item.pepoCode === pepoCode);
  },

  updateStatus: function(pepoCode, status) {
    const item = this.getItem(pepoCode);
    if (item) {
      item.status = status;
      return item;
    }
    return null;
  },

  createLog: function(logEntry) {
    this.logs.push(logEntry);
  },

  getLogs: function() {
    return this.logs;
  },

  addDocument: function(doc) {
    this.documents.push(doc);
  },

  getDocument: function(id) {
    return this.documents.find(doc => doc.id === id);
  },

  updateDocumentStatus: function(id, status, trackingId) {
    const doc = this.getDocument(id);
    if (doc) {
      doc.status = status;
      if (trackingId) doc.trackingId = trackingId;
      return doc;
    }
    return null;
  },

  addServiceRecord: function(record) {
    this.serviceRecords.push(record);
  },

  getServiceRecords: function() {
    return this.serviceRecords;
  },

  addNotification: function(notif) {
    this.notifications.push(notif);
  },

  getNotifications: function() {
    return this.notifications;
  },

  addUser: function(user) {
    this.users.push(user);
  },

  getUser: function(username) {
    return this.users.find(user => user.username === username);
  }
};

function scanQRCode(pepoCode) {
  const item = equipmentDB.getItem(pepoCode);

  if (!item) {
    return { success: false, message: 'Item not found' };
  }

  if (item.status !== 'Available') {
    return { success: false, message: 'Item is not available' };
  }

  equipmentDB.updateStatus(pepoCode, 'In Use');

  const logEntry = {
    type: 'Borrowing Log',
    pepoCode: pepoCode,
    itemName: item.name,
    date: new Date().toISOString().split('T')[0]
  };
  equipmentDB.createLog(logEntry);

  return { success: true, message: 'Item borrowed', item: item };
}

function uploadDocument(user, title) {
  if (!user || !['Staff', 'Admin'].includes(user.role)) {
    return { success: false, message: 'Unauthorized' };
  }

  const doc = {
    id: 'DOC-' + Date.now(),
    title: title,
    uploadedBy: user.username,
    status: 'Pending',
    date: new Date().toISOString().split('T')[0]
  };
  equipmentDB.addDocument(doc);

  return { success: true, message: 'Document uploaded', document: doc };
}

function approveDocument(docId, approver) {
  if (!approver || approver.role !== 'Admin') {
    return { success: false, message: 'Only Admin can approve' };
  }

  const doc = equipmentDB.getDocument(docId);
  if (!doc) {
    return { success: false, message: 'Document not found' };
  }

  const trackingId = 'TRK-' + new Date().getFullYear() + '-' + Math.floor(Math.random() * 10000);
  equipmentDB.updateDocumentStatus(docId, 'Approved', trackingId);

  return { success: true, message: 'Document approved', trackingId: trackingId };
}

function releaseDocument(docId) {
  const doc = equipmentDB.getDocument(docId);
  if (!doc || doc.status !== 'Approved') {
    return { success: false, message: 'Document must be Approved first' };
  }

  equipmentDB.updateDocumentStatus(docId, 'Released', doc.trackingId);

  equipmentDB.addNotification({
    type: 'Document Released',
    docId: docId,
    recipient: doc.uploadedBy,
    date: new Date().toISOString().split('T')[0]
  });

  return { success: true, message: 'Document released' };
}

function scanEquipmentForMaintenance(pepoCode) {
  const item = equipmentDB.getItem(pepoCode);

  if (!item) {
    return { success: false, message: 'Item not found' };
  }

  if (item.status !== 'Due for Maintenance') {
    return { success: false, message: 'Item not due for maintenance' };
  }

  equipmentDB.updateStatus(pepoCode, 'Under Repair');

  const serviceRecord = {
    id: 'SRV-' + Date.now(),
    pepoCode: pepoCode,
    itemName: item.name,
    status: 'In Progress',
    date: new Date().toISOString().split('T')[0]
  };
  equipmentDB.addServiceRecord(serviceRecord);

  return { success: true, message: 'Service record created', record: serviceRecord };
}

function checkModulePermission(user, module, action) {
  const permissions = {
    Staff: { document: ['request', 'upload'], equipment: ['borrow', 'view'], archive: [] },
    Admin: { document: ['request', 'upload', 'approve', 'release'], equipment: ['borrow', 'view', 'archive'], archive: ['archive'] },
    'Property Custodian': { document: ['request', 'view'], equipment: ['borrow', 'view', 'reserve'], archive: [] },
    'Supply Personnel': { document: ['view'], equipment: ['request'], archive: [] }
  };

  const userPerms = permissions[user.role] || {};
  const modulePerms = userPerms[module] || [];

  if (modulePerms.includes(action)) {
    return { allowed: true };
  }
  return { allowed: false, message: 'Permission denied for ' + action };
}

function notifyStatusChange(notif) {
  equipmentDB.addNotification(notif);
}

describe('TRACKPOOL - Module Integration (QR to Database)', () => {
  beforeEach(() => {
    equipmentDB.reset();
    equipmentDB.addItem({
      pepoCode: 'PEPO-2024-001',
      name: 'Dell Laptop XPS 15',
      status: 'Available',
      category: 'Electronics'
    });
    equipmentDB.addUser({ username: 'jsmith', role: 'Staff' });
    equipmentDB.addUser({ username: 'admin', role: 'Admin' });
  });

  describe('QR Scanner to Equipment Database', () => {
    test('scans valid PEPO QR code and updates item status to In Use', () => {
      const result = scanQRCode('PEPO-2024-001');
      expect(result.success).toBe(true);
      expect(result.message).toBe('Item borrowed');
      const item = equipmentDB.getItem('PEPO-2024-001');
      expect(item.status).toBe('In Use');
    });

    test('creates Borrowing Log entry with current date', () => {
      const today = new Date().toISOString().split('T')[0];
      scanQRCode('PEPO-2024-001');
      const logs = equipmentDB.getLogs();
      expect(logs.length).toBe(1);
      expect(logs[0].type).toBe('Borrowing Log');
      expect(logs[0].date).toBe(today);
    });

    test('returns error for invalid PEPO code', () => {
      const result = scanQRCode('PEPO-9999-999');
      expect(result.success).toBe(false);
      expect(result.message).toBe('Item not found');
    });

    test('prevents borrowing unavailable item', () => {
      equipmentDB.updateStatus('PEPO-2024-001', 'In Use');
      const result = scanQRCode('PEPO-2024-001');
      expect(result.success).toBe(false);
      expect(result.message).toBe('Item is not available');
    });
  });
});

describe('TRACKPOOL - Document Lifecycle Integration', () => {
  beforeEach(() => {
    equipmentDB.reset();
    equipmentDB.addUser({ username: 'jsmith', role: 'Staff' });
    equipmentDB.addUser({ username: 'admin', role: 'Admin' });
  });

  test('should allow Staff to upload document with Pending status', () => {
    const user = equipmentDB.getUser('jsmith');
    const result = uploadDocument(user, 'Annual Report 2024');
    expect(result.success).toBe(true);
    expect(result.document.status).toBe('Pending');
  });

  test('should reject document upload from unauthorized user', () => {
    const result = uploadDocument(null, 'Report');
    expect(result.success).toBe(false);
    expect(result.message).toBe('Unauthorized');
  });

  test('should transition document from Pending to Approved', () => {
    const user = equipmentDB.getUser('jsmith');
    const uploadResult = uploadDocument(user, 'Report');
    const docId = uploadResult.document.id;

    const admin = equipmentDB.getUser('admin');
    const approveResult = approveDocument(docId, admin);

    expect(approveResult.success).toBe(true);
    const doc = equipmentDB.getDocument(docId);
    expect(doc.status).toBe('Approved');
  });

  test('should reject non-Admin from approving document', () => {
    const user = equipmentDB.getUser('jsmith');
    const uploadResult = uploadDocument(user, 'Report');
    const docId = uploadResult.document.id;

    const approveResult = approveDocument(docId, user);
    expect(approveResult.success).toBe(false);
    expect(approveResult.message).toBe('Only Admin can approve');
  });

  test('should generate tracking ID when document is approved', () => {
    const user = equipmentDB.getUser('jsmith');
    const uploadResult = uploadDocument(user, 'Report');
    const docId = uploadResult.document.id;

    const admin = equipmentDB.getUser('admin');
    const approveResult = approveDocument(docId, admin);

    expect(approveResult.trackingId).toBeDefined();
    expect(approveResult.trackingId).toMatch(/^TRK-/);
  });

  test('should transition document from Approved to Released', () => {
    const user = equipmentDB.getUser('jsmith');
    const uploadResult = uploadDocument(user, 'Report');
    const docId = uploadResult.document.id;

    const admin = equipmentDB.getUser('admin');
    approveDocument(docId, admin);
    const releaseResult = releaseDocument(docId);

    expect(releaseResult.success).toBe(true);
    const doc = equipmentDB.getDocument(docId);
    expect(doc.status).toBe('Released');
  });

  test('should reject release of non-approved document', () => {
    const user = equipmentDB.getUser('jsmith');
    const uploadResult = uploadDocument(user, 'Report');
    const docId = uploadResult.document.id;

    const releaseResult = releaseDocument(docId);
    expect(releaseResult.success).toBe(false);
  });

  test('should fail approval for non-existent document', () => {
    const admin = equipmentDB.getUser('admin');
    const result = approveDocument('DOC-99999', admin);
    expect(result.success).toBe(false);
  });
});

describe('TRACKPOOL - Equipment & Maintenance Handshake', () => {
  beforeEach(() => {
    equipmentDB.reset();
    equipmentDB.addItem({
      pepoCode: 'PEPO-MAINT-001',
      name: 'Printer HP LaserJet',
      status: 'Due for Maintenance',
      category: 'Office Equipment'
    });
  });

  test('should scan equipment marked as Due for Maintenance', () => {
    const item = equipmentDB.getItem('PEPO-MAINT-001');
    expect(item.status).toBe('Due for Maintenance');
  });

  test('should create service record when maintenance QR scanned', () => {
    const result = scanEquipmentForMaintenance('PEPO-MAINT-001');
    expect(result.success).toBe(true);
    expect(result.message).toBe('Service record created');
    expect(result.record).toBeDefined();
  });

  test('should update equipment status to Under Repair', () => {
    scanEquipmentForMaintenance('PEPO-MAINT-001');
    const item = equipmentDB.getItem('PEPO-MAINT-001');
    expect(item.status).toBe('Under Repair');
  });

  test('should prevent scanning equipment not due for maintenance', () => {
    equipmentDB.updateStatus('PEPO-MAINT-001', 'Available');
    const result = scanEquipmentForMaintenance('PEPO-MAINT-001');
    expect(result.success).toBe(false);
    expect(result.message).toBe('Item not due for maintenance');
  });

  test('should return error for non-existent equipment', () => {
    const result = scanEquipmentForMaintenance('PEPO-NOEXIST');
    expect(result.success).toBe(false);
    expect(result.message).toBe('Item not found');
  });

  test('should generate service record with unique ID', () => {
    const result = scanEquipmentForMaintenance('PEPO-MAINT-001');
    expect(result.record.id).toMatch(/^SRV-/);
  });

  test('should include equipment details in service record', () => {
    const result = scanEquipmentForMaintenance('PEPO-MAINT-001');
    expect(result.record.pepoCode).toBe('PEPO-MAINT-001');
    expect(result.record.itemName).toBe('Printer HP LaserJet');
  });
});

describe('TRACKPOOL - Cross-Module Security', () => {
  beforeEach(() => {
    equipmentDB.reset();
    equipmentDB.addUser({ username: 'jsmith', role: 'Staff' });
    equipmentDB.addUser({ username: 'admin', role: 'Admin' });
    equipmentDB.addUser({ username: 'custodian', role: 'Property Custodian' });
  });

  test('should allow Staff to initiate document request', () => {
    const user = equipmentDB.getUser('jsmith');
    const result = checkModulePermission(user, 'document', 'request');
    expect(result.allowed).toBe(true);
  });

  test('should allow Staff to upload document', () => {
    const user = equipmentDB.getUser('jsmith');
    const result = checkModulePermission(user, 'document', 'upload');
    expect(result.allowed).toBe(true);
  });

  test('should block Staff from archiving equipment', () => {
    const user = equipmentDB.getUser('jsmith');
    const result = checkModulePermission(user, 'equipment', 'archive');
    expect(result.allowed).toBe(false);
  });

  test('should allow Admin to archive equipment', () => {
    const user = equipmentDB.getUser('admin');
    const result = checkModulePermission(user, 'equipment', 'archive');
    expect(result.allowed).toBe(true);
  });

  test('should allow Admin to approve documents', () => {
    const user = equipmentDB.getUser('admin');
    const result = checkModulePermission(user, 'document', 'approve');
    expect(result.allowed).toBe(true);
  });

  test('should allow Admin to release documents', () => {
    const user = equipmentDB.getUser('admin');
    const result = checkModulePermission(user, 'document', 'release');
    expect(result.allowed).toBe(true);
  });

  test('should allow Property Custodian to reserve equipment', () => {
    const user = equipmentDB.getUser('custodian');
    const result = checkModulePermission(user, 'equipment', 'reserve');
    expect(result.allowed).toBe(true);
  });

  test('should block Property Custodian from archiving', () => {
    const user = equipmentDB.getUser('custodian');
    const result = checkModulePermission(user, 'archive', 'archive');
    expect(result.allowed).toBe(false);
  });

  test('should handle unknown role gracefully', () => {
    const result = checkModulePermission({ role: 'Unknown' }, 'document', 'view');
    expect(result.allowed).toBe(false);
  });
});

describe('TRACKPOOL - Notification System Integration', () => {
  beforeEach(() => {
    equipmentDB.reset();
    equipmentDB.addUser({ username: 'jsmith', role: 'Staff' });
    equipmentDB.addUser({ username: 'admin', role: 'Admin' });
  });

  test('should trigger notification when Document Released', () => {
    const user = equipmentDB.getUser('jsmith');
    const uploadResult = uploadDocument(user, 'Report');
    const docId = uploadResult.document.id;

    const admin = equipmentDB.getUser('admin');
    approveDocument(docId, admin);
    releaseDocument(docId);

    const notifs = equipmentDB.getNotifications();
    expect(notifs.length).toBe(1);
    expect(notifs[0].type).toBe('Document Released');
  });

  test('should include recipient in notification', () => {
    const user = equipmentDB.getUser('jsmith');
    const uploadResult = uploadDocument(user, 'Report');
    const docId = uploadResult.document.id;

    const admin = equipmentDB.getUser('admin');
    approveDocument(docId, admin);
    releaseDocument(docId);

    const notifs = equipmentDB.getNotifications();
    expect(notifs[0].recipient).toBe('jsmith');
  });

  test('should include document ID in notification', () => {
    const user = equipmentDB.getUser('jsmith');
    const uploadResult = uploadDocument(user, 'Report');
    const docId = uploadResult.document.id;

    const admin = equipmentDB.getUser('admin');
    approveDocument(docId, admin);
    releaseDocument(docId);

    const notifs = equipmentDB.getNotifications();
    expect(notifs[0].docId).toBe(docId);
  });

  test('should allow manual notification trigger', () => {
    notifyStatusChange({
      type: 'Maintenance Complete',
      recipient: 'admin',
      date: new Date().toISOString().split('T')[0]
    });

    const notifs = equipmentDB.getNotifications();
    expect(notifs.length).toBe(1);
  });

  test('should track multiple notifications', () => {
    notifyStatusChange({ type: 'Notif 1', recipient: 'user1' });
    notifyStatusChange({ type: 'Notif 2', recipient: 'user2' });

    const notifs = equipmentDB.getNotifications();
    expect(notifs.length).toBe(2);
  });
});