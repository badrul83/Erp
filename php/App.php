<?php
declare(strict_types=1);
require_once __DIR__ . '/DealerTargets.php';
require_once __DIR__ . '/DealerSalesForce.php';
require_once __DIR__ . '/Accounting.php';
require_once __DIR__ . '/EasyAccounts.php';
require_once __DIR__ . '/UserAccess.php';
require_once __DIR__ . '/HumanResources.php';

final class DepoAuthException extends RuntimeException {}

/**
 * cPanel/PHP implementation of the former Apps Script RPC layer.
 * All mutations use prepared statements and database transactions so the web
 * UI can keep its existing `run(method, args)` contract.
 */
final class DepoApp
{
    use DealerTargets, DealerSalesForce, Accounting, EasyAccounts, UserAccess, HumanResources;
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        date_default_timezone_set((string) ($config['timezone'] ?? 'Asia/Dhaka'));
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('depo_erp_session');
            $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_path' => '/', 'cookie_secure' => $https]);
        }
        $d = $config['db'] ?? [];
        $this->db = new PDO(
            'mysql:host=' . ($d['host'] ?? '127.0.0.1') . ';port=' . ($d['port'] ?? 3306) . ';dbname=' . ($d['name'] ?? '') . ';charset=' . ($d['charset'] ?? 'utf8mb4'),
            (string) ($d['user'] ?? ''), (string) ($d['pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $this->ensureDealerPhotoColumn();
        $this->ensureCustomerRegistrationSchema();
        $this->ensureDealerSalesFinanceSchema();
        $this->ensureProductOptionTypeSchema();
        $this->ensureProductPromotionSchema();
        $this->ensureTransactionPromotionSchema();
        $this->ensureProductImageSchema();
        $this->ensureSupplierProfileSchema();
        $this->ensureUserAccessSchema();
        $this->ensureDealerSalesForceSchema();
        $this->ensureEasyAccounts();
        $this->ensureHRSchema();
        $this->ensureRecordLifecycleSchema();
    }

    private function ensureRecordLifecycleSchema(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS RecordChangeHistory (
            ChangeID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, TableName VARCHAR(80) NOT NULL,
            RecordID VARCHAR(80) NOT NULL, ChangedAt DATETIME NOT NULL, ChangedBy VARCHAR(120) NOT NULL,
            ChangedFieldsJSON LONGTEXT NOT NULL, BeforeJSON LONGTEXT NOT NULL, AfterJSON LONGTEXT NOT NULL,
            INDEX record_history (TableName, RecordID), INDEX changed_at (ChangedAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS ArchivedRecords (
            ArchiveID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, TableName VARCHAR(80) NOT NULL,
            RecordID VARCHAR(80) NOT NULL, PreviousStatus VARCHAR(30) NOT NULL, ArchivedAt DATETIME NOT NULL,
            ArchivedBy VARCHAR(120) NOT NULL, Reason VARCHAR(500) NULL, RestoredAt DATETIME NULL,
            RestoredBy VARCHAR(120) NULL, INDEX archive_open (RestoredAt, ArchivedAt), INDEX archived_record (TableName, RecordID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS InventoryDamageReversals (
            DamageID VARCHAR(80) NOT NULL PRIMARY KEY,
            ReversalReferenceID VARCHAR(80) NOT NULL,
            ReversedAt DATETIME NOT NULL,
            ReversedBy VARCHAR(120) NOT NULL,
            Reason VARCHAR(500) NOT NULL,
            UNIQUE KEY inventory_damage_reversal_ref (ReversalReferenceID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS RecordReversalLinks (
            LinkID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ArchiveID BIGINT UNSIGNED NOT NULL,
            EntityTable VARCHAR(80) NOT NULL, EntityID VARCHAR(80) NOT NULL, Kind VARCHAR(20) NOT NULL,
            OriginalID VARCHAR(80) NOT NULL, ReversalID VARCHAR(80) NOT NULL, PreviousStatus VARCHAR(30) NULL,
            INDEX record_reversal_archive (ArchiveID), INDEX record_reversal_entity (EntityTable,EntityID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function ensureSupplierProfileSchema(): void
    {
        $columns = [
            'ContactPerson' => 'VARCHAR(160) NULL',
            'Phone' => 'VARCHAR(60) NULL',
            'AlternatePhone' => 'VARCHAR(60) NULL',
            'Email' => 'VARCHAR(190) NULL',
            'FactoryName' => 'VARCHAR(200) NULL',
            'FactoryAddress' => 'TEXT NULL',
            'OfficeAddress' => 'TEXT NULL',
            'TradeLicenseNo' => 'VARCHAR(120) NULL',
            'TradeLicenseExpiry' => 'DATE NULL',
            'BIN' => 'VARCHAR(80) NULL',
            'TIN' => 'VARCHAR(80) NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Agreements" AND COLUMN_NAME=?', [$name]);
            if (!$exists) $this->db->exec('ALTER TABLE Agreements ADD COLUMN `' . $name . '` ' . $definition);
        }
    }

    private function ensureDealerPhotoColumn(): void
    {
        $exists = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Dealers" AND COLUMN_NAME="DealerPhotoDataUrl"');
        if (!$exists) {
            $this->db->exec('ALTER TABLE Dealers ADD COLUMN DealerPhotoDataUrl MEDIUMTEXT NULL AFTER DealerCode');
        }
    }

    private function ensureCustomerRegistrationSchema(): void
    {
        $columns = [
            'DealerClass' => 'VARCHAR(20) NOT NULL DEFAULT "REGISTERED"',
            'CommissionRate' => 'DECIMAL(7,2) NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Dealers" AND COLUMN_NAME=?', [$name]);
            if (!$exists) $this->db->exec('ALTER TABLE Dealers ADD COLUMN `' . $name . '` ' . $definition);
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS CustomerProfiles (
            CustomerID VARCHAR(20) PRIMARY KEY, CustomerName VARCHAR(200) NOT NULL, Mobile VARCHAR(20) NOT NULL,
            ContactPerson VARCHAR(160) NULL, Area VARCHAR(120) NULL, Address TEXT NULL, NID VARCHAR(80) NULL,
            Email VARCHAR(190) NULL, TradeLicense VARCHAR(120) NULL, Status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
            CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL, UpdatedAt DATETIME NOT NULL, UpdatedBy VARCHAR(120) NOT NULL,
            UNIQUE KEY customer_mobile (Mobile), INDEX (CustomerName), INDEX (Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function ensureDealerSalesFinanceSchema(): void
    {
        $columns = [
            'PaymentMode' => 'VARCHAR(10) NOT NULL DEFAULT "DUE"',
            'CommissionRate' => 'DECIMAL(7,2) NULL',
            'CommissionAmount' => 'DECIMAL(16,2) NOT NULL DEFAULT 0',
        ];
        foreach ($columns as $name => $definition) {
            $exists = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="DealerSales" AND COLUMN_NAME=?', [$name]);
            if (!$exists) $this->db->exec('ALTER TABLE DealerSales ADD COLUMN `' . $name . '` ' . $definition);
        }
    }

    private function ensureProductOptionTypeSchema(): void
    {
        $exists = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Products" AND COLUMN_NAME="OptionType"');
        if (!$exists) $this->db->exec('ALTER TABLE Products ADD COLUMN OptionType VARCHAR(20) NOT NULL DEFAULT "VARIANT" AFTER Variant');
    }
    private function ensureProductPromotionSchema(): void
    {
        $columns = [
            'PromotionType' => 'VARCHAR(20) NOT NULL DEFAULT \'NON_CP\' AFTER OptionType',
            'PromotionGifts' => 'TEXT NULL AFTER PromotionType',
        ];
        foreach ($columns as $name => $definition) {
            $exists = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Products" AND COLUMN_NAME=?', [$name]);
            if (!$exists) $this->db->exec('ALTER TABLE Products ADD COLUMN `' . $name . '` ' . $definition);
        }
    }
    private function ensureTransactionPromotionSchema(): void
    {
        foreach (['CompanySupplyItems', 'DealerSaleItems'] as $table) {
            $promotionTypeAdded = false;
            $columns = [
                'PromotionType' => 'VARCHAR(20) NULL DEFAULT NULL AFTER Variant',
                'PromotionGifts' => 'TEXT NULL AFTER PromotionType',
            ];
            if ($table === 'CompanySupplyItems') $columns += [
                'OfferProductID' => 'VARCHAR(20) NULL AFTER PromotionGifts',
                'OfferProductName' => 'VARCHAR(160) NULL AFTER OfferProductID',
                'OfferVariant' => 'VARCHAR(160) NULL AFTER OfferProductName',
                'OfferUnitName' => 'VARCHAR(60) NULL AFTER OfferVariant',
                'OfferQuantity' => 'DECIMAL(16,4) NULL AFTER OfferUnitName',
                'OfferUnitPrice' => 'DECIMAL(16,2) NULL AFTER OfferQuantity',
                'OfferConversionToBase' => 'DECIMAL(16,6) NULL AFTER OfferUnitPrice',
                'OfferBaseQuantity' => 'DECIMAL(16,4) NULL AFTER OfferConversionToBase',
            ];
            foreach ($columns as $name => $definition) {
                $exists = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $name]);
                if (!$exists) {
                    $this->db->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' . $definition);
                    if ($name === 'PromotionType') $promotionTypeAdded = true;
                }
            }
            if ($promotionTypeAdded) $this->db->exec('UPDATE `' . $table . '` i LEFT JOIN Products p ON p.ProductID=i.ProductID SET i.PromotionType=CASE WHEN UPPER(COALESCE(p.PromotionType,"NON_CP"))="WITH_CP" THEN "WITH_CP" ELSE "NON_CP" END, i.PromotionGifts=CASE WHEN UPPER(COALESCE(p.PromotionType,"NON_CP"))="WITH_CP" THEN COALESCE(p.PromotionGifts,"[]") ELSE "[]" END WHERE i.PromotionType IS NULL');
        }
    }
    private function ensureProductImageSchema(): void
    {
        $exists = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Products" AND COLUMN_NAME="ProductImageDataUrl"');
        if (!$exists) $this->db->exec('ALTER TABLE Products ADD COLUMN ProductImageDataUrl MEDIUMTEXT NULL AFTER ProductName');
    }
    public function dispatch(string $method, array $args): mixed
    {
        $public = ['getAppInfo', 'getAuthState', 'loginUser', 'logoutUser', 'getBrandingSettings'];
        if (!in_array($method, $public, true)) {
            $this->user();
            $this->authorizeMethod($method);
        }
        return match ($method) {
            'getAppInfo' => ['version' => $this->config['version'] ?? '2.0.0', 'name' => $this->config['app_name'] ?? 'Distribution Commission ERP'],
            'getAuthState' => $this->authState(),
            'loginUser' => $this->login((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'logoutUser' => $this->logout(),
            'getLoginHistory' => $this->loginHistory(),
            'changeOwnPassword' => $this->changeOwnPassword((array) ($args[0] ?? [])),
            'updatePresence' => $this->updatePresence((string) ($args[0] ?? '')),
            'getOnlineUsers' => $this->onlineUsers(),
            'getBrandingSettings' => $this->branding(),
            'getArchivedRecords' => $this->getArchivedRecords(),
            'archiveRecord' => $this->archiveRecord((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (string) ($args[2] ?? '')),
            'getAdminDeleteImpact' => $this->adminDeleteImpact((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), false),
            'archiveRecordWithReversals' => $this->archiveRecordWithReversals((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (array) ($args[2] ?? []), (string) ($args[3] ?? '')),
            'restoreRecord' => $this->restoreRecord((string) ($args[0] ?? '')),
            'getRecordChangeHistory' => $this->getRecordChangeHistory((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'updateBrandingSettings' => $this->saveBranding((array) ($args[0] ?? [])),
            'getUsers' => $this->users(),
            'createUser' => $this->createUser((array) ($args[0] ?? [])),
            'saveUser' => $this->saveUser((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'updateUserProfile' => $this->updateUserProfile((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'changeUserStatus' => $this->changeUserStatus((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'getAccessPolicy' => $this->accessPolicy(),
            'saveUserAccess' => $this->saveUserAccess((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'getHREmployees' => $this->getHREmployees(),
            'createHRProfile' => $this->createHRProfile((array) ($args[0] ?? [])),
            'getHRProfileDetails' => $this->getHRProfileDetails((string) ($args[0] ?? '')),
            'updateHRProfile' => $this->updateHRProfile((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'createHREmployee' => $this->createHREmployee((array) ($args[0] ?? [])),
            'updateHREmployee' => $this->updateHREmployee((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'changeHREmployeeStatus' => $this->changeHREmployeeStatus((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'getHRAttendance' => $this->getHRAttendance((array) ($args[0] ?? [])),
            'saveHRAttendance' => $this->saveHRAttendance((array) ($args[0] ?? [])),
            'getHRAchievements' => $this->getHRAchievements((array) ($args[0] ?? [])),
            'createHRAchievement' => $this->createHRAchievement((array) ($args[0] ?? [])),
            'getHRSalesReports' => $this->getHRSalesReports((string) ($args[0] ?? date('Y-m'))),
            'postHRSalesReport' => $this->postHRSalesReport((array) ($args[0] ?? [])),
            'getHRPayroll' => $this->getHRPayroll((string) ($args[0] ?? date('Y-m'))),
            'generateHRPayroll' => $this->generateHRPayroll((string) ($args[0] ?? date('Y-m'))),
            'updateHRPayroll' => $this->updateHRPayroll((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'updateHRPayrollBatch' => $this->updateHRPayrollBatch((string) ($args[0] ?? date('Y-m')), (array) ($args[1] ?? [])),
            'approveHRPayroll' => $this->approveHRPayroll((string) ($args[0] ?? date('Y-m'))),
            'recordHRDisbursement' => $this->recordHRDisbursement((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'getHRLeaveData' => $this->getHRLeaveData((array) ($args[0] ?? [])),
            'createHRLeaveType' => $this->createHRLeaveType((array) ($args[0] ?? [])),
            'createHRLeaveRequest' => $this->createHRLeaveRequest((array) ($args[0] ?? [])),
            'decideHRLeaveRequest' => $this->decideHRLeaveRequest((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'getHRLoans' => $this->getHRLoans((array) ($args[0] ?? [])),
            'createHRLoanRequest' => $this->createHRLoanRequest((array) ($args[0] ?? [])),
            'decideHRLoan' => $this->decideHRLoan((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'recordHRLoanDisbursement' => $this->recordHRLoanDisbursement((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'getHRClaims' => $this->getHRClaims((array) ($args[0] ?? [])),
            'createHRClaim' => $this->createHRClaim((array) ($args[0] ?? [])),
            'decideHRClaim' => $this->decideHRClaim((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'recordHRClaimPayment' => $this->recordHRClaimPayment((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'getHRPettyCash' => $this->getHRPettyCash((array) ($args[0] ?? [])),
            'createHRPettyCashEntry' => $this->createHRPettyCashEntry((array) ($args[0] ?? [])),
            'getHREmployeeProfile' => $this->getHREmployeeProfile((string) ($args[0] ?? '')),
            'getDealerTeamSummary' => $this->getDealerTeamSummary(),
            'getLookupData' => $this->lookups(),
            'getAgreements' => $this->agreements(),
            'getAgreement' => $this->agreement((string) ($args[0] ?? '')),
            'createAgreement' => $this->createAgreement((array) ($args[0] ?? [])),
            'updateAgreement' => $this->updateAgreement((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'deleteAgreement' => $this->deleteAgreement((string) ($args[0] ?? '')),
            'getDealers' => $this->dealers(),
            'getCustomerProfiles' => $this->customerProfiles(),
            'createCustomerProfile' => $this->createCustomerProfile((array) ($args[0] ?? [])),
            'getDealerTargetDashboard' => $this->dealerTargetDashboard((array) ($args[0] ?? [])),
            'getDealerTargetConfig' => $this->dealerTargetConfig((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'saveDealerTarget' => $this->saveDealerTarget((array) ($args[0] ?? [])),
            'getDealerSalesForceDashboard' => $this->dealerSalesForceDashboard((array) ($args[0] ?? [])),
            'saveDealerSalesRepresentative' => $this->saveDealerSalesRepresentative((array) ($args[0] ?? [])),
            'saveDealerSRTarget' => $this->saveDealerSRTarget((array) ($args[0] ?? [])),
            'recordDealerSRSale' => $this->recordDealerSRSale((array) ($args[0] ?? [])),
            'getDealer' => $this->dealer((string) ($args[0] ?? '')),
            'getAccountingDashboard' => $this->accountingDashboard(),
            'getAccountingSetup' => $this->financeSetup(),
            'saveAccountingSetup' => $this->saveFinanceSetup((array) ($args[0] ?? [])),
            'getChartOfAccounts' => $this->chartAccounts(),
            'saveChartAccount' => $this->saveChartAccount((array) ($args[0] ?? [])),
            'getAccountingVouchers' => $this->accountingVouchers((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'getAccountingVoucher' => $this->accountingVoucher((string) ($args[0] ?? '')),
            'createAccountingVoucher' => $this->createAccountingVoucher((array) ($args[0] ?? [])),
            'reverseAccountingVoucher' => $this->reverseAccountingVoucher((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'getAccountingTrialBalance' => $this->accountingTrialBalance((string) ($args[0] ?? '')),
            'getAccountingAccountLedger' => $this->accountingAccountLedger((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (string) ($args[2] ?? '')),
            'getAccountingPartyLedger' => $this->accountingPartyLedger((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (string) ($args[2] ?? ''), (string) ($args[3] ?? '')),
            'getEasyAccountSetup' => $this->easyAccountSetup(),
            'getEasyAccountsDashboard' => $this->easyAccountsDashboard((array) ($args[0] ?? [])),
            'getEasyAccountEntry' => $this->easyEntry((string) ($args[0] ?? '')),
            'createEasyAccountEntry' => $this->createEasyAccountEntry((array) ($args[0] ?? [])),
            'reverseEasyAccountEntry' => $this->reverseEasyAccountEntry((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'createDealer' => $this->createDealer((array) ($args[0] ?? [])),
            'updateDealer' => $this->updateDealer((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'updateDealerCommissionRate' => $this->updateDealerCommissionRate((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'changeDealerStatus' => $this->changeDealerStatus((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'getProducts' => $this->products(),
            'getProductConfigurationRegister' => $this->productConfigurationRegister(),
            'getProductDetails' => $this->product((string) ($args[0] ?? '')),
            'createProduct' => $this->createProduct((array) ($args[0] ?? [])),
            'updateProduct' => $this->updateProduct((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'changeProductStatus' => $this->changeProductStatus((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'getInventoryProductRegister', 'getInventoryStockReport' => $this->inventory(),
            'getProductDamages' => $this->damages(),
            'createProductDamage' => $this->createDamage((array) ($args[0] ?? [])),
            'reverseProductDamage' => $this->reverseProductDamage((string) ($args[0] ?? ''), (string) ($args[1] ?? '')),
            'getPurchases', 'getCompanySupplies' => $this->supplies(),
            'getPurchaseOrderRegister', 'getPurchaseOrdersReport' => $this->purchaseOrderRegister(),
            'getPurchase', 'getCompanySupply', 'getPurchaseOrderInvoiceReport' => $this->supply((string) ($args[0] ?? '')),
            'createPurchase', 'createCompanySupply' => $this->createSupply((array) ($args[0] ?? [])),
            'updatePurchaseOrder', 'updateCompanySupply' => $this->updateSupply((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'confirmPurchaseOrder', 'confirmCompanySupply' => $this->supplyStatus((string) ($args[0] ?? ''), 'CONFIRMED'),
            'cancelPurchaseOrder', 'cancelCompanySupply' => $this->supplyStatus((string) ($args[0] ?? ''), 'CANCELLED'),
            'deletePurchaseOrder' => $this->deleteSupply((string) ($args[0] ?? '')),
            'getPendingGoodsReceiptCount' => $this->pendingGrnCount(),
            'getGoodsReceiptRegister' => $this->grnRegister(),
            'saveGoodsReceipt' => $this->saveGrn((array) ($args[0] ?? [])),
            'postGoodsReceipt' => $this->postGrn((string) ($args[0] ?? '')),
            'getSales', 'getDealerSales' => $this->sales(),
            'getCustomerHistory' => $this->customerHistory((string) ($args[0] ?? '')),
            'getSale', 'getDealerSale' => $this->sale((string) ($args[0] ?? '')),
            'createSale', 'createDealerSale' => $this->createSale((array) ($args[0] ?? [])),
            'updateSale', 'updateDealerSale' => $this->updateSale((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            'confirmDealerSale' => $this->saleStatus((string) ($args[0] ?? ''), 'CONFIRMED'),
            'deliverDealerSale' => $this->deliverSale((string) ($args[0] ?? '')),
            'getProductReturns' => $this->returns(),
            'createProductReturn' => $this->createReturn((array) ($args[0] ?? []), 'SALE'),
            'createPurchaseReturn' => $this->createReturn((array) ($args[0] ?? []), 'PURCHASE'),
            'getPayments' => $this->rows('Payments'),
            'createPayment' => throw new RuntimeException('Payments can only be posted through Easy Accounts.'),
            'deletePayment' => throw new RuntimeException('Posted payment history cannot be deleted. Reverse the linked Easy Accounts entry instead.'),
            'getExpenses' => $this->rows('Expenses','Status<>?', ['ARCHIVED']),
            'createExpense' => $this->createExpense((array) ($args[0] ?? [])),
            'getProductCategories' => $this->rows('ProductCategories'),
            'getExpenseCategories' => $this->rows('ExpenseCategories'),
            'getPaymentMethods' => $this->paymentMethods((bool) ($args[0] ?? false)),
            'createProductCategory' => $this->createSetting('ProductCategories', 'CAT', 'CategoryID', 'CategoryName', (string) ($args[0] ?? ''), false),
            'createExpenseCategory' => $this->createSetting('ExpenseCategories', 'EXC', 'ExpenseCategoryID', 'CategoryName', (string) ($args[0] ?? '')),
            'createPaymentMethod' => $this->createSetting('PaymentMethods', 'PMT', 'PaymentMethodID', 'MethodName', (string) ($args[0] ?? '')),
            'updateSettingItem' => $this->updateSetting((string) ($args[0] ?? ''), (string) ($args[1] ?? ''), (array) ($args[2] ?? [])),
            'getDashboardData' => $this->dashboard((string) ($args[0] ?? '')),
            'getReport' => $this->report((string) ($args[0] ?? ''), (array) ($args[1] ?? [])),
            default => throw new InvalidArgumentException('Unknown API method: ' . $method),
        };
    }

    private function rows(string $table, string $where = '', array $params = []): array
    {
        $sql = 'SELECT * FROM `' . $table . '`' . ($where ? ' WHERE ' . $where : '');
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }
    private function one(string $table, string $idColumn, string $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM `' . $table . '` WHERE `' . $idColumn . '` = ? LIMIT 1');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->db->prepare($sql); $statement->execute($params); return $statement->fetchColumn();
    }
    private function text(mixed $value): string { return trim((string) ($value ?? '')); }
    private function num(mixed $value, string $name, bool $zero = false): float
    {
        if ($this->text($value) === '') { if ($zero) return 0.0; throw new InvalidArgumentException($name . ' is required.'); }
        if (!is_numeric($value) || ($zero ? (float)$value < 0 : (float)$value <= 0)) throw new InvalidArgumentException($name . ' must be a valid positive number.');
        return (float) $value;
    }
    private function today(mixed $value = ''): string { $value = $this->text($value); return $value ?: date('Y-m-d'); }
    private function requiredDate(mixed $value, string $name): string
    {
        $date = $this->text($value);
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $parts) || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new InvalidArgumentException($name . ' is required and must be a valid date.');
        }
        return $date;
    }
    private function now(): string { return date('Y-m-d H:i:s'); }
    private function current(): string { return (string) ($_SESSION['depo_user']['Username'] ?? 'system'); }
    private function user(bool $admin = false): array
    {
        $session = $_SESSION['depo_user'] ?? null;
        if (!is_array($session)) throw new DepoAuthException('AUTH_REQUIRED: Please sign in first.');
        $user = $this->one('Users', 'UserID', (string) $session['UserID']);
        if (!$user || $user['Status'] !== 'ACTIVE') { unset($_SESSION['depo_user']); throw new DepoAuthException('AUTH_REQUIRED: Please sign in first.'); }
        $user['Role'] = $this->normalizedRole((string) $user['Role']);
        if ($admin && $user['Role'] !== 'ADMIN') throw new RuntimeException('Only an Administrator can perform this action.');
        return $user;
    }
    private function id(string $table, string $column, string $prefix): string
    {
        $max = 0;
        foreach ($this->value('SELECT `' . $column . '` FROM `' . $table . '`') ? $this->rows($table) : [] as $row) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', (string) $row[$column], $m)) $max = max($max, (int) $m[1]);
        }
        return $prefix . '-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }
    private function audit(string $action, string $module, string $record, string $description): void
    {
        $this->insert('AuditLog', ['LogID' => $this->id('AuditLog','LogID','LOG'), 'Timestamp' => $this->now(), 'UserEmail' => $this->current(), 'Action' => $action, 'Module' => $module, 'RecordID' => $record, 'Description' => $description]);
    }
    private function insert(string $table, array $row): void
    {
        $fields = array_keys($row);
        $this->db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')')->execute(array_values($row));
    }
    private function update(string $table, string $idColumn, string $id, array $row): void
    {
        $before = $this->one($table, $idColumn, $id);
        $sets = []; foreach ($row as $field => $value) $sets[] = '`' . $field . '` = ?';
        $statement = $this->db->prepare('UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE `' . $idColumn . '` = ?');
        $statement->execute([...array_values($row), $id]);
        if ($statement->rowCount() === 0 && !$this->one($table, $idColumn, $id)) throw new RuntimeException('Record was not found.');
        if ($before) {
            $sensitive = '/password|photo|image|logo|nid|account|bank|finger|token|secret|phone|mobile|email/i';
            $oldValues = []; $newValues = []; $changed = [];
            foreach ($row as $field => $value) {
                $old = $before[$field] ?? null;
                if ((string) ($old ?? '') === (string) ($value ?? '')) continue;
                $changed[] = $field;
                $oldValues[$field] = preg_match($sensitive, $field) ? '[redacted]' : $old;
                $newValues[$field] = preg_match($sensitive, $field) ? '[redacted]' : $value;
            }
            if ($changed) {
                $this->db->prepare('INSERT INTO RecordChangeHistory (TableName,RecordID,ChangedAt,ChangedBy,ChangedFieldsJSON,BeforeJSON,AfterJSON) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$table, $id, $this->now(), $this->current(), json_encode($changed, JSON_UNESCAPED_UNICODE), json_encode($oldValues, JSON_UNESCAPED_UNICODE), json_encode($newValues, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    private function recordDetailHistory(string $table, string $id, string $label, array $before, array $after): void
    {
        if (!$before || json_encode($before) === json_encode($after)) return;
        $this->db->prepare('INSERT INTO RecordChangeHistory (TableName,RecordID,ChangedAt,ChangedBy,ChangedFieldsJSON,BeforeJSON,AfterJSON) VALUES (?,?,?,?,?,?,?)')
            ->execute([$table,$id,$this->now(),$this->current(),json_encode([$label],JSON_UNESCAPED_UNICODE),json_encode([$label=>$before],JSON_UNESCAPED_UNICODE),json_encode([$label=>$after],JSON_UNESCAPED_UNICODE)]);
    }

    private function lifecycleTables(): array
    {
        return [
            'Agreements'=>'AgreementID','Dealers'=>'DealerID','CustomerProfiles'=>'CustomerID','Products'=>'ProductID',
            'ProductUnits'=>'ProductUnitID','ProductCategories'=>'CategoryID','ExpenseCategories'=>'ExpenseCategoryID',
            'PaymentMethods'=>'PaymentMethodID','Employees'=>'EmployeeID',
            'Users'=>'UserID','CompanySupplies'=>'SupplyID','GoodsReceipts'=>'GRNID','DealerSales'=>'SaleID',
            'Expenses'=>'ExpenseID','HRPayroll'=>'PayrollID','HRLeaveRequests'=>'LeaveRequestID',
            'HRLoans'=>'LoanID','HRExpenseClaims'=>'ClaimID','DealerSalesRepresentatives'=>'SRID'
        ];
    }

    private function archiveRecord(string $table, string $id, string $reason = ''): array
    {
        $this->user(true);
        if (in_array($table, ['Products','CompanySupplies','GoodsReceipts','DealerSales'], true)) throw new RuntimeException('Use the Administrator impact review so linked finance and stock records are reversed safely.');
        $keys = $this->lifecycleTables();
        if (!isset($keys[$table])) throw new InvalidArgumentException('This record type cannot be archived here. Use its correction or reversal workflow.');
        $idColumn = $keys[$table];
        $hasStatus = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME="Status"', [$table]);
        if (!$hasStatus) throw new RuntimeException('This record has no archive status; use its own workflow.');
        $record = $this->one($table, $idColumn, $id);
        if (!$record) throw new RuntimeException('Record was not found.');
        if (strtoupper((string) $record['Status']) === 'ARCHIVED') throw new RuntimeException('Record is already archived.');
        if ($table === 'Users' && (string) $record['UserID'] === (string) ($this->user()['UserID'] ?? '')) throw new RuntimeException('You cannot archive your own administrator account.');
        if ($table === 'Agreements' && (int)$this->value('SELECT COUNT(*) FROM CompanySupplies WHERE AgreementID=?',[$id])+(int)$this->value('SELECT COUNT(*) FROM StockLedger WHERE AgreementID=?',[$id])+(int)$this->value('SELECT COUNT(*) FROM DealerSales WHERE AgreementID=?',[$id])+(int)$this->value('SELECT COUNT(*) FROM Payments WHERE AgreementID=?',[$id])+(int)$this->value('SELECT COUNT(*) FROM Expenses WHERE AgreementID=?',[$id])+(int)$this->value('SELECT COUNT(*) FROM ProductReturns WHERE AgreementID=?',[$id])>0) throw new RuntimeException('Supplier has linked purchase, stock or payment history. Keep the supplier record for those references and update its agreement dates instead.');
        if ($table === 'Dealers') {
            foreach($this->rows('DealerSales','DealerID=?',[$id]) as $linkedSale) if($this->salePayment($linkedSale)['due']>0.009) throw new RuntimeException('Dealer has unpaid invoices. Settle the due balance before archiving this dealer.');
        }
        if ($table === 'Employees' && (int)$this->value('SELECT COUNT(*) FROM HRPayroll WHERE EmployeeID=? AND Status IN ("REVIEW","APPROVED")',[$id])>0) throw new RuntimeException('Employee has a pending or approved salary record. Complete payroll before archiving the employee.');
        if ($table === 'HRPayroll') throw new RuntimeException('Payroll must be corrected in Salary Payable. Paid and approved salary records are retained as payroll history.');
        if ($table === 'HRLoans' && strtoupper((string)$record['Status']) === 'DISBURSED') throw new RuntimeException('Disbursed employee loans are financial records and cannot be archived.');
        if ($table === 'HRExpenseClaims' && strtoupper((string)$record['Status']) === 'PAID') throw new RuntimeException('Paid employee claims are financial records and cannot be archived.');
        if ($table === 'HRLeaveRequests' && !in_array(strtoupper((string)$record['Status']), ['PENDING','REJECTED'], true)) throw new RuntimeException('Approved leave affects attendance and salary. Keep it in HR history and correct it through the Leave workflow.');
        $previous = (string)$record['Status'];
        $this->db->beginTransaction();
        try {
            if ($table === 'Employees' && !empty($record['UserID'])) {
                $linkedUser=$this->one('Users','UserID',(string)$record['UserID']);
                if($linkedUser&&strtoupper((string)$linkedUser['Status'])==='ACTIVE')$this->update('Users','UserID',(string)$record['UserID'],['Status'=>'INACTIVE','UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);
            }
            $this->update($table, $idColumn, $id, ['Status'=>'ARCHIVED']);
            $this->db->prepare('INSERT INTO ArchivedRecords (TableName,RecordID,PreviousStatus,ArchivedAt,ArchivedBy,Reason) VALUES (?,?,?,?,?,?)')->execute([$table,$id,$previous,$this->now(),$this->current(),$this->text($reason) ?: null]);
            $this->audit('ARCHIVE',$table,$id,$reason ?: 'Record archived by administrator.');
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        return ['TableName'=>$table,'RecordID'=>$id,'Status'=>'ARCHIVED'];
    }

    private function adminDeleteImpact(string $table,string $id,bool $includeRefs=true):array
    {
        $this->user(true);$table=trim($table);$id=$this->text($id);$keys=$this->lifecycleTables();if(!in_array($table,['DealerSales','CompanySupplies','GoodsReceipts','Products'],true)||!isset($keys[$table])||$id==='')throw new InvalidArgumentException('Select a supported Inventory, Sales, Purchase Order or GRN record.');$record=$this->one($table,$keys[$table],$id);if(!$record||strtoupper((string)$record['Status'])==='ARCHIVED')throw new RuntimeException('Active record was not found.');$groups=[];$refs=['Stock'=>[],'Vouchers'=>[],'Payments'=>[],'EasyEntries'=>[]];$add=function($key,$label,$count,$amount,$quantity=0,$note='')use(&$groups){if($count>0)$groups[]=['Key'=>$key,'Label'=>$label,'Count'=>(int)$count,'Amount'=>round((float)$amount,2),'Quantity'=>round((float)$quantity,4),'Note'=>$note];};
        if($table==='Products'){$linked=(int)$this->value('SELECT COUNT(*) FROM DealerSaleItems WHERE ProductID=?',[$id])+(int)$this->value('SELECT COUNT(*) FROM CompanySupplyItems WHERE ProductID=?',[$id])+(int)$this->value('SELECT COUNT(*) FROM GoodsReceiptItems WHERE ProductID=?',[$id])+(int)$this->value('SELECT COUNT(*) FROM ProductReturnItems WHERE ProductID=?',[$id]);$stock=(float)$this->value('SELECT COALESCE(SUM(BaseQuantityIn-BaseQuantityOut),0) FROM StockLedger WHERE ProductID=?',[$id]);if($linked||abs($stock)>0.000001)throw new RuntimeException('Product still has transaction or stock history. First correct the source documents and bring its stock to zero.');$add('record','Product and configured units',1+(int)$this->value('SELECT COUNT(*) FROM ProductUnits WHERE ProductID=?',[$id]),0);}
        if($table==='DealerSales'){$returns=$this->rows('ProductReturns','SaleID=?',[$id]);$returnIds=array_column($returns,'ReturnID');$stock=$this->rows('StockLedger','TransactionType="DEALER_SALE" AND ReferenceID=?',[$id]);foreach($stock as$row)$refs['Stock'][]=$row;foreach($returnIds as$returnId)foreach($this->rows('StockLedger','ReferenceID=? AND TransactionType="SALE_RETURN"',[$returnId])as$row)$refs['Stock'][]=$row;$payments=$this->rows('Payments','SaleID=? AND Status="POSTED"',[$id]);foreach($payments as$payment)$refs['Payments'][]=$payment;$accountVouchers=$this->rows('AccountingVouchers','SourceType="DEALER_SALE" AND SourceID=? AND Status="POSTED"',[$id]);foreach($accountVouchers as$voucher)$refs['Vouchers'][]=$voucher;$refs['EasyEntries']=$this->linkedEasyEntries($payments);$add('record','Invoice and sale lines',1+(int)$this->value('SELECT COUNT(*) FROM DealerSaleItems WHERE SaleID=?',[$id]),$record['TotalAmount']);$add('payments','Posted customer payments',count($payments),array_sum(array_column($payments,'Amount')));$add('stock','Sale and linked return stock movements',count($refs['Stock']),array_sum(array_map(fn($r)=>(float)$r['BaseQuantityOut']*(float)$r['UnitCost'], $refs['Stock'])),array_sum(array_map(fn($r)=>(float)$r['BaseQuantityOut']-(float)$r['BaseQuantityIn'],$refs['Stock'])));$add('returns','Linked return documents',count($returns),array_sum(array_column($returns,'TotalAmount')));$add('vouchers','Sale vouchers',count($accountVouchers),array_sum(array_column($accountVouchers,'TotalDebit')));$add('easy','Payment accounting entries',count($refs['EasyEntries']),array_sum(array_column($refs['EasyEntries'],'Amount')));}
        if($table==='CompanySupplies'){$receipts=$this->rows('GoodsReceipts','SupplyID=? AND Status="POSTED"',[$id]);foreach($receipts as$receipt)foreach($this->rows('StockLedger','TransactionType="GOODS_RECEIPT" AND ReferenceID LIKE ?',[$receipt['GRNID'].':%'])as$row)$refs['Stock'][]=$row;$payments=$this->rows('Payments','SupplyID=? AND Status="POSTED"',[$id]);foreach($payments as$payment)$refs['Payments'][]=$payment;$refs['EasyEntries']=$this->linkedEasyEntries($payments);$add('record','Purchase order and lines',1+(int)$this->value('SELECT COUNT(*) FROM CompanySupplyItems WHERE SupplyID=?',[$id]),$record['TotalAmount']);$add('receipts','Posted goods receipts',count($receipts),array_sum(array_map(fn($r)=>(float)$this->value('SELECT COALESCE(SUM(BaseAcceptedQuantity*UnitCost),0) FROM GoodsReceiptItems WHERE GRNID=?',[$r['GRNID']]),$receipts)));$add('payments','Posted supplier payments',count($payments),array_sum(array_column($payments,'Amount')));$add('stock','Receipt stock movements',count($refs['Stock']),array_sum(array_map(fn($r)=>(float)$r['BaseQuantityIn']*(float)$r['UnitCost'],$refs['Stock'])),array_sum(array_column($refs['Stock'],'BaseQuantityIn')));$add('easy','Payment accounting entries',count($refs['EasyEntries']),array_sum(array_column($refs['EasyEntries'],'Amount')));}
        if($table==='GoodsReceipts'){$refs['Stock']=$this->rows('StockLedger','TransactionType="GOODS_RECEIPT" AND ReferenceID LIKE ?',[$id.':%']);$items=$this->rows('GoodsReceiptItems','GRNID=?',[$id]);$lineNotes=[];foreach($items as$item){$product=$this->product((string)$item['ProductID']);$lineNotes[]=($product['ProductName']??$item['ProductID']).' × '.(float)$item['AcceptedQuantity'].' '.(string)$item['UnitName'];}$add('record','GRN '.$id.' header and all receipt lines',1+count($items),(float)$this->value('SELECT COALESCE(SUM(BaseAcceptedQuantity*UnitCost),0) FROM GoodsReceiptItems WHERE GRNID=?',[$id]),0,implode(' · ',$lineNotes));$add('stock','Receipt stock movements',count($refs['Stock']),array_sum(array_map(fn($r)=>(float)$r['BaseQuantityIn']*(float)$r['UnitCost'],$refs['Stock'])),array_sum(array_column($refs['Stock'],'BaseQuantityIn')));}
        $result=['TableName'=>$table,'RecordID'=>$id,'Title'=>['DealerSales'=>'Sales invoice','CompanySupplies'=>'Purchase order','GoodsReceipts'=>'Goods receipt','Products'=>'Product'][$table],'Groups'=>$groups];if($includeRefs)$result['Refs']=$refs;return$result;
    }

    private function linkedEasyEntries(array $payments):array
    {
        $out=[];foreach($payments as$payment){$id=(string)($payment['EasyEntryID']??'');if($id==='')continue;$entry=$this->one('EasyAccountEntries','EasyEntryID',$id);if($entry&&$entry['Status']==='POSTED')$out[$id]=$entry;}return array_values($out);
    }

    private function syncSupplyReceiptStatus(string $supplyId):void
    {
        $supply=$this->one('CompanySupplies','SupplyID',$supplyId);if(!$supply)return;$items=$this->rows('CompanySupplyItems','SupplyID=?',[$supplyId]);$hasAny=false;$all=!empty($items);foreach($items as$item){$accepted=(float)$this->value('SELECT COALESCE(SUM(i.BaseAcceptedQuantity),0) FROM GoodsReceiptItems i JOIN GoodsReceipts r ON r.GRNID=i.GRNID WHERE i.SupplyItemID=? AND r.Status="POSTED"',[$item['SupplyItemID']]);if($accepted>0.000001)$hasAny=true;if($accepted+0.000001<(float)$item['BaseQuantity'])$all=false;}$status=$all?'RECEIVED':($hasAny?'PARTIALLY_RECEIVED':'CONFIRMED');$this->update('CompanySupplies','SupplyID',$supplyId,['Status'=>$status,'PaymentStatus'=>$this->paymentStatus((float)$supply['TotalAmount'],$this->supplyPaid($supplyId)),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);
    }

    private function reverseVoucherInTransaction(string$id,string$date):string
    {
        $original=$this->one('AccountingVouchers','VoucherID',$id);if(!$original||$original['Status']!=='POSTED')return'';if($this->value('SELECT COUNT(*) FROM AccountingVouchers WHERE ReversalOf=? AND Status="POSTED"',[$id]))return'';$this->accountingOpenPeriod($date);$lines=$this->rows('AccountingVoucherLines','VoucherID=? ORDER BY LineNo',[$id]);$reverse=[];foreach($lines as$line)$reverse[]=['AccountID'=>$line['AccountID'],'Debit'=>(float)$line['Credit'],'Credit'=>(float)$line['Debit'],'PartyType'=>$line['PartyType'],'PartyID'=>$line['PartyID'],'ReferenceNo'=>$line['ReferenceNo'],'Narration'=>'Reversal: '.(string)$line['Narration']];$result=AccountingRules::lines($reverse);$new=$this->id('AccountingVouchers','VoucherID','VCH');$now=$this->now();$this->insert('AccountingVouchers',['VoucherID'=>$new,'VoucherDate'=>$date,'VoucherType'=>'RV','Status'=>'POSTED','SourceType'=>'REVERSAL','SourceID'=>$id,'SourceNo'=>'Reversal of '.$id,'PartyType'=>$original['PartyType'],'PartyID'=>$original['PartyID'],'Narration'=>'Administrative archive reversal of '.$id,'TotalDebit'=>$result['TotalDebit'],'TotalCredit'=>$result['TotalCredit'],'ReversalOf'=>$id,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'PostedAt'=>$now,'PostedBy'=>$this->current()]);foreach($result['Lines']as$n=>$line)$this->insert('AccountingVoucherLines',['VoucherLineID'=>$this->id('AccountingVoucherLines','VoucherLineID','VLI'),'VoucherID'=>$new,'LineNo'=>$n+1]+$line+['CreatedAt'=>$now,'CreatedBy'=>$this->current()]);return$new;
    }

    private function archiveRecordWithReversals(string$table,string$id,array$selected,string$reason=''):array
    {
        $this->user(true);$impact=$this->adminDeleteImpact($table,$id,true);$required=array_column($impact['Groups'],'Key');$selected=array_values(array_unique(array_map('strval',$selected)));foreach($required as$key)if(!in_array($key,$selected,true))throw new RuntimeException('Check each listed impact before continuing. Nothing was archived.');$this->db->beginTransaction();try{$date=$this->today();$refs=$impact['Refs'];$map=[];
            foreach($refs['EasyEntries']as$entry){$reversal=$this->reverseVoucherInTransaction((string)$entry['VoucherID'],$date);if($reversal){$map[]=['Kind'=>'VOUCHER','OriginalID'=>$entry['VoucherID'],'ReversalID'=>$reversal];$this->update('EasyAccountEntries','EasyEntryID',(string)$entry['EasyEntryID'],['Status'=>'REVERSED','ReversalVoucherID'=>$reversal,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$map[]=['Kind'=>'EASY','OriginalID'=>$entry['EasyEntryID'],'ReversalID'=>$reversal,'PreviousStatus'=>'POSTED'];}}
            foreach($refs['Payments']as$payment){if(strtoupper((string)$payment['Status'])==='POSTED'){$this->update('Payments','PaymentID',(string)$payment['PaymentID'],['Status'=>'REVERSED']);$map[]=['Kind'=>'PAYMENT','OriginalID'=>$payment['PaymentID'],'ReversalID'=>'','PreviousStatus'=>'POSTED'];}}
            foreach($refs['Vouchers']as$voucher){$reversal=$this->reverseVoucherInTransaction((string)$voucher['VoucherID'],$date);if($reversal)$map[]=['Kind'=>'VOUCHER','OriginalID'=>$voucher['VoucherID'],'ReversalID'=>$reversal];}
            foreach($refs['Stock']as$row){$in=(float)$row['BaseQuantityOut'];$out=(float)$row['BaseQuantityIn'];$ref=$this->id('StockLedger','ReferenceID','ARC');$this->recordStock((string)$row['AgreementID'],(string)$row['ProductID'],$date,'ARCHIVE_REVERSAL',$ref,(string)$row['UnitName'],$in,$out,(float)$row['UnitCost'],'Administrative archive reversal of '.$row['TransactionType'].' '.$row['ReferenceID'],(bool)$row['IsGift'],(string)($row['Variant']??''));$map[]=['Kind'=>'STOCK','OriginalID'=>$row['LedgerID'],'ReversalID'=>$ref];}
            $keys=$this->lifecycleTables();$record=$this->one($table,$keys[$table],$id);if(!$record)throw new RuntimeException('Record was not found.');$previous=(string)$record['Status'];$this->update($table,$keys[$table],$id,['Status'=>'ARCHIVED']);if($table==='GoodsReceipts')$this->syncSupplyReceiptStatus((string)$record['SupplyID']);$archiveReason=$this->text($reason);if($archiveReason!=='')$archiveReason.=' ';$archiveReason.='Admin archived after impact review; linked payments, accounting vouchers and stock movements were reversed.';$this->db->prepare('INSERT INTO ArchivedRecords (TableName,RecordID,PreviousStatus,ArchivedAt,ArchivedBy,Reason) VALUES (?,?,?,?,?,?)')->execute([$table,$id,$previous,$this->now(),$this->current(),$archiveReason]);$archiveId=(int)$this->db->lastInsertId();foreach($map as$link)$this->insert('RecordReversalLinks',['ArchiveID'=>$archiveId,'EntityTable'=>$table,'EntityID'=>$id,'Kind'=>$link['Kind'],'OriginalID'=>$link['OriginalID'],'ReversalID'=>$link['ReversalID'],'PreviousStatus'=>$link['PreviousStatus']??null]);$this->audit('ARCHIVE',$table,$id,$archiveReason);$this->db->commit();return ['ArchiveID'=>$archiveId,'TableName'=>$table,'RecordID'=>$id,'Status'=>'ARCHIVED','ReversedCount'=>count($map)];
        }catch(Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }

    private function restoreRecord(string $archiveId): array
    {
        $this->user(true);
        $statement=$this->db->prepare('SELECT * FROM ArchivedRecords WHERE ArchiveID=? AND RestoredAt IS NULL LIMIT 1');$statement->execute([$archiveId]);$archive=$statement->fetch();
        if (!$archive) throw new RuntimeException('Active archived record was not found.');
        $keys=$this->lifecycleTables();$table=(string)$archive['TableName'];if(!isset($keys[$table]))throw new RuntimeException('This record type cannot be restored here.');
        $current=$this->one($table,$keys[$table],(string)$archive['RecordID']);if(!$current||strtoupper((string)($current['Status']??''))!=='ARCHIVED')throw new RuntimeException('The archived record is no longer in the expected state.');
        if($table==='Users'&&strtoupper((string)$archive['PreviousStatus'])==='ACTIVE'&&(int)$this->value('SELECT COUNT(*) FROM Users WHERE Role="ADMIN" AND Status="ACTIVE"')===0)throw new RuntimeException('At least one active administrator must remain.');
        $this->db->beginTransaction();try{$links=$this->rows('RecordReversalLinks','ArchiveID=? ORDER BY LinkID',[(int)$archiveId]);foreach($links as$link){if($link['Kind']==='STOCK'){$reversed=$this->one('StockLedger','ReferenceID',(string)$link['ReversalID']);if($reversed){$ref=$this->id('StockLedger','ReferenceID','RST');$this->recordStock((string)$reversed['AgreementID'],(string)$reversed['ProductID'],$this->today(),'ARCHIVE_RESTORE',$ref,(string)$reversed['UnitName'],(float)$reversed['BaseQuantityOut'],(float)$reversed['BaseQuantityIn'],(float)$reversed['UnitCost'],'Restore of archived '.$table.' '.$archive['RecordID'],(bool)$reversed['IsGift'],(string)($reversed['Variant']??''));}}elseif($link['Kind']==='VOUCHER'){$this->reverseVoucherInTransaction((string)$link['ReversalID'],$this->today());}elseif($link['Kind']==='PAYMENT'){$this->update('Payments','PaymentID',(string)$link['OriginalID'],['Status'=>$link['PreviousStatus']?:'POSTED']);}elseif($link['Kind']==='EASY'){$entry=$this->one('EasyAccountEntries','EasyEntryID',(string)$link['OriginalID']);if($entry&&$entry['Status']==='REVERSED')$this->update('EasyAccountEntries','EasyEntryID',(string)$link['OriginalID'],['Status'=>$link['PreviousStatus']?:'POSTED','ReversalVoucherID'=>null,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);}}$this->update($table,$keys[$table],(string)$archive['RecordID'],['Status'=>$archive['PreviousStatus']]);if($table==='GoodsReceipts')$this->syncSupplyReceiptStatus((string)$current['SupplyID']);$this->db->prepare('UPDATE ArchivedRecords SET RestoredAt=?,RestoredBy=? WHERE ArchiveID=?')->execute([$this->now(),$this->current(),$archiveId]);$this->audit('RESTORE',$table,(string)$archive['RecordID'],'Record restored; stock and accounting reversals were reversed where possible.');$this->db->commit();}catch(Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
        return ['ArchiveID'=>(int)$archiveId,'TableName'=>$table,'RecordID'=>$archive['RecordID'],'Status'=>$archive['PreviousStatus']];
    }

    private function restoreLatestRecord(string $table, string $id): array
    {
        $this->user(true);$statement=$this->db->prepare('SELECT ArchiveID FROM ArchivedRecords WHERE TableName=? AND RecordID=? AND RestoredAt IS NULL ORDER BY ArchiveID DESC LIMIT 1');$statement->execute([$table,$id]);$archiveId=$statement->fetchColumn();
        if(!$archiveId)throw new RuntimeException('Use Settings → Archive & Change History to restore this record.');
        return $this->restoreRecord((string)$archiveId);
    }

    private function getArchivedRecords(): array
    {
        $this->user(true);
        return $this->rows('ArchivedRecords','RestoredAt IS NULL ORDER BY ArchivedAt DESC');
    }

    private function getRecordChangeHistory(string $table, string $id): array
    {
        $this->user(true);
        $statement=$this->db->prepare('SELECT * FROM RecordChangeHistory WHERE TableName=? AND RecordID=? ORDER BY ChangedAt DESC LIMIT 200');$statement->execute([$table,$id]);return $statement->fetchAll();
    }

    private function authState(): array
    {
        try { $user = $this->user(); return ['authenticated' => true, 'hasUsers' => true, 'user' => $this->publicUser($user)]; }
        catch (DepoAuthException) { return ['authenticated' => false, 'hasUsers' => (bool) $this->value('SELECT COUNT(*) FROM Users'), 'user' => null]; }
    }
    private function publicUser(array $row): array
    {
        $profile = $this->permissionProfile($row);
        return ['UserID'=>$row['UserID'], 'Username'=>$row['Username'], 'Name'=>$row['Name'], 'Phone'=>$row['Phone'] ?? '', 'ProfilePhotoDataUrl'=>$row['ProfilePhotoDataUrl'] ?? '', 'Role'=>$profile['Role'], 'DealerID'=>$profile['DealerID'], 'EmployeeID'=>$profile['EmployeeID'], 'PermissionKeys'=>$profile['PermissionKeys'], 'Status'=>$row['Status']];
    }
    private function login(string $username, string $password): array
    {
        $identifier = strtolower($this->text($username));
        if (!$identifier || !$this->text($password)) throw new InvalidArgumentException('Username or phone number and password are required.');
        $statement = $this->db->prepare('SELECT * FROM Users WHERE LOWER(Username) = ? LIMIT 1'); $statement->execute([$identifier]); $user = $statement->fetch();
        if (!$user) {
            $phone = $this->normalizeUserPhone($identifier);
            if ($phone !== '') { $statement = $this->db->prepare('SELECT * FROM Users WHERE Phone = ? LIMIT 1'); $statement->execute([$phone]); $user = $statement->fetch(); if (!$user) { foreach ($this->rows('Users') as $candidate) { if ($this->normalizeUserPhone((string) ($candidate['Phone'] ?? '')) === $phone) { $user = $candidate; break; } } } }
        }
        if (!$user || $user['Status'] !== 'ACTIVE' || !password_verify($password, $user['PasswordHash'])) throw new DepoAuthException('Invalid username/phone or password.');
        session_regenerate_id(true);
        $_SESSION['depo_user'] = $this->publicUser($user);
        $this->recordLogin($user, $username);
        return $this->publicUser($user);
    }
    private function recordLogin(array $user, string $loginId): void
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) $ip = '';
        $publicIp = $this->validatedPublicIp($ip);
        $agent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500);
        $this->db->prepare('INSERT INTO UserLoginHistory (UserID, UserName, LoginID, SessionKey, LoginAt, IPAddress, UserAgent) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([(string) $user['UserID'], (string) $user['Name'], substr($loginId, 0, 120), hash('sha256', session_id()), $this->now(), $publicIp ?: ($ip ?: null), $agent ?: null]);
    }
    private function loginHistory(): array
    {
        $user = $this->user();
        $admin = $this->normalizedRole((string) ($user['Role'] ?? '')) === 'ADMIN';
        $sql = 'SELECT h.LoginHistoryID,h.UserID,h.UserName,h.LoginID,h.LoginAt,h.IPAddress,h.UserAgent,u.Role FROM UserLoginHistory h LEFT JOIN Users u ON u.UserID=h.UserID';
        $params = [];
        if (!$admin) { $sql .= ' WHERE h.UserID=?'; $params[] = (string) $user['UserID']; }
        $sql .= ' ORDER BY h.LoginAt DESC,h.LoginHistoryID DESC LIMIT 3';
        $query = $this->db->prepare($sql); $query->execute($params);
        return ['IsAdministratorView'=>$admin,'Rows'=>$query->fetchAll()];
    }
    private function changeOwnPassword(array $input): bool
    {
        $user = $this->user();
        $userId = (string) $user['UserID'];
        $current = $this->text($input['CurrentPassword'] ?? '');
        $password = $this->text($input['Password'] ?? '');
        $confirmation = $this->text($input['ConfirmPassword'] ?? '');
        $row = $this->one('Users', 'UserID', $userId);
        if (!$row || !password_verify($current, (string) ($row['PasswordHash'] ?? ''))) throw new InvalidArgumentException('Current password is incorrect.');
        if (strlen($password) < 6) throw new InvalidArgumentException('New password must be at least 6 characters.');
        if (!hash_equals($password, $confirmation)) throw new InvalidArgumentException('New password and confirmation do not match.');
        $this->update('Users', 'UserID', $userId, ['PasswordHash'=>password_hash($password, PASSWORD_DEFAULT), 'UpdatedAt'=>$this->now(), 'UpdatedBy'=>$userId]);
        $oldSessionKey = hash('sha256', session_id());
        session_regenerate_id(true);
        $this->db->prepare('DELETE FROM UserOnlineSessions WHERE SessionKey=?')->execute([$oldSessionKey]);
        $this->updatePresence();
        $this->audit('PASSWORD_CHANGE', 'Users', $userId, 'User changed their own password.');
        return true;
    }
    private function logout(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
            $this->db->prepare('DELETE FROM UserOnlineSessions WHERE SessionKey=?')->execute([hash('sha256', session_id())]);
        }
        $_SESSION = []; session_destroy(); return true;
    }
    private function validatedPublicIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '';
        return $ip;
    }
    private function updatePresence(string $reportedPublicIp = ''): bool
    {
        $user = $this->user();
        $sessionKey = hash('sha256', session_id());
        $remoteIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remoteIp !== '' && !filter_var($remoteIp, FILTER_VALIDATE_IP)) $remoteIp = '';
        $ip = $this->validatedPublicIp($remoteIp) ?: $this->validatedPublicIp($reportedPublicIp) ?: $remoteIp;
        $agent = substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500);
        $this->db->prepare('INSERT INTO UserOnlineSessions (SessionKey, UserID, UserName, LoginID, IPAddress, UserAgent, LastSeen) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE UserID=VALUES(UserID),UserName=VALUES(UserName),LoginID=VALUES(LoginID),IPAddress=VALUES(IPAddress),UserAgent=VALUES(UserAgent),LastSeen=VALUES(LastSeen)')
            ->execute([$sessionKey, (string) $user['UserID'], (string) $user['Name'], (string) ($user['Phone'] ?? $user['Username'] ?? ''), $ip ?: null, $agent ?: null, $this->now()]);
        if ($this->validatedPublicIp($ip) !== '') {
            $this->db->prepare('UPDATE UserLoginHistory SET IPAddress=? WHERE SessionKey=? OR (SessionKey IS NULL AND UserID=? AND LoginAt >= DATE_SUB(?, INTERVAL 10 MINUTE))')->execute([$ip, $sessionKey, (string) $user['UserID'], $this->now()]);
        }
        $this->db->prepare('DELETE FROM UserOnlineSessions WHERE LastSeen < DATE_SUB(?, INTERVAL 1 DAY)')->execute([$this->now()]);
        return true;
    }
    private function onlineUsers(): array
    {
        $user = $this->user();
        if ($this->normalizedRole((string) ($user['Role'] ?? '')) !== 'ADMIN') throw new RuntimeException('Only an Administrator can view currently online users.');
        $query = $this->db->prepare('SELECT UserID,UserName,LoginID,IPAddress,UserAgent,LastSeen FROM UserOnlineSessions WHERE LastSeen >= DATE_SUB(?, INTERVAL 90 SECOND) ORDER BY LastSeen DESC');
        $query->execute([$this->now()]); $rows = $query->fetchAll();
        $unique = [];
        foreach ($rows as $row) $unique[(string) $row['UserID']] = true;
        return ['UsersCount'=>count($unique),'SessionsCount'=>count($rows),'Rows'=>$rows,'WindowSeconds'=>90];
    }
    private function userPhotoDataUrl(mixed $value): ?string
    {
        $photo = trim((string) ($value ?? ''));
        if ($photo === '') return null;
        if (strlen($photo) > 81920 || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#i', $photo, $match)) {
            throw new InvalidArgumentException('Profile photo must be a valid JPEG, PNG or WebP image below 60 KB.');
        }
        $bytes = base64_decode($match[2], true);
        $image = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($bytes === false || strlen($bytes) > 61440 || !$image || !in_array((string) ($image['mime'] ?? ''), ['image/jpeg','image/png','image/webp'], true)) {
            throw new InvalidArgumentException('Profile photo could not be verified as a supported image below 60 KB.');
        }
        return $photo;
    }
    private function users(): array { $this->user(true); return array_map(fn(array $row) => $this->publicUser($row), $this->rows('Users')); }
    private function createUser(array $input): array
    {
        $this->user(true); $password = $this->text($input['Password'] ?? ''); $role = $this->normalizedRole($this->text($input['Role'] ?? 'STANDARD'));
        if ($role === 'EMPLOYEE') throw new InvalidArgumentException('Employee accounts must be created and linked through HR management.');

        [$officialName, $phone] = $this->validateUserProfile((string) ($input['Name'] ?? ''), (string) ($input['Phone'] ?? '')); $username = $phone;
        if (strlen($password) < 6) throw new InvalidArgumentException('Password must be at least 6 characters.');
        if (!hash_equals($password, $this->text($input['ConfirmPassword'] ?? ''))) throw new InvalidArgumentException('Password and confirmation do not match.');

        $dealerId = $role === 'DEALER' ? $this->text($input['DealerID'] ?? '') : '';
        if ($role === 'DEALER') $this->validateDealerUser($dealerId);
        $photo = $this->userPhotoDataUrl($input['ProfilePhotoDataUrl'] ?? '');
        $id = $this->id('Users','UserID','USR'); $now = $this->now(); $row = ['UserID'=>$id,'Username'=>$username,'Name'=>$officialName,'Phone'=>$phone,'ProfilePhotoDataUrl'=>$photo,'Role'=>$role,'DealerID'=>$dealerId ?: null,'PasswordHash'=>password_hash($password,PASSWORD_DEFAULT),'Status'=>'ACTIVE','CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];
        $this->db->beginTransaction();
        try { $this->insert('Users',$row); $this->savePermissionKeys($id, $role, (array) ($input['PermissionKeys'] ?? $this->defaultPermissionKeys($role))); $this->audit('CREATE','Users',$id,'User created with role and module privileges.'); $this->db->commit(); }
        catch (Throwable $error) { $this->db->rollBack(); throw $error; }
        return $this->publicUser($row);
    }
    private function saveUser(string $userId, array $input): array
    {
        $actor = $this->user(true);
        if (trim($userId) === '') return $this->createUser($input);
        $target = $this->one('Users', 'UserID', $userId);
        if (!$target) throw new RuntimeException('User was not found.');
        $role = $this->normalizedRole($this->text($input['Role'] ?? $target['Role']));
        $targetRole = $this->normalizedRole((string) $target['Role']);
        if (($targetRole === 'EMPLOYEE' || $role === 'EMPLOYEE') && $targetRole !== $role) {
            throw new RuntimeException('Employee account role and HR linkage must be managed through HR management.');
        }
        if ((string) $actor['UserID'] === $userId && $role !== 'ADMIN') throw new RuntimeException('You cannot remove your own Administrator access.');
        if (strtoupper((string) $target['Role']) === 'ADMIN' && $role !== 'ADMIN' && strtoupper((string) $target['Status']) === 'ACTIVE' && (int) $this->value('SELECT COUNT(*) FROM Users WHERE Role="ADMIN" AND Status="ACTIVE"') <= 1) {
            throw new RuntimeException('Keep at least one active Administrator account.');
        }
        [$officialName, $phone] = $this->validateUserProfile((string) ($input['Name'] ?? $target['Name']), (string) ($input['Phone'] ?? $target['Phone'] ?? ''), $userId);
        $dealerId = $role === 'DEALER' ? $this->text($input['DealerID'] ?? '') : ($role === 'EMPLOYEE' ? (string) ($target['DealerID'] ?? '') : '');
        if ($role === 'DEALER') $this->validateDealerUser($dealerId);
        $photo = array_key_exists('ProfilePhotoDataUrl', $input) ? $this->userPhotoDataUrl($input['ProfilePhotoDataUrl']) : ($target['ProfilePhotoDataUrl'] ?? null);
        $password = $this->text($input['Password'] ?? '');
        if ($password !== '' && strlen($password) < 6) throw new InvalidArgumentException('Password must be at least 6 characters.');
        if ($password !== '' && !hash_equals($password, $this->text($input['ConfirmPassword'] ?? ''))) throw new InvalidArgumentException('Password and confirmation do not match.');
        $changes = ['Username'=>$phone,'Name'=>$officialName,'Phone'=>$phone,'ProfilePhotoDataUrl'=>$photo,'Role'=>$role,'DealerID'=>$dealerId ?: null,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()];
        if ($password !== '') $changes['PasswordHash'] = password_hash($password, PASSWORD_DEFAULT);
        $this->db->beginTransaction();
        try {
            $this->update('Users', 'UserID', $userId, $changes);
            $this->savePermissionKeys($userId, $role, (array) ($input['PermissionKeys'] ?? $this->defaultPermissionKeys($role)));
            $this->audit('UPDATE', 'Users', $userId, 'User profile, photo, access and optional password updated.');
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        $saved = $this->one('Users', 'UserID', $userId);
        return $this->publicUser($saved ?: $target);
    }
    private function changeUserStatus(string $id, string $status): array { $actor=$this->user(true); $status=strtoupper($this->text($status)); if(!in_array($status,['ACTIVE','INACTIVE'],true)) throw new InvalidArgumentException('Status must be ACTIVE or INACTIVE.'); if((string)$actor['UserID']===$id&&$status==='INACTIVE')throw new RuntimeException('You cannot disable your own account.');$old=$this->one('Users','UserID',$id);if(!$old)throw new RuntimeException('User was not found.');if($status==='INACTIVE'&&strtoupper((string)$old['Role'])==='ADMIN'&&(int)$this->value('SELECT COUNT(*) FROM Users WHERE Role="ADMIN" AND Status="ACTIVE"')<=1)throw new RuntimeException('Keep at least one active Administrator account.');if($old['Status']==='ARCHIVED'){$restored=$this->restoreLatestRecord('Users',$id);if($status!==$restored['Status'])$this->update('Users','UserID',$id,['Status'=>$status,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);}else $this->update('Users','UserID',$id,['Status'=>$status,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]); $this->audit('UPDATE','Users',$id,'User status changed.'); return $this->users(); }

    /**
     * Branding is fetched before a user signs in. Older installations can
     * therefore become unusable after this table was introduced unless they
     * happen to run the installer again. Keep this additive migration local to
     * the feature so an existing database is repaired without touching data.
     */
    private function ensureSettingsTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS settings (
            SettingKey VARCHAR(80) PRIMARY KEY,
            SettingValue LONGTEXT NOT NULL,
            UpdatedAt DATETIME NOT NULL,
            UpdatedBy VARCHAR(120) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function setting(string $key, mixed $default): mixed
    {
        $this->ensureSettingsTable();
        $v = $this->value('SELECT SettingValue FROM settings WHERE SettingKey=?',[$key]);
        return $v === false ? $default : json_decode((string)$v,true);
    }
    private function branding(): array { return $this->setting('branding',['CompanyName'=>'Distribution ERP','Address'=>'','SoftwareName'=>'Distribution ERP','LogoDataUrl'=>'']); }
    private function saveBranding(array $input): array
    {
        $this->user(true); $current=$this->branding(); $out=[]; foreach(['CompanyName','Address','SoftwareName','LogoDataUrl'] as $key) $out[$key]=$this->text($input[$key] ?? $current[$key] ?? '');
        if (!$out['CompanyName'] || !$out['SoftwareName']) throw new InvalidArgumentException('Company Name and Software Name are required.');
        if ($out['LogoDataUrl'] && (!preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=\s]+$#i',$out['LogoDataUrl']) || strlen($out['LogoDataUrl']) > 48000)) throw new InvalidArgumentException('Logo must be a small PNG, JPG, or WebP image.');
        $sql='INSERT INTO settings (SettingKey,SettingValue,UpdatedAt,UpdatedBy) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE SettingValue=VALUES(SettingValue),UpdatedAt=VALUES(UpdatedAt),UpdatedBy=VALUES(UpdatedBy)';
        $this->db->prepare($sql)->execute(['branding',json_encode($out,JSON_UNESCAPED_UNICODE),$this->now(),$this->current()]); $this->audit('UPDATE','Settings','branding','Company branding updated.'); return $out;
    }

    private function deposits(string $agreementId): array { return $this->rows('AgreementDeposits','AgreementID=?',[$agreementId]); }
    private function agreements(): array
    {
        $this->user(); return array_map(function(array $row): array { $deposits=$this->deposits($row['AgreementID']); $row['PurchaseDiscountRate']=(float)$row['CommissionRate']; $row['SecurityDeposits']=$deposits; if($deposits) $row['SecurityDeposit']=array_sum(array_map(fn($d)=>(float)$d['Amount'],$deposits)); return $row; },$this->rows('Agreements','Status<>?', ['ARCHIVED']));
    }
    private function agreement(string $id): ?array { foreach($this->agreements() as $row) if($row['AgreementID']===$id) return $row; return null; }
    private function writeDeposits(string $agreementId,array $items): float
    {
        $before=$this->rows('AgreementDeposits','AgreementID=? ORDER BY DepositID',[$agreementId]);$this->db->prepare('DELETE FROM AgreementDeposits WHERE AgreementID=?')->execute([$agreementId]); $sum=0.0; $now=$this->now();
        foreach($items as $item){ if(!is_array($item) || !$this->text($item['DueDate']??'')&&!$this->text($item['Amount']??'')&&!$this->text($item['DepositDate']??'')&&!$this->text($item['Description']??'')) continue; $amount=$this->num($item['Amount']??'', 'Security Deposit Amount'); $sum+=$amount; $this->insert('AgreementDeposits',['DepositID'=>$this->id('AgreementDeposits','DepositID','SDP'),'AgreementID'=>$agreementId,'DueDate'=>$this->text($item['DueDate']??'')?:null,'Amount'=>$amount,'DepositDate'=>$this->text($item['DepositDate']??'')?:null,'Description'=>$this->text($item['Description']??''),'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()]); }
        $this->recordDetailHistory('Agreements',$agreementId,'SecurityDeposits',$before,$this->rows('AgreementDeposits','AgreementID=? ORDER BY DepositID',[$agreementId]));
        return $sum;
    }
    private function createAgreement(array $input): array
    {
        $this->user(); $company=$this->text($input['Company']??''); if(!$company) throw new InvalidArgumentException('Company is required.'); $rate=$this->num($input['PurchaseDiscountRate']??$input['CommissionRate']??5,'Purchase Discount Rate',true); if($rate>100)throw new InvalidArgumentException('Purchase Discount Rate cannot exceed 100%.'); $status=strtoupper($this->text($input['Status']??'DRAFT')); if(!in_array($status,['DRAFT','APPROVED'],true))throw new InvalidArgumentException('Agreement status must be DRAFT or APPROVED.');
        $this->db->beginTransaction(); try{$id=$this->id('Agreements','AgreementID','AGR'); $now=$this->now(); $deposits=array_key_exists('SecurityDeposits',$input)?(array)$input['SecurityDeposits']:($this->num($input['SecurityDeposit']??0,'Security Deposit',true)>0?[['Amount'=>$input['SecurityDeposit']]]:[]); $total=$this->writeDeposits($id,$deposits); $this->insert('Agreements',['AgreementID'=>$id,'Company'=>$company,'StartDate'=>$this->text($input['StartDate']??'')?:null,'EndDate'=>$this->text($input['EndDate']??'')?:null,'SecurityDeposit'=>$total,'CommissionRate'=>$rate,'Status'=>$status,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current(),'Address'=>$this->text($input['OfficeAddress']??$input['Address']??''),'OfficeAddress'=>$this->text($input['OfficeAddress']??$input['Address']??''),'ContactPerson'=>$this->text($input['ContactPerson']??''),'Phone'=>$this->text($input['Phone']??''),'AlternatePhone'=>$this->text($input['AlternatePhone']??''),'Email'=>$this->text($input['Email']??''),'FactoryName'=>$this->text($input['FactoryName']??''),'FactoryAddress'=>$this->text($input['FactoryAddress']??''),'TradeLicenseNo'=>$this->text($input['TradeLicenseNo']??''),'TradeLicenseExpiry'=>$this->text($input['TradeLicenseExpiry']??'')?:null,'BIN'=>$this->text($input['BIN']??''),'TIN'=>$this->text($input['TIN']??'')]); $this->audit('CREATE','Agreements',$id,'Supplier created.'); $this->db->commit(); return $this->agreement($id);}catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function updateAgreement(string $id,array $input): array
    {
        $this->user(); $old=$this->one('Agreements','AgreementID',$id); if(!$old)throw new RuntimeException('Supplier was not found.'); $rate=$this->num($input['PurchaseDiscountRate']??$input['CommissionRate']??$old['CommissionRate'],'Purchase Discount Rate',true); $status=strtoupper($this->text($input['Status']??$old['Status'])); if(!in_array($status,['DRAFT','APPROVED'],true)||$rate>100)throw new InvalidArgumentException('Invalid Supplier status or discount.');
        $this->db->beginTransaction();try{$total=(float)$old['SecurityDeposit'];if(array_key_exists('SecurityDeposits',$input))$total=$this->writeDeposits($id,(array)$input['SecurityDeposits']);$this->update('Agreements','AgreementID',$id,['Company'=>$this->text($input['Company']??$old['Company']),'StartDate'=>$this->text($input['StartDate']??$old['StartDate'])?:null,'EndDate'=>$this->text($input['EndDate']??$old['EndDate'])?:null,'SecurityDeposit'=>$total,'CommissionRate'=>$rate,'Status'=>$status,'Address'=>$this->text($input['OfficeAddress']??$input['Address']??$old['OfficeAddress']??$old['Address']),'OfficeAddress'=>$this->text($input['OfficeAddress']??$input['Address']??$old['OfficeAddress']??$old['Address']),'ContactPerson'=>$this->text($input['ContactPerson']??$old['ContactPerson']??''),'Phone'=>$this->text($input['Phone']??$old['Phone']??''),'AlternatePhone'=>$this->text($input['AlternatePhone']??$old['AlternatePhone']??''),'Email'=>$this->text($input['Email']??$old['Email']??''),'FactoryName'=>$this->text($input['FactoryName']??$old['FactoryName']??''),'FactoryAddress'=>$this->text($input['FactoryAddress']??$old['FactoryAddress']??''),'TradeLicenseNo'=>$this->text($input['TradeLicenseNo']??$old['TradeLicenseNo']??''),'TradeLicenseExpiry'=>$this->text($input['TradeLicenseExpiry']??$old['TradeLicenseExpiry']??'')?:null,'BIN'=>$this->text($input['BIN']??$old['BIN']??''),'TIN'=>$this->text($input['TIN']??$old['TIN']??''),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('UPDATE','Agreements',$id,'Supplier updated.');$this->db->commit();return $this->agreement($id);}catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function deleteAgreement(string $id): array { return $this->archiveRecord('Agreements',$id,'Archived from Supplier Register.'); }

    private function dealers(): array
    {
        $user = $this->user();
        $dealerId = $this->dealerUserId($user);
        return $dealerId ? $this->rows('Dealers', 'DealerID=? AND Status<>?', [$dealerId,'ARCHIVED']) : $this->rows('Dealers','Status<>?', ['ARCHIVED']);
    }
    private function customerProfiles(): array
    {
        $user = $this->user();
        if ($this->dealerUserId($user)) return [];
        return $this->rows('CustomerProfiles','Status<>?', ['ARCHIVED']);
    }
    private function assertUniqueCustomerMobile(string $mobile, string $exceptDealerId = ''): string
    {
        $normalized = $this->normalizeUserPhone($mobile);
        if ($normalized === '') throw new InvalidArgumentException('Enter an 11-digit Bangladesh mobile number starting with 01.');
        if ($this->value('SELECT COUNT(*) FROM CustomerProfiles WHERE Mobile=?', [$normalized])) throw new InvalidArgumentException('This mobile number is already registered as a customer.');
        foreach ($this->rows('Dealers') as $dealer) {
            if ((string) ($dealer['DealerID'] ?? '') === $exceptDealerId) continue;
            if ($this->normalizeUserPhone((string) ($dealer['DealerMobile'] ?? $dealer['Phone'] ?? '')) === $normalized) throw new InvalidArgumentException('This mobile number is already assigned to another dealer.');
        }
        return $normalized;
    }
    private function createCustomerProfile(array $input): array
    {
        $user = $this->user();
        if ($this->dealerUserId($user)) throw new DepoAuthException('Customer registration is managed by the office.');
        $name = $this->text($input['CustomerName'] ?? '');
        $mobile = $this->normalizeUserPhone((string) ($input['Mobile'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Customer name is required.');
        if ($mobile === '') throw new InvalidArgumentException('Enter an 11-digit Bangladesh mobile number starting with 01.');
        $mobile = $this->assertUniqueCustomerMobile($mobile);
        $now = $this->now();
        $id = $this->id('CustomerProfiles', 'CustomerID', 'CUS');
        $row = ['CustomerID'=>$id, 'CustomerName'=>$name, 'Mobile'=>$mobile,
            'ContactPerson'=>$this->text($input['ContactPerson'] ?? ''), 'Area'=>$this->text($input['Area'] ?? ''),
            'Address'=>$this->text($input['Address'] ?? ''), 'NID'=>$this->text($input['NID'] ?? ''),
            'Email'=>$this->text($input['Email'] ?? ''), 'TradeLicense'=>$this->text($input['TradeLicense'] ?? ''),
            'Status'=>'ACTIVE', 'CreatedAt'=>$now, 'CreatedBy'=>$this->current(), 'UpdatedAt'=>$now, 'UpdatedBy'=>$this->current()];
        $this->insert('CustomerProfiles', $row);
        $this->audit('CREATE', 'CustomerProfiles', $id, 'Non-dealer customer registered.');
        return $row;
    }
    private function dealer(string $id): ?array
    {
        $user = $this->user();
        $dealerId = $this->dealerUserId($user);
        if ($dealerId && $dealerId !== $id) return null;
        return $this->one('Dealers','DealerID',$id);
    }
    private function dealerCode(): string { $max=0;foreach($this->rows('Dealers') as $r)if(preg_match('/DLR-(\d+)/i',$r['DealerCode'],$m))$max=max($max,(int)$m[1]);return 'DLR-'.str_pad((string)($max+1),6,'0',STR_PAD_LEFT); }
    private function dealerPhoto(mixed $value): ?string { $photo=$this->text($value);if(!$photo)return null;if(!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#i',$photo)||strlen($photo)>30720)throw new InvalidArgumentException('Dealer photo must be a valid image below 30 KB.');return $photo; }
    private function createDealer(array $input): array
    {
        $this->user();$name=$this->text($input['DealerName']??'');$mobile=$this->text($input['DealerMobile']??$input['Phone']??'');$address=$this->text($input['Address']??'');$license=$this->text($input['TradeLicense']??'');$photo=$this->dealerPhoto($input['DealerPhotoDataUrl']??'');if(!$name||!$mobile||!$address)throw new InvalidArgumentException('Dealer Name, Dealer Mobile and Address are required.');$mobile=$this->assertUniqueCustomerMobile($mobile);$dealerClass=strtoupper($this->text($input['DealerClass']??'REGISTERED'));if(!in_array($dealerClass,['REGISTERED','COMMISSION'],true))throw new InvalidArgumentException('Choose Registered Dealer or Commission-based Dealer.');$commissionRate=null;if($dealerClass==='COMMISSION'){$commissionRate=$this->num($input['CommissionRate']??'','Commission Rate',true);if($commissionRate>100)throw new InvalidArgumentException('Commission rate cannot exceed 100%.');}$id=strtoupper($this->text($input['DealerID']??''))?:$this->id('Dealers','DealerID','D');if($this->dealer($id))throw new InvalidArgumentException('Dealer ID already exists.');$now=$this->now();$area=$this->text($input['Area']??$input['Region']??'');$contact=$this->text($input['DealerContact']??$input['ContactPerson']??'');$row=['DealerID'=>$id,'DealerName'=>$name,'ContactPerson'=>$contact,'Phone'=>$mobile,'Address'=>$address,'Region'=>$area,'NID'=>$this->text($input['NID']??''),'TradeLicense'=>$license,'Status'=>'DRAFT','CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current(),'Area'=>$area,'DealerContact'=>$contact,'DealerMobile'=>$mobile,'TSSContact'=>$this->text($input['TSSContact']??''),'TSSMobile'=>$this->text($input['TSSMobile']??''),'DealerCode'=>$this->dealerCode(),'DealerPhotoDataUrl'=>$photo,'DealerClass'=>$dealerClass,'CommissionRate'=>$commissionRate];$this->insert('Dealers',$row);$this->audit('CREATE','Dealers',$id,'Dealer registered.');return $row;
    }
    private function updateDealer(string $id,array $input): array { $old=$this->dealer($id);if(!$old)throw new RuntimeException('Dealer was not found.');if(in_array(strtoupper($old['Status']),['APPROVED','ARCHIVED'],true))throw new RuntimeException('Only a Draft Dealer can be edited.');$name=$this->text($input['DealerName']??$old['DealerName']);$mobile=$this->text($input['DealerMobile']??$input['Phone']??$old['DealerMobile']??$old['Phone']);$address=$this->text($input['Address']??$old['Address']);$license=$this->text($input['TradeLicense']??$old['TradeLicense']);$photo=array_key_exists('DealerPhotoDataUrl',$input)?$this->dealerPhoto($input['DealerPhotoDataUrl']):($old['DealerPhotoDataUrl']??null);if(!$name||!$mobile||!$address)throw new InvalidArgumentException('Dealer Name, Dealer Mobile and Address are required.');$mobile=$this->assertUniqueCustomerMobile($mobile,$id);$dealerClass=strtoupper($this->text($input['DealerClass']??$old['DealerClass']??'REGISTERED'));if(!in_array($dealerClass,['REGISTERED','COMMISSION'],true))throw new InvalidArgumentException('Invalid dealer classification.');$commissionRate=$dealerClass==='COMMISSION'?$this->num($input['CommissionRate']??$old['CommissionRate']??'','Commission Rate',true):null;if($commissionRate!==null&&$commissionRate>100)throw new InvalidArgumentException('Commission rate cannot exceed 100%.');$area=$this->text($input['Area']??$old['Area']??$old['Region']);$contact=$this->text($input['DealerContact']??$old['DealerContact']??$old['ContactPerson']);$this->update('Dealers','DealerID',$id,['DealerName'=>$name,'ContactPerson'=>$contact,'Phone'=>$mobile,'Address'=>$address,'Region'=>$area,'NID'=>$this->text($input['NID']??$old['NID']),'TradeLicense'=>$license,'Area'=>$area,'DealerContact'=>$contact,'TSSContact'=>$this->text($input['TSSContact']??$old['TSSContact']),'TSSMobile'=>$this->text($input['TSSMobile']??$old['TSSMobile']),'DealerPhotoDataUrl'=>$photo,'DealerClass'=>$dealerClass,'CommissionRate'=>$commissionRate,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('UPDATE','Dealers',$id,'Dealer updated.');return $this->dealer($id); }
    private function changeDealerStatus(string $id,string $status):array{$dealer=$this->one('Dealers','DealerID',$id);if(!$dealer)throw new RuntimeException('Dealer was not found.');$status=strtoupper($this->text($status));if(!in_array($status,['DRAFT','APPROVED','ACTIVE','ARCHIVED'],true))throw new InvalidArgumentException('Invalid Dealer status.');if($status==='ARCHIVED')return$this->archiveRecord('Dealers',$id,'Archived from Customer Register.');if(strtoupper((string)$dealer['Status'])==='ARCHIVED'){$this->restoreLatestRecord('Dealers',$id);return$this->dealer($id);}if(in_array($status,['APPROVED','ACTIVE'],true)&&(!$this->text($dealer['DealerMobile']??$dealer['Phone']??'')||!$this->text($dealer['Address']??'')))throw new InvalidArgumentException('Dealer Mobile and Address are required before approval.');$this->update('Dealers','DealerID',$id,['Status'=>$status,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('STATUS','Dealers',$id,'Dealer status changed.');return $this->dealer($id);}
    private function updateDealerCommissionRate(string $id,array $input): array
    {
        $this->user();$dealer=$this->dealer($id);if(!$dealer)throw new RuntimeException('Dealer was not found.');
        if(strtoupper((string)($dealer['DealerClass']??'REGISTERED'))!=='COMMISSION')throw new InvalidArgumentException('Only commission-based dealers have a commission rate.');
        $rate=$this->num($input['CommissionRate']??'','Commission Rate',true);if($rate>100)throw new InvalidArgumentException('Commission rate cannot exceed 100%.');
        $this->update('Dealers','DealerID',$id,['CommissionRate'=>$rate,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('UPDATE','Dealers',$id,'Commission rate updated to '.$rate.'%.');return $this->dealer($id);
    }

    private function products(): array { $this->user(); return $this->rows('Products','Status<>?', ['ARCHIVED']); }
    private function units(string $productId = ''): array { return $productId ? $this->rows('ProductUnits','ProductID=?',[$productId]) : $this->rows('ProductUnits'); }
    private function product(string $id): ?array { $this->user(); $row=$this->one('Products','ProductID',$id); if($row)$row['Units']=$this->units($id); return $row; }
    private function productImage(mixed $value): ?string { $image=$this->text($value);if(!$image)return null;if(!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#i',$image)||strlen($image)>200000)throw new InvalidArgumentException('Product image must be a valid JPG, PNG or WebP image below 200 KB.');return $image; }
    private function variants(array $product): array { $raw=$this->text($product['Variant']??$product['Variants']??'');$out=[];foreach(explode(',',$raw) as $value){$value=$this->text($value);if($value&&!in_array(strtolower($value),array_map('strtolower',$out),true))$out[]=$value;}return $out; }
    private function massUnitKgFactor(string $unitName):?float
    {
        $unit=strtolower(preg_replace('/[^a-z]/i','',$unitName));
        if(in_array($unit,['kg','kgs','kilogram','kilograms'],true))return 1.0;
        if(in_array($unit,['g','gm','gms','gram','grams'],true))return 0.001;
        if(in_array($unit,['mg','milligram','milligrams'],true))return 0.000001;
        if(in_array($unit,['ton','tonne','tonnes','metricton'],true))return 1000.0;
        return null;
    }
    private function massUnitToBase(array $product,string $unitName):?float
    {
        $baseFactor=$this->massUnitKgFactor($this->text($product['BaseUnit']??''));$unitFactor=$this->massUnitKgFactor($unitName);
        if(!$baseFactor||!$unitFactor)return null;
        return $unitFactor/$baseFactor;
    }
    private function volumeUnitLitreFactor(string $unitName):?float
    {
        $unit=strtolower(preg_replace('/[^a-z]/i','',$unitName));
        if(in_array($unit,['l','lt','liter','liters','litre','litres'],true))return 1.0;
        if(in_array($unit,['ml','milliliter','milliliters','millilitre','millilitres'],true))return 0.001;
        return null;
    }
    private function packageUnitToBase(array $product,string $unitName):?float
    {
        $massBase=$this->massUnitKgFactor($this->text($product['BaseUnit']??''));$massUnit=$this->massUnitKgFactor($unitName);
        if($massBase!==null&&$massUnit!==null&&$massBase>0)return $massUnit/$massBase;
        $volumeBase=$this->volumeUnitLitreFactor($this->text($product['BaseUnit']??''));$volumeUnit=$this->volumeUnitLitreFactor($unitName);
        if($volumeBase!==null&&$volumeUnit!==null&&$volumeBase>0)return $volumeUnit/$volumeBase;
        return null;
    }
    private function packVariantToBase(array $product,string $variant):?float
    {
        if(!preg_match('/^\s*(\d+(?:\.\d+)?)\s*(kg|kgs|kilograms?|g|gm|gms|grams?|mg|milligrams?|tons?|tonnes?|ml|milliliters?|millilitres?|l|liters?|litres?)\s*$/i',$variant,$match))return null;
        $conversion=$this->packageUnitToBase($product,$match[2]);
        return $conversion!==null?(float)$match[1]*$conversion:null;
    }
    private function hasPostedPackageSizeReceipt(string $productId,string $variant):bool
    {
        $pack='Pack ('.$variant.')';$carton='CTN ('.$variant.')';
        return (int)$this->value('SELECT COUNT(*) FROM GoodsReceiptItems i JOIN GoodsReceipts r ON r.GRNID=i.GRNID WHERE i.ProductID=? AND COALESCE(i.Variant,"")=? AND i.UnitName IN (?,?) AND r.Status="POSTED"',[$productId,$variant,$pack,$carton])>0;
    }
    private function normalizeProductPromotion(array $input,?array $old=null):array
    {
        $type=strtoupper($this->text($input['PromotionType']??$old['PromotionType']??'NON_CP'));
        if(!in_array($type,['WITH_CP','NON_CP'],true))throw new InvalidArgumentException('Choose With CP or Non-CP.');
        $raw=$input['PromotionGifts']??$old['PromotionGifts']??[];
        if(is_string($raw)){$decoded=json_decode($raw,true);$raw=is_array($decoded)?$decoded:(trim($raw)===''?[]:preg_split('/\s*,\s*/',trim($raw)));}
        if(!is_array($raw))throw new InvalidArgumentException('Choose valid consumer promotion gift items.');
        if($type==='NON_CP')return ['PromotionType'=>$type,'PromotionGifts'=>'[]'];
        $isVariantMap=count($raw)>0;foreach($raw as $value)if(!is_array($value)||!array_key_exists('Variant',$value)||(!array_key_exists('Gift',$value)&&!array_key_exists('Offer',$value)&&!array_key_exists('Name',$value))){$isVariantMap=false;break;}
        if($isVariantMap){$gifts=[];$seen=[];foreach($raw as$value){$variant=trim($this->text($value['Variant']??''));$gift=trim($this->text($value['Gift']??$value['Offer']??$value['Name']??''));if($gift==='')continue;$giftLength=function_exists('mb_strlen')?mb_strlen($gift,'UTF-8'):strlen($gift);if($giftLength>160)throw new InvalidArgumentException('Offer / gift names must be 160 characters or fewer.');$key=strtolower($variant);if(isset($seen[$key]))throw new InvalidArgumentException('Each package size or variant can have only one CP offer.');$seen[$key]=true;$gifts[]=['Variant'=>$variant,'Gift'=>$gift];}return ['PromotionType'=>$type,'PromotionGifts'=>json_encode($gifts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];}
        $gifts=[];$seen=[];foreach($raw as$value){if(is_array($value)||is_object($value))throw new InvalidArgumentException('Choose a valid offer / gift for each package size or variant.');$value=trim($this->text($value));if($value==='')continue;if(strcasecmp($value,'Mug')===0)$value='Mug';elseif(strcasecmp($value,'Bucket')===0)$value='Bucket';elseif(preg_match('/^Other:\s*(.{1,80})$/iu',$value,$match))$value='Other: '.trim($match[1]);else throw new InvalidArgumentException('Enter an offer / gift name for each package size or variant.');$key=function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);if(!isset($seen[$key])){$seen[$key]=true;$gifts[]=$value;}}
        
        return ['PromotionType'=>$type,'PromotionGifts'=>json_encode($gifts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    }
    private function assertProductPromotionVariants(array $promotion,array $variants):void
    {
        if(($promotion['PromotionType']??'NON_CP')!=='WITH_CP')return;$gifts=json_decode((string)($promotion['PromotionGifts']??'[]'),true);if(!is_array($gifts)||!$gifts)return;$isVariantMap=true;foreach($gifts as$value)if(!is_array($value)||!array_key_exists('Variant',$value)){$isVariantMap=false;break;}if(!$isVariantMap)return;$allowed=array_map(static fn($value)=>strtolower(trim((string)$value)),$variants?:['']);foreach($gifts as$value){$variant=strtolower(trim((string)($value['Variant']??'')));if(!in_array($variant,$allowed,true))throw new InvalidArgumentException('A CP offer is linked to a package size or variant that is not selected for this product.');}
    }
    private function productUnit(string $productId,string $unitName,bool $purchase=false,string $variant=''): array
    {
        if(!$purchase&&preg_match('/^Pack\s*\((.+)\)$/i',$unitName)&&!$this->hasPostedPackageSizeReceipt($productId,$variant))throw new InvalidArgumentException('This package size has not been received through a posted GRN for this product.');
        foreach($this->units($productId) as $unit)if(strcasecmp((string)$unit['UnitName'],$unitName)===0 && strtoupper($unit['Status'])!=='ARCHIVED'){if($purchase && !(bool)$unit['PurchaseAllowed'])throw new RuntimeException('This unit cannot be used in Purchase.');if(!$purchase && !(bool)$unit['SaleAllowed'])throw new RuntimeException('This unit cannot be used in Sale.');return $unit;}
        $product=$this->product($productId);
        if($purchase&&$product&&preg_match('/^Pack\s*\((\d+(?:\.\d+)?)\s*(kg|kgs|kilograms?|g|gm|gms|grams?|mg|milligrams?)\)$/i',$unitName,$packMatch)){$conversion=$this->massUnitToBase($product,$packMatch[2]);$conversion=$conversion!==null?(float)$packMatch[1]*$conversion:null;if($conversion!==null&&$conversion>0)return['ProductID'=>$productId,'UnitName'=>$unitName,'ConversionToBase'=>$conversion,'PurchaseAllowed'=>1,'SaleAllowed'=>0,'PurchasePrice'=>0,'SalePrice'=>0,'UnitPrice'=>0,'Status'=>'ACTIVE'];}
        if(!$purchase&&$product&&preg_match('/^Pack\s*\((\d+(?:\.\d+)?)\s*(kg|kgs|kilograms?|g|gm|gms|grams?|mg|milligrams?)\)$/i',$unitName,$packMatch)){$packSize=(float)$packMatch[1]*1000*(float)$this->massUnitKgFactor($packMatch[2]);$received=$this->purchasedPackSizesInGrams($productId,$variant);$isReceived=false;foreach($received as$receivedSize)if(abs($receivedSize-$packSize)<0.000001){$isReceived=true;break;}if(!$isReceived)throw new InvalidArgumentException('This packet size has not been received through a posted GRN for this product.');$conversion=$this->massUnitToBase($product,$packMatch[2]);$conversion=$conversion!==null?(float)$packMatch[1]*$conversion:null;if($conversion!==null&&$conversion>0){$baseUnit=strtolower(trim((string)($product['BaseUnit']??'')));$basePriceUnit=null;foreach($product['Units']??[]as$configured)if(strtolower(trim((string)$configured['UnitName']))===$baseUnit&&strtoupper((string)$configured['Status'])!=='ARCHIVED'){$basePriceUnit=$configured;break;}$basePrice=(float)($basePriceUnit['SalePrice']??$basePriceUnit['UnitPrice']??0);return['ProductID'=>$productId,'UnitName'=>$unitName,'ConversionToBase'=>$conversion,'PurchaseAllowed'=>0,'SaleAllowed'=>1,'PurchasePrice'=>0,'SalePrice'=>$basePrice*$conversion,'UnitPrice'=>$basePrice*$conversion,'Status'=>'ACTIVE'];}}
        if($purchase&&preg_match('/^Pack\s*\((.+)\)$/i',$unitName,$match)&&strcasecmp(trim($match[1]),trim($variant))===0){$conversion=$this->packVariantToBase($product??[],$variant);if($conversion!==null&&$conversion>0)return['ProductID'=>$productId,'UnitName'=>'Pack ('.$variant.')','ConversionToBase'=>$conversion,'PurchaseAllowed'=>1,'SaleAllowed'=>0,'PurchasePrice'=>0,'SalePrice'=>0,'UnitPrice'=>0,'Status'=>'ACTIVE'];}
        if($product){$conversion=$this->massUnitToBase($product,$unitName);if($conversion!==null&&$conversion>0){$baseUnit=$this->text($product['BaseUnit']??'');$basePriceUnit=null;foreach($product['Units']??[] as $configured)if(strcasecmp((string)$configured['UnitName'],$baseUnit)===0&&strtoupper((string)$configured['Status'])!=='ARCHIVED'){$basePriceUnit=$configured;break;}$baseUnitPrice=(float)($basePriceUnit['UnitPrice']??0);$purchasePrice=(float)($basePriceUnit['PurchasePrice']??$baseUnitPrice)*$conversion;$salePrice=(float)($basePriceUnit['SalePrice']??$baseUnitPrice)*$conversion;return['ProductID'=>$productId,'UnitName'=>$unitName,'ConversionToBase'=>$conversion,'PurchaseAllowed'=>1,'SaleAllowed'=>1,'PurchasePrice'=>$purchasePrice,'SalePrice'=>$salePrice,'UnitPrice'=>$baseUnitPrice*$conversion,'Status'=>'ACTIVE'];}}
        throw new RuntimeException('Select a valid active product unit.');
    }
    private function productPromotionGiftForVariant(array $product,string $variant):string
    {
        if(strtoupper((string)($product['PromotionType']??'NON_CP'))!=='WITH_CP')return'';
        $raw=$product['PromotionGifts']??[];
        if(is_string($raw)){$decoded=json_decode($raw,true);$raw=is_array($decoded)?$decoded:[];}
        if(!is_array($raw))return'';
        $legacy=[];$hasVariantMap=false;$requested=strtolower(trim($variant));
        foreach($raw as$value){
            if(is_array($value)&&array_key_exists('Variant',$value)){
                $hasVariantMap=true;
                if(strtolower(trim((string)($value['Variant']??'')))===$requested)return trim((string)($value['Gift']??$value['Offer']??$value['Name']??''));
            }elseif(is_string($value)&&trim($value)!=='')$legacy[]=preg_replace('/^Other:\\s*/i','',trim($value));
        }
        if($hasVariantMap)return'';
        return implode(', ',$legacy);
    }
    private function normalizePurchaseOffer(array $line,array $mainProduct,string $variant):array
    {
        $configuredGift=$this->productPromotionGiftForVariant($mainProduct,$variant);
        $submittedGift=trim($this->text($line['OfferProductName']??''));
        $gift=$submittedGift!==''?$submittedGift:$configuredGift;
        
        $giftLength=function_exists('mb_strlen')?mb_strlen($gift,'UTF-8'):strlen($gift);
        if($giftLength>160)throw new InvalidArgumentException('Free gift descriptions must be 160 characters or fewer.');
        return ['OfferProductID'=>null,'OfferProductName'=>$gift!==''?$gift:null,'OfferVariant'=>null,'OfferUnitName'=>null,'OfferQuantity'=>null,'OfferUnitPrice'=>0,'OfferConversionToBase'=>null,'OfferBaseQuantity'=>null];
    }
    private function normalizeItems(array $raw,string $type,float $discount=0,bool $forceConfiguredSalePrice=false): array
    {
        $items=[];
        foreach($raw as $line){
            if(!is_array($line))continue;
            $product=$this->product($this->text($line['ProductID']??''));
            if(!$product)throw new RuntimeException('Product was not found.');
            if(in_array(strtoupper((string)$product['Status']),['ARCHIVED','DISCONTINUED'],true))throw new RuntimeException(strtoupper((string)$product['Status'])==='DISCONTINUED'?'Discontinued products cannot be used in new transactions.':'Archived products cannot be used.');
            $configured=$this->variants($product);$variant=$this->text($line['Variant']??'');
            if($configured){$match=null;foreach($configured as $candidate)if(strcasecmp($candidate,$variant)===0)$match=$candidate;if($match===null)throw new InvalidArgumentException('Select a valid variant for '.$product['ProductName'].'.');$variant=$match;}
            elseif($variant)throw new InvalidArgumentException('Selected product has no configured variants.');
            $requestedUnit=$this->text($line['UnitName']??'');$baseName=$this->text($product['BaseUnit']??$product['UnitOfMeasure']??'');$isPieceBase=(bool)preg_match('/^(?:pc|pcs|piece|pieces)$/i',$baseName);$piecesPerCarton=0.0;$isPieceCarton=$type==='PURCHASE'&&$isPieceBase&&(bool)preg_match('/^(?:ctn|cartons?)$/i',$requestedUnit);
            if($isPieceCarton){$postedPieces=$this->text($line['PiecesPerCarton']??'');$configuredCarton=null;foreach($this->units($product['ProductID'])as$configuredUnit)if(preg_match('/^(?:ctn|cartons?)$/i',(string)($configuredUnit['UnitName']??''))&&strtoupper((string)($configuredUnit['Status']??'ACTIVE'))!=='ARCHIVED'){$configuredCarton=$configuredUnit;break;}$piecesPerCarton=$postedPieces!==''?$this->num($postedPieces,'Pieces per CTN'):(float)($configuredCarton['ConversionToBase']??0);if($piecesPerCarton<1||abs($piecesPerCarton-round($piecesPerCarton))>0.000001)throw new InvalidArgumentException('Enter how many whole Pieces are in 1 CTN.');$piecesPerCarton=(float)round($piecesPerCarton);$requestedUnit=$configuredCarton['UnitName']??$requestedUnit;}
            if($isPieceCarton){$unit=null;foreach($this->units($product['ProductID'])as$configuredUnit)if(strcasecmp((string)($configuredUnit['UnitName']??''),$requestedUnit)===0&&strtoupper((string)($configuredUnit['Status']??'ACTIVE'))!=='ARCHIVED'){$unit=$configuredUnit;break;}if(!$unit)$unit=['ProductID'=>$product['ProductID'],'UnitName'=>$requestedUnit,'ConversionToBase'=>$piecesPerCarton,'PurchaseAllowed'=>1,'SaleAllowed'=>1,'PurchasePrice'=>0,'SalePrice'=>0,'UnitPrice'=>0,'Status'=>'ACTIVE'];else{$unit['ConversionToBase']=$piecesPerCarton;if(!(bool)($unit['PurchaseAllowed']??false))throw new RuntimeException('This unit cannot be used in Purchase.');}}
            else $unit=$this->productUnit($product['ProductID'],$requestedUnit,$type==='PURCHASE',$variant);
            $quantity=$this->num($line['Quantity']??'','Quantity');
            if(strtoupper((string)($product['OptionType']??'VARIANT'))==='PACKAGING'){
                $packUnit='Pack ('.$variant.')';$cartonUnit='CTN ('.$variant.')';$packConversion=$this->packVariantToBase($product,$variant);$selectedUnit=(string)$unit['UnitName'];$isPack=strcasecmp($selectedUnit,$packUnit)===0;$isCarton=strcasecmp($selectedUnit,$cartonUnit)===0;$expectedConversion=$isPack?$packConversion:($isCarton?(float)$unit['ConversionToBase']:null);
                if($packConversion===null||(!$isPack&&!$isCarton)||$expectedConversion===null||abs((float)$unit['ConversionToBase']-(float)$expectedConversion)>0.000001)throw new InvalidArgumentException('Select the matching pack or CTN unit for '.$variant.'.');
                if($isCarton){$pieces=(float)$unit['ConversionToBase']/(float)$packConversion;if($pieces<1||abs($pieces-round($pieces))>0.00001)throw new InvalidArgumentException('The configured CTN conversion is not a whole number of sealed packs.');}
                if($type==='SALE'&&!$this->hasPostedPackageSizeReceipt($product['ProductID'],$variant))throw new InvalidArgumentException('This package size has not been received through a posted GRN for this product.');
                if($type==='SALE'&&$isCarton&&abs($quantity-round($quantity))>0.000001)throw new InvalidArgumentException('CTN sale quantity must be a whole number. Pcs quantities may be fractional for opened stock.');
            }            $default=$type==='PURCHASE'?(float)$unit['PurchasePrice']:(float)$unit['SalePrice'];
            $price=$forceConfiguredSalePrice&&$type==='SALE'?$default:($this->text($line['UnitPrice']??'')===''?$default:$this->num($line['UnitPrice'],'Unit Price',true));
            if($type==='PURCHASE'&&$price<=0)throw new InvalidArgumentException('Enter a purchase price greater than zero for '.$product['ProductName'].'.');
            $gift=$price==0 || !empty($line['IsGift']) || strtoupper($product['ProductType'])==='GIFT';if($gift)$price=0;
            $requestedPromotionType=strtoupper($this->text($line['PromotionType']??''));
            if($requestedPromotionType!==''&&!in_array($requestedPromotionType,['WITH_CP','NON_CP'],true))throw new InvalidArgumentException('Choose With CP or Non-CP for '.$product['ProductName'].'.');
            $promotionType=$requestedPromotionType?:($type==='PURCHASE'?'NON_CP':((strtoupper((string)($product['PromotionType']??'NON_CP'))==='WITH_CP')?'WITH_CP':'NON_CP'));
            if($promotionType==='WITH_CP'&&$type!=='PURCHASE'&&strtoupper((string)($product['PromotionType']??'NON_CP'))!=='WITH_CP')throw new InvalidArgumentException('With CP is available only for products configured with a consumer promotion.');
            $promotionGifts=$promotionType==='WITH_CP'?(string)($product['PromotionGifts']??'[]'):'[]';
            $offer=$type==='PURCHASE'?($promotionType==='WITH_CP'?$this->normalizePurchaseOffer($line,$product,$variant):['OfferProductID'=>null,'OfferProductName'=>null,'OfferVariant'=>null,'OfferUnitName'=>null,'OfferQuantity'=>null,'OfferUnitPrice'=>null,'OfferConversionToBase'=>null,'OfferBaseQuantity'=>null]):[];
            $items[]=array_merge(['ProductID'=>$product['ProductID'],'Variant'=>$variant,'UnitName'=>$unit['UnitName'],'Quantity'=>$quantity,'ConversionToBase'=>(float)$unit['ConversionToBase'],'BaseQuantity'=>$quantity*(float)$unit['ConversionToBase'],'UnitPrice'=>$price,'LineTotal'=>$quantity*$price,'IsGift'=>$gift?1:0,'PromotionType'=>$promotionType,'PromotionGifts'=>$promotionGifts,'_PromotionTypeExplicit'=>$requestedPromotionType!== '','PiecesPerCarton'=>$isPieceCarton?$piecesPerCarton:null],$offer);
        }
        if($type==='SALE')$this->assertSaleAmounts($items);
        return $items;
    }
    private function inheritPromotionSnapshots(array $items,array $previous):array
    {
        $byLine=[];
        foreach($previous as $line){
            $key=strtolower(implode('|',[(string)($line['ProductID']??''),(string)($line['Variant']??''),(string)($line['UnitName']??'')]));
            $byLine[$key]=$line;
        }
        foreach($items as &$line){
            $key=strtolower(implode('|',[(string)($line['ProductID']??''),(string)($line['Variant']??''),(string)($line['UnitName']??'')]));
            if(!isset($byLine[$key])){unset($line['_PromotionTypeExplicit']);continue;}
            $old=$byLine[$key];$type=strtoupper((string)($old['PromotionType']??''));
            $explicit=!empty($line['_PromotionTypeExplicit']);
            if(!$explicit&&in_array($type,['WITH_CP','NON_CP'],true))$line['PromotionType']=$type;
            if(in_array($type,['WITH_CP','NON_CP'],true)&&(!$explicit||strtoupper((string)($line['PromotionType']??''))===$type)&&array_key_exists('PromotionGifts',$old)&&$old['PromotionGifts']!==null)$line['PromotionGifts']=$old['PromotionGifts'];
        }
        unset($line);foreach($items as &$line)unset($line['_PromotionTypeExplicit']);unset($line);
        return $items;
    }
    private function assertSaleAmounts(array$items):float{$total=0.0;if(!$items)throw new InvalidArgumentException('Add at least one product to the Sale.');foreach($items as$item){$product=$this->product($this->text($item['ProductID']??''));$name=$product['ProductName']??$this->text($item['ProductID']??'Product');$quantity=(float)($item['Quantity']??0);$price=(float)($item['UnitPrice']??0);$lineTotal=(float)($item['LineTotal']??($quantity*$price));if($quantity<=0)throw new InvalidArgumentException('Enter a quantity greater than zero for '.$name.'.');if($price<=0)throw new InvalidArgumentException('Enter a unit price greater than zero for '.$name.'.');if($lineTotal<=0)throw new InvalidArgumentException('The total value must be greater than zero for '.$name.'.');$total+=$lineTotal;}if($total<=0)throw new InvalidArgumentException('Sale total value must be greater than zero.');return$total;}
    private function validateProductOptionType(string $optionType, string $baseUnit, array $options): void
    {
        if (!in_array($optionType, ['VARIANT', 'PACKAGING'], true)) throw new InvalidArgumentException('Select Product Variant or Sealed Packaging Sizes.');
        if ($optionType !== 'PACKAGING') return;
        if (!$options) throw new InvalidArgumentException('Add at least one sealed package size, such as 40 g, 1 kg, 250 ml or 1 L.');
        if ($this->massUnitKgFactor($baseUnit) === null && $this->volumeUnitLitreFactor($baseUnit) === null) throw new InvalidArgumentException('Sealed packages require a mass Base Unit (Gram/Kg) or liquid Base Unit (Millilitre/Litre).');
        $seen = [];
        foreach ($options as $label) {
            $conversion = $this->packVariantToBase(['BaseUnit' => $baseUnit], (string) $label);
            if ($conversion === null || $conversion <= 0) throw new InvalidArgumentException('Each package size must use the selected base-unit dimension, such as 40 g / 1 kg or 250 ml / 1 L.');
            foreach ($seen as $existing) if (abs($existing - $conversion) < 0.000001) throw new InvalidArgumentException('The same package size cannot be listed twice (for example 1,000 g and 1 kg, or 1,000 ml and 1 L).');
            $seen[] = $conversion;
        }
    }

    private function packagingUnits(string $baseUnit, array $options, array $units, string $optionType): array
    {
        if ($optionType !== 'PACKAGING') return $units;
        $base = null;
        foreach ($units as $unit) if (is_array($unit) && strcasecmp($this->text($unit['UnitName'] ?? ''), $baseUnit) === 0) { $base = $unit; break; }
        if (!$base) throw new InvalidArgumentException('The selected Stock Base Unit must be included in the unit configuration.');
        foreach ($options as $label) {
            $conversion = $this->packVariantToBase(['BaseUnit' => $baseUnit], (string) $label);
            $name = 'Pack (' . $label . ')';
            $existingIndex = null;
            foreach ($units as $index => $unit) if (is_array($unit) && strcasecmp($this->text($unit['UnitName'] ?? ''), $name) === 0) { $existingIndex = $index; break; }
            if ($existingIndex !== null) {
                $units[$existingIndex]['UnitName'] = $name;
                $units[$existingIndex]['ConversionToBase'] = $conversion;
                $units[$existingIndex]['PurchaseAllowed'] = true;
                $units[$existingIndex]['SaleAllowed'] = array_key_exists('SaleAllowed', $units[$existingIndex]) ? (bool)$units[$existingIndex]['SaleAllowed'] : true;
                $units[$existingIndex]['PurchasePrice'] = (float)($units[$existingIndex]['PurchasePrice'] ?? $units[$existingIndex]['UnitPrice'] ?? 0);
                $units[$existingIndex]['SalePrice'] = (float)($units[$existingIndex]['SalePrice'] ?? $units[$existingIndex]['UnitPrice'] ?? 0);
                $units[$existingIndex]['UnitPrice'] = (float)($units[$existingIndex]['UnitPrice'] ?? $units[$existingIndex]['SalePrice']);
                continue;
            }
            $units[] = ['UnitName'=>$name,'ConversionToBase'=>$conversion,'PurchaseAllowed'=>true,'SaleAllowed'=>array_key_exists('SaleAllowed', $base) ? (bool)$base['SaleAllowed'] : true,'UnitPrice'=>(float)($base['UnitPrice']??0)*$conversion,'PurchasePrice'=>(float)($base['PurchasePrice']??$base['UnitPrice']??0)*$conversion,'SalePrice'=>(float)($base['SalePrice']??$base['UnitPrice']??0)*$conversion];
        }
        foreach ($options as $label) {
            $packName='Pack ('.$label.')';$cartonName='CTN ('.$label.')';$packIndex=null;$cartonIndex=null;
            foreach($units as $index=>$unit){if(!is_array($unit))continue;$unitName=$this->text($unit['UnitName']??'');if(strcasecmp($unitName,$packName)===0)$packIndex=$index;if(strcasecmp($unitName,$cartonName)===0)$cartonIndex=$index;}
            if($packIndex===null)throw new InvalidArgumentException('A sealed pack unit is required for every selected package size.');
            if($cartonIndex===null)continue;
            $packConversion=(float)$units[$packIndex]['ConversionToBase'];$cartonConversion=(float)($units[$cartonIndex]['ConversionToBase']??0);$pieces=$packConversion>0?$cartonConversion/$packConversion:0;
            if($packConversion<=0||$cartonConversion<=0||$pieces<1||abs($pieces-round($pieces))>0.00001)throw new InvalidArgumentException('Each CTN must contain a whole number of sealed packs.');
            $packPurchase=(float)($units[$packIndex]['PurchasePrice']??$units[$packIndex]['UnitPrice']??0);$packSale=(float)($units[$packIndex]['SalePrice']??$units[$packIndex]['UnitPrice']??0);
            $units[$cartonIndex]['UnitName']=$cartonName;$units[$cartonIndex]['ConversionToBase']=$packConversion*(int)round($pieces);$units[$cartonIndex]['PurchaseAllowed']=true;$units[$cartonIndex]['SaleAllowed']=true;$units[$cartonIndex]['PurchasePrice']=$packPurchase*(int)round($pieces);$units[$cartonIndex]['SalePrice']=$packSale*(int)round($pieces);$units[$cartonIndex]['UnitPrice']=$units[$cartonIndex]['SalePrice'];
        }
        return $units;
    }
    private function assertProductCategory(string $name, string $existingName = ''): string
    {
        $name = $this->text($name);
        if ($name === '') throw new InvalidArgumentException('Product Category is required. Select an existing category or create one first.');
        if ($existingName !== '' && strcasecmp(trim($existingName), $name) === 0) return $existingName;
        $category = $this->value('SELECT CategoryName FROM ProductCategories WHERE LOWER(CategoryName)=LOWER(?) AND Status<>"ARCHIVED"', [$name]);
        if (!$category) throw new InvalidArgumentException('Select an active Product Category or create it before saving the product.');
        return (string) $category;
    }

    private function assertUniqueProductConfiguration(string $supplierId, string $name, string $excludeId = ''): void
    {
        $normalize = static function (string $value): string {
            $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
            return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        };
        $key = $normalize($name);
        foreach ($this->rows('Products') as $existing) {
            if ((string) ($existing['ProductID'] ?? '') === $excludeId || (string) ($existing['SupplierID'] ?? '') !== $supplierId) continue;
            $existingKey = $normalize((string) ($existing['ProductName'] ?? ''));
            if ($existingKey === $key) throw new InvalidArgumentException('A product with this name is already configured for the selected Supplier. Edit the existing product instead of creating a duplicate.');
        }
    }
    private function productConfigurationRegister(): array
    {
        $this->user(); $out=[];
        foreach ($this->rows('Products','COALESCE(Status,"ACTIVE")<>?', ['ARCHIVED']) as $product) {
            $supplier=$this->agreement((string) ($product['SupplierID'] ?? ''));
            $units=array_values(array_filter($this->units((string) $product['ProductID']),fn(array $unit):bool=>strtoupper((string) ($unit['Status'] ?? 'ACTIVE'))!=='ARCHIVED'));
            $optionType=strtoupper((string)($product['OptionType']??'VARIANT'));
            $options=$this->variants($product)?:[''];
            foreach ($options as $option) {
                $out[]=['ProductID'=>$product['ProductID'],'SKU'=>$this->sku((string)($supplier['Company']??'SUP'),(string)$product['ProductName'],$option,(string)$product['ProductID']),'SupplierID'=>$product['SupplierID'],'SupplierName'=>$supplier['Company']??'','ProductName'=>$product['ProductName'],'ProductImageDataUrl'=>$product['ProductImageDataUrl']??'','Category'=>$product['Category']??'','OptionType'=>$optionType,'PromotionType'=>$product['PromotionType']??'NON_CP','PromotionGifts'=>$product['PromotionGifts']??'[]','Variant'=>$option,'PackagingSize'=>$optionType==='PACKAGING'?$option:'','PackageConversionToBase'=>$optionType==='PACKAGING'?($this->packVariantToBase($product,$option)??0):0,'BaseUnit'=>$product['BaseUnit']??$product['UnitOfMeasure']??'','UnitOfMeasure'=>implode(', ',array_column($units,'UnitName')),'Status'=>$product['Status']];
            }
        }
        return $out;
    }
    private function createProduct(array $input): array
    {
        $this->user();
        $name=$this->text($input['ProductName']??'');
        $image=$this->productImage($input['ProductImageDataUrl']??'');
        $promotion=$this->normalizeProductPromotion($input);
        $supplier=$this->agreement($this->text($input['SupplierID']??$input['AgreementID']??''));
        if(!$name)throw new InvalidArgumentException('Product Name is required.');
        if(!$supplier||$supplier['Status']!=='APPROVED')throw new InvalidArgumentException('Select an approved Supplier for the product.');
        $base=$this->text($input['BaseUnit']??$input['UnitOfMeasure']??'');
        if(!$base)throw new InvalidArgumentException('Base Unit is required.');
        $optionType=strtoupper($this->text($input['OptionType']??'VARIANT'));
        $variants=$this->variants(['Variant'=>$input['Variants']??$input['Variant']??'']);
        $this->assertProductPromotionVariants($promotion,$variants);
        $this->validateProductOptionType($optionType,$base,$variants);
        $category=$this->assertProductCategory($this->text($input['Category']??''));
        $units=(array)($input['Units']??[]);
        if(!$units)$units=[['UnitName'=>$base,'ConversionToBase'=>1,'PurchaseAllowed'=>true,'SaleAllowed'=>true,'UnitPrice'=>0,'PurchasePrice'=>0,'SalePrice'=>0]];
        $hasBase=false;foreach($units as $unit)if(strcasecmp($this->text($unit['UnitName']??''),$base)===0)$hasBase=true;
        if(!$hasBase)array_unshift($units,['UnitName'=>$base,'ConversionToBase'=>1,'PurchaseAllowed'=>true,'SaleAllowed'=>true,'UnitPrice'=>0,'PurchasePrice'=>0,'SalePrice'=>0]);
        $units=$this->packagingUnits($base,$variants,$units,$optionType);
        $this->db->beginTransaction();
        try{
            $this->assertUniqueProductConfiguration((string)$supplier['AgreementID'],$name);
            $id=$this->id('Products','ProductID','PRD');$now=$this->now();$sku=$this->sku($supplier['Company'],$name,'',$id);
            $this->insert('Products',['ProductID'=>$id,'SKU'=>$sku,'ProductName'=>$name,'ProductImageDataUrl'=>$image,'Category'=>$category,'BaseUnit'=>$base,'Status'=>'ACTIVE','CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current(),'ProductType'=>strtoupper($this->text($input['ProductType']??'REGULAR'))?:'REGULAR','Variant'=>implode(', ',$variants),'OptionType'=>$optionType,'PromotionType'=>$promotion['PromotionType'],'PromotionGifts'=>$promotion['PromotionGifts'],'UnitOfMeasure'=>$base,'SupplierID'=>$supplier['AgreementID']]);
            $this->writeUnits($id,$units);$this->audit('CREATE','Products',$id,'Product created.');$this->db->commit();return $this->product($id);
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function sku(string $supplier,string $name,string $variant,string $id):string{ $initials=function(string $value,string $fallback):string{$parts=preg_split('/[^A-Za-z0-9]+/',strtoupper($value),-1,PREG_SPLIT_NO_EMPTY);return $parts?implode('',array_map(fn($p)=>$p[0],$parts)):$fallback;};$parts=[$initials($supplier,'SUP'),$initials($name,'PRD')];if($variant)$parts[]=preg_replace('/[^A-Z0-9]+/','',strtoupper($variant));preg_match('/(\d+)$/',$id,$m);$parts[]=str_pad($m[1]??'1',5,'0',STR_PAD_LEFT);return implode('-',$parts);}
    private function writeUnits(string $productId,array $units):void
    {
        $this->db->prepare('UPDATE ProductUnits SET Status="ARCHIVED", UpdatedAt=?, UpdatedBy=? WHERE ProductID=?')->execute([$this->now(),$this->current(),$productId]);$seen=[];foreach($units as $input){if(!is_array($input))continue;$name=$this->text($input['UnitName']??'');$key=strtolower($name);if(!$name||isset($seen[$key]))throw new InvalidArgumentException('Product units must have unique names.');$seen[$key]=true;$conversion=$this->num($input['ConversionToBase']??'','Conversion to base');$purchase=$this->num($input['PurchasePrice']??$input['UnitPrice']??0,'Purchase Price',true);$sale=$this->num($input['SalePrice']??$input['UnitPrice']??0,'Sale Price',true);$old=$this->value('SELECT ProductUnitID FROM ProductUnits WHERE ProductID=? AND UnitName=?',[$productId,$name]);$row=['ProductID'=>$productId,'UnitName'=>$name,'ConversionToBase'=>$conversion,'PurchaseAllowed'=>!empty($input['PurchaseAllowed'])?1:0,'SaleAllowed'=>!empty($input['SaleAllowed'])?1:0,'UnitPrice'=>$this->num($input['UnitPrice']??$sale,'Unit Price',true),'Status'=>'ACTIVE','UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current(),'SalePrice'=>$sale,'PurchasePrice'=>$purchase];if($old){$this->update('ProductUnits','ProductUnitID',(string)$old,$row);}else{$this->insert('ProductUnits',['ProductUnitID'=>$this->id('ProductUnits','ProductUnitID','UNT'),'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()]+$row);}}
    }
    private function updateProduct(string $id,array $input):array
    {
        $old=$this->product($id);if(!$old)throw new RuntimeException('Product was not found.');
        $promotion=$this->normalizeProductPromotion($input,$old);
        $supplier=$this->agreement($this->text($input['SupplierID']??$input['AgreementID']??$old['SupplierID']));
        if(!$supplier||$supplier['Status']!=='APPROVED')throw new InvalidArgumentException('Select an approved Supplier for the product.');
        $name=$this->text($input['ProductName']??$old['ProductName']);if(!$name)throw new InvalidArgumentException('Product Name is required.');
        $base=$this->text($input['BaseUnit']??$input['UnitOfMeasure']??$old['BaseUnit']);
        $optionType=strtoupper($this->text($input['OptionType']??$old['OptionType']??'VARIANT'));
        $variants=$this->variants(['Variant'=>$input['Variants']??$input['Variant']??$old['Variant']]);
        $this->assertProductPromotionVariants($promotion,$variants);
        $this->validateProductOptionType($optionType,$base,$variants);
        $category=$this->assertProductCategory($this->text($input['Category']??$old['Category']??''),$this->text($old['Category']??''));
        $image=array_key_exists('ProductImageDataUrl',$input)?$this->productImage($input['ProductImageDataUrl']):($old['ProductImageDataUrl']??null);
        $row=['ProductName'=>$name,'ProductImageDataUrl'=>$image,'Category'=>$category,'BaseUnit'=>$base,'ProductType'=>strtoupper($this->text($input['ProductType']??$old['ProductType'])),'Variant'=>implode(', ',$variants),'OptionType'=>$optionType,'PromotionType'=>$promotion['PromotionType'],'PromotionGifts'=>$promotion['PromotionGifts'],'UnitOfMeasure'=>$base,'SupplierID'=>$supplier['AgreementID'],'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()];
        $units=array_key_exists('Units',$input)?(array)$input['Units']:$this->units($id);
        if($optionType==='PACKAGING')$units=$this->packagingUnits($base,$variants,$units,$optionType);
        $this->db->beginTransaction();
        try{$this->assertUniqueProductConfiguration((string)$supplier['AgreementID'],$name,$id);$this->update('Products','ProductID',$id,$row);if(array_key_exists('Units',$input)||$optionType==='PACKAGING')$this->writeUnits($id,$units);$this->audit('UPDATE','Products',$id,'Product updated.');$this->db->commit();return $this->product($id);}catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    private function changeProductStatus(string $id,string $status):array{$this->user();$status=strtoupper($this->text($status));if(!in_array($status,['ACTIVE','DISCONTINUED','ARCHIVED'],true))throw new InvalidArgumentException('Status must be Active, Discontinued or Archived.');$old=$this->one('Products','ProductID',$id);if(!$old)throw new RuntimeException('Product was not found.');if($status==='ARCHIVED')throw new RuntimeException('Use the Administrator impact review to archive a product.');if(strtoupper((string)$old['Status'])==='ARCHIVED')throw new RuntimeException('Archived products must be restored through the Administrator recovery review.');$this->update('Products','ProductID',$id,['Status'=>$status,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('STATUS','Products',$id,'Product status changed to '.$status.'.');return $this->product($id);}

    private function stockBalance(string $agreement,string $product,string $variant=''):float{return(float)$this->value('SELECT COALESCE(SUM(BaseQuantityIn-BaseQuantityOut),0) FROM StockLedger WHERE AgreementID=? AND ProductID=? AND COALESCE(Variant, "")=?',[$agreement,$product,$variant]);}
    private function packagingConversions(string $productId,string $variant):?array
    {
        $product=$this->product($productId);if(!$product||strtoupper((string)($product['OptionType']??'VARIANT'))!=='PACKAGING')return null;
        $pack=$this->packVariantToBase($product,$variant);$carton=null;foreach($this->units($productId)as$unit)if(strcasecmp((string)($unit['UnitName']??''),'CTN ('.$variant.')')===0&&strtoupper((string)($unit['Status']??'ACTIVE'))!=='ARCHIVED'){$carton=(float)($unit['ConversionToBase']??0);break;}
        if(!$pack||$pack<=0||!$carton||$carton<=0)return null;$packs=$carton/$pack;if($packs<1||abs($packs-round($packs))>0.00001)return null;
        return ['PackToBase'=>$pack,'CartonToBase'=>$carton,'PacksPerCarton'=>(int)round($packs),'PackUnit'=>'Pack ('.$variant.')','CartonUnit'=>'CTN ('.$variant.')'];
    }
    private function consumePackagingPackBase(array &$bucket,float $baseQuantity,array $conversion):void
    {
        $remaining=max(0,$baseQuantity);$fromLoose=min(max(0,(float)$bucket['LooseBaseQuantity']),$remaining);$bucket['LooseBaseQuantity']-=$fromLoose;$remaining-=$fromLoose;
        if($remaining>0.000001){$cartonBase=(float)$conversion['CartonToBase'];$sealed=max(0,(int)$bucket['SealedCartons']);$open=$cartonBase>0?(int)ceil(($remaining-0.000001)/$cartonBase):0;$open=min($sealed,$open);$bucket['SealedCartons']-=$open;$covered=$open*$cartonBase;$used=min($remaining,$covered);$remaining-=$used;$bucket['LooseBaseQuantity']+=max(0,$covered-$used);}
        if($remaining>0.000001)$bucket['LooseBaseQuantity']-=$remaining;
        if(abs((float)$bucket['LooseBaseQuantity'])<0.000001)$bucket['LooseBaseQuantity']=0.0;
    }
    private function consumePackagingCartonBase(array &$bucket,float $baseQuantity,array $conversion):void
    {
        $cartonBase=(float)$conversion['CartonToBase'];if($cartonBase<=0)return;$cartonQuantity=$baseQuantity/$cartonBase;$whole=(int)round($cartonQuantity);
        if($whole>0&&abs($cartonQuantity-$whole)<0.00001&&$whole<=(int)$bucket['SealedCartons']){$bucket['SealedCartons']-=$whole;return;}
        $this->consumePackagingPackBase($bucket,$baseQuantity,$conversion);
    }
    private function packagingStockBuckets(string $productId,string $variant,bool $includeReservations=true,string $excludeSaleId=''):?array
    {
        $conversion=$this->packagingConversions($productId,$variant);if(!$conversion)return null;$approved=[];foreach($this->agreements()as$agreement)if(($agreement['Status']??'')==='APPROVED')$approved[(string)$agreement['AgreementID']]=true;
        $buckets=[];foreach(array_keys($approved)as$agreementId)$buckets[$agreementId]=['SealedCartons'=>0,'LooseBaseQuantity'=>0.0];
        $statement=$this->db->prepare('SELECT * FROM StockLedger WHERE ProductID=? AND COALESCE(Variant,"")=? ORDER BY CreatedAt,LedgerID');$statement->execute([$productId,$variant]);$cartonPattern='/^(?:CTN|CARTONS?)\s*(?:\('.preg_quote($variant,'/').'\))?$/i';$packUnit=$conversion['PackUnit'];
        foreach($statement->fetchAll()as$movement){$agreementId=(string)$movement['AgreementID'];if(!isset($buckets[$agreementId]))continue;$unit=(string)($movement['UnitName']??'');$isCarton=(bool)preg_match($cartonPattern,trim($unit))||strcasecmp(trim($unit),$conversion['CartonUnit'])===0;$isPack=strcasecmp(trim($unit),$packUnit)===0;$in=(float)($movement['BaseQuantityIn']??0);$out=(float)($movement['BaseQuantityOut']??0);
            if($in>0){if($isCarton){$cartons=$in/(float)$conversion['CartonToBase'];$whole=(int)floor($cartons+0.000001);$buckets[$agreementId]['SealedCartons']+=$whole;$buckets[$agreementId]['LooseBaseQuantity']+=max(0,$in-$whole*(float)$conversion['CartonToBase']);}else{$buckets[$agreementId]['LooseBaseQuantity']+=$in;}}
            if($out>0){if($isCarton)$this->consumePackagingCartonBase($buckets[$agreementId],$out,$conversion);else$this->consumePackagingPackBase($buckets[$agreementId],$out,$conversion);}
        }
        if($includeReservations){$sql='SELECT i.*,s.SaleID AS ReservedSaleID FROM DealerSaleItems i JOIN DealerSales s ON s.SaleID=i.SaleID WHERE i.ProductID=? AND COALESCE(i.Variant,"")=? AND s.Status IN ("DRAFT","CONFIRMED")';$params=[$productId,$variant];if($excludeSaleId!==''){$sql.=' AND s.SaleID<>?';$params[]=$excludeSaleId;}$sql.=' ORDER BY s.CreatedAt,s.SaleID,i.SaleItemID';$reserved=$this->db->prepare($sql);$reserved->execute($params);foreach($reserved->fetchAll()as$item){$base=(float)($item['BaseQuantity']??0);$unit=(string)($item['UnitName']??'');if($base<=0)continue;if(preg_match($cartonPattern,trim($unit))||strcasecmp(trim($unit),$conversion['CartonUnit'])===0){$need=$base/(float)$conversion['CartonToBase'];foreach($buckets as&$bucket){$take=min((int)$bucket['SealedCartons'],max(0,(int)floor($need+0.000001)));$bucket['SealedCartons']-=$take;$need-=$take;if($need<1)break;}unset($bucket);}else{$this->consumePackagingPackBaseAcrossBuckets($buckets,$base,$conversion);}}
        }
        $sealed=0;$loose=0.0;foreach($buckets as$bucket){$sealed+=(int)$bucket['SealedCartons'];$loose+=(float)$bucket['LooseBaseQuantity'];}
        return ['Agreements'=>$buckets,'SealedCartons'=>$sealed,'LooseBaseQuantity'=>$loose,'AvailablePackEquivalent'=>($loose+$sealed*(float)$conversion['CartonToBase'])/(float)$conversion['PackToBase'],'Conversion'=>$conversion];
    }
    private function consumePackagingPackBaseAcrossBuckets(array &$buckets,float $baseQuantity,array $conversion):void
    {
        $remaining=max(0,$baseQuantity);foreach($buckets as&$bucket){$available=max(0,(float)$bucket['LooseBaseQuantity'])+max(0,(int)$bucket['SealedCartons'])*(float)$conversion['CartonToBase'];$take=min($available,$remaining);if($take>0)$this->consumePackagingPackBase($bucket,$take,$conversion);$remaining-=$take;if($remaining<=0.000001)break;}unset($bucket);
    }
    private function assertAvailablePackagingSaleItems(array $items,string $excludeSaleId=''):void
    {
        $states=[];foreach($items as$item){$productId=(string)($item['ProductID']??'');$variant=(string)($item['Variant']??'');$product=$this->product($productId);if(!$product||strtoupper((string)($product['OptionType']??'VARIANT'))!=='PACKAGING')continue;$key=$productId.'|'.$variant;if(!array_key_exists($key,$states))$states[$key]=$this->packagingStockBuckets($productId,$variant,true,$excludeSaleId);$state=&$states[$key];if(!$state)throw new RuntimeException('Packaging conversion is incomplete for '.$product['ProductName'].' ('.$variant.'). Repair its Pack and CTN configuration before sale.');$unit=(string)($item['UnitName']??'');$base=(float)($item['BaseQuantity']??0);$conversion=$state['Conversion'];
            if(strcasecmp(trim($unit),$conversion['CartonUnit'])===0){$need=(float)($item['Quantity']??0);if(abs($need-round($need))>0.000001||$need>(int)$state['SealedCartons'])throw new RuntimeException('Only whole sealed CTN can be sold for '.$product['ProductName'].' ('.$variant.'). Broken carton stock can only be sold as Pcs.');$remaining=$need;foreach($state['Agreements']as&$bucket){$take=min((int)$bucket['SealedCartons'],(int)$remaining);$bucket['SealedCartons']-=$take;$remaining-=$take;if($remaining<=0)break;}unset($bucket);$state['SealedCartons']=0;foreach($state['Agreements']as$bucket)$state['SealedCartons']+=(int)$bucket['SealedCartons'];
            }else{if($base>$state['LooseBaseQuantity']+$state['SealedCartons']*(float)$conversion['CartonToBase']+0.000001)throw new RuntimeException('Insufficient Pack-equivalent stock for '.$product['ProductName'].' ('.$variant.').');$this->consumePackagingPackBaseAcrossBuckets($state['Agreements'],$base,$conversion);$state['SealedCartons']=0;$state['LooseBaseQuantity']=0.0;foreach($state['Agreements']as$bucket){$state['SealedCartons']+=(int)$bucket['SealedCartons'];$state['LooseBaseQuantity']+=(float)$bucket['LooseBaseQuantity'];}}
            unset($state);
        }
    }
    private function packagingStockPlan(string $productId,string $variant,string $unitName,float $baseQuantity,string $excludeSaleId):array
    {
        $state=$this->packagingStockBuckets($productId,$variant,true,$excludeSaleId);if(!$state)return$this->stockPlan($productId,$baseQuantity,$variant);$buckets=$state['Agreements'];$conversion=$state['Conversion'];$plan=[];$remaining=$baseQuantity;
        if(strcasecmp(trim($unitName),$conversion['CartonUnit'])===0){$need=(int)round($baseQuantity/(float)$conversion['CartonToBase']);foreach($buckets as$agreementId=>$bucket){$take=min($need,(int)$bucket['SealedCartons']);if($take>0){$plan[]=['AgreementID'=>$agreementId,'Quantity'=>$take*(float)$conversion['CartonToBase']];$need-=$take;}if($need>0)break;}if($need>0)throw new RuntimeException('Insufficient sealed CTN stock. Choose Pcs for loose or broken stock.');return$plan;}
        foreach($buckets as$agreementId=>$bucket){$available=max(0,(float)$bucket['LooseBaseQuantity'])+max(0,(int)$bucket['SealedCartons'])*(float)$conversion['CartonToBase'];$take=min($available,$remaining);if($take>0){$plan[]=['AgreementID'=>$agreementId,'Quantity'=>$take];$remaining-=$take;}if($remaining<=0.000001)break;}if($remaining>0.000001)throw new RuntimeException('Insufficient Pack-equivalent stock.');return$plan;
    }
    private function recordStock(string $agreement,string $product,string $date,string $type,string $reference,string $unit,float $in,float $out,float $cost,string $note='',bool $gift=false,string $variant=''):void{$balance=$this->stockBalance($agreement,$product,$variant)+$in-$out;if($balance<-0.000001)throw new RuntimeException('Stock cannot become negative.');$this->insert('StockLedger',['LedgerID'=>$this->id('StockLedger','LedgerID','STK'),'AgreementID'=>$agreement,'ProductID'=>$product,'TransactionDate'=>$date,'TransactionType'=>$type,'ReferenceID'=>$reference,'UnitName'=>$unit,'Quantity'=>$in?:$out,'BaseQuantityIn'=>$in,'BaseQuantityOut'=>$out,'BalanceQuantity'=>$balance,'UnitCost'=>$cost,'Description'=>$note,'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current(),'IsGift'=>$gift?1:0,'Variant'=>$variant]);}
    private function purchasedPackSizesInGrams(string $productId,string $variant=''):array
    {
        $statement=$this->db->prepare('SELECT DISTINCT i.UnitName FROM GoodsReceiptItems i JOIN GoodsReceipts r ON r.GRNID=i.GRNID WHERE i.ProductID=? AND COALESCE(i.Variant,"")=? AND r.Status="POSTED"');$statement->execute([$productId,$variant]);$sizes=[];foreach($statement->fetchAll(PDO::FETCH_COLUMN)as$unitName){if(!preg_match('/^(?:Pack|CTN)\s*\((\d+(?:\.\d+)?)\s*(kg|kgs|kilograms?|g|gm|gms|grams?|mg|milligrams?)\)$/i',(string)$unitName,$match))continue;$factor=$this->massUnitKgFactor($match[2]);if(!$factor)continue;$size=(float)$match[1]*$factor*1000;if($size>0&&!in_array(round($size,6),$sizes,true))$sizes[]=round($size,6);}sort($sizes,SORT_NUMERIC);return$sizes;
    }
    private function inventory():array
    {
        $this->user();
        $receiptDetailsByProduct=[];
        $receiptCostByProduct=[];
        $grnRegister=$this->grnRegister();
        foreach($grnRegister['Receipts']as$receipt){
            if(strtoupper((string)($receipt['Status']??''))!=='POSTED')continue;
            foreach((array)($receipt['Items']??[])as$item){
                $receiptKey=(string)($item['ProductID']??'').'|'.(string)($item['Variant']??'');
                $receiptDetailsByProduct[$receiptKey][]=['GRNID'=>$receipt['GRNID']??'','SupplyID'=>$receipt['SupplyID']??'','ReferenceNo'=>$receipt['ReferenceNo']??'','ReceiptDate'=>$receipt['ReceiptDate']??'','GateEntryNo'=>$receipt['GateEntryNo']??'','DeliveryChallan'=>$receipt['DeliveryChallan']??'','Notes'=>$receipt['Notes']??'','UnitName'=>$item['UnitName']??'','ReceivedQuantity'=>(float)($item['ReceivedQuantity']??0),'AcceptedQuantity'=>(float)($item['AcceptedQuantity']??0),'RejectedQuantity'=>(float)($item['RejectedQuantity']??0),'BaseAcceptedQuantity'=>(float)($item['BaseAcceptedQuantity']??0),'UnitCost'=>(float)($item['UnitCost']??0),'Amount'=>(float)($item['BaseAcceptedQuantity']??0)*(float)($item['UnitCost']??0)];
                if(empty($item['IsGift'])){
                    $accepted=(float)($item['BaseAcceptedQuantity']??0);
                    if($accepted>0){$receiptCostByProduct[$receiptKey]['Quantity']=($receiptCostByProduct[$receiptKey]['Quantity']??0)+$accepted;$receiptCostByProduct[$receiptKey]['Value']=($receiptCostByProduct[$receiptKey]['Value']??0)+$accepted*(float)($item['UnitCost']??0);}
                }
            }
        }
        $incomingForecastByProduct=[];
        foreach((array)($grnRegister['PendingOrders']??[])as$order)foreach((array)($order['Items']??[])as$item){
            $receiptKey=(string)($item['ProductID']??'').'|'.(string)($item['Variant']??'');
            $incomingForecastByProduct[$receiptKey]=($incomingForecastByProduct[$receiptKey]??0)+(float)($item['RemainingQuantity']??0)*(float)($item['ConversionToBase']??0);
        }
        $outgoingForecastByProduct=[];
        foreach($this->rows('DealerSales','Status=?',['CONFIRMED'])as$sale)foreach($this->rows('DealerSaleItems','SaleID=?',[$sale['SaleID']])as$item){
            $receiptKey=(string)($item['ProductID']??'').'|'.(string)($item['Variant']??'');
            $outgoingForecastByProduct[$receiptKey]=($outgoingForecastByProduct[$receiptKey]??0)+(float)($item['BaseQuantity']??0);
        }
        $out=[];
        foreach($this->products()as$p){
            $variants=$this->variants($p)?:[''];
            foreach($variants as$variant){
                $key=$p['ProductID'].'|'.$variant;
                $onHand=(float)$this->value('SELECT COALESCE(SUM(BaseQuantityIn-BaseQuantityOut),0) FROM StockLedger WHERE ProductID=? AND COALESCE(Variant,"")=?',[$p['ProductID'],$variant]);
                $gift=(float)$this->value('SELECT COALESCE(SUM(CASE WHEN IsGift=1 THEN BaseQuantityIn-BaseQuantityOut ELSE 0 END),0) FROM StockLedger WHERE ProductID=? AND COALESCE(Variant,"")=?',[$p['ProductID'],$variant]);
                $reserved=0.0;
                foreach($this->rows('DealerSaleItems','ProductID=? AND COALESCE(Variant,"")=?',[$p['ProductID'],$variant])as$item){$sale=$this->one('DealerSales','SaleID',$item['SaleID']);if($sale&&in_array($sale['Status'],['DRAFT','CONFIRMED'],true))$reserved+=(float)$item['BaseQuantity'];}
                $units=array_values(array_filter($this->units($p['ProductID']),fn($u)=>strtoupper($u['Status'])!=='ARCHIVED'));
                $base=array_values(array_filter($units,fn($u)=>strcasecmp($u['UnitName'],$p['BaseUnit'])===0))[0]??($units[0]??['PurchasePrice'=>0]);
                $unitRows=array_map(fn($u)=>['UnitName'=>$u['UnitName'],'Quantity'=>(float)$u['ConversionToBase']?$onHand/(float)$u['ConversionToBase']:0],$units);
                $optionType=strtoupper((string)($p['OptionType']??'VARIANT'));
                $packageSizeBase=$optionType==='PACKAGING'?$this->packVariantToBase($p,$variant):null;
                $packageCount=$packageSizeBase&&$packageSizeBase>0?max(0,$onHand/$packageSizeBase):null;
                $availableOnHand=max(0,$onHand-$reserved);
                $availablePackageCount=$packageSizeBase&&$packageSizeBase>0?max(0,$availableOnHand/$packageSizeBase):null;
                $packagingBuckets=$optionType==='PACKAGING'?$this->packagingStockBuckets((string)$p['ProductID'],(string)$variant,true):null;
                $availableSealedCartons=$packagingBuckets?(int)$packagingBuckets['SealedCartons']:null;
                $availableLoosePackEquivalent=$packagingBuckets?(float)$packagingBuckets['LooseBaseQuantity']/(float)$packagingBuckets['Conversion']['PackToBase']:null;
                $incoming=(float)($incomingForecastByProduct[$key]??0);$outgoing=(float)($outgoingForecastByProduct[$key]??0);$forecastedOnHand=$onHand+$incoming-$outgoing;
                $cartonUnit=null;$pieceUnit=null;$packUnit=null;
                foreach($units as$unit){$unitName=trim((string)($unit['UnitName']??''));$conversion=(float)($unit['ConversionToBase']??0);if($conversion<=0)continue;
                    if($optionType==='PACKAGING'){
                        if(strcasecmp($unitName,'CTN ('.$variant.')')===0)$cartonUnit=$unit;
                        if(strcasecmp($unitName,'Pack ('.$variant.')')===0)$packUnit=$unit;
                    }
                    $normalized=preg_replace('/\s*\([^)]*\)\s*$/','',$unitName);$normalized=strtolower(trim((string)$normalized));
                    if(!$cartonUnit&&in_array($normalized,['carton','cartons','ctn'],true))$cartonUnit=$unit;
                    if(!$pieceUnit&&in_array($normalized,['piece','pieces','pc','pcs'],true))$pieceUnit=$unit;
                }
                if($optionType==='PACKAGING'&&$packUnit)$pieceUnit=$packUnit;
                $cartonConversion=(float)($cartonUnit['ConversionToBase']??0);$pieceConversion=(float)($pieceUnit['ConversionToBase']??0);
                $piecesPerCarton=$cartonConversion>0&&$pieceConversion>0?round($cartonConversion/$pieceConversion):0;
                $toPieceCount=static function(float$baseQuantity)use($cartonConversion,$pieceConversion,$piecesPerCarton):?float{if($pieceConversion<=0)return null;if($cartonConversion>0&&$piecesPerCarton>0)return round(($baseQuantity/$cartonConversion)*$piecesPerCarton);return round($baseQuantity/$pieceConversion);};
                $priceUnit=$optionType==='PACKAGING'&&$packUnit?$packUnit:$base;
                $priceUnitConversion=(float)($priceUnit['ConversionToBase']??1);if($priceUnitConversion<=0)$priceUnitConversion=1;
                $onHandDisplayConversion=$cartonConversion>0?$cartonConversion:1;$onHandDisplayUnit=$cartonUnit['UnitName']??($base['UnitName']??$p['BaseUnit']);
                $supplier=$this->agreement($p['SupplierID']);
                $cost=$receiptCostByProduct[$key]??[];
                $averagePurchaseCost=(float)($cost['Quantity']??0)>0?(float)$cost['Value']/(float)$cost['Quantity']:(float)($base['PurchasePrice']??0);
                $out[]=['ProductID'=>$p['ProductID'],'InventoryReceipts'=>$receiptDetailsByProduct[$key]??[],'SupplierID'=>$p['SupplierID'],'SupplierName'=>$supplier['Company']??'','SKU'=>$this->sku($supplier['Company']??'SUP',$p['ProductName'],$variant,$p['ProductID']),'ProductName'=>$p['ProductName'],'Category'=>$p['Category'],'Tag'=>strtoupper((string)($p['ProductType']??''))==='GIFT'?'GIFT':(strtoupper((string)($p['PromotionType']??'NON_CP'))==='WITH_CP'?'CP':'NON-CP'),'PromotionType'=>$p['PromotionType']??'NON_CP','PromotionGifts'=>$p['PromotionGifts']??'[]','Variant'=>$variant,'OptionType'=>$optionType,'PackagingSize'=>$optionType==='PACKAGING'?$variant:'','PackageConversionToBase'=>$packageSizeBase,'PackageCount'=>$packageCount,'AvailablePackageCount'=>$availablePackageCount,'AvailableSealedCartons'=>$availableSealedCartons,'AvailableLoosePackEquivalent'=>$availableLoosePackEquivalent,'PackageUnitName'=>$optionType==='PACKAGING'?'Pack ('.$variant.')':'','PurchasedPackSizes'=>$this->purchasedPackSizesInGrams($p['ProductID'],$variant),'AllVariants'=>$p['Variant'],'UnitOfMeasure'=>implode(', ',array_column($units,'UnitName')),'OnHandByUnit'=>$unitRows,'OnHandGiftByUnit'=>array_map(fn($u)=>['UnitName'=>$u['UnitName'],'Quantity'=>(float)$u['ConversionToBase']?$gift/(float)$u['ConversionToBase']:0],$units),'OnHandGift'=>$gift,'ProductType'=>$p['ProductType'],'Status'=>$p['Status'],'BaseUnit'=>$p['BaseUnit'],'OnHand'=>$onHand,'OnHandDisplayQuantity'=>$onHand/$onHandDisplayConversion,'OnHandDisplayUnit'=>$onHandDisplayUnit,'AvailableOnHand'=>$availableOnHand,'ReservedForSale'=>$reserved,'IncomingForecast'=>$incoming,'OutgoingForecast'=>$outgoing,'ForecastedPurchaseDisplayQuantity'=>$incoming/$onHandDisplayConversion,'ForecastedPurchaseDisplayUnit'=>$onHandDisplayUnit,'ForecastedPurchasePieces'=>$toPieceCount($incoming),'ForecastedSaleDisplayQuantity'=>$outgoing/$onHandDisplayConversion,'ForecastedSaleDisplayUnit'=>$onHandDisplayUnit,'ForecastedSalePieces'=>$toPieceCount($outgoing),'ForecastedOnHand'=>$forecastedOnHand,'ForecastedDisplayQuantity'=>$forecastedOnHand/$onHandDisplayConversion,'ForecastedDisplayUnit'=>$onHandDisplayUnit,'CartonConversionToBase'=>$cartonConversion?:null,'PieceConversionToBase'=>$pieceConversion?:null,'OnHandCartons'=>$cartonConversion>0?$onHand/$cartonConversion:null,'OnHandPieces'=>$toPieceCount($onHand),'ForecastedCartons'=>$cartonConversion>0?$forecastedOnHand/$cartonConversion:null,'ForecastedPieces'=>$toPieceCount($forecastedOnHand),'CartonUnitName'=>$cartonUnit['UnitName']??'','PieceUnitName'=>$pieceUnit['UnitName']??'','PriceUnitName'=>$priceUnit['UnitName']??($p['BaseUnit']??''),'SalePrice'=>(float)($priceUnit['SalePrice']??$priceUnit['UnitPrice']??0),'Cost'=>$averagePurchaseCost*$priceUnitConversion,'StockValue'=>max(0,$onHand-$gift)*$averagePurchaseCost];
            }
        }
        return$out;
    }
    private function damages():array{$this->user();$out=[];foreach($this->rows('StockLedger','TransactionType="PRODUCT_DAMAGE"') as $r){$p=$this->product($r['ProductID']);$a=$this->agreement($r['AgreementID']);$reversal=$this->one('InventoryDamageReversals','DamageID',(string)$r['ReferenceID']);$out[]=['DamageID'=>$r['ReferenceID'],'ProductID'=>$r['ProductID'],'ProductName'=>$p['ProductName']??'','SupplierID'=>$r['AgreementID'],'SupplierName'=>$a['Company']??'','TransactionDate'=>$r['TransactionDate'],'Variant'=>$r['Variant'],'UnitName'=>$r['UnitName'],'Quantity'=>(float)$r['BaseQuantityOut']/(float)($this->productUnit($r['ProductID'],$r['UnitName'])['ConversionToBase']??1),'BaseQuantityOut'=>$r['BaseQuantityOut'],'BaseUnit'=>$p['BaseUnit']??'','UnitCost'=>$r['UnitCost'],'DamageValue'=>(float)$r['BaseQuantityOut']*(float)$r['UnitCost'],'Description'=>$r['Description'],'CreatedAt'=>$r['CreatedAt'],'CreatedBy'=>$r['CreatedBy'],'IsReversed'=>(bool)$reversal,'ReversalReferenceID'=>$reversal['ReversalReferenceID']??'','ReversedAt'=>$reversal['ReversedAt']??'','ReversedBy'=>$reversal['ReversedBy']??'','ReversalReason'=>$reversal['Reason']??''];}return array_reverse($out);}
    private function createDamage(array $input):array
    {
        $this->user();$p=$this->product($this->text($input['ProductID']??''));if(!$p)throw new InvalidArgumentException('Select a valid product.');
        $description=$this->text($input['Description']??'');if(!$description)throw new InvalidArgumentException('Damage reason / notes are required.');
        $variant=$this->text($input['Variant']??'');$valid=$this->variants($p);if($valid&&!in_array(strtolower($variant),array_map('strtolower',$valid),true))throw new InvalidArgumentException('Select a valid product option.');if(!$valid)$variant='';
        $unit=$this->productUnit($p['ProductID'],$this->text($input['UnitName']??''),false,$variant);$quantity=$this->num($input['Quantity']??'','Damaged quantity');
        if(strtoupper((string)($p['OptionType']??'VARIANT'))==='PACKAGING'){
            $expected=$this->packVariantToBase($p,$variant);$selected=(string)$unit['UnitName'];$isPack=strcasecmp($selected,'Pack ('.$variant.')')===0;$isCarton=strcasecmp($selected,'CTN ('.$variant.')')===0;if($expected===null||(!$isPack&&!$isCarton)||($isPack&&abs((float)$unit['ConversionToBase']-$expected)>0.000001))throw new InvalidArgumentException('Select the matching sealed pack or CTN unit.');if($isCarton){$pieces=(float)$unit['ConversionToBase']/(float)$expected;if($pieces<1||abs($pieces-round($pieces))>0.00001)throw new InvalidArgumentException('The configured CTN conversion is not a whole number of sealed packs.');}
            if(abs($quantity-round($quantity))>0.000001)throw new InvalidArgumentException('Damaged sealed package or CTN quantity must be a whole number.');
        }
        $base=$quantity*(float)$unit['ConversionToBase'];if($base>$this->stockBalance($p['SupplierID'],$p['ProductID'],$variant)+0.000001)throw new RuntimeException('Damaged quantity exceeds available stock.');
        $id=$this->id('StockLedger','ReferenceID','DMG');$cost=(float)$unit['PurchasePrice']/(float)$unit['ConversionToBase'];$this->db->beginTransaction();try{$this->recordStock($p['SupplierID'],$p['ProductID'],$this->requiredDate($input['TransactionDate']??'','Damage date'),'PRODUCT_DAMAGE',$id,$unit['UnitName'],0,$base,$cost,$description,false,$variant);$this->audit('CREATE','InventoryDamage',$id,'Product damage recorded; stock quantity reduced.');$this->db->commit();}catch(Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
        return ['DamageID'=>$id,'ProductID'=>$p['ProductID'],'ProductName'=>$p['ProductName'],'SupplierID'=>$p['SupplierID'],'Variant'=>$variant,'UnitName'=>$unit['UnitName'],'Quantity'=>$quantity,'BaseQuantityOut'=>$base,'BaseUnit'=>$p['BaseUnit'],'DamageValue'=>$base*$cost,'TransactionDate'=>$this->requiredDate($input['TransactionDate']??'','Damage date'),'Description'=>$description];
    }

    private function reverseProductDamage(string $damageId, string $reason): array
    {
        $this->user(true);
        $damageId = $this->text($damageId);
        $reason = $this->text($reason);
        if ($damageId === '' || $reason === '') throw new InvalidArgumentException('Select a damage record and enter a correction reason.');
        if (strlen($reason) > 500) throw new InvalidArgumentException('Correction reason must be 500 characters or fewer.');
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT * FROM StockLedger WHERE TransactionType="PRODUCT_DAMAGE" AND ReferenceID=? FOR UPDATE');
            $query->execute([$damageId]);
            $damage = $query->fetch();
            if (!$damage) throw new RuntimeException('Damage record was not found.');
            if ($this->one('InventoryDamageReversals', 'DamageID', $damageId)) throw new RuntimeException('This damage record has already been reversed.');
            $reversalId = $this->id('StockLedger', 'ReferenceID', 'DREV');
            $quantity = (float) $damage['BaseQuantityOut'];
            $this->recordStock((string)$damage['AgreementID'], (string)$damage['ProductID'], $this->today(), 'DAMAGE_REVERSAL', $reversalId,
                (string)$damage['UnitName'], $quantity, 0, (float)$damage['UnitCost'], 'Reversal of damage ' . $damageId . ': ' . $reason, (bool)$damage['IsGift'], (string)($damage['Variant'] ?? ''));
            $this->insert('InventoryDamageReversals', ['DamageID'=>$damageId,'ReversalReferenceID'=>$reversalId,'ReversedAt'=>$this->now(),'ReversedBy'=>$this->current(),'Reason'=>$reason]);
            $this->audit('REVERSE', 'InventoryDamage', $damageId, 'Damage movement reversed as ' . $reversalId . '. Reason: ' . $reason);
            $this->db->commit();
            return ['DamageID'=>$damageId,'ReversalReferenceID'=>$reversalId,'Status'=>'REVERSED'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function supplies():array{ $this->user();return array_map(function(array $row):array{$row['PaidAmount']=$this->supplyPaid($row['SupplyID']);$row['PaymentStatus']=$this->paymentStatus((float)$row['TotalAmount'],(float)$row['PaidAmount']);return$row;},$this->rows('CompanySupplies','Status<>?', ['ARCHIVED'])); }
    private function supply(string $id):?array{foreach($this->supplies()as$row)if($row['SupplyID']===$id){$row['Items']=$this->rows('CompanySupplyItems','SupplyID=?',[$id]);$row['HasGoodsReceipts']=(bool)$this->value('SELECT COUNT(*) FROM GoodsReceipts WHERE SupplyID=?',[$id]);return$row;}return null;}
    private function purchaseOrderRegister():array
    {
        $this->user();
        $orders=$this->supplies();$orderStatus=[];foreach($orders as$order)$orderStatus[$order['SupplyID']]=strtoupper((string)$order['Status']);
        $products=[];foreach($this->rows('Products')as$product)$products[$product['ProductID']]=$product;
        $receiptStatus=[];foreach($this->rows('GoodsReceipts')as$receipt)$receiptStatus[$receipt['GRNID']]=$receipt['Status'];
        $postedAccepted=[];$draftReceived=[];
        foreach($this->rows('GoodsReceiptItems')as$receiptItem){$status=$receiptStatus[$receiptItem['GRNID']]??'';$key=$receiptItem['SupplyItemID'];if($status==='POSTED')$postedAccepted[$key]=($postedAccepted[$key]??0)+(float)$receiptItem['BaseAcceptedQuantity'];elseif($status==='DRAFT')$draftReceived[$key]=($draftReceived[$key]??0)+(float)$receiptItem['BaseReceivedQuantity'];}
        $itemsByOrder=[];
        foreach($this->rows('CompanySupplyItems')as$item){$product=$products[$item['ProductID']]??[];$conversion=max(0.000001,(float)$item['ConversionToBase']);$baseQuantity=(float)$item['BaseQuantity'];if($baseQuantity<=0)$baseQuantity=(float)$item['Quantity']*$conversion;$receivedBase=(float)($postedAccepted[$item['SupplyItemID']]??0);$grnBase=(float)($draftReceived[$item['SupplyItemID']]??0);$isOpen=in_array($orderStatus[$item['SupplyID']]??'', ['CONFIRMED','PARTIALLY_RECEIVED'],true);$waitingBase=$isOpen?max(0,$baseQuantity-$receivedBase-$grnBase):0;$unitCostBase=(float)$item['UnitPrice']/$conversion;
            $item['Category']=$this->text($product['Category']??'')?:'Uncategorized';$item['ProductName']=$product['ProductName']??$item['ProductID'];$item['BaseUnit']=$product['BaseUnit']??$product['UnitOfMeasure']??$item['UnitName'];$item['OrderedBaseQuantity']=$baseQuantity;$item['WaitingBaseQuantity']=$waitingBase;$item['DraftGrnBaseQuantity']=$grnBase;$item['ReceivedBaseQuantity']=$receivedBase;$item['WaitingAmount']=$waitingBase*$unitCostBase;$item['DraftGrnAmount']=$grnBase*$unitCostBase;$item['ReceivedAmount']=$receivedBase*$unitCostBase;$itemsByOrder[$item['SupplyID']][]=$item;
        }
        foreach($orders as&$order)$order['Items']=$itemsByOrder[$order['SupplyID']]??[];unset($order);return['Orders'=>$orders];
    }
    private function supplyPaid(string $id):float{return(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM Payments WHERE SupplyID=? AND Status="POSTED"',[$id]);}
    private function paymentStatus(float $total,float $paid):string{return$total<=0||$paid>=$total?'PAID':($paid>0?'PARTIAL':'UNPAID');}
    private function assertSupplyItems(array $items,string $supplier):void{foreach($items as $line){$p=$this->product($this->text($line['ProductID']??''));if(!$p)throw new RuntimeException('Product was not found.');if($p['SupplierID']!==$supplier)throw new RuntimeException('Product "'.$p['ProductName'].'" belongs to another Supplier.');}}
    private function writeSupplyItems(string $id,array $items):void{$before=$this->rows('CompanySupplyItems','SupplyID=? ORDER BY SupplyItemID',[$id]);$this->db->prepare('DELETE FROM CompanySupplyItems WHERE SupplyID=?')->execute([$id]);$now=$this->now();foreach($items as $item)$this->insert('CompanySupplyItems',['SupplyItemID'=>$this->id('CompanySupplyItems','SupplyItemID','SPI'),'SupplyID'=>$id,'ProductID'=>$item['ProductID'],'UnitName'=>$item['UnitName'],'Quantity'=>$item['Quantity'],'ConversionToBase'=>$item['ConversionToBase'],'BaseQuantity'=>$item['BaseQuantity'],'UnitPrice'=>$item['UnitPrice'],'LineTotal'=>$item['LineTotal'],'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'IsGift'=>$item['IsGift'],'Variant'=>$item['Variant'],'PromotionType'=>$item['PromotionType'],'PromotionGifts'=>$item['PromotionGifts'],'OfferProductID'=>$item['OfferProductID']??null,'OfferProductName'=>$item['OfferProductName']??null,'OfferVariant'=>$item['OfferVariant']??null,'OfferUnitName'=>$item['OfferUnitName']??null,'OfferQuantity'=>$item['OfferQuantity']??null,'OfferUnitPrice'=>$item['OfferUnitPrice']??null,'OfferConversionToBase'=>$item['OfferConversionToBase']??null,'OfferBaseQuantity'=>$item['OfferBaseQuantity']??null]);$this->recordDetailHistory('CompanySupplies',$id,'Items',$before,$this->rows('CompanySupplyItems','SupplyID=? ORDER BY SupplyItemID',[$id]));}
    private function syncPurchasePricesToProductUnits(array $items):void
    {
        $updates=[];
        foreach($items as $item){
            if(!is_array($item)||!empty($item['IsGift']))continue;
            $productId=$this->text($item['ProductID']??'');$unitName=$this->text($item['UnitName']??'');$price=(float)($item['UnitPrice']??0);
            if($productId===''||$unitName===''||$price<=0)continue;
            $product=$this->product($productId);if(!$product)continue;
            $units=array_values(array_filter($product['Units']??[],static fn($unit)=>strtoupper((string)($unit['Status']??'ACTIVE'))!=='ARCHIVED'));
            $piecesPerCarton=(float)($item['PiecesPerCarton']??0);$baseName=(string)($product['BaseUnit']??$product['UnitOfMeasure']??'');
            if($piecesPerCarton>=1&&preg_match('/^(?:pc|pcs|piece|pieces)$/i',$baseName)&&preg_match('/^(?:ctn|cartons?)$/i',$unitName)){
                $carton=null;$base=null;foreach($units as$candidate){if(strcasecmp((string)($candidate['UnitName']??''),$baseName)===0)$base=$candidate;if(preg_match('/^(?:ctn|cartons?)$/i',(string)($candidate['UnitName']??'')))$carton=$candidate;}
                $cartonName=(string)($carton['UnitName']??$unitName);$salePerPiece=(float)($base['SalePrice']??$base['UnitPrice']??0);$cartonSale=$salePerPiece*$piecesPerCarton;$now=$this->now();
                $cartonRow=['ProductID'=>$productId,'UnitName'=>$cartonName,'ConversionToBase'=>$piecesPerCarton,'PurchaseAllowed'=>1,'SaleAllowed'=>1,'UnitPrice'=>$cartonSale,'Status'=>'ACTIVE','UpdatedAt'=>$now,'UpdatedBy'=>$this->current(),'SalePrice'=>$cartonSale,'PurchasePrice'=>$price];
                if($carton){$this->update('ProductUnits','ProductUnitID',(string)$carton['ProductUnitID'],$cartonRow);}else{$this->insert('ProductUnits',['ProductUnitID'=>$this->id('ProductUnits','ProductUnitID','UNT'),'CreatedAt'=>$now,'CreatedBy'=>$this->current()]+$cartonRow);}
                $unitName=$cartonName;$product=$this->product($productId);$units=array_values(array_filter($product['Units']??[],static fn($unit)=>strtoupper((string)($unit['Status']??'ACTIVE'))!=='ARCHIVED'));
            }
            $selected=null;foreach($units as $unit)if(strcasecmp((string)($unit['UnitName']??''),$unitName)===0){$selected=$unit;break;}
            if(!$selected)continue;
            $updates[$productId.'|'.strtolower($unitName)]=[$productId,$unitName,$price];
            $optionType=strtoupper((string)($product['OptionType']??'VARIANT'));
            if($optionType==='PACKAGING'&&preg_match('/^(CTN|Pack)\s*\((.+)\)$/i',$unitName,$match)){
                $counterpart=(strcasecmp($match[1],'CTN')===0?'Pack':'CTN').' ('.$match[2].')';$other=null;
                foreach($units as $unit)if(strcasecmp((string)($unit['UnitName']??''),$counterpart)===0){$other=$unit;break;}
                $ratio=(float)($selected['ConversionToBase']??0)/(float)($other['ConversionToBase']??0);
                if($other&&$ratio>0){$otherPrice=$price/$ratio;$updates[$productId.'|'.strtolower($counterpart)]=[$productId,$counterpart,$otherPrice];}
            }elseif(preg_match('/^(?:CTN|CARTON)(?:\b|\s*\()/i',$unitName)){
                $baseName=(string)($product['BaseUnit']??$product['UnitOfMeasure']??'');$base=null;
                foreach($units as $unit)if(strcasecmp((string)($unit['UnitName']??''),$baseName)===0){$base=$unit;break;}
                $ratio=(float)($selected['ConversionToBase']??0)/(float)($base['ConversionToBase']??0);
                if($base&&$ratio>0)$updates[$productId.'|'.strtolower($baseName)]=[$productId,$baseName,$price/$ratio];
            }elseif(strcasecmp($unitName,(string)($product['BaseUnit']??''))===0||preg_match('/^PIECE?S?$/i',$unitName)){
                foreach($units as $unit)if(preg_match('/^(?:CTN|CARTON)(?:\b|\s*\()/i',(string)($unit['UnitName']??''))){
                    $ratio=(float)($unit['ConversionToBase']??0)/(float)($selected['ConversionToBase']??0);
                    if($ratio>0){$cartonName=(string)$unit['UnitName'];$updates[$productId.'|'.strtolower($cartonName)]=[$productId,$cartonName,$price*$ratio];}
                }
            }
        }
        $statement=$this->db->prepare('UPDATE ProductUnits SET PurchasePrice=?, UpdatedAt=?, UpdatedBy=? WHERE ProductID=? AND UnitName=? AND Status<>"ARCHIVED"');
        foreach($updates as [$productId,$unitName,$price])$statement->execute([$price,$this->now(),$this->current(),$productId,$unitName]);
    }
    private function createSupply(array $input):array
    {
        $this->user();$key=$this->text($input['IdempotencyKey']??'');if($key&&($prior=$this->value('SELECT SupplyID FROM CompanySupplies WHERE IdempotencyKey=?',[$key])))return$this->supply((string)$prior);$supplier=$this->agreement($this->text($input['AgreementID']??''));if(!$supplier||$supplier['Status']!=='APPROVED')throw new InvalidArgumentException('Select an approved Supplier.');$this->assertSupplyItems((array)($input['Items']??[]),$supplier['AgreementID']);$items=$this->normalizeItems((array)($input['Items']??[]),'PURCHASE',(float)$supplier['CommissionRate']);if(!$items)throw new InvalidArgumentException('Add at least one product.');$total=array_sum(array_column($items,'LineTotal'));$this->db->beginTransaction();try{$id=$this->id('CompanySupplies','SupplyID','PO');$now=$this->now();$this->insert('CompanySupplies',['SupplyID'=>$id,'AgreementID'=>$supplier['AgreementID'],'SupplyDate'=>$this->requiredDate($input['SupplyDate']??'','Purchase date'),'ReferenceNo'=>$this->text($input['ReferenceNo']??''),'TotalAmount'=>$total,'Status'=>'DRAFT','Notes'=>$this->text($input['Notes']??''),'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current(),'PaymentStatus'=>$total>0?'UNPAID':'PAID','IdempotencyKey'=>$key?:null]);$this->writeSupplyItems($id,$items);$this->syncPurchasePricesToProductUnits($items);$this->audit('CREATE','CompanySupplies',$id,'Purchase Order created.');$this->db->commit();return$this->supply($id);}catch(Throwable$e){$this->db->rollBack();throw$e;}
    }
    private function updateSupply(string $id,array $input):array
    {
        $old=$this->supply($id);if(!$old)throw new RuntimeException('Purchase Order was not found.');
        if($old['Status']==='CANCELLED')throw new RuntimeException('A cancelled Purchase Order cannot be edited.');
        $hasReceipts=!empty($old['HasGoodsReceipts'])||in_array($old['Status'],['PARTIALLY_RECEIVED','RECEIVED'],true);
        $agreementId=$this->text($input['AgreementID']??$old['AgreementID']);
        if($hasReceipts){
            if($agreementId!==$old['AgreementID'])throw new RuntimeException('Supplier cannot be changed after a Goods Receipt has been created.');
            $this->db->beginTransaction();try{$this->update('CompanySupplies','SupplyID',$id,['SupplyDate'=>$this->requiredDate($input['SupplyDate']??'','Purchase date'),'ReferenceNo'=>$this->text($input['ReferenceNo']??''),'Notes'=>$this->text($input['Notes']??''),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('UPDATE','CompanySupplies',$id,'Purchase Order header updated; supplier and item lines remained locked after Goods Receipt.');$this->db->commit();return$this->supply($id);}catch(Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
        }
        if(!in_array($old['Status'],['DRAFT','CONFIRMED'],true))throw new RuntimeException('This Purchase Order can no longer be edited.');
        $supplier=$this->agreement($agreementId);if(!$supplier||$supplier['Status']!=='APPROVED')throw new InvalidArgumentException('Select an approved Supplier.');
        $this->assertSupplyItems((array)($input['Items']??[]),$supplier['AgreementID']);$items=$this->inheritPromotionSnapshots($this->normalizeItems((array)($input['Items']??[]),'PURCHASE',(float)$supplier['CommissionRate']),(array)($old['Items']??[]));if(!$items)throw new InvalidArgumentException('Add at least one product.');
        $this->db->beginTransaction();try{$total=array_sum(array_column($items,'LineTotal'));$this->update('CompanySupplies','SupplyID',$id,['AgreementID'=>$supplier['AgreementID'],'SupplyDate'=>$this->requiredDate($input['SupplyDate']??'','Purchase date'),'ReferenceNo'=>$this->text($input['ReferenceNo']??''),'TotalAmount'=>$total,'Notes'=>$this->text($input['Notes']??''),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->writeSupplyItems($id,$items);$this->syncPurchasePricesToProductUnits($items);$this->audit('UPDATE','CompanySupplies',$id,'Purchase Order updated.');$this->db->commit();return$this->supply($id);}catch(Throwable$e){if($this->db->inTransaction())$this->db->rollBack();throw$e;}
    }
    private function supplyStatus(string $id,string $status):array{$old=$this->supply($id);if(!$old)throw new RuntimeException('Purchase Order was not found.');if($status==='CONFIRMED'&&$old['Status']!=='DRAFT')throw new RuntimeException('Only a Draft Purchase Order can be confirmed.');if($status==='CANCELLED'&&$old['Status']!=='CONFIRMED')throw new RuntimeException('Only a Confirmed Purchase Order can be cancelled.');if($status==='CANCELLED'&&$this->value('SELECT COUNT(*) FROM GoodsReceipts WHERE SupplyID=?',[$id]))throw new RuntimeException('This Purchase Order has a Goods Receipt record and cannot be cancelled.');$this->update('CompanySupplies','SupplyID',$id,['Status'=>$status,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit($status,'CompanySupplies',$id,'Purchase Order status changed.');return$this->supply($id);}
    private function deleteSupply(string $id):array{$this->user(true);throw new RuntimeException('Use the Administrator impact review to archive a Purchase Order.');}

    private function sales():array
    {
        $user = $this->user();
        $dealerId = $this->dealerUserId($user);
        $source = $dealerId ? $this->rows('DealerSales', 'DealerID=? AND Status<>?', [$dealerId,'ARCHIVED']) : $this->rows('DealerSales','Status<>?', ['ARCHIVED']);
        return array_map(function(array$row):array{$payment=$this->salePayment($row);$row['PaidAmount']=$payment['paid'];$row['DueAmount']=$payment['due'];$row['PaymentStatus']=$this->paymentStatus((float)$row['TotalAmount'],$payment['paid']);$row['PaymentMode']=(strtoupper((string)($row['PaymentMode']??''))==='CASH'||$payment['mode']==='CASH')?'CASH':'DUE';return$row;},$source);
    }
    private function customerHistory(string $inputPhone): array
    {
        $phone = $this->normalizeUserPhone($inputPhone);
        if ($phone === '') throw new InvalidArgumentException('Enter a Bangladesh mobile number with 11 digits starting with 01.');
        $dealerIds = []; $dealerNames = [];
        foreach ($this->rows('Dealers') as $dealer) {
            $mobile = $this->normalizeUserPhone((string) ($dealer['DealerMobile'] ?? $dealer['Phone'] ?? ''));
            if ($mobile === $phone) { $dealerIds[]=(string)$dealer['DealerID']; $dealerNames[(string)$dealer['DealerID']]=(string)$dealer['DealerName']; }
        }
        $matches=[]; $profileName='';
        foreach ($this->rows('DealerSales') as $sale) {
            $mobileMatch=$this->normalizeUserPhone((string)($sale['CustomerMobile']??''))===$phone;
            $dealerMatch=in_array((string)($sale['DealerID']??''),$dealerIds,true)&&strtoupper((string)($sale['CustomerType']??''))==='DEALER';
            if(!$mobileMatch&&!$dealerMatch)continue;
            if(!$profileName&&!empty($sale['CustomerName']))$profileName=(string)$sale['CustomerName'];
            if(!in_array(strtoupper((string)($sale['Status']??'')),['CONFIRMED','DELIVERED','POSTED'],true))continue;
            $payment=$this->salePayment($sale);$sale['PaidAmount']=$payment['paid'];$sale['DueAmount']=$payment['due'];
            if($dealerMatch)$sale['CustomerName']=$dealerNames[(string)$sale['DealerID']]??$sale['CustomerName'];
            $matches[]=$sale;
        }
        usort($matches,fn(array $a,array $b):int=>strcmp((string)$b['SaleDate'],(string)$a['SaleDate']));
        if(!$profileName&&$dealerNames)$profileName=reset($dealerNames);
        $total=array_sum(array_map(fn(array $row):float=>(float)$row['TotalAmount'],$matches));
        $paid=array_sum(array_map(fn(array $row):float=>(float)$row['PaidAmount'],$matches));
        $due=array_sum(array_map(fn(array $row):float=>(float)$row['DueAmount'],$matches));
        return ['Phone'=>$phone,'Name'=>$profileName?:'Customer','TotalPurchased'=>$total,'PaidAmount'=>$paid,'DueAmount'=>$due,'Transactions'=>$matches];
    }

    private function sale(string $id):?array{foreach($this->sales()as$row)if($row['SaleID']===$id){$row['Items']=$this->rows('DealerSaleItems','SaleID=?',[$id]);if(!$row['CustomerName']&&$row['DealerID'])$row['CustomerName']=$this->dealer($row['DealerID'])['DealerName']??$row['DealerID'];return$row;}return null;}
    private function salePayment(array $sale):array
    {
        $payments=$this->rows('Payments','Status="POSTED"'); $direct=[]; $cashSales=[]; $pool=0.0;
        foreach($payments as$p){$direct[$p['SaleID']??'']=($direct[$p['SaleID']??'']??0)+(float)$p['Amount'];if(($p['PaymentMethod']??'')==='Cash Sale'&&!empty($p['SaleID']))$cashSales[$p['SaleID']]=true;if(!$p['SaleID']&&$sale['DealerID']&&$p['PayerType']==='DEALER'&&$p['PayerID']===$sale['DealerID']&&$p['ReceiverType']==='COMPANY')$pool+=(float)$p['Amount'];}
        $sales=$sale['DealerID']?$this->rows('DealerSales','DealerID=? ORDER BY SaleDate, CreatedAt, SaleID',[$sale['DealerID']]):[$sale];
        foreach($sales as$line){$cash=(float)($direct[$line['SaleID']]??0);$remaining=max(0,(float)$line['TotalAmount']-$cash);$allocated=min($pool,$remaining);$pool-=$allocated;if($line['SaleID']===$sale['SaleID']){ $paid=$cash+$allocated;return['paid'=>$paid,'due'=>max(0,(float)$sale['TotalAmount']-$paid),'mode'=>isset($cashSales[$sale['SaleID']])?'CASH':'DUE']; }}
        return['paid'=>0.0,'due'=>(float)$sale['TotalAmount'],'mode'=>isset($cashSales[$sale['SaleID']])?'CASH':'DUE'];
    }
    private function syncCashSalePayment(string $saleId,string $paymentMode,float $total,string $customerType,string $dealerId,string $saleDate,string $invoiceNo):void
    {
        $this->db->prepare('DELETE FROM Payments WHERE SaleID=? AND PaymentMethod="Cash Sale"')->execute([$saleId]);
        if(strtoupper($paymentMode)!=='CASH'||$total<=0.000001)return;
        $now=$this->now();$this->insert('Payments',['PaymentID'=>$this->id('Payments','PaymentID','PAY'),'AgreementID'=>'','PayerType'=>$customerType==='DEALER'?'DEALER':'OUTSIDE','PayerID'=>$dealerId?:null,'ReceiverType'=>'COMPANY','ReceiverID'=>'','PaymentDate'=>$saleDate,'Amount'=>$total,'PaymentMethod'=>'Cash Sale','ReferenceNo'=>$invoiceNo,'SaleID'=>$saleId,'Notes'=>'Cash collected for invoice '.$invoiceNo,'Status'=>'POSTED','CreatedAt'=>$now,'CreatedBy'=>$this->current(),'SupplyID'=>'']);
    }
    private function stockPlan(string$product,float$quantity,string$variant):array{$left=$quantity;$plan=[];foreach($this->agreements()as$a){if($a['Status']!=='APPROVED')continue;$available=$this->stockBalance($a['AgreementID'],$product,$variant);if($available>0){$take=min($available,$left);$plan[]=['AgreementID'=>$a['AgreementID'],'Quantity'=>$take];$left-=$take;}if($left<=0.000001)break;}if($left>0.000001)throw new RuntimeException('Insufficient stock for product '.$product.'.');return$plan;}
    private function assertAvailableSaleStock(string$productId,string$variant,float$requested,string$excludeSaleId=''):void{$onHand=0.0;foreach($this->agreements()as$agreement)if($agreement['Status']==='APPROVED')$onHand+=$this->stockBalance($agreement['AgreementID'],$productId,$variant);$sql='SELECT COALESCE(SUM(i.BaseQuantity),0) FROM DealerSaleItems i JOIN DealerSales s ON s.SaleID=i.SaleID WHERE i.ProductID=? AND COALESCE(i.Variant,"")=? AND s.Status IN ("DRAFT","CONFIRMED")';$params=[$productId,$variant];if($excludeSaleId){$sql.=' AND s.SaleID<>?';$params[]=$excludeSaleId;}$reserved=(float)$this->value($sql,$params);$available=max(0.0,$onHand-$reserved);if($requested>$available+0.000001){$product=$this->product($productId);$name=$product['ProductName']??$productId;$unit=$product['BaseUnit']??'base units';throw new RuntimeException('Insufficient stock for '.$name.': requested '.number_format($requested,2,'.','').' '.$unit.', only '.number_format($available,2,'.','').' '.$unit.' available.');}}
private function createSale(array $input): array
    {
        $user=$this->user();$dealerUserId=$this->dealerUserId($user);
        if($dealerUserId){$input['CustomerType']='DEALER';$input['DealerID']=$dealerUserId;$input['PaymentMode']='DUE';$input['InvoiceNo']='';}
        $paymentMode=strtoupper($this->text($input['PaymentMode']??'DUE'));
        if(!in_array($paymentMode,['CASH','DUE'],true))throw new InvalidArgumentException('Select Cash Sale or Due Sale.');
        $type=strtoupper($this->text($input['CustomerType']??($this->text($input['DealerID']??'')?'DEALER':'OUTSIDE')));$dealer=null;$name=$this->text($input['CustomerName']??'');
        if($type==='DEALER'){$dealer=$this->dealer($this->text($input['DealerID']??''));if(!$dealer||!in_array($dealer['Status'],['APPROVED','ACTIVE'],true))throw new InvalidArgumentException('Select an approved Dealer.');$name=$dealer['DealerName'];}
        elseif($type!=='OUTSIDE'||!$name)throw new InvalidArgumentException('Outside Customer Name is required.');
        $customerMobile=$type==='OUTSIDE'?$this->normalizeUserPhone((string)($input['CustomerMobile']??'')):'';
        if($type==='OUTSIDE'&&$customerMobile==='')throw new InvalidArgumentException('Outside customers need an 11-digit Bangladesh mobile number starting with 01.');
        $commissionRate=$dealer&&strtoupper((string)($dealer['DealerClass']??'REGISTERED'))==='COMMISSION'?(float)($dealer['CommissionRate']??0):null;
        $items=$this->inheritPromotionSnapshots($this->normalizeItems((array)($input['Items']??[]),'SALE',0,(bool)$dealerUserId),(array)($old['Items']??[]));if(!$items)throw new InvalidArgumentException('Add at least one product.');$requested=[];
        foreach($items as$item){$key=$item['ProductID'].'|'.$item['Variant'];$requested[$key]=($requested[$key]??0)+$item['BaseQuantity'];}
        foreach($requested as$key=>$qty){[$pid,$variant]=explode('|',$key,2);$this->assertAvailableSaleStock($pid,$variant,$qty);}
        $this->assertAvailablePackagingSaleItems($items);
        $total=array_sum(array_column($items,'LineTotal'));$commissionAmount=$commissionRate!==null?round($total*$commissionRate/100,2):0.0;
        $this->db->beginTransaction();try{$id=$this->id('DealerSales','SaleID','SAL');$now=$this->now();
            $this->insert('DealerSales',['SaleID'=>$id,'AgreementID'=>'','DealerID'=>$dealer['DealerID']??'','SaleDate'=>$this->requiredDate($input['SaleDate']??'','Sale date'),'InvoiceNo'=>$this->text($input['InvoiceNo']??'')?:$id,'TotalAmount'=>$total,'Status'=>'DRAFT','Notes'=>$this->text($input['Notes']??''),'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current(),'CustomerType'=>$type,'CustomerID'=>$dealer['DealerID']??'','CustomerName'=>$name,'CustomerMobile'=>$customerMobile,'PaymentMode'=>$paymentMode,'CommissionRate'=>$commissionRate,'CommissionAmount'=>$commissionAmount]);
            foreach($items as$item)$this->insert('DealerSaleItems',['SaleItemID'=>$this->id('DealerSaleItems','SaleItemID','SLI'),'SaleID'=>$id,'ProductID'=>$item['ProductID'],'UnitName'=>$item['UnitName'],'Quantity'=>$item['Quantity'],'ConversionToBase'=>$item['ConversionToBase'],'BaseQuantity'=>$item['BaseQuantity'],'UnitPrice'=>$item['UnitPrice'],'LineTotal'=>$item['LineTotal'],'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'IsGift'=>$item['IsGift'],'Variant'=>$item['Variant'],'PromotionType'=>$item['PromotionType'],'PromotionGifts'=>$item['PromotionGifts']]);
            $this->audit('CREATE','DealerSales',$id,$dealerUserId?'Dealer order created as Draft.':'Sale draft created.');$this->db->commit();return$this->sale($id);
        }catch(Throwable$error){$this->db->rollBack();throw$error;}
    }
    private function updateSale(string $id,array $input): array
    {
        $user=$this->user();$dealerUserId=$this->dealerUserId($user);$old=$this->sale($id);if(!$old)throw new RuntimeException('Sale was not found.');
        if($dealerUserId&&$old['Status']!=='DRAFT')throw new RuntimeException('A Dealer User can edit only its own Draft Order.');if(!in_array($old['Status'],['DRAFT','CONFIRMED'],true))throw new RuntimeException('Only a Draft or Confirmed Sale can be edited.');
        if($dealerUserId){$input['CustomerType']='DEALER';$input['DealerID']=$dealerUserId;$input['PaymentMode']='DUE';$input['InvoiceNo']='';}
        $paymentMode=strtoupper($this->text($input['PaymentMode']??$old['PaymentMode']??'DUE'));if(!in_array($paymentMode,['CASH','DUE'],true))throw new InvalidArgumentException('Select Cash Sale or Due Sale.');
        $type=strtoupper($this->text($input['CustomerType']??$old['CustomerType']));$dealer=null;$name=$this->text($input['CustomerName']??$old['CustomerName']);
        if($type==='DEALER'){$dealer=$this->dealer($this->text($input['DealerID']??$old['DealerID']));if(!$dealer||!in_array($dealer['Status'],['APPROVED','ACTIVE'],true))throw new InvalidArgumentException('Select an approved Dealer.');$name=$dealer['DealerName'];}
        elseif($type!=='OUTSIDE'||!$name)throw new InvalidArgumentException('Outside Customer Name is required.');
        $customerMobile=$type==='OUTSIDE'?$this->normalizeUserPhone((string)($input['CustomerMobile']??$old['CustomerMobile']??'')):'';if($type==='OUTSIDE'&&$customerMobile==='')throw new InvalidArgumentException('Outside customers need an 11-digit Bangladesh mobile number starting with 01.');
        $commissionRate=$dealer&&strtoupper((string)($dealer['DealerClass']??'REGISTERED'))==='COMMISSION'?(float)($dealer['CommissionRate']??0):null;
        $items=$this->normalizeItems((array)($input['Items']??[]),'SALE',0,(bool)$dealerUserId);if(!$items)throw new InvalidArgumentException('Add at least one product.');$requested=[];
        foreach($items as$item){$key=$item['ProductID'].'|'.$item['Variant'];$requested[$key]=($requested[$key]??0)+$item['BaseQuantity'];}
        foreach($requested as$key=>$qty){[$pid,$variant]=explode('|',$key,2);$this->assertAvailableSaleStock($pid,$variant,$qty,$id);}
        $this->assertAvailablePackagingSaleItems($items,$id);
        $total=array_sum(array_column($items,'LineTotal'));$commissionAmount=$commissionRate!==null?round($total*$commissionRate/100,2):0.0;$now=$this->now();$saleDate=$this->requiredDate($input['SaleDate']??$old['SaleDate'],'Sale date');$invoice=$this->text($input['InvoiceNo']??$old['InvoiceNo'])?:$id;
        $this->db->beginTransaction();try{$this->update('DealerSales','SaleID',$id,['AgreementID'=>'','DealerID'=>$dealer['DealerID']??'','SaleDate'=>$saleDate,'InvoiceNo'=>$invoice,'TotalAmount'=>$total,'Notes'=>$this->text($input['Notes']??''),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current(),'CustomerType'=>$type,'CustomerID'=>$dealer['DealerID']??'','CustomerName'=>$name,'CustomerMobile'=>$customerMobile,'PaymentMode'=>$paymentMode,'CommissionRate'=>$commissionRate,'CommissionAmount'=>$commissionAmount]);
            $beforeItems=$this->rows('DealerSaleItems','SaleID=? ORDER BY SaleItemID',[$id]);$this->db->prepare('DELETE FROM DealerSaleItems WHERE SaleID=?')->execute([$id]);foreach($items as$item)$this->insert('DealerSaleItems',['SaleItemID'=>$this->id('DealerSaleItems','SaleItemID','SLI'),'SaleID'=>$id,'ProductID'=>$item['ProductID'],'UnitName'=>$item['UnitName'],'Quantity'=>$item['Quantity'],'ConversionToBase'=>$item['ConversionToBase'],'BaseQuantity'=>$item['BaseQuantity'],'UnitPrice'=>$item['UnitPrice'],'LineTotal'=>$item['LineTotal'],'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'IsGift'=>$item['IsGift'],'Variant'=>$item['Variant'],'PromotionType'=>$item['PromotionType'],'PromotionGifts'=>$item['PromotionGifts']]);$this->recordDetailHistory('DealerSales',$id,'Items',$beforeItems,$this->rows('DealerSaleItems','SaleID=? ORDER BY SaleItemID',[$id]));
            $this->audit('UPDATE','DealerSales',$id,'Sale updated; payment due recalculated from posted payments.');$this->db->commit();return$this->sale($id);
        }catch(Throwable$error){$this->db->rollBack();throw$error;}
    }
    private function saleStatus(string$id,string$status):array{$sale=$this->sale($id);if(!$sale)throw new RuntimeException('Sale was not found.');if($sale['Status']!=='DRAFT')throw new RuntimeException('Only a Draft Sale can be confirmed.');if(strtoupper($status)==='CONFIRMED'){$this->assertSaleAmounts((array)($sale['Items']??[]));$this->assertAvailablePackagingSaleItems((array)($sale['Items']??[]),$id);}$this->update('DealerSales','SaleID',$id,['Status'=>$status,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('CONFIRM','DealerSales',$id,'Sale confirmed.');return$this->sale($id);}
private function postDeliveredSaleVoucher(array $sale): void
    {
        $saleId=(string)$sale['SaleID'];
        if($this->value('SELECT COUNT(*) FROM AccountingVouchers WHERE SourceType="DEALER_SALE" AND SourceID=? AND Status="POSTED"',[$saleId]))return;
        $date=AccountingRules::date($sale['SaleDate']);$this->accountingOpenPeriod($date);$now=$this->now();$total=round((float)$sale['TotalAmount'],2);$dealerId=(string)($sale['DealerID']??'');$isDealer=strtoupper((string)($sale['CustomerType']??''))==='DEALER';$partyType=$isDealer?'DEALER':'';$partyId=$isDealer?$dealerId:'';$reference=(string)($sale['InvoiceNo']??$saleId);$narration='Sales invoice '.$reference.' · '.(string)($sale['CustomerName']??'');
        $cash=strtoupper((string)($sale['PaymentMode']??'DUE'))==='CASH';$lines=[
            ['AccountID'=>$cash?'1010':'1100','Debit'=>$total,'Credit'=>0,'PartyType'=>$cash?'':$partyType,'PartyID'=>$cash?'':$partyId,'ReferenceNo'=>$reference,'Narration'=>$narration],
            ['AccountID'=>'4010','Debit'=>0,'Credit'=>$total,'PartyType'=>'','PartyID'=>'','ReferenceNo'=>$reference,'Narration'=>$narration],
        ];
        $commission=round((float)($sale['CommissionAmount']??0),2);
        if($commission>0&&$isDealer){$lines[]=['AccountID'=>'6060','Debit'=>$commission,'Credit'=>0,'PartyType'=>'DEALER','PartyID'=>$dealerId,'ReferenceNo'=>$reference,'Narration'=>'Commission accrued at '.number_format((float)($sale['CommissionRate']??0),2).'%'];$lines[]=['AccountID'=>'2050','Debit'=>0,'Credit'=>$commission,'PartyType'=>'DEALER','PartyID'=>$dealerId,'ReferenceNo'=>$reference,'Narration'=>'Commission payable for '.$reference];}
        $debit=array_sum(array_column($lines,'Debit'));$credit=array_sum(array_column($lines,'Credit'));if(abs($debit-$credit)>0.005)throw new RuntimeException('Sale voucher is not balanced.');
        $voucherId=$this->id('AccountingVouchers','VoucherID','VCH');$this->insert('AccountingVouchers',['VoucherID'=>$voucherId,'VoucherDate'=>$date,'VoucherType'=>'JV','Status'=>'POSTED','SourceType'=>'DEALER_SALE','SourceID'=>$saleId,'SourceNo'=>$reference,'PartyType'=>$partyType?:null,'PartyID'=>$partyId?:null,'Narration'=>$narration,'TotalDebit'=>$debit,'TotalCredit'=>$credit,'ReversalOf'=>null,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'PostedAt'=>$now,'PostedBy'=>$this->current()]);
        foreach($lines as$number=>$line)$this->insert('AccountingVoucherLines',['VoucherLineID'=>$this->id('AccountingVoucherLines','VoucherLineID','VLI'),'VoucherID'=>$voucherId,'LineNo'=>$number+1]+$line+['CreatedAt'=>$now,'CreatedBy'=>$this->current()]);
    }
    private function deliverSale(string $id): array
    {
        $sale=$this->sale($id);if(!$sale)throw new RuntimeException('Sale was not found.');if($sale['Status']!=='CONFIRMED')throw new RuntimeException('Only a Confirmed Sale can be delivered.');
        $this->ensureAccounting();$this->db->beginTransaction();try{
            $this->assertAvailablePackagingSaleItems((array)$sale['Items'],$id);
            foreach($sale['Items']as$item){$product=$this->product((string)$item['ProductID']);$plan=$product&&strtoupper((string)($product['OptionType']??'VARIANT'))==='PACKAGING'?$this->packagingStockPlan($item['ProductID'],$item['Variant'],$item['UnitName'],(float)$item['BaseQuantity'],$id):$this->stockPlan($item['ProductID'],(float)$item['BaseQuantity'],$item['Variant']);foreach($plan as$piece)$this->recordStock($piece['AgreementID'],$item['ProductID'],$sale['SaleDate'],'DEALER_SALE',$id,$item['UnitName'],0,$piece['Quantity'],(float)$item['UnitPrice']/(float)$item['ConversionToBase'],'Sale delivered.',(bool)$item['IsGift'],$item['Variant']);}
            $this->update('DealerSales','SaleID',$id,['Status'=>'DELIVERED','UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);
            $mode=strtoupper((string)($sale['PaymentMode']??'DUE'));$this->syncCashSalePayment($id,$mode,(float)$sale['TotalAmount'],strtoupper((string)($sale['CustomerType']??'')),(string)($sale['DealerID']??''),(string)$sale['SaleDate'],(string)($sale['InvoiceNo']??$id));
            $this->postDeliveredSaleVoucher($sale);$this->audit('DELIVER','DealerSales',$id,'Sale delivered, stock deducted and posted to Accounts.');$this->db->commit();return$this->sale($id);
        }catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
    }
    private function pendingGrnCount():int{return(int)$this->value('SELECT COUNT(*) FROM CompanySupplies WHERE Status IN ("CONFIRMED","PARTIALLY_RECEIVED")');}
    private function grnRegister():array
    {
        $this->user();$receipts=$this->rows('GoodsReceipts','Status<>?', ['ARCHIVED']);$receiptBy=[];$accepted=[];$draft=[];foreach($receipts as$r){$receiptBy[$r['GRNID']]=$r;if($r['Status']==='DRAFT')$draft[$r['SupplyID']]=$r['GRNID'];}foreach($this->rows('GoodsReceiptItems')as$i){$r=$receiptBy[$i['GRNID']]??null;if($r&&$r['Status']==='POSTED')$accepted[$i['SupplyItemID']]=($accepted[$i['SupplyItemID']]??0)+(float)$i['BaseAcceptedQuantity'];}$pending=[];foreach($this->supplies()as$s){if(!in_array($s['Status'],['CONFIRMED','PARTIALLY_RECEIVED'],true))continue;$items=[];foreach($this->rows('CompanySupplyItems','SupplyID=?',[$s['SupplyID']])as$i){$conversion=(float)$i['ConversionToBase'];$previous=($accepted[$i['SupplyItemID']]??0)/$conversion;$remaining=max(0,(float)$i['Quantity']-$previous);if($remaining>0.000001){$p=$this->product($i['ProductID']);$optionType=strtoupper((string)($p['OptionType']??'VARIANT'));$packConversion=$optionType==='PACKAGING'?$this->packVariantToBase($p,(string)$i['Variant']):0;$packUnit='Pack ('.(string)$i['Variant'].')';$cartonUnit='CTN ('.(string)$i['Variant'].')';$packsPerUnit=$optionType!=='PACKAGING'?0:(strcasecmp((string)$i['UnitName'],$packUnit)===0?1:(strcasecmp((string)$i['UnitName'],$cartonUnit)===0&&$packConversion>0?$conversion/$packConversion:0));$items[]=['SupplyItemID'=>$i['SupplyItemID'],'ProductID'=>$i['ProductID'],'ProductName'=>$p['ProductName']??$i['ProductID'],'Variant'=>$i['Variant'],'UnitName'=>$i['UnitName'],'ConversionToBase'=>$conversion,'OptionType'=>$optionType,'SealedPackCountPerUnit'=>$packsPerUnit,'OrderedQuantity'=>(float)$i['Quantity'],'PreviouslyAcceptedQuantity'=>$previous,'RemainingQuantity'=>$remaining,'UnitCost'=>(float)$i['UnitPrice']/$conversion,'IsGift'=>(bool)$i['IsGift']];}}if($items){$supplier=$this->agreement($s['AgreementID']);$pending[]=['SupplyID'=>$s['SupplyID'],'AgreementID'=>$s['AgreementID'],'SupplierName'=>$supplier['Company']??'','SupplyDate'=>$s['SupplyDate'],'CreatedAt'=>$s['CreatedAt']??'','ReferenceNo'=>$s['ReferenceNo'],'Status'=>$s['Status'],'TotalAmount'=>$s['TotalAmount'],'OpenDraftGRNID'=>$draft[$s['SupplyID']]??'','Items'=>$items];}}
        $register=[];foreach($receipts as$r){$items=$this->rows('GoodsReceiptItems','GRNID=?',[$r['GRNID']]);$formatted=[];$total=0.;foreach($items as$i){$p=$this->product($i['ProductID']);$item=['GRNItemID'=>$i['GRNItemID'],'SupplyItemID'=>$i['SupplyItemID'],'ProductID'=>$i['ProductID'],'ProductName'=>$p['ProductName']??$i['ProductID'],'Variant'=>$i['Variant'],'UnitName'=>$i['UnitName'],'ConversionToBase'=>(float)$i['ConversionToBase'],'OrderedQuantity'=>(float)$i['OrderedQuantity'],'PreviouslyAcceptedQuantity'=>(float)$i['PreviouslyAcceptedQuantity'],'ReceivedQuantity'=>(float)$i['ReceivedQuantity'],'AcceptedQuantity'=>(float)$i['AcceptedQuantity'],'RejectedQuantity'=>(float)$i['RejectedQuantity'],'BaseReceivedQuantity'=>(float)$i['BaseReceivedQuantity'],'BaseAcceptedQuantity'=>(float)$i['BaseAcceptedQuantity'],'BaseRejectedQuantity'=>(float)$i['BaseRejectedQuantity'],'UnitCost'=>(float)$i['UnitCost'],'RejectionReason'=>$i['RejectionReason'],'IsGift'=>(bool)$i['IsGift']];$total+=$item['BaseAcceptedQuantity']*$item['UnitCost'];$formatted[]=$item;}$supplier=$this->agreement($r['AgreementID']);$supply=$this->supply($r['SupplyID']);$register[]=['GRNID'=>$r['GRNID'],'SupplyID'=>$r['SupplyID'],'AgreementID'=>$r['AgreementID'],'ReferenceNo'=>$supply['ReferenceNo']??'','SupplierName'=>$supplier['Company']??'','PurchaseOrderDate'=>$supply['SupplyDate']??'','ReceiptDate'=>$r['ReceiptDate'],'GateEntryNo'=>$r['GateEntryNo'],'DeliveryChallan'=>$r['DeliveryChallan'],'Status'=>$r['Status'],'Notes'=>$r['Notes'],'CreatedAt'=>$r['CreatedAt'],'CreatedBy'=>$r['CreatedBy'],'PostedAt'=>$r['PostedAt'],'PostedBy'=>$r['PostedBy'],'Items'=>$formatted,'LineCount'=>count($formatted),'AcceptedValue'=>$total,'PurchaseStatus'=>$supply['Status']??''];}usort($register,fn($a,$b)=>strcmp((string)$b['CreatedAt'],(string)$a['CreatedAt']));return['PendingOrders'=>$pending,'Receipts'=>$register];
    }
    private function saveGrn(array $input):array
    {
        $this->user();$id=$this->text($input['GRNID']??'');$supply=$this->supply($this->text($input['SupplyID']??''));if(!$supply||!in_array($supply['Status'],['CONFIRMED','PARTIALLY_RECEIVED'],true))throw new RuntimeException('Goods can only be received against a Confirmed or Partially Received Purchase Order.');$this->requiredDate($supply['SupplyDate']??'','Purchase order date');$old=$id?$this->one('GoodsReceipts','GRNID',$id):null;if($id&&(!$old||$old['Status']!=='DRAFT'))throw new RuntimeException('Only a Draft GRN can be edited.');if(!$id&&$this->value('SELECT COUNT(*) FROM GoodsReceipts WHERE SupplyID=? AND Status="DRAFT"',[$supply['SupplyID']]))throw new RuntimeException('This Purchase Order already has an open Draft GRN.');$previous=[];foreach($this->rows('GoodsReceiptItems')as$i){$head=$this->one('GoodsReceipts','GRNID',$i['GRNID']);if($head&&$head['SupplyID']===$supply['SupplyID']&&$head['Status']==='POSTED')$previous[$i['SupplyItemID']]=($previous[$i['SupplyItemID']]??0)+(float)$i['BaseAcceptedQuantity'];}$purchase=[];foreach($supply['Items']as$i)$purchase[$i['SupplyItemID']]=$i;$items=[];foreach((array)($input['Items']??[])as$raw){$purchaseItem=$purchase[$this->text($raw['SupplyItemID']??'')]??null;if(!$purchaseItem)throw new RuntimeException('A GRN line does not match its Purchase Order.');$received=$this->num($raw['ReceivedQuantity']??0,'Received quantity',true);if($received<=0)continue;$accepted=$this->num($raw['AcceptedQuantity']??0,'Accepted quantity',true);$rejected=$this->num($raw['RejectedQuantity']??0,'Rejected quantity',true);$conversion=(float)$purchaseItem['ConversionToBase'];if(abs($accepted+$rejected-$received)>0.000001)throw new RuntimeException('Accepted + Rejected must equal Received quantity.');$remaining=(float)$purchaseItem['Quantity']-($previous[$purchaseItem['SupplyItemID']]??0)/$conversion;if($received>$remaining+0.000001)throw new RuntimeException('Received quantity exceeds the remaining Purchase Order quantity.');$reason=$this->text($raw['RejectionReason']??'');if($rejected>0&&!$reason)throw new RuntimeException('Add a rejection reason for rejected goods.');$items[]=['purchase'=>$purchaseItem,'received'=>$received,'accepted'=>$accepted,'rejected'=>$rejected,'previous'=>($previous[$purchaseItem['SupplyItemID']]??0)/$conversion,'reason'=>$reason];}if(!$items)throw new RuntimeException('Enter a received quantity for at least one Purchase Order item.');$this->db->beginTransaction();try{$id=$id?:$this->id('GoodsReceipts','GRNID','GRN');$now=$this->now();$header=['SupplyID'=>$supply['SupplyID'],'AgreementID'=>$supply['AgreementID'],'ReceiptDate'=>$this->requiredDate($input['ReceiptDate']??'','Receipt date'),'GateEntryNo'=>$this->text($input['GateEntryNo']??''),'DeliveryChallan'=>$this->text($input['DeliveryChallan']??''),'Status'=>'DRAFT','Notes'=>$this->text($input['Notes']??''),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];if($old){$this->update('GoodsReceipts','GRNID',$id,$header);$this->db->prepare('DELETE FROM GoodsReceiptItems WHERE GRNID=?')->execute([$id]);}else{$this->insert('GoodsReceipts',['GRNID'=>$id,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'PostedAt'=>null,'PostedBy'=>null,'IdempotencyKey'=>$this->text($input['IdempotencyKey']??'')?:null]+$header);}foreach($items as$line){$p=$line['purchase'];$conversion=(float)$p['ConversionToBase'];$this->insert('GoodsReceiptItems',['GRNItemID'=>$this->id('GoodsReceiptItems','GRNItemID','GNI'),'GRNID'=>$id,'SupplyItemID'=>$p['SupplyItemID'],'ProductID'=>$p['ProductID'],'Variant'=>$p['Variant'],'UnitName'=>$p['UnitName'],'ConversionToBase'=>$conversion,'OrderedQuantity'=>$p['Quantity'],'PreviouslyAcceptedQuantity'=>$line['previous'],'ReceivedQuantity'=>$line['received'],'AcceptedQuantity'=>$line['accepted'],'RejectedQuantity'=>$line['rejected'],'BaseReceivedQuantity'=>$line['received']*$conversion,'BaseAcceptedQuantity'=>$line['accepted']*$conversion,'BaseRejectedQuantity'=>$line['rejected']*$conversion,'UnitCost'=>(float)$p['UnitPrice']/$conversion,'RejectionReason'=>$line['reason'],'IsGift'=>$p['IsGift'],'CreatedAt'=>$now,'CreatedBy'=>$this->current()]);}$this->audit($old?'UPDATE':'CREATE','GoodsReceipts',$id,'Draft GRN saved.');$this->db->commit();foreach($this->grnRegister()['Receipts']as$r)if($r['GRNID']===$id)return$r;throw new RuntimeException('GRN was saved but could not be read.');}catch(Throwable$e){$this->db->rollBack();throw$e;}
    }
    private function postGrn(string $id):array{$grn=$this->one('GoodsReceipts','GRNID',$id);if(!$grn||$grn['Status']!=='DRAFT')throw new RuntimeException('Draft GRN was not found.');$supply=$this->supply($grn['SupplyID']);if(!$supply||!in_array($supply['Status'],['CONFIRMED','PARTIALLY_RECEIVED'],true))throw new RuntimeException('The linked Purchase Order is not open for goods receipt.');$this->requiredDate($supply['SupplyDate']??'','Purchase order date');$this->db->beginTransaction();try{$items=$this->rows('GoodsReceiptItems','GRNID=?',[$id]);foreach($items as$i)if((float)$i['BaseAcceptedQuantity']>0)$this->recordStock($grn['AgreementID'],$i['ProductID'],$grn['ReceiptDate'],'GOODS_RECEIPT',$id.':'.$i['GRNItemID'],$i['UnitName'],(float)$i['BaseAcceptedQuantity'],0,(float)$i['UnitCost'],'Accepted from GRN '.$id,(bool)$i['IsGift'],$i['Variant']);$this->update('GoodsReceipts','GRNID',$id,['Status'=>'POSTED','PostedAt'=>$this->now(),'PostedBy'=>$this->current(),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$all=true;foreach($supply['Items']as$item){$accepted=(float)$this->value('SELECT COALESCE(SUM(i.BaseAcceptedQuantity),0) FROM GoodsReceiptItems i JOIN GoodsReceipts r ON r.GRNID=i.GRNID WHERE i.SupplyItemID=? AND r.Status="POSTED"',[$item['SupplyItemID']]);if($accepted+0.000001<(float)$item['BaseQuantity'])$all=false;}$this->update('CompanySupplies','SupplyID',$supply['SupplyID'],['Status'=>$all?'RECEIVED':'PARTIALLY_RECEIVED','PaymentStatus'=>$this->paymentStatus((float)$supply['TotalAmount'],$this->supplyPaid($supply['SupplyID'])),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('POST','GoodsReceipts',$id,'Accepted goods posted to inventory.');$this->db->commit();return['GRNID'=>$id,'Status'=>'POSTED','SupplyID'=>$supply['SupplyID'],'PurchaseStatus'=>$all?'RECEIVED':'PARTIALLY_RECEIVED'];}catch(Throwable$e){$this->db->rollBack();throw$e;}}

    private function returns():array{$this->user();return$this->rows('ProductReturns');}
    private function createReturn(array $input,string $type):array
    {
        $this->user();$source=$type==='PURCHASE'?$this->supply($this->text($input['SupplyID']??'')):$this->sale($this->text($input['SaleID']??''));if(!$source)throw new InvalidArgumentException('Select a valid source document.');$items=$this->normalizeItems((array)($input['Items']??[]),$type==='PURCHASE'?'PURCHASE':'SALE');if(!$items)throw new InvalidArgumentException('Add at least one product.');$id=$this->id('ProductReturns','ReturnID','RET');$now=$this->now();$agreement=$type==='PURCHASE'?$source['AgreementID']:'';$dealer=$type==='SALE'?$source['DealerID']:'';$total=array_sum(array_column($items,'LineTotal'));$this->db->beginTransaction();try{$this->insert('ProductReturns',['ReturnID'=>$id,'AgreementID'=>$agreement,'DealerID'=>$dealer,'SaleID'=>$type==='SALE'?$source['SaleID']:'','ReturnDate'=>$this->requiredDate($input['ReturnDate']??'','Return date'),'Reason'=>$this->text($input['Reason']??''),'TotalAmount'=>$total,'Status'=>'POSTED','Notes'=>$this->text($input['Notes']??''),'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'ReturnType'=>$type,'SupplyID'=>$type==='PURCHASE'?$source['SupplyID']:'']);foreach($items as$i){$this->insert('ProductReturnItems',['ReturnItemID'=>$this->id('ProductReturnItems','ReturnItemID','RTI'),'ReturnID'=>$id,'ProductID'=>$i['ProductID'],'UnitName'=>$i['UnitName'],'Quantity'=>$i['Quantity'],'ConversionToBase'=>$i['ConversionToBase'],'BaseQuantity'=>$i['BaseQuantity'],'UnitPrice'=>$i['UnitPrice'],'LineTotal'=>$i['LineTotal'],'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'IsGift'=>$i['IsGift'],'Variant'=>$i['Variant']]);$p=$this->product($i['ProductID']);if($type==='SALE')$this->recordStock($p['SupplierID'],$i['ProductID'],$this->requiredDate($input['ReturnDate']??'','Return date'),'SALE_RETURN',$id,$i['UnitName'],$i['BaseQuantity'],0,(float)$i['UnitPrice']/(float)$i['ConversionToBase'],'Sale return.',(bool)$i['IsGift'],$i['Variant']);}$this->audit('POST','ProductReturns',$id,'Product return posted.');$this->db->commit();return$this->one('ProductReturns','ReturnID',$id);}catch(Throwable$e){$this->db->rollBack();throw$e;}
    }
    private function createExpense(array$input):array{$this->user();$category=$this->text($input['Category']??'');if(!$category)throw new InvalidArgumentException('Expense Category is required.');if(!$this->value('SELECT COUNT(*) FROM ExpenseCategories WHERE CategoryName=? AND Status<>"ARCHIVED"',[$category]))throw new RuntimeException('Select a configured Expense Category.');$id=$this->id('Expenses','ExpenseID','EXP');$row=['ExpenseID'=>$id,'AgreementID'=>$this->text($input['AgreementID']??''),'ExpenseDate'=>$this->requiredDate($input['ExpenseDate']??'','Expense date'),'Category'=>$category,'Amount'=>$this->num($input['Amount']??'','Expense Amount'),'ReferenceNo'=>$this->text($input['ReferenceNo']??''),'Description'=>$this->text($input['Description']??''),'Status'=>'POSTED','CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()];$this->insert('Expenses',$row);$this->audit('POST','Expenses',$id,'Expense posted.');return$row;}

    private function paymentMethods(bool$archived=false):array{$this->user();return$this->rows('PaymentMethods',$archived?'':'Status<>"ARCHIVED"');}
    private function createSetting(string$table,string$prefix,string$idField,string$nameField,string$name,bool$adminOnly=true):array{$this->user($adminOnly);$name=$this->text($name);if(!$name)throw new InvalidArgumentException('Name is required.');if($this->value('SELECT COUNT(*) FROM `'.$table.'` WHERE LOWER(`'.$nameField.'`)=LOWER(?) AND Status<>"ARCHIVED"',[$name]))throw new RuntimeException($name.' already exists.');$id=$this->id($table,$idField,$prefix);$row=[$idField=>$id,$nameField=>$name,'Status'=>'ACTIVE','CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()];$this->insert($table,$row);$this->audit('CREATE',$table,$id,$name.' created.');return$row;}
    private function updateSetting(string$kind,string$id,array$input):array{$this->user(true);$defs=['productCategory'=>['ProductCategories','CategoryID','CategoryName'],'expenseCategory'=>['ExpenseCategories','ExpenseCategoryID','CategoryName'],'paymentMethod'=>['PaymentMethods','PaymentMethodID','MethodName']];if(!isset($defs[$kind]))throw new InvalidArgumentException('Unknown setting type.');[$table,$idField,$nameField]=$defs[$kind];$old=$this->one($table,$idField,$id);if(!$old)throw new RuntimeException('Setting was not found.');$name=$this->text($input['Name']??$old[$nameField]);$status=strtoupper($this->text($input['Status']??$old['Status']));if(!$name||!in_array($status,['ACTIVE','ARCHIVED'],true))throw new InvalidArgumentException('Invalid setting.');$this->update($table,$idField,$id,[$nameField=>$name,'Status'=>$status]);return$this->one($table,$idField,$id);}
    private function lookups():array
    {
        $user = $this->user();
        if ($this->dealerUserId($user)) {
            return ['agreements'=>[],'products'=>$this->dealerOrderCatalog(),'dealers'=>$this->dealers(),'purchases'=>[],'sales'=>$this->sales(),'productCategories'=>[],'expenseCategories'=>[],'paymentMethods'=>[]];
        }
        $allowed = array_flip($this->configuredPermissionKeys($user));
        $can = fn(string $key): bool => isset($allowed[$key]);
        $needsSupplier = $can('agreements') || $can('purchases') || $can('goods_receipts') || $can('product_config') || $can('payments') || $can('returns');
        $needsProduct = $can('inventory') || $can('product_config') || $can('purchases') || $can('sales') || $can('product_damage') || $can('returns');
        $needsDealer = $can('dealers') || $can('dealer_incentive') || $can('sales') || $can('returns') || $can('payments');
        return [
            'agreements'=>$needsSupplier?$this->agreements():[],
            'products'=>$needsProduct?array_map(fn($p)=>$this->product($p['ProductID']),$this->products()):[],
            'dealers'=>$needsDealer?$this->dealers():[],
            'purchases'=>($can('purchases')||$can('goods_receipts'))?$this->supplies():[],
            'sales'=>($can('sales')||$can('returns'))?$this->sales():[],
            'productCategories'=>$can('product_config')?$this->rows('ProductCategories'):[],
            'expenseCategories'=>($can('product_config')||$can('expenses'))?$this->rows('ExpenseCategories'):[],
            'paymentMethods'=>($can('product_config')||$can('payments'))?$this->paymentMethods():[],
        ];
    }

    private function dashboard(string $selectedDate):array
    {
        $this->user();
        $date=$this->today($selectedDate);
        $agreements=$this->agreements();
        $approved=array_values(array_filter($agreements,fn($a)=>$a['Status']==='APPROVED'));
        $dealers=$this->dealers();
        $supplies=$this->supplies();
        $sales=$this->sales();
        $payments=$this->rows('Payments');
        $expenses=$this->rows('Expenses');
        $returns=$this->returns();
        $inventory=$this->inventory();
        $supplierName=[];
        foreach($agreements as $a)$supplierName[$a['AgreementID']]=$a['Company'];
        $dealerName=[];
        foreach($dealers as $d)$dealerName[$d['DealerID']]=$d['DealerName'];
        $posted=array_values(array_filter($payments,fn($p)=>strtoupper((string)($p['Status']??''))==='POSTED'));

        $supplierDues=[];
        foreach($supplies as $s){
            $paid=$this->supplyPaid($s['SupplyID']);
            $due=max(0,(float)$s['TotalAmount']-$paid);
            if($due>0&&$s['Status']!=='CANCELLED')$supplierDues[]=['SupplyID'=>$s['SupplyID'],'Supplier'=>$supplierName[$s['AgreementID']]??$s['AgreementID'],'SupplierID'=>$s['AgreementID'],'Amount'=>(float)$s['TotalAmount'],'PaidAmount'=>$paid,'DueAmount'=>$due,'Status'=>$s['Status']];
        }
        $dealerDues=[];
        foreach($sales as $s)if(($s['CustomerType']??'DEALER')==='DEALER'&&(float)$s['DueAmount']>0)$dealerDues[]=['DealerID'=>$s['DealerID'],'Dealer'=>$dealerName[$s['DealerID']]??$s['DealerID'],'SaleID'=>$s['SaleID'],'InvoiceNo'=>$s['InvoiceNo']??$s['SaleID'],'Amount'=>(float)$s['TotalAmount'],'PaidAmount'=>(float)$s['PaidAmount'],'DueAmount'=>(float)$s['DueAmount'],'Status'=>$s['Status']];

        $purchaseBy=[];
        foreach($supplies as $s)$purchaseBy[$s['AgreementID']]=($purchaseBy[$s['AgreementID']]??0)+(float)$s['TotalAmount'];
        $salesBy=[];
        foreach($this->rows('DealerSaleItems') as $i){$p=$this->product($i['ProductID']);if($p)$salesBy[$p['SupplierID']]=($salesBy[$p['SupplierID']]??0)+(float)$i['LineTotal'];}
        $paidBy=[];
        foreach($posted as $p)if($p['PayerType']==='DEALER'&&$p['ReceiverType']==='COMPANY')$paidBy[$p['AgreementID']]=($paidBy[$p['AgreementID']]??0)+(float)$p['Amount'];
        $supplierList=[];
        foreach($approved as $a){$sid=$a['AgreementID'];$supplierList[]=['SupplierID'=>$sid,'Supplier'=>$a['Company'],'Address'=>$a['Address'],'Status'=>$a['Status'],'TotalPurchaseAmount'=>$purchaseBy[$sid]??0,'TotalSalesAmount'=>$salesBy[$sid]??0,'PaidAmount'=>$paidBy[$sid]??0,'DueAmount'=>max(0,($salesBy[$sid]??0)-($paidBy[$sid]??0))];}

        $dailySales=array_values(array_filter($sales,fn($s)=>$s['SaleDate']===$date&&$s['Status']==='DELIVERED'));
        $dailyPurchase=array_values(array_filter($supplies,fn($s)=>$s['SupplyDate']===$date&&$s['Status']==='RECEIVED'));
        // Dealer payments received by the company, including cash-sale receipts.
        $dailyCollection=array_values(array_filter($posted,fn($p)=>$p['PaymentDate']===$date&&$p['PayerType']==='DEALER'&&$p['ReceiverType']==='COMPANY'));
        $dailyCollectionRows=array_map(fn($p)=>[
            'PaymentID'=>$p['PaymentID'],
            'DealerID'=>$p['PayerID']??'',
            'Dealer'=>$dealerName[$p['PayerID']??'']??($p['PayerID']??'Dealer'),
            'PaymentDate'=>$p['PaymentDate'],
            'Amount'=>(float)$p['Amount'],
            'PaymentMethod'=>$p['PaymentMethod']??'',
            'ReferenceNo'=>$p['ReferenceNo']??'',
            'SaleID'=>$p['SaleID']??'',
            'AgreementID'=>$p['AgreementID']??'',
        ],$dailyCollection);

        return[
            'activeAgreements'=>count($approved),
            'securityDeposit'=>array_sum(array_map(fn($a)=>(float)$a['SecurityDeposit'],$approved)),
            'supplyValue'=>array_sum(array_map(fn($s)=>(float)$s['TotalAmount'],$supplies)),
            'stockUnits'=>array_sum(array_map(fn($s)=>(float)$s['OnHand'],$inventory)),
            'inventoryValue'=>array_sum(array_map(fn($r)=>(float)($r['StockValue']??0),$inventory)),
            'dealerCount'=>count(array_filter($dealers,fn($d)=>in_array($d['Status'],['ACTIVE','APPROVED'],true))),
            'dealerSales'=>array_sum(array_map(fn($s)=>(float)$s['TotalAmount'],$sales)),
            'returns'=>array_sum(array_map(fn($r)=>(float)$r['TotalAmount'],$returns)),
            'payments'=>array_sum(array_map(fn($p)=>(float)$p['Amount'],$payments)),
            'expenses'=>array_sum(array_map(fn($e)=>(float)$e['Amount'],$expenses)),
            'month'=>substr($date,0,7),
            'selectedDate'=>$date,
            'dailySalesCount'=>count($dailySales),
            'dailySalesAmount'=>array_sum(array_map(fn($s)=>(float)$s['TotalAmount'],$dailySales)),
            'dailyPurchaseCount'=>count($dailyPurchase),
            'dailyPurchaseAmount'=>array_sum(array_map(fn($s)=>(float)$s['TotalAmount'],$dailyPurchase)),
            'dailyCollectionCount'=>count($dailyCollection),
            'dailyCollectionAmount'=>array_sum(array_map(fn($p)=>(float)$p['Amount'],$dailyCollection)),
            'dailyCollectionRows'=>$dailyCollectionRows,
            'supplierList'=>$supplierList,
            'dealerList'=>array_values(array_map(fn($d)=>['DealerID'=>$d['DealerID'],'Dealer'=>$d['DealerName'],'Address'=>$d['Address'],'Status'=>$d['Status']],array_filter($dealers,fn($d)=>in_array($d['Status'],['ACTIVE','APPROVED'],true)))),
            'supplierDues'=>$supplierDues,
            'dealerDues'=>$dealerDues,
            'unsoldStock'=>array_values(array_map(fn($r)=>['Supplier'=>$supplierName[$r['SupplierID']]??$r['SupplierID'],'Product'=>$r['ProductName'],'Variant'=>$r['Variant']?:'-','Unit'=>$r['BaseUnit']?:'Pcs','Quantity'=>$r['OnHand'],'StockValue'=>$r['StockValue']],array_filter($inventory,fn($r)=>(float)$r['OnHand']>0))),
            'inventory'=>$inventory,
        ];
    }
    private function report(string $name,array $filters):array{$rows=match($name){'agreements'=>$this->agreements(),'supplies'=>$this->supplies(),'stock'=>$this->inventory(),'sales'=>$this->sales(),'returns'=>$this->returns(),'payments'=>$this->rows('Payments'),'expenses'=>$this->rows('Expenses'),default=>throw new InvalidArgumentException('Unknown report.')};if($this->text($filters['AgreementID']??''))$rows=array_values(array_filter($rows,fn($r)=>($r['AgreementID']??'')===$filters['AgreementID']));return['rows'=>$rows,'count'=>count($rows)];}
}
