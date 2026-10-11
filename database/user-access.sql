CREATE TABLE IF NOT EXISTS UserPermissions (
  UserID VARCHAR(20) NOT NULL,
  PermissionKey VARCHAR(60) NOT NULL,
  Allowed TINYINT(1) NOT NULL DEFAULT 0,
  UpdatedAt DATETIME NOT NULL,
  UpdatedBy VARCHAR(120) NOT NULL,
  PRIMARY KEY (UserID, PermissionKey),
  INDEX UserPermissions_Key (PermissionKey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
