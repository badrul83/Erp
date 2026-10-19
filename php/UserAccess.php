<?php
declare(strict_types=1);

/**
 * Role and module access control for the ERP.
 *
 * Administrator accounts are unrestricted. Standard users receive a practical
 * operations preset which an administrator can tailor per account. A dealer
 * account is always linked to one approved dealer and can only create/view
 * that dealer's orders and invoices.
 */
trait UserAccess
{
    private function ensureUserAccessSchema(): void
    {
        $hasDealerId = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Users" AND COLUMN_NAME="DealerID"');
        if (!$hasDealerId) {
            $this->db->exec('ALTER TABLE Users ADD COLUMN DealerID VARCHAR(20) NULL AFTER Role');
        }
        $hasPhone = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Users" AND COLUMN_NAME="Phone"');
        if (!$hasPhone) {
            $this->db->exec('ALTER TABLE Users ADD COLUMN Phone VARCHAR(60) NULL AFTER Name');
        }
        $hasPhoneIndex = (int) $this->value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Users" AND COLUMN_NAME="Phone" AND NON_UNIQUE=0 AND SEQ_IN_INDEX=1');
        if (!$hasPhoneIndex) {
            $this->db->exec('ALTER TABLE Users ADD UNIQUE INDEX Users_Phone_UQ (Phone)');
        }
        $hasProfilePhoto = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Users" AND COLUMN_NAME="ProfilePhotoDataUrl"');
        if (!$hasProfilePhoto) $this->db->exec('ALTER TABLE Users ADD COLUMN ProfilePhotoDataUrl MEDIUMTEXT NULL AFTER Phone');
        $this->db->exec('CREATE TABLE IF NOT EXISTS UserPermissions (
            UserID VARCHAR(20) NOT NULL,
            PermissionKey VARCHAR(60) NOT NULL,
            Allowed TINYINT(1) NOT NULL DEFAULT 0,
            UpdatedAt DATETIME NOT NULL,
            UpdatedBy VARCHAR(120) NOT NULL,
            PRIMARY KEY (UserID, PermissionKey),
            INDEX UserPermissions_Key (PermissionKey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS UserLoginHistory (
            LoginHistoryID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            UserID VARCHAR(20) NOT NULL,
            UserName VARCHAR(160) NOT NULL,
            LoginID VARCHAR(120) NOT NULL,
            SessionKey CHAR(64) NULL,
            LoginAt DATETIME NOT NULL,
            IPAddress VARCHAR(45) NULL,
            UserAgent VARCHAR(500) NULL,
            INDEX session_login_time (SessionKey, LoginAt),
            INDEX user_login_time (UserID, LoginAt),
            INDEX login_time (LoginAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $hasLoginSessionKey = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="UserLoginHistory" AND COLUMN_NAME="SessionKey"');
        if (!$hasLoginSessionKey) $this->db->exec('ALTER TABLE UserLoginHistory ADD COLUMN SessionKey CHAR(64) NULL AFTER LoginID');
        $hasLoginSessionIndex = (int) $this->value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="UserLoginHistory" AND INDEX_NAME="session_login_time"');
        if (!$hasLoginSessionIndex) $this->db->exec('CREATE INDEX session_login_time ON UserLoginHistory (SessionKey, LoginAt)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS UserOnlineSessions (
            SessionKey CHAR(64) NOT NULL PRIMARY KEY,
            UserID VARCHAR(20) NOT NULL,
            UserName VARCHAR(160) NOT NULL,
            LoginID VARCHAR(120) NOT NULL,
            IPAddress VARCHAR(45) NULL,
            UserAgent VARCHAR(500) NULL,
            LastSeen DATETIME NOT NULL,
            INDEX online_last_seen (LastSeen),
            INDEX online_user (UserID, LastSeen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function normalizedRole(string $role): string
    {
        $role = strtoupper(trim($role));
        if ($role === 'ADMIN') return 'ADMIN';
        if ($role === 'DEALER') return 'DEALER';
        if ($role === 'EMPLOYEE') return 'EMPLOYEE';
        // Keep old STAFF and MANAGER accounts working after the access upgrade.
        return 'STANDARD';
    }

    private function permissionCatalog(): array
    {
        return [
            ['Key'=>'dashboard', 'Label'=>'Dashboard', 'Group'=>'Overview', 'Note'=>'Business summary and daily totals.'],
            ['Key'=>'agreements', 'Label'=>'Supplier', 'Group'=>'Operations', 'Note'=>'Supplier profile and agreement records.'],
            ['Key'=>'purchases', 'Label'=>'Purchase', 'Group'=>'Operations', 'Note'=>'Purchase order create, edit and view.'],
            ['Key'=>'goods_receipts', 'Label'=>'Goods Receipt (GRN)', 'Group'=>'Operations', 'Note'=>'Receive and post purchase goods.'],
            ['Key'=>'inventory', 'Label'=>'Inventory', 'Group'=>'Operations', 'Note'=>'Stock balances and product availability.'],
            ['Key'=>'product_damage', 'Label'=>'Product Damage', 'Group'=>'Operations', 'Note'=>'Damage and stock adjustment records.'],
            ['Key'=>'dealers', 'Label'=>'Dealer Register', 'Group'=>'Operations', 'Note'=>'Dealer registration and profile records.'],
            ['Key'=>'dealer_incentive', 'Label'=>'Dealer Incentive', 'Group'=>'Dealer', 'Note'=>'Target and incentive summaries.'],
            ['Key'=>'dealer_sales_management', 'Label'=>'SR Management', 'Group'=>'Human Resources', 'Note'=>'Dealer-assigned SR profiles, weekly/monthly targets and sales performance.'],
            ['Key'=>'sales', 'Label'=>'Sales', 'Group'=>'Operations', 'Note'=>'Sales order, invoice and delivery workflow.'],
            ['Key'=>'returns', 'Label'=>'Product Returns', 'Group'=>'Operations', 'Note'=>'Sales and product-return records.'],
            ['Key'=>'payments', 'Label'=>'Payment Register (history)', 'Group'=>'Accounting', 'Note'=>'Read-only operational payment history; all new financial entries are posted through Easy Accounts.'],
            ['Key'=>'expenses', 'Label'=>'Expenses', 'Group'=>'Accounting', 'Note'=>'Operating expense records within the Accounting module.'],
            ['Key'=>'product_config', 'Label'=>'Product Configuration', 'Group'=>'Configuration', 'Note'=>'Product, unit and price setup.'],
            ['Key'=>'reports', 'Label'=>'Reports', 'Group'=>'Reports', 'Note'=>'Operational reports and printable records.'],
            ['Key'=>'accounting', 'Label'=>'Accounting', 'Group'=>'Accounting', 'Note'=>'Accounting dashboard and ledgers.'],
            ['Key'=>'easy_accounts', 'Label'=>'Easy Accounts', 'Group'=>'Accounting', 'Note'=>'Guided cash, bill, payment and expense entries posting to the main ledger.'],
            ['Key'=>'dealer_orders', 'Label'=>'Own Orders & Invoices', 'Group'=>'Dealer Portal', 'Note'=>'Dealer can only place orders and see invoices for the linked dealer.'],
            ['Key'=>'employee_portal', 'Label'=>'Employee Profile', 'Group'=>'Human Resources', 'Note'=>'Employee can view their own profile, monthly attendance, achievements and payroll history.'],
        ];
    }

    private function catalogKeys(): array
    {
        return array_column($this->permissionCatalog(), 'Key');
    }

    private function defaultPermissionKeys(string $role): array
    {
        $role = $this->normalizedRole($role);
        if ($role === 'DEALER') return ['dealer_orders','dealer_sales_management'];
        if ($role === 'EMPLOYEE') return ['employee_portal'];
        if ($role === 'STANDARD') return ['dashboard','agreements','purchases','goods_receipts','inventory','product_damage','dealers','sales','returns','payments','expenses','reports','easy_accounts'];
        return $this->catalogKeys();
    }

    private function configuredPermissionKeys(array $user): array
    {
        $role = $this->normalizedRole((string) ($user['Role'] ?? 'STANDARD'));
        if ($role === 'ADMIN') return $this->catalogKeys();
        $rows = $this->rows('UserPermissions', 'UserID=?', [(string) $user['UserID']]);
        if (!$rows) return $this->defaultPermissionKeys($role);
        $known = array_flip($this->catalogKeys());
        $keys = [];
        foreach ($rows as $row) {
            $key = (string) ($row['PermissionKey'] ?? '');
            if (!empty($row['Allowed']) && isset($known[$key])) $keys[] = $key;
        }
        // Dealer portal access is an enforced fixed policy. Existing Dealer
        // users may have permissions saved before a new dealer-only feature
        // was added, so always include the current dealer portal defaults.
        if ($role === 'DEALER') $keys = array_merge($keys, $this->defaultPermissionKeys('DEALER'));
        return array_values(array_unique($keys));
    }

    private function savePermissionKeys(string $userId, string $role, array $keys): void
    {
        $allowed = array_flip($this->catalogKeys());
        $selected = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if (isset($allowed[$key])) $selected[$key] = true;
        }
        $role = $this->normalizedRole($role);
        if ($role === 'DEALER') $selected = ['dealer_orders'=>true,'dealer_sales_management'=>true];
        if ($role === 'EMPLOYEE') $selected = ['employee_portal'=>true];
        if ($role === 'ADMIN') $selected = array_fill_keys($this->catalogKeys(), true);
        $query = $this->db->prepare('INSERT INTO UserPermissions (UserID, PermissionKey, Allowed, UpdatedAt, UpdatedBy) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE Allowed=VALUES(Allowed), UpdatedAt=VALUES(UpdatedAt), UpdatedBy=VALUES(UpdatedBy)');
        foreach ($this->catalogKeys() as $key) {
            $query->execute([$userId, $key, isset($selected[$key]) ? 1 : 0, $this->now(), $this->current()]);
        }
    }

    private function dealerUserId(array $user): string
    {
        return $this->normalizedRole((string) ($user['Role'] ?? '')) === 'DEALER' ? trim((string) ($user['DealerID'] ?? '')) : '';
    }

    private function validateDealerUser(string $dealerId): array
    {
        $dealer = $this->one('Dealers', 'DealerID', $dealerId);
        if (!$dealer || !in_array(strtoupper((string) $dealer['Status']), ['APPROVED', 'ACTIVE'], true)) {
            throw new InvalidArgumentException('A Dealer User must be linked to an approved registered dealer.');
        }
        return $dealer;
    }

    private function permissionProfile(array $user): array
    {
        $role = $this->normalizedRole((string) ($user['Role'] ?? 'STANDARD'));
        return [
            'Role' => $role,
            'DealerID' => in_array($role, ['DEALER','EMPLOYEE'], true) ? (string) ($user['DealerID'] ?? '') : '',
            'EmployeeID' => $role === 'EMPLOYEE' ? (string) ($user['EmployeeID'] ?? '') : '',
            'PermissionKeys' => $this->configuredPermissionKeys($user),
        ];
    }

    private function normalizeUserPhone(string $phone): string
    {
        $digits = preg_replace('/\\D+/', '', trim($phone)) ?? '';
        if (str_starts_with($digits, '00880')) $digits = substr($digits, 4);
        elseif (str_starts_with($digits, '880')) $digits = substr($digits, 3);
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) $digits = '0' . $digits;
        return preg_match('/^01[3-9][0-9]{8}$/', $digits) ? $digits : '';
    }

    private function validateUserProfile(string $name, string $phone, string $exceptUserId = ''): array
    {
        $name = trim($name);
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if (!preg_match('/^\\+?[0-9][0-9\\s().-]*$/', trim($phone))) throw new InvalidArgumentException('Phone number can contain digits and common phone formatting only.');
        $phone = $this->normalizeUserPhone($phone);
        if ($nameLength < 2 || $nameLength > 160) throw new InvalidArgumentException('Enter the user’s full official name (2-160 characters).');
        if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) throw new InvalidArgumentException('Enter an 11-digit Bangladesh mobile number starting with 01.');
        $duplicate = (int) $this->value('SELECT COUNT(*) FROM Users WHERE (Phone=? OR LOWER(Username)=?) AND UserID<>?', [$phone, strtolower($phone), $exceptUserId]);
        if ($duplicate) throw new InvalidArgumentException('This phone number is already assigned to another user or username.');
        return [$name, $phone];
    }

    private function accessPolicy(): array
    {
        $this->user(true);
        return [
            'Catalog' => $this->permissionCatalog(),
            'RoleDefaults' => [
                'STANDARD' => $this->defaultPermissionKeys('STANDARD'),
                'DEALER' => $this->defaultPermissionKeys('DEALER'),
            ],
            'DealerPolicy' => 'Dealer User is bound to one approved dealer. It can place draft orders, view/print only its invoices, and manage only its own SR profiles, weekly/monthly targets and retail sales activity. Confirmation, delivery, company stock, payments, reports, settings and other dealers remain unavailable.',
        ];
    }

    private function saveUserAccess(string $userId, array $input): array
    {
        $actor = $this->user(true);
        $target = $this->one('Users', 'UserID', $userId);
        if (!$target) throw new RuntimeException('User was not found.');
        $role = $this->normalizedRole((string) ($input['Role'] ?? $target['Role']));
        if ((string) $actor['UserID'] === $userId && $role !== 'ADMIN') {
            throw new RuntimeException('You cannot remove your own Administrator access.');
        }
        $dealerId = $role === 'DEALER' ? trim((string) ($input['DealerID'] ?? '')) : '';
        if ($role === 'DEALER') $this->validateDealerUser($dealerId);
        $this->db->beginTransaction();
        try {
            $this->update('Users', 'UserID', $userId, ['Role'=>$role, 'DealerID'=>$dealerId ?: null, 'UpdatedAt'=>$this->now(), 'UpdatedBy'=>$this->current()]);
            $this->savePermissionKeys($userId, $role, (array) ($input['PermissionKeys'] ?? $this->defaultPermissionKeys($role)));
            $this->audit('ACCESS_UPDATE', 'Users', $userId, 'Role, linked dealer and module privileges updated.');
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        $row = $this->one('Users', 'UserID', $userId);
        return $this->publicUser($row ?: $target);
    }

    private function updateUserProfile(string $userId, array $input): array
    {
        $this->user(true);
        $target = $this->one('Users', 'UserID', $userId);
        if (!$target) throw new RuntimeException('User was not found.');
        [$name, $phone] = $this->validateUserProfile((string) ($input['Name'] ?? ''), (string) ($input['Phone'] ?? ''), $userId);
        $this->update('Users', 'UserID', $userId, ['Username'=>$phone, 'Name'=>$name, 'Phone'=>$phone, 'UpdatedAt'=>$this->now(), 'UpdatedBy'=>$this->current()]);
        $this->audit('UPDATE', 'Users', $userId, 'Official name and phone number updated.');
        return $this->users();
    }

    private function methodPermission(string $method): ?string
    {
        $map = [
            'getDashboardData'=>'dashboard',
            'getArchivedRecords'=>null,'archiveRecord'=>null,'getAdminDeleteImpact'=>null,'archiveRecordWithReversals'=>null,'restoreRecord'=>null,'getRecordChangeHistory'=>null,
            'getAgreements'=>'agreements','getAgreement'=>'agreements','createAgreement'=>'agreements','updateAgreement'=>'agreements','deleteAgreement'=>'agreements',
            'getPurchases'=>'purchases','getCompanySupplies'=>'purchases','getPurchaseOrderRegister'=>'purchases','getPurchaseOrdersReport'=>'reports','getPurchase'=>'purchases','getPurchaseOrderInvoiceReport'=>'reports','getCompanySupply'=>'purchases','getPurchaseProductPriority'=>'purchases','createPurchase'=>'purchases','createCompanySupply'=>'purchases','updatePurchaseOrder'=>'purchases','updateCompanySupply'=>'purchases','confirmPurchaseOrder'=>'purchases','confirmCompanySupply'=>'purchases','cancelPurchaseOrder'=>'purchases','cancelCompanySupply'=>'purchases','deletePurchaseOrder'=>'purchases',
            'getPendingGoodsReceiptCount'=>'goods_receipts','getGoodsReceiptRegister'=>'goods_receipts','saveGoodsReceipt'=>'goods_receipts','postGoodsReceipt'=>'goods_receipts','createGoodsReceiptReturn'=>'goods_receipts',
            'getInventoryProductRegister'=>'inventory','getInventoryStockReport'=>'reports',
            'getProductDamages'=>'product_damage','createProductDamage'=>'product_damage','reverseProductDamage'=>null,
            'getDealers'=>'dealers','getDealer'=>'dealers','createDealer'=>'dealers','updateDealer'=>'dealers','updateDealerCommissionRate'=>'dealers','changeDealerStatus'=>'dealers','getCustomerProfiles'=>'dealers','createCustomerProfile'=>'dealers',
            'getDealerTargetDashboard'=>'dealer_incentive','getDealerTargetConfig'=>'dealer_incentive','saveDealerTarget'=>'dealer_incentive',
            'getDealerSalesForceDashboard'=>'dealer_sales_management','saveDealerSalesRepresentative'=>'dealer_sales_management','saveDealerSRTarget'=>'dealer_sales_management','recordDealerSRSale'=>'dealer_sales_management',
            'getSales'=>'sales','getCustomerHistory'=>'sales','getDealerSales'=>'sales','getSale'=>'sales','getDealerSale'=>'sales','createSale'=>'sales','createDealerSale'=>'sales','updateSale'=>'sales','updateDealerSale'=>'sales','confirmDealerSale'=>'sales','deliverDealerSale'=>'sales',
            'getProductReturns'=>'returns','createProductReturn'=>'returns','createPurchaseReturn'=>'returns',
            'getPayments'=>'payments','createPayment'=>'payments','deletePayment'=>'payments',
            'getExpenses'=>'expenses','createExpense'=>'expenses',
            'getProducts'=>'product_config','getProductConfigurationRegister'=>'product_config','getProductDetails'=>'product_config','createProduct'=>'product_config','updateProduct'=>'product_config','changeProductStatus'=>'product_config',
            'getProductCategories'=>'product_config','getExpenseCategories'=>'accounting','getPaymentMethods'=>'product_config','createProductCategory'=>'product_config','createExpenseCategory'=>'accounting','updateExpenseCategory'=>'accounting','createPaymentMethod'=>'product_config','updateSettingItem'=>'product_config',
            'getReport'=>'reports',
            'getAccountingDashboard'=>'accounting','getAccountingSetup'=>'accounting','saveAccountingSetup'=>'accounting','getChartOfAccounts'=>'accounting','saveChartAccount'=>'accounting','getAccountingVouchers'=>'accounting','getAccountingVoucher'=>'accounting','createAccountingVoucher'=>'accounting','reverseAccountingVoucher'=>'accounting','getAccountingTrialBalance'=>'accounting','getAccountingAccountLedger'=>'accounting','getAccountingPartyLedger'=>'accounting','getSupplierPayableLedger'=>'accounting',
            'getEasyAccountSetup'=>'easy_accounts','getEasyAccountsDashboard'=>'easy_accounts','getEasyAccountEntry'=>'easy_accounts','createEasyAccountEntry'=>'easy_accounts','reverseEasyAccountEntry'=>'easy_accounts',
            'getUsers'=>'settings','createUser'=>'settings','saveUser'=>'settings','updateUserProfile'=>'settings','changeUserStatus'=>'settings','getAccessPolicy'=>'settings','saveUserAccess'=>'settings','updateBrandingSettings'=>'settings',
            'getHREmployees'=>'hr_management','createHRProfile'=>'hr_management','getHRProfileDetails'=>'hr_management','updateHRProfile'=>'hr_management','createHREmployee'=>'hr_management','updateHREmployee'=>'hr_management','changeHREmployeeStatus'=>'hr_management',
            'getHRAttendance'=>'hr_management','saveHRAttendance'=>'hr_management','getHRAchievements'=>'hr_management','createHRAchievement'=>'hr_management',
            'getHRSalesReports'=>'hr_management','postHRSalesReport'=>'hr_management',
            'getHRPayroll'=>'hr_management','generateHRPayroll'=>'hr_management','updateHRPayroll'=>'hr_management','updateHRPayrollBatch'=>'hr_management','approveHRPayroll'=>'hr_management','getHREmployeeProfile'=>'employee_portal',
            'recordHRDisbursement'=>null,'getDealerTeamSummary'=>null,
            'getHRLeaveData'=>'hr_management','createHRLeaveType'=>'hr_management','createHRLeaveRequest'=>'employee_portal','decideHRLeaveRequest'=>'hr_management',
            'getHRLoans'=>'hr_management','createHRLoanRequest'=>'employee_portal','decideHRLoan'=>'hr_management','recordHRLoanDisbursement'=>null,
            'getHRClaims'=>'hr_management','createHRClaim'=>'employee_portal','decideHRClaim'=>'hr_management','recordHRClaimPayment'=>null,
            'getHRPettyCash'=>null,'createHRPettyCashEntry'=>null,
        ];
        return $map[$method] ?? null;
    }

    private function authorizeMethod(string $method): void
    {
        $user = $this->user();
        $role = $this->normalizedRole((string) $user['Role']);
        if ($role === 'ADMIN') return;

        // These methods scope themselves to the authenticated user's own account,
        // except that the history handler allows an Administrator to view all rows.
        if (in_array($method, ['getLoginHistory','changeOwnPassword','updatePresence'], true)) return;

        if ($role === 'DEALER') {
            $dealerMethods = ['getLookupData','getSales','getDealerSales','getSale','getDealerSale','createSale','createDealerSale','updateSale','updateDealerSale','getDealerSalesForceDashboard','saveDealerSalesRepresentative','saveDealerSRTarget','recordDealerSRSale','getHRPayroll','recordHRDisbursement','getDealerTeamSummary','getHRLeaveData','decideHRLeaveRequest','getHRLoans','decideHRLoan','recordHRLoanDisbursement','getHRClaims','decideHRClaim','recordHRClaimPayment','getHRPettyCash','createHRPettyCashEntry'];
            if (!in_array($method, $dealerMethods, true) || !$this->dealerUserId($user)) {
                throw new RuntimeException('This Dealer User is only allowed to access its own Orders and Invoices.');
            }
            if (in_array($method, ['getHRPayroll','recordHRDisbursement','getDealerTeamSummary','getHRLeaveData','decideHRLeaveRequest','getHRLoans','decideHRLoan','recordHRLoanDisbursement','getHRClaims','decideHRClaim','recordHRClaimPayment','getHRPettyCash','createHRPettyCashEntry'], true)) return;
            $required = in_array($method, ['getDealerSalesForceDashboard','saveDealerSalesRepresentative','saveDealerSRTarget','recordDealerSRSale'], true) ? 'dealer_sales_management' : 'dealer_orders';
            if (!in_array($required, $this->configuredPermissionKeys($user), true)) {
                throw new RuntimeException('This Dealer User access is disabled by the Administrator.');
            }
            return;
        }

        if ($role === 'EMPLOYEE') {
            $employeeMethods=['getHREmployeeProfile','getHRLeaveData','createHRLeaveRequest','getHRLoans','createHRLoanRequest','getHRClaims','createHRClaim'];
            if (!in_array($method,$employeeMethods,true) || empty($user['EmployeeID'])) throw new RuntimeException('Employee login is limited to its linked personal HR profile and requests.');
            return;
        }

        if ($method === 'getLookupData') return;
        $permission = $this->methodPermission($method);
        if (!$permission || !in_array($permission, $this->configuredPermissionKeys($user), true)) {
            throw new RuntimeException('You do not have permission to access this module.');
        }
    }

    private function dealerOrderCatalog(): array
    {
        $rows = [];
        foreach ($this->products() as $product) {
            $detail = $this->product((string) $product['ProductID']);
            if (!$detail || strtoupper((string) $detail['Status']) === 'ARCHIVED') continue;
            $units = [];
            foreach ((array) ($detail['Units'] ?? []) as $unit) {
                if (strtoupper((string) ($unit['Status'] ?? 'ACTIVE')) === 'ARCHIVED' || empty($unit['SaleAllowed'])) continue;
                $units[] = [
                    'UnitName'=>(string) $unit['UnitName'], 'ConversionToBase'=>(float) $unit['ConversionToBase'],
                    'SaleAllowed'=>true, 'Status'=>'ACTIVE', 'SalePrice'=>(float) ($unit['SalePrice'] ?? $unit['UnitPrice'] ?? 0), 'UnitPrice'=>(float) ($unit['SalePrice'] ?? $unit['UnitPrice'] ?? 0),
                ];
            }
            $rows[] = ['ProductID'=>$detail['ProductID'],'SKU'=>$detail['SKU'],'ProductName'=>$detail['ProductName'],'Status'=>$detail['Status'],'BaseUnit'=>$detail['BaseUnit'],'UnitOfMeasure'=>$detail['UnitOfMeasure'],'Variant'=>$detail['Variant'],'Units'=>$units];
        }
        return $rows;
    }
}
