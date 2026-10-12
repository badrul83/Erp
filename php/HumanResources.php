<?php
declare(strict_types=1);

trait HumanResources
{
    private function ensureHRSchema(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS Employees (
            EmployeeID VARCHAR(20) PRIMARY KEY, EmployeeCode VARCHAR(30) NOT NULL UNIQUE, UserID VARCHAR(20) NULL UNIQUE,
            DealerID VARCHAR(20) NULL, FullName VARCHAR(160) NOT NULL, Phone VARCHAR(40) NULL,
            Email VARCHAR(160) NULL, NID VARCHAR(80) NULL, JobTitle VARCHAR(120) NOT NULL, Department VARCHAR(120) NULL,
            JoiningDate DATE NULL, EmploymentType VARCHAR(30) NULL, MonthlySalary DECIMAL(16,2) NULL,
            Status VARCHAR(20) NOT NULL DEFAULT "ACTIVE", CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL,
            UpdatedAt DATETIME NOT NULL, UpdatedBy VARCHAR(120) NOT NULL, INDEX (DealerID), INDEX (Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->normalizeHREmployeeCodes();
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRSourceRoster (
            SourceRosterID VARCHAR(24) PRIMARY KEY, EmployeeID VARCHAR(20) NOT NULL UNIQUE,
            SourceFile VARCHAR(255) NOT NULL, SourcePeriod CHAR(7) NULL, SourceRowNo INT NULL,
            SourceSRName VARCHAR(160) NULL, SourcePayeeName VARCHAR(160) NULL, SourceDealerName VARCHAR(200) NULL, SourceArea VARCHAR(160) NULL,
            TargetAmount DECIMAL(16,2) NULL, OnlineAmount DECIMAL(16,2) NULL, SalesAmount DECIMAL(16,2) NULL,
            AchievementRate DECIMAL(18,14) NULL, SourceSalaryTADA DECIMAL(16,2) NULL, CostSalesRate DECIMAL(18,14) NULL,
            SourceIncentive DECIMAL(16,2) NULL, SuperSmartSales DECIMAL(16,2) NULL, MegaCoilSales DECIMAL(16,2) NULL,
            DetergentSales DECIMAL(16,2) NULL, FoodSales DECIMAL(16,2) NULL, SourceNote TEXT NULL,
            SourceJoiningDate DATE NULL, ImportedAt DATETIME NOT NULL, ImportedBy VARCHAR(120) NOT NULL,
            UNIQUE KEY source_import_row (SourceFile,SourcePeriod,SourceRowNo), INDEX (SourceDealerName)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HREmployeeProfiles (
            EmployeeID VARCHAR(20) PRIMARY KEY, DateOfBirth DATE NOT NULL,
            BanglaName VARCHAR(160) NULL, FRIDNo VARCHAR(80) NULL, Gender VARCHAR(30) NULL, BloodGroup VARCHAR(10) NULL,
            MaritalStatus VARCHAR(30) NULL, FatherName VARCHAR(160) NULL, MotherName VARCHAR(160) NULL, SpouseName VARCHAR(160) NULL,
            Religion VARCHAR(60) NULL, Nationality VARCHAR(80) NULL, PhotoDataUrl MEDIUMTEXT NULL,
            CompanyName VARCHAR(160) NULL, Division VARCHAR(120) NULL, Section VARCHAR(120) NULL, PositionName VARCHAR(120) NULL, FingerID VARCHAR(80) NULL, WorkStatus VARCHAR(24) NULL,
            AutoAttendance TINYINT(1) NOT NULL DEFAULT 0, IgnoreForSalary TINYINT(1) NOT NULL DEFAULT 0,
            PresentAddress TEXT NULL, PresentAddressBangla TEXT NULL, PermanentAddress TEXT NULL, PermanentAddressBangla TEXT NULL,
            LivingArea VARCHAR(120) NULL, Thana VARCHAR(120) NULL, District VARCHAR(120) NULL,
            ShiftName VARCHAR(80) NULL, PayStructure VARCHAR(80) NULL, Grade VARCHAR(40) NULL, WeeklyHoliday VARCHAR(20) NULL,
            Designation VARCHAR(120) NULL, GrossSalary DECIMAL(16,2) NULL, BasicSalary DECIMAL(16,2) NULL,
            HouseRent DECIMAL(16,2) NULL, MedicalAllowance DECIMAL(16,2) NULL, TransportAllowance DECIMAL(16,2) NULL,
            LunchAllowance DECIMAL(16,2) NULL, FixedOT DECIMAL(16,2) NULL, TAAllowance DECIMAL(16,2) NULL, DAAllowance DECIMAL(16,2) NULL, GettingFixedOT TINYINT(1) NOT NULL DEFAULT 0,
            PFContributionPercent DECIMAL(7,3) NULL, BasicForPFPercent DECIMAL(7,3) NULL, PFEffectiveDate DATE NULL,
            OTActual TINYINT(1) NOT NULL DEFAULT 0, OTCompliance TINYINT(1) NOT NULL DEFAULT 0, GetBonus TINYINT(1) NOT NULL DEFAULT 0,
            SalesTarget DECIMAL(16,2) NULL, SalesAchievement DECIMAL(16,2) NULL, CommissionBase DECIMAL(16,2) NULL,
            CommissionRate DECIMAL(7,3) NULL, BonusAmount DECIMAL(16,2) NULL,
            EducationJSON MEDIUMTEXT NOT NULL, ExperienceJSON MEDIUMTEXT NULL, ExpertiseJSON MEDIUMTEXT NULL, NomineeJSON MEDIUMTEXT NULL,
            PaymentMode VARCHAR(40) NULL, AccountHolder VARCHAR(160) NULL, BankName VARCHAR(160) NULL, BankBranch VARCHAR(160) NULL,
            AccountNumber VARCHAR(80) NULL, RoutingNumber VARCHAR(80) NULL, MFSProvider VARCHAR(80) NULL, MFSNumber VARCHAR(40) NULL,
            CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL, UpdatedAt DATETIME NOT NULL, UpdatedBy VARCHAR(120) NOT NULL,
            INDEX (DateOfBirth), INDEX (District)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRAttendance (
            AttendanceID VARCHAR(24) PRIMARY KEY, EmployeeID VARCHAR(20) NOT NULL, DealerID VARCHAR(20) NOT NULL,
            AttendanceDate DATE NOT NULL, AttendanceStatus VARCHAR(20) NOT NULL, PaidLeave TINYINT(1) NOT NULL DEFAULT 0,
            CheckIn TIME NULL, CheckOut TIME NULL, Notes VARCHAR(500) NULL, CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL,
            UpdatedAt DATETIME NOT NULL, UpdatedBy VARCHAR(120) NOT NULL, UNIQUE KEY employee_day (EmployeeID, AttendanceDate), INDEX (DealerID, AttendanceDate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRSalesReports (
            ReportID VARCHAR(24) PRIMARY KEY, EmployeeID VARCHAR(20) NOT NULL, DealerID VARCHAR(20) NOT NULL,
            PayrollMonth CHAR(7) NOT NULL, ReportDate DATE NOT NULL, TargetAmount DECIMAL(16,2) NOT NULL,
            OnlineAmount DECIMAL(16,2) NOT NULL DEFAULT 0, SuperSmartSales DECIMAL(16,2) NOT NULL DEFAULT 0,
            MegaCoilSales DECIMAL(16,2) NOT NULL DEFAULT 0, DetergentSales DECIMAL(16,2) NOT NULL DEFAULT 0,
            FoodSales DECIMAL(16,2) NOT NULL DEFAULT 0, SalesAmount DECIMAL(16,2) NOT NULL DEFAULT 0,
            AchievementRate DECIMAL(18,14) NOT NULL DEFAULT 0, Notes VARCHAR(600) NULL,
            Status VARCHAR(20) NOT NULL DEFAULT "POSTED", CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL,
            UpdatedAt DATETIME NOT NULL, UpdatedBy VARCHAR(120) NOT NULL,
            UNIQUE KEY employee_report_day (EmployeeID,ReportDate), INDEX (PayrollMonth,EmployeeID), INDEX (DealerID,ReportDate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRAchievements (
            AchievementID VARCHAR(24) PRIMARY KEY, EmployeeID VARCHAR(20) NOT NULL, DealerID VARCHAR(20) NOT NULL,
            AchievementDate DATE NOT NULL, Title VARCHAR(180) NOT NULL, Category VARCHAR(80) NOT NULL,
            Description TEXT NULL, MetricValue DECIMAL(16,2) NULL, CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL, INDEX (EmployeeID, AchievementDate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRPayroll (
            PayrollID VARCHAR(24) PRIMARY KEY, EmployeeID VARCHAR(20) NOT NULL, DealerID VARCHAR(20) NOT NULL,
            PayrollMonth CHAR(7) NOT NULL, WorkingDays INT NOT NULL, PaidDays DECIMAL(6,2) NOT NULL,
            MonthlySalary DECIMAL(16,2) NOT NULL, AttendancePay DECIMAL(16,2) NOT NULL,
            CommissionBase DECIMAL(16,2) NOT NULL DEFAULT 0, CommissionRate DECIMAL(8,5) NOT NULL DEFAULT 0,
            CommissionAmount DECIMAL(16,2) NOT NULL DEFAULT 0, TADA DECIMAL(16,2) NOT NULL DEFAULT 0,
            Incentive DECIMAL(16,2) NOT NULL DEFAULT 0, Additions DECIMAL(16,2) NOT NULL DEFAULT 0,
            Deductions DECIMAL(16,2) NOT NULL DEFAULT 0, LoanDeduction DECIMAL(16,2) NOT NULL DEFAULT 0,
            NetPay DECIMAL(16,2) NOT NULL, Status VARCHAR(20) NOT NULL DEFAULT "REVIEW",
            DisbursedAmount DECIMAL(16,2) NULL, DisbursementMethod VARCHAR(40) NULL,
            DisbursedAt DATETIME NULL, DisbursedBy VARCHAR(120) NULL, DisbursementReference VARCHAR(160) NULL,
            CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL, UpdatedAt DATETIME NOT NULL, UpdatedBy VARCHAR(120) NOT NULL,
            UNIQUE KEY employee_month (EmployeeID, PayrollMonth), INDEX (DealerID, PayrollMonth, Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRLeaveTypes (
            LeaveTypeID VARCHAR(24) PRIMARY KEY, DealerID VARCHAR(20) NOT NULL, TypeName VARCHAR(80) NOT NULL,
            IsPaid TINYINT(1) NOT NULL DEFAULT 1, AnnualEntitlement DECIMAL(6,2) NOT NULL DEFAULT 0, Status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
            CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL, UNIQUE KEY dealer_leave_type (DealerID, TypeName), INDEX (DealerID, Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRLeaveRequests (
            LeaveRequestID VARCHAR(24) PRIMARY KEY, EmployeeID VARCHAR(20) NOT NULL, DealerID VARCHAR(20) NOT NULL,
            LeaveTypeID VARCHAR(24) NOT NULL, StartDate DATE NOT NULL, EndDate DATE NOT NULL, Days DECIMAL(6,2) NOT NULL,
            Reason VARCHAR(600) NULL, Status VARCHAR(20) NOT NULL DEFAULT "PENDING", DecisionNotes VARCHAR(500) NULL,
            DecidedAt DATETIME NULL, DecidedBy VARCHAR(120) NULL, CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL,
            INDEX (DealerID, Status, StartDate), INDEX (EmployeeID, StartDate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRLoans (
            LoanID VARCHAR(24) PRIMARY KEY, EmployeeID VARCHAR(20) NOT NULL, DealerID VARCHAR(20) NOT NULL,
            LoanType VARCHAR(20) NOT NULL DEFAULT "LOAN", RequestedAmount DECIMAL(16,2) NOT NULL,
            ApprovedAmount DECIMAL(16,2) NULL, MonthlyInstallment DECIMAL(16,2) NULL, OutstandingAmount DECIMAL(16,2) NULL,
            Reason VARCHAR(600) NULL, Status VARCHAR(20) NOT NULL DEFAULT "PENDING", DisbursementMethod VARCHAR(40) NULL,
            DisbursementReference VARCHAR(160) NULL, DisbursedAt DATETIME NULL, DisbursedBy VARCHAR(120) NULL,
            DecisionNotes VARCHAR(500) NULL, DecidedAt DATETIME NULL, DecidedBy VARCHAR(120) NULL,
            CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL, UpdatedAt DATETIME NOT NULL, UpdatedBy VARCHAR(120) NOT NULL,
            INDEX (DealerID, Status, CreatedAt), INDEX (EmployeeID, Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRLoanRepayments (
            RepaymentID VARCHAR(24) PRIMARY KEY, LoanID VARCHAR(24) NOT NULL, PayrollID VARCHAR(24) NOT NULL,
            EmployeeID VARCHAR(20) NOT NULL, DealerID VARCHAR(20) NOT NULL, PayrollMonth CHAR(7) NOT NULL,
            Amount DECIMAL(16,2) NOT NULL, Status VARCHAR(20) NOT NULL DEFAULT "PENDING", PaidAt DATETIME NULL,
            CreatedAt DATETIME NOT NULL, UNIQUE KEY loan_payroll (LoanID, PayrollID), INDEX (DealerID, PayrollMonth, Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRExpenseClaims (
            ClaimID VARCHAR(24) PRIMARY KEY, EmployeeID VARCHAR(20) NOT NULL, DealerID VARCHAR(20) NOT NULL,
            ExpenseDate DATE NOT NULL, Category VARCHAR(80) NOT NULL, Amount DECIMAL(16,2) NOT NULL,
            ApprovedAmount DECIMAL(16,2) NULL, Description VARCHAR(600) NULL, ReceiptReference VARCHAR(160) NULL,
            Status VARCHAR(20) NOT NULL DEFAULT "PENDING", PaymentMethod VARCHAR(40) NULL, PaymentReference VARCHAR(160) NULL,
            PaidAt DATETIME NULL, PaidBy VARCHAR(120) NULL, DecisionNotes VARCHAR(500) NULL, DecidedAt DATETIME NULL,
            DecidedBy VARCHAR(120) NULL, CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL,
            INDEX (DealerID, Status, ExpenseDate), INDEX (EmployeeID, ExpenseDate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS HRPettyCashEntries (
            EntryID VARCHAR(24) PRIMARY KEY, DealerID VARCHAR(20) NOT NULL, EntryDate DATE NOT NULL,
            EntryType VARCHAR(20) NOT NULL, Category VARCHAR(80) NULL, EmployeeID VARCHAR(20) NULL,
            Amount DECIMAL(16,2) NOT NULL, ReferenceNo VARCHAR(160) NULL, Description VARCHAR(600) NULL,
            SourceClaimID VARCHAR(24) NULL, CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL,
            INDEX (DealerID, EntryDate), INDEX (SourceClaimID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        foreach (['TAAllowance'=>'DECIMAL(16,2) NULL','DAAllowance'=>'DECIMAL(16,2) NULL'] as $column=>$definition) {
            $exists=(int)$this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="HREmployeeProfiles" AND COLUMN_NAME=?',[$column]);
            if(!$exists)$this->db->exec('ALTER TABLE HREmployeeProfiles ADD COLUMN '.$column.' '.$definition);
        }
        $hasEmployeeId = (int) $this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Users" AND COLUMN_NAME="EmployeeID"');
        if (!$hasEmployeeId) $this->db->exec('ALTER TABLE Users ADD COLUMN EmployeeID VARCHAR(20) NULL AFTER DealerID');
        foreach (['DisbursedAmount'=>'DECIMAL(16,2) NULL','DisbursementMethod'=>'VARCHAR(40) NULL','LoanDeduction'=>'DECIMAL(16,2) NOT NULL DEFAULT 0','CommissionBase'=>'DECIMAL(16,2) NOT NULL DEFAULT 0','CommissionRate'=>'DECIMAL(8,5) NOT NULL DEFAULT 0','CommissionAmount'=>'DECIMAL(16,2) NOT NULL DEFAULT 0','TADA'=>'DECIMAL(16,2) NOT NULL DEFAULT 0','Incentive'=>'DECIMAL(16,2) NOT NULL DEFAULT 0','PerformanceDetailsJSON'=>'LONGTEXT NULL','AdjustmentDetailsJSON'=>'LONGTEXT NULL'] as $column=>$definition) {
            $exists=(int)$this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="HRPayroll" AND COLUMN_NAME=?',[$column]);
            if(!$exists)$this->db->exec('ALTER TABLE HRPayroll ADD COLUMN `'.$column.'` '.$definition);
        }
        foreach (['UserID'=>'VARCHAR(20) NULL','DealerID'=>'VARCHAR(20) NULL','Phone'=>'VARCHAR(40) NULL','JoiningDate'=>'DATE NULL','EmploymentType'=>'VARCHAR(30) NULL','MonthlySalary'=>'DECIMAL(16,2) NULL'] as $column=>$definition) {
            $nullable=$this->value('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Employees" AND COLUMN_NAME=?',[$column]);
            if($nullable==='NO')$this->db->exec('ALTER TABLE Employees MODIFY `'.$column.'` '.$definition);
        }
        foreach (['AchievementRate','CostSalesRate'] as $column) {
            $scale=$this->value('SELECT NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="HRSourceRoster" AND COLUMN_NAME=?',[$column]);
            if($scale!==false && (int)$scale<14)$this->db->exec('ALTER TABLE HRSourceRoster MODIFY `'.$column.'` DECIMAL(18,14) NULL');
        }
    }

    private function hrActor(bool $manager = false): array
    {
        $user = $this->user(); $role = $this->normalizedRole((string) $user['Role']);
        if ($role === 'ADMIN') return $user;
        if ($manager) throw new RuntimeException('Only an Administrator can access HR management.');
        if ($role !== 'EMPLOYEE' || empty($user['EmployeeID'])) throw new RuntimeException('Employee portal access is required.');
        return $user;
    }
    private function hrEmployee(string $id): ?array { return $this->one('Employees', 'EmployeeID', $id); }
    private function hrScope(array $actor): array
    {
        return $this->normalizedRole((string) $actor['Role']) === 'ADMIN'
            ? ['manager'=>true,'dealerId'=>''] : ['manager'=>false,'dealerId'=>(string) ($actor['DealerID'] ?? '')];
    }
    private function hrEmployeeRows(array $actor): array
    {
        $scope=$this->hrScope($actor);
        return $scope['manager'] ? $this->rows('Employees','Status<>?', ['ARCHIVED']) : $this->rows('Employees','DealerID=? AND Status<>?',[$scope['dealerId'],'ARCHIVED']);
    }
    private function getHREmployees(): array
    {
        $this->hrActor(true);
        return $this->rowsBySql('SELECT e.*,p.DateOfBirth,p.BanglaName,p.FRIDNo,p.Gender,p.BloodGroup,p.MaritalStatus,p.FatherName,p.MotherName,p.SpouseName,p.Religion,p.Nationality,p.CompanyName,p.Division,p.Section,p.PositionName,p.FingerID,p.AutoAttendance,p.IgnoreForSalary,p.District,p.LivingArea,p.Designation,p.GrossSalary,p.BasicSalary,p.TAAllowance,p.DAAllowance,p.SalesTarget,p.SalesAchievement,p.BonusAmount,s.SourcePeriod,s.SourceRowNo,s.SourceSRName,s.SourcePayeeName,s.SourceDealerName,s.SourceArea,s.TargetAmount,s.OnlineAmount,s.SalesAmount,s.AchievementRate,s.SourceSalaryTADA,s.CostSalesRate,s.SourceIncentive,s.SuperSmartSales,s.MegaCoilSales,s.DetergentSales,s.FoodSales,s.SourceNote,s.SourceJoiningDate,s.SourceFile FROM Employees e LEFT JOIN HREmployeeProfiles p ON p.EmployeeID=e.EmployeeID LEFT JOIN HRSourceRoster s ON s.EmployeeID=e.EmployeeID WHERE e.Status<>? ORDER BY CASE WHEN e.Status="SETUP_PENDING" THEN 0 ELSE 1 END,e.FullName',['ARCHIVED']);
    }
    private function hrOptionalDate(mixed $value,string $label): ?string
    {
        $date=$this->text($value);if($date==='')return null;
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException($label.' must be a valid date.');return $date;
    }
    private function hrOptionalAmount(mixed $value,string $label,?float $max=null): ?float
    {
        if($this->text($value)==='')return null;$number=$this->num($value,$label,true);if($max!==null&&$number>$max)throw new InvalidArgumentException($label.' cannot exceed '.$max.'.');return $number;
    }
    private function hrProfileRows(mixed $value,array $fields,string $label,int $limit=12): array
    {
        if(is_string($value)){$decoded=json_decode($value,true);if(!is_array($decoded))throw new InvalidArgumentException($label.' data is invalid.');$value=$decoded;}
        if(!is_array($value))return [];$rows=[];foreach(array_slice($value,0,$limit) as $row){if(!is_array($row))continue;$clean=[];$hasValue=false;foreach($fields as $field){$text=$this->text($row[$field]??'');$v=function_exists('mb_substr')?mb_substr($text,0,500):substr($text,0,500);$clean[$field]=$v;if($v!=='')$hasValue=true;}if($hasValue)$rows[]=$clean;}return $rows;
    }
    private function hrProfilePhoto(mixed $value): ?string
    {
        $data=$this->text($value);if($data==='')return null;if(!preg_match('/^data:image\/jpeg;base64,([A-Za-z0-9+\/=]+)$/',$data,$match))throw new InvalidArgumentException('Employee photo must be a compressed JPEG image.');$binary=base64_decode($match[1],true);if($binary===false||strlen($binary)>30720)throw new InvalidArgumentException('Employee photo must be 30 KB or smaller.');$image=@getimagesizefromstring($binary);if(!$image||($image['mime']??'')!=='image/jpeg')throw new InvalidArgumentException('Employee photo is not a valid JPEG image.');return 'data:image/jpeg;base64,'.$match[1];
    }
    private function normalizeHREmployeeCodes(): void
    {
        $rows=$this->rowsBySql('SELECT EmployeeID,EmployeeCode FROM Employees');$legacy=[];$reserved=[];
        foreach($rows as $row){if(preg_match('/^EMP-CODE-(\\d+)$/i',(string)$row['EmployeeCode'],$match)){$number=(int)$match[1];if($number<1||$number>9999)throw new RuntimeException('An existing employee code cannot be converted to the four-digit ID format.');$legacy[]=['EmployeeID'=>$row['EmployeeID'],'Code'=>str_pad((string)$number,4,'0',STR_PAD_LEFT)];}else $reserved[(string)$row['EmployeeCode']]=true;}
        if(!$legacy)return;$targets=[];foreach($legacy as $item){if(isset($reserved[$item['Code']])||isset($targets[$item['Code']]))return;$targets[$item['Code']]=true;}
        $this->db->beginTransaction();try{foreach($legacy as $item)$this->update('Employees','EmployeeID',(string)$item['EmployeeID'],['EmployeeCode'=>$item['Code']]);$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}
    }
    private function nextHREmployeeCode(): string
    {
        $max=0;foreach($this->rowsBySql('SELECT EmployeeCode FROM Employees') as $row){if(preg_match('/(\d+)$/',(string)$row['EmployeeCode'],$match))$max=max($max,(int)$match[1]);}
        if($max>=9999)throw new RuntimeException('The four-digit employee ID sequence is full.');
        return str_pad((string)($max+1),4,'0',STR_PAD_LEFT);
    }
    private function hrProfilePayload(array $input,?array $existing=null): array
    {
        $quickActivate=!empty($input['ActivateProfile']);$name=$this->text($input['FullName']??$existing['FullName']??'');$phoneRaw=$this->text($input['Phone']??$existing['Phone']??'');if(!$name||!$phoneRaw)throw new InvalidArgumentException('Full name and Bangladesh mobile number are required.');[$name,$phone]=$this->validateUserProfile($name,$phoneRaw,(string)($existing['UserID']??''));$employeeId=(string)($existing['EmployeeID']??'');$duplicate=(int)$this->value('SELECT COUNT(*) FROM Employees WHERE Phone=? AND EmployeeID<>?',[$phone,$employeeId]);if($duplicate)throw new InvalidArgumentException('This mobile number is already assigned to another employee profile.');
        $dob=$this->hrOptionalDate($input['DateOfBirth']??$existing['DateOfBirth']??'','Date of birth');if(!$dob)throw new InvalidArgumentException('Date of birth is required.');if($dob>date('Y-m-d'))throw new InvalidArgumentException('Date of birth cannot be in the future.');
        $education=$this->hrProfileRows($input['Education']??($existing['Education']??[]),['Certificate','Board','Institute','Year','Result'],'Education');if((!$education||$this->text($education[0]['Certificate']??'')==='')&&!$quickActivate&&($existing['Status']??'')!=='ACTIVE')throw new InvalidArgumentException('At least one education certificate is required before saving a setup-pending profile.');
        $dealerId=$this->text($input['DealerID']??$existing['DealerID']??'');if(!$dealerId)throw new InvalidArgumentException('Select the registered dealer this employee will work under.');$this->validateDealerUser($dealerId);
        $joining=$this->hrOptionalDate($input['JoiningDate']??$existing['JoiningDate']??'','Joining date');$pfDate=$this->hrOptionalDate($input['PFEffectiveDate']??$existing['PFEffectiveDate']??'','PF effective date');
        $basicInput=$input['BasicSalary']??$existing['BasicSalary']??$input['GrossSalary']??$existing['GrossSalary']??$existing['MonthlySalary']??'';$basic=$this->hrOptionalAmount($basicInput,'Basic salary');$gross=$this->hrOptionalAmount($input['GrossSalary']??$basic??$existing['GrossSalary']??$existing['MonthlySalary']??'','Gross salary');if(array_key_exists('BasicSalary',$input))$gross=$basic;
        $jobTitle=$this->text($input['JobTitle']??$input['Designation']??$input['PositionName']??$existing['JobTitle']??'')?:'Pending role';if($quickActivate&&strtolower($jobTitle)==='pending role')throw new InvalidArgumentException('Designation is required to activate an employee.');if($quickActivate&&!$joining)throw new InvalidArgumentException('Joining date is required to activate an employee.');$department=$this->text($input['Department']??$input['Section']??$existing['Department']??'')?:null;
        $columns=['BanglaName','FRIDNo','Gender','BloodGroup','MaritalStatus','FatherName','MotherName','SpouseName','Religion','Nationality','CompanyName','Division','Section','PositionName','FingerID','WorkStatus','PresentAddress','PresentAddressBangla','PermanentAddress','PermanentAddressBangla','LivingArea','Thana','District','ShiftName','PayStructure','Grade','WeeklyHoliday','Designation','AccountHolder','BankName','BankBranch','AccountNumber','RoutingNumber','MFSProvider','MFSNumber','PaymentMode'];$profile=[];foreach($columns as $column)$profile[$column]=$this->text($input[$column]??$existing[$column]??'')?:null;
        $numeric=['HouseRent','MedicalAllowance','TransportAllowance','LunchAllowance','FixedOT','TAAllowance','DAAllowance','PFContributionPercent','BasicForPFPercent','SalesTarget','SalesAchievement','CommissionBase','CommissionRate','BonusAmount'];foreach($numeric as $column)$profile[$column]=$this->hrOptionalAmount($input[$column]??$existing[$column]??'',$column,in_array($column,['PFContributionPercent','BasicForPFPercent','CommissionRate'],true)?100:null);
        $profile['DateOfBirth']=$dob;$profile['PhotoDataUrl']=$this->hrProfilePhoto($input['PhotoDataUrl']??'')??($existing['PhotoDataUrl']??null);$profile['AutoAttendance']=!empty($input['AutoAttendance'])?1:0;$profile['IgnoreForSalary']=!empty($input['IgnoreForSalary'])?1:0;$profile['GettingFixedOT']=!empty($input['GettingFixedOT'])?1:0;$profile['OTActual']=!empty($input['OTActual'])?1:0;$profile['OTCompliance']=!empty($input['OTCompliance'])?1:0;$profile['GetBonus']=!empty($input['GetBonus'])?1:0;
        $profile['GrossSalary']=$gross;$profile['BasicSalary']=$basic;$profile['PFEffectiveDate']=$pfDate;$profile['EducationJSON']=json_encode($education,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$profile['ExperienceJSON']=json_encode($this->hrProfileRows($input['Experience']??($existing['Experience']??[]),['Company','Designation','FromDate','ToDate','Description'],'Experience'),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$profile['ExpertiseJSON']=json_encode($this->hrProfileRows($input['Expertise']??($existing['Expertise']??[]),['Area','Level','Years'],'Expertise'),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$profile['NomineeJSON']=json_encode($this->hrProfileRows($input['Nominees']??($existing['Nominees']??[]),['Name','Relationship','DateOfBirth','SharePercent','Phone','NID','Address'],'Nominees'),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        return ['name'=>$name,'phone'=>$phone,'dealerId'=>$dealerId,'joining'=>$joining,'activate'=>$quickActivate,'employmentType'=>$this->text($input['EmploymentType']??$existing['EmploymentType']??'')?:null,'jobTitle'=>$jobTitle,'department'=>$department,'gross'=>$gross,'profile'=>$profile];
    }
    private function createHRProfile(array $input): array
    {
        $this->hrActor(true);$payload=$this->hrProfilePayload($input);$id=$this->id('Employees','EmployeeID','EMP');$code=$this->nextHREmployeeCode();$now=$this->now();$row=['EmployeeID'=>$id,'EmployeeCode'=>$code,'UserID'=>null,'DealerID'=>$payload['dealerId'],'FullName'=>$payload['name'],'Phone'=>$payload['phone'],'Email'=>$this->text($input['Email']??'')?:null,'NID'=>$this->text($input['NID']??'')?:null,'JobTitle'=>$payload['jobTitle'],'Department'=>$payload['department'],'JoiningDate'=>$payload['joining'],'EmploymentType'=>$payload['employmentType'],'MonthlySalary'=>$payload['gross'],'Status'=>$payload['activate']?'ACTIVE':'SETUP_PENDING','CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];$profile=$payload['profile']+['CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];$profile['EmployeeID']=$id;
        $this->db->beginTransaction();try{$this->insert('Employees',$row);$this->insert('HREmployeeProfiles',$profile);$this->audit('CREATE','HR Employees',$id,$payload['activate']?'Employee profile activated in HR; login setup remains separate.':'New employee profile created; setup remains pending.');$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}return $this->getHRProfileDetails($id);
    }
    private function updateHRProfile(string $id,array $input): array
    {
        $this->hrActor(true);$employee=$this->hrEmployee($id);if(!$employee)throw new RuntimeException('Employee was not found.');$old=$this->rowsBySql('SELECT * FROM HREmployeeProfiles WHERE EmployeeID=?',[$id])[0]??[];if($old){$old['Education']=json_decode((string)$old['EducationJSON'],true)?:[];$old['Experience']=json_decode((string)$old['ExperienceJSON'],true)?:[];$old['Expertise']=json_decode((string)$old['ExpertiseJSON'],true)?:[];$old['Nominees']=json_decode((string)$old['NomineeJSON'],true)?:[];}
        $payload=$this->hrProfilePayload($input+['DateOfBirth'=>$input['DateOfBirth']??$old['DateOfBirth']??'','Education'=>$input['Education']??($old['Education']??[])],array_merge($employee,$old));$now=$this->now();$fields=['DealerID'=>$payload['dealerId'],'FullName'=>$payload['name'],'Phone'=>$payload['phone'],'Email'=>$this->text($input['Email']??$employee['Email']??'')?:null,'NID'=>$this->text($input['NID']??$employee['NID']??'')?:null,'JobTitle'=>$payload['jobTitle'],'Department'=>$payload['department'],'JoiningDate'=>$payload['joining'],'EmploymentType'=>$payload['employmentType'],'MonthlySalary'=>$payload['gross'],'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];$profile=$payload['profile'];$fields['Status']=$payload['activate']?'ACTIVE':$employee['Status'];$profile['UpdatedAt']=$now;$profile['UpdatedBy']=$this->current();
        $this->db->beginTransaction();try{$this->update('Employees','EmployeeID',$id,$fields);if(!empty($employee['UserID'])){$userFields=['Username'=>$payload['phone'],'Phone'=>$payload['phone'],'Name'=>$payload['name'],'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];if($payload['activate'])$userFields['Status']='ACTIVE';$this->update('Users','UserID',(string)$employee['UserID'],$userFields);}if($old)$this->update('HREmployeeProfiles','EmployeeID',$id,$profile);else{$profile['EmployeeID']=$id;$profile['CreatedAt']=$now;$profile['CreatedBy']=$this->current();$this->insert('HREmployeeProfiles',$profile);}$this->audit('UPDATE','HR Employees',$id,'Employee details updated.');$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}return $this->getHRProfileDetails($id);
    }
    private function getHRProfileDetails(string $id): array
    {
        $this->hrActor(true);$employee=$this->hrEmployee($id);if(!$employee)throw new RuntimeException('Employee was not found.');$profile=$this->rowsBySql('SELECT * FROM HREmployeeProfiles WHERE EmployeeID=?',[$id])[0]??[];foreach(['EducationJSON'=>'Education','ExperienceJSON'=>'Experience','ExpertiseJSON'=>'Expertise','NomineeJSON'=>'Nominees'] as $json=>$field){$profile[$field]=isset($profile[$json])?(json_decode((string)$profile[$json],true)?:[]):[];}return ['Employee'=>$employee,'Profile'=>$profile];
    }
    private function getDealerTeamSummary(): array
    {
        $actor=$this->user();if($this->normalizedRole((string)$actor['Role'])!=='DEALER')throw new RuntimeException('Dealer team summary is available to dealer accounts only.');$dealerId=$this->dealerUserId($actor);if(!$dealerId)throw new RuntimeException('Dealer account is not linked to a registered dealer.');
        $employeeCount=(int)$this->value('SELECT COUNT(*) FROM Employees WHERE DealerID=? AND Status=?',[$dealerId,'ACTIVE']);
        $salesRoles=(int)$this->value('SELECT COUNT(*) FROM Employees WHERE DealerID=? AND Status=? AND (LOWER(JobTitle) LIKE ? OR LOWER(JobTitle) IN ("sr","salesman","sales representative"))',[$dealerId,'ACTIVE','%sales%']);
        $registeredSR=(int)$this->value('SELECT COUNT(*) FROM DealerSalesRepresentatives WHERE DealerID=? AND Status=?',[$dealerId,'ACTIVE']);
        $pending=(int)$this->value('SELECT COUNT(*) FROM HRPayroll WHERE DealerID=? AND Status<>?',[$dealerId,'PAID']);
        $due=(float)$this->value('SELECT COALESCE(SUM(NetPay),0) FROM HRPayroll WHERE DealerID=? AND Status<>?',[$dealerId,'PAID']);
        $dealer=$this->one('Dealers','DealerID',$dealerId);return ['DealerID'=>$dealerId,'DealerName'=>$dealer['DealerName']??$dealerId,'ActiveEmployees'=>$employeeCount,'SalesEmployees'=>$salesRoles,'RegisteredSalesmen'=>$registeredSR,'PendingPayrolls'=>$pending,'PendingPayrollAmount'=>$due];
    }
    private function createHREmployee(array $input): array
    {
        $actor=$this->hrActor(true); $scope=$this->hrScope($actor); $dealerId=$scope['manager']?$this->text($input['DealerID']??''):$scope['dealerId'];
        $dealer=$this->validateDealerUser($dealerId); $name=$this->text($input['FullName']??''); $phone=$this->text($input['Phone']??'');
        $password=$this->text($input['Password']??'');
        if(!$name||!$phone||!$this->text($input['JobTitle']??'')) throw new InvalidArgumentException('Name, phone and job title are required.'); [$name,$phone]=$this->validateUserProfile($name,$phone); $username=$phone;
        if(strlen($password)<8) throw new InvalidArgumentException('Set a password of at least 8 characters. The employee phone number is the login ID.');
        $salary=$this->num($input['MonthlySalary']??'', 'Monthly salary'); $now=$this->now(); $userId=$this->id('Users','UserID','USR'); $employeeId=$this->id('Employees','EmployeeID','EMP');
        $this->db->beginTransaction();
        try {
            $userRow=['UserID'=>$userId,'Username'=>$username,'Name'=>$name,'Name'=>$name,'Phone'=>$phone,'Role'=>'EMPLOYEE','DealerID'=>$dealerId,'EmployeeID'=>$employeeId,'PasswordHash'=>password_hash($password,PASSWORD_DEFAULT),'Status'=>'ACTIVE','CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];
            $this->insert('Users',$userRow); $this->savePermissionKeys($userId,'EMPLOYEE',['employee_portal']);
            $employee=['EmployeeID'=>$employeeId,'EmployeeCode'=>$this->nextHREmployeeCode(),'UserID'=>$userId,'DealerID'=>$dealerId,'FullName'=>$name,'Phone'=>$phone,'Email'=>$this->text($input['Email']??''),'NID'=>$this->text($input['NID']??''),'JobTitle'=>$this->text($input['JobTitle']),'Department'=>$this->text($input['Department']??''),'JoiningDate'=>$this->today($input['JoiningDate']??''),'EmploymentType'=>$this->text($input['EmploymentType']??'PERMANENT'),'MonthlySalary'=>$salary,'Status'=>'ACTIVE','CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];
            $this->insert('Employees',$employee); $this->audit('CREATE','HR Employees',$employeeId,'Employee hired and linked to dealer '.$dealer['DealerName'].'.'); $this->db->commit();
            return $employee;
        } catch(Throwable $error) { $this->db->rollBack(); throw $error; }
    }
    private function updateHREmployee(string $id,array $input): array
    {
        $actor=$this->hrActor(true); $employee=$this->hrEmployee($id); if(!$employee) throw new RuntimeException('Employee was not found.');
        $scope=$this->hrScope($actor); if(!$scope['manager']&&$employee['DealerID']!==$scope['dealerId']) throw new RuntimeException('Employee belongs to another dealer.');
        $name=$this->text($input['FullName']??$employee['FullName']);$phone=$this->text($input['Phone']??$employee['Phone']);[$name,$phone]=$this->validateUserProfile($name,$phone,(string)$employee['UserID']);$salary=$this->num($input['MonthlySalary']??$employee['MonthlySalary'],'Monthly salary');
        $this->db->beginTransaction();try{
            $this->update('Employees','EmployeeID',$id,['FullName'=>$name,'Phone'=>$phone,'Email'=>$this->text($input['Email']??$employee['Email']),'NID'=>$this->text($input['NID']??$employee['NID']),'JobTitle'=>$this->text($input['JobTitle']??$employee['JobTitle']),'Department'=>$this->text($input['Department']??$employee['Department']),'JoiningDate'=>$this->today($input['JoiningDate']??$employee['JoiningDate']),'EmploymentType'=>$this->text($input['EmploymentType']??$employee['EmploymentType']),'MonthlySalary'=>$salary,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);
            $this->update('Users','UserID',(string)$employee['UserID'],['Username'=>$phone,'Name'=>$name,'Phone'=>$phone,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('UPDATE','HR Employees',$id,'Employee profile updated.');$this->db->commit();
        }catch(Throwable $error){$this->db->rollBack();throw $error;}return $this->hrEmployee($id)??$employee;
    }
    private function changeHREmployeeStatus(string $id,string $status): array
    {
        $this->hrActor(true);$status=strtoupper($this->text($status));if(!in_array($status,['ACTIVE','INACTIVE','ARCHIVED'],true))throw new InvalidArgumentException('Invalid employee status.');$employee=$this->hrEmployee($id);if(!$employee)throw new RuntimeException('Employee was not found.');if($status==='ARCHIVED')return$this->archiveRecord('Employees',$id,'Archived from HR Employee Register.');if($employee['Status']==='ARCHIVED'){$this->restoreLatestRecord('Employees',$id);return$this->getHREmployees();}
        if(empty($employee['UserID'])&&$status==='ACTIVE')throw new RuntimeException('Use Quick activate in the employee profile to activate an HR-only employee.');
        $this->db->beginTransaction();try{$this->update('Employees','EmployeeID',$id,['Status'=>$status,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);if(!empty($employee['UserID']))$this->update('Users','UserID',(string)$employee['UserID'],['Status'=>$status==='ACTIVE'?'ACTIVE':'INACTIVE','UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('STATUS','HR Employees',$id,'Employee status changed to '.$status.'.');$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}return $this->getHREmployees();
    }
    private function getHRAttendance(array $filter): array
    {
        $actor=$this->hrActor(true);$scope=$this->hrScope($actor);$month=$this->hrMonth($filter['Month']??date('Y-m'));$sql='SELECT a.*,e.EmployeeCode,e.FullName,e.JobTitle FROM HRAttendance a JOIN Employees e ON e.EmployeeID=a.EmployeeID WHERE DATE_FORMAT(a.AttendanceDate,"%Y-%m")=?';$params=[$month];
        if(!$scope['manager']){$sql.=' AND a.DealerID=?';$params[]=$scope['dealerId'];}if(!empty($filter['EmployeeID'])){$sql.=' AND a.EmployeeID=?';$params[]=$this->text($filter['EmployeeID']);}return $this->rowsBySql($sql.' ORDER BY a.AttendanceDate DESC,e.FullName',$params);
    }
    private function saveHRAttendance(array $input): array
    {
        $actor=$this->hrActor(true);$date=$this->hrDateValue($this->text($input['AttendanceDate']??''),'Attendance date');$items=(array)($input['Entries']??[]);if(!$items)throw new InvalidArgumentException('Attendance entries are required.');$scope=$this->hrScope($actor);$eligible=array_filter($this->hrEmployeeRows($actor),static fn($e)=>$e['Status']==='ACTIVE'&&!empty($e['DealerID']));$allowed=array_flip(array_column($eligible,'EmployeeID'));$now=$this->now();
        $this->db->beginTransaction();try{foreach($items as $item){$id=$this->text($item['EmployeeID']??'');if(!isset($allowed[$id]))throw new RuntimeException('An employee is outside your dealer scope.');$status=strtoupper($this->text($item['Status']??''));if(!in_array($status,['PRESENT','ABSENT','HALF_DAY','LEAVE','HOLIDAY'],true))throw new InvalidArgumentException('Invalid attendance status.');$employee=$this->hrEmployee($id);$exists=$this->value('SELECT AttendanceID FROM HRAttendance WHERE EmployeeID=? AND AttendanceDate=?',[$id,$date]);$row=['EmployeeID'=>$id,'DealerID'=>$employee['DealerID'],'AttendanceDate'=>$date,'AttendanceStatus'=>$status,'PaidLeave'=>!empty($item['PaidLeave'])?1:0,'CheckIn'=>$this->text($item['CheckIn']??'')?:null,'CheckOut'=>$this->text($item['CheckOut']??'')?:null,'Notes'=>$this->text($item['Notes']??''),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];if($exists)$this->update('HRAttendance','AttendanceID',(string)$exists,$row);else{$row=['AttendanceID'=>$this->id('HRAttendance','AttendanceID','ATT'),'CreatedAt'=>$now,'CreatedBy'=>$this->current()]+$row;$this->insert('HRAttendance',$row);}}$this->audit('UPSERT','HR Attendance','',$date.' attendance saved for '.count($items).' employees.');$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}return $this->getHRAttendance(['Month'=>substr($date,0,7)]);
    }
    private function getHRAchievements(array $filter=[]): array
    {
        $actor=$this->user();$role=$this->normalizedRole((string)$actor['Role']);if(!in_array($role,['ADMIN','DEALER','EMPLOYEE'],true)&&!in_array('hr_management',$this->configuredPermissionKeys($actor),true))throw new RuntimeException('You do not have permission to view achievements.');$scope=$this->hrScope($actor);$sql='SELECT a.*,e.EmployeeCode,e.FullName,e.JobTitle FROM HRAchievements a JOIN Employees e ON e.EmployeeID=a.EmployeeID WHERE 1=1';$params=[];if(!$scope['manager']){$sql.=' AND a.EmployeeID=?';$params[]=(string)$actor['EmployeeID'];}elseif(!empty($filter['EmployeeID'])){$sql.=' AND a.EmployeeID=?';$params[]=$this->text($filter['EmployeeID']);}if(!empty($filter['Month'])){$sql.=' AND DATE_FORMAT(a.AchievementDate,"%Y-%m")=?';$params[]=$this->hrMonth($filter['Month']);}return $this->rowsBySql($sql.' ORDER BY a.AchievementDate DESC,a.CreatedAt DESC',$params);
    }
    private function createHRAchievement(array $input): array
    {
        $actor=$this->hrActor(true);$scope=$this->hrScope($actor);$employeeId=$scope['manager']?$this->text($input['EmployeeID']??''):(string)$actor['EmployeeID'];$employee=$this->hrEmployee($employeeId);if(!$employee||$employee['Status']!=='ACTIVE'||empty($employee['DealerID'])||(!$scope['manager']&&$employee['UserID']!==$actor['UserID']))throw new RuntimeException('Only active employees with a registered dealer assignment can receive achievements.');$title=$this->text($input['Title']??'');if(!$title)throw new InvalidArgumentException('Achievement title is required.');$id=$this->id('HRAchievements','AchievementID','ACH');$this->insert('HRAchievements',['AchievementID'=>$id,'EmployeeID'=>$employeeId,'DealerID'=>$employee['DealerID'],'AchievementDate'=>$this->hrDateValue($this->text($input['AchievementDate']??''),'Achievement date'),'Title'=>$title,'Category'=>$this->text($input['Category']??'PERFORMANCE'),'Description'=>$this->text($input['Description']??''),'MetricValue'=>$this->text($input['MetricValue']??'')===''?null:$this->num($input['MetricValue'],'Metric',true),'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()]);$this->audit('CREATE','HR Achievements',$id,'Achievement recorded.');return $this->getHRAchievements(['EmployeeID'=>$employeeId]);
    }
    private function hrMonth(mixed $value): string
    {
        $month=$this->text($value);if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))throw new InvalidArgumentException('Month must use YYYY-MM format.');return $month;
    }
    private function rowsBySql(string $sql,array $params=[]): array { $stmt=$this->db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(); }
    private function getHRPayroll(string $month): array
    {
        $actor=$this->hrFinanceActor(['ADMIN','DEALER']);$month=$this->hrMonth($month);
        $where=' WHERE p.PayrollMonth=? AND p.Status<>"ARCHIVED"';$params=[$month];if($this->normalizedRole((string)$actor['Role'])==='DEALER'){$where.=' AND p.DealerID=?';$params[]=$this->dealerUserId($actor);}$rows=$this->rowsBySql('SELECT p.*,e.EmployeeCode,e.FullName,e.JobTitle,e.Phone,e.JoiningDate,e.MonthlySalary AS EmployeeMonthlySalary,d.DealerName,pr.Designation,pr.PositionName,pr.BasicSalary,pr.HouseRent,pr.MedicalAllowance,pr.TransportAllowance,pr.LunchAllowance,pr.FixedOT,pr.GrossSalary,pr.SalesTarget AS ProfileSalesTarget,pr.CommissionRate AS ProfileCommissionRate FROM HRPayroll p JOIN Employees e ON e.EmployeeID=p.EmployeeID LEFT JOIN Dealers d ON d.DealerID=p.DealerID LEFT JOIN HREmployeeProfiles pr ON pr.EmployeeID=e.EmployeeID'.$where.' ORDER BY d.DealerName,e.FullName',$params);
        foreach($rows as &$row){$row['PerformanceDetails']=json_decode((string)($row['PerformanceDetailsJSON']??''),true)?:[];$row['AdjustmentDetails']=json_decode((string)($row['AdjustmentDetailsJSON']??''),true)?:[];$row['LoanItems']=$this->rowsBySql('SELECT r.RepaymentID,r.LoanID,r.Amount,r.Status,l.Reason AS Purpose,l.ApprovedAmount FROM HRLoanRepayments r LEFT JOIN HRLoans l ON l.LoanID=r.LoanID WHERE r.PayrollID=? ORDER BY r.CreatedAt,r.RepaymentID',[(string)$row['PayrollID']]);}
        unset($row);return $rows;
    }
    private function getHRSalesReports(string $month): array
    {
        $this->hrActor(true);$month=$this->hrMonth($month);return $this->rowsBySql('SELECT s.*,e.EmployeeCode,e.FullName,e.JobTitle,e.Phone,e.JoiningDate,p.Designation,p.PositionName,p.LivingArea,p.District,d.DealerName,h.AttendancePay AS PayrollAttendancePay,h.TADA AS PayrollTADA,h.CommissionAmount AS PayrollCommission,h.NetPay AS PayrollNetPay,h.Status AS PayrollStatus FROM HRSalesReports s JOIN Employees e ON e.EmployeeID=s.EmployeeID LEFT JOIN HREmployeeProfiles p ON p.EmployeeID=e.EmployeeID LEFT JOIN Dealers d ON d.DealerID=s.DealerID LEFT JOIN HRPayroll h ON h.EmployeeID=s.EmployeeID AND h.PayrollMonth=s.PayrollMonth WHERE s.PayrollMonth=? AND s.Status<>"ARCHIVED" ORDER BY s.ReportDate DESC,e.FullName',[$month]);
    }
    private function postHRSalesReport(array $input): array
    {
        $this->hrActor(true);$employeeId=$this->text($input['EmployeeID']??'');$employee=$this->hrEmployee($employeeId);if(!$employee||$employee['Status']!=='ACTIVE'||empty($employee['DealerID']))throw new RuntimeException('Choose an active employee assigned to a registered dealer.');
        $reportDate=$this->hrDateValue($this->text($input['ReportDate']??''),'Report date');$month=substr($reportDate,0,7);$profile=$this->rowsBySql('SELECT * FROM HREmployeeProfiles WHERE EmployeeID=?',[$employeeId])[0]??[];$source=$this->rowsBySql('SELECT * FROM HRSourceRoster WHERE EmployeeID=? AND SourcePeriod=?',[$employeeId,$month])[0]??[];$payrollId=$this->value('SELECT PayrollID FROM HRPayroll WHERE EmployeeID=? AND PayrollMonth=?',[$employeeId,$month]);$payroll=$payrollId?$this->one('HRPayroll','PayrollID',(string)$payrollId):null;$performance=$payroll?(json_decode((string)($payroll['PerformanceDetailsJSON']??''),true)?:[]):[];if($payroll&&strtoupper((string)$payroll['Status'])==='PAID')throw new RuntimeException('This employee has already been paid for this month. Posted sales reports cannot change a paid payroll.');
        $prior=$this->rowsBySql('SELECT TargetAmount FROM HRSalesReports WHERE EmployeeID=? AND PayrollMonth=? ORDER BY ReportDate DESC,UpdatedAt DESC LIMIT 1',[$employeeId,$month])[0]??[];$targetValue=$this->text($input['TargetAmount']??'');if($targetValue==='')$targetValue=$prior['TargetAmount']??$performance['TargetAmount']??$profile['SalesTarget']??$source['TargetAmount']??'';$target=$this->num($targetValue,'Monthly sales target');if($target<=0)throw new InvalidArgumentException('Set a monthly sales target before posting the daily report.');if($prior&&abs((float)$prior['TargetAmount']-$target)>0.009)throw new InvalidArgumentException('This employee already has a monthly target in the posted reports. Keep that target consistent for the month or update the reports together.');
        $online=$this->num($input['OnlineAmount']??0,'Online amount',true);$super=$this->num($input['SuperSmartSales']??0,'Super Smart sales',true);$mega=$this->num($input['MegaCoilSales']??0,'Mega Coil sales',true);$detergent=$this->num($input['DetergentSales']??0,'Detergent sales',true);$food=$this->num($input['FoodSales']??0,'Food sales',true);$sales=round($super+$mega+$detergent,2);$dailyRate=$target>0?$sales/$target:0;$notes=substr($this->text($input['Notes']??''),0,600);$existing=$this->rowsBySql('SELECT ReportID FROM HRSalesReports WHERE EmployeeID=? AND ReportDate=?',[$employeeId,$reportDate])[0]??[];$reportId=$existing['ReportID']??$this->id('HRSalesReports','ReportID','SRP');$now=$this->now();
        $this->db->beginTransaction();try{
            $reportFields=['EmployeeID'=>$employeeId,'DealerID'=>(string)$employee['DealerID'],'PayrollMonth'=>$month,'ReportDate'=>$reportDate,'TargetAmount'=>$target,'OnlineAmount'=>$online,'SuperSmartSales'=>$super,'MegaCoilSales'=>$mega,'DetergentSales'=>$detergent,'FoodSales'=>$food,'SalesAmount'=>$sales,'AchievementRate'=>$dailyRate,'Notes'=>$notes,'Status'=>'POSTED','UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];
            if($existing)$this->update('HRSalesReports','ReportID',(string)$reportId,$reportFields);else $this->insert('HRSalesReports',array_merge(['ReportID'=>$reportId,'CreatedAt'=>$now,'CreatedBy'=>$this->current()],$reportFields));
            $totals=$this->rowsBySql('SELECT COUNT(*) AS ReportCount,COALESCE(SUM(OnlineAmount),0) AS OnlineAmount,COALESCE(SUM(SuperSmartSales),0) AS SuperSmartSales,COALESCE(SUM(MegaCoilSales),0) AS MegaCoilSales,COALESCE(SUM(DetergentSales),0) AS DetergentSales,COALESCE(SUM(FoodSales),0) AS FoodSales,COALESCE(SUM(SalesAmount),0) AS SalesAmount FROM HRSalesReports WHERE EmployeeID=? AND PayrollMonth=?',[$employeeId,$month])[0]??[];$monthTargetRow=$this->rowsBySql('SELECT TargetAmount FROM HRSalesReports WHERE EmployeeID=? AND PayrollMonth=? ORDER BY ReportDate DESC,UpdatedAt DESC LIMIT 1',[$employeeId,$month])[0]??[];$monthTarget=(float)($monthTargetRow['TargetAmount']??$target);$salesTotal=(float)$totals['SalesAmount'];$payrollId=$payroll?(string)$payroll['PayrollID']:$this->id('HRPayroll','PayrollID','PAY');
            if(!$payroll){
                $from=$month.'-01';$to=date('Y-m-t',strtotime($from));$calendarDays=(int)date('t',strtotime($from));$attendance=$this->rowsBySql('SELECT AttendanceDate,AttendanceStatus,PaidLeave FROM HRAttendance WHERE EmployeeID=? AND AttendanceDate BETWEEN ? AND ?',[$employeeId,$from,$to]);$paidDates=[];foreach($attendance as $day){$date=(string)$day['AttendanceDate'];if(in_array($day['AttendanceStatus'],['PRESENT','HOLIDAY'],true))$paidDates[$date]=1;elseif($day['AttendanceStatus']==='HALF_DAY')$paidDates[$date]=.5;elseif($day['AttendanceStatus']==='LEAVE'&&(int)$day['PaidLeave']===1)$paidDates[$date]=1;}$leaveRows=$this->rowsBySql('SELECT r.StartDate,r.EndDate FROM HRLeaveRequests r JOIN HRLeaveTypes t ON t.LeaveTypeID=r.LeaveTypeID WHERE r.EmployeeID=? AND r.Status="APPROVED" AND t.IsPaid=1 AND r.StartDate<=? AND r.EndDate>=?',[$employeeId,$to,$from]);foreach($leaveRows as $leave){$cursor=max($from,(string)$leave['StartDate']);$last=min($to,(string)$leave['EndDate']);while($cursor<=$last){if(!isset($paidDates[$cursor]))$paidDates[$cursor]=1;$cursor=date('Y-m-d',strtotime($cursor.' +1 day'));}}$paidDays=min((float)$calendarDays,array_sum($paidDates));$salary=(float)($employee['MonthlySalary']??0);if($salary<=0)$salary=(float)($profile['BasicSalary']??0);$attendancePay=round($salary*$paidDays/max(1,$calendarDays),2);$tada=(float)($profile['TAAllowance']??0)+(float)($profile['DAAllowance']??0);$rate=(float)($profile['CommissionRate']??0)/100;$incentive=(float)($source['SourceIncentive']??$profile['BonusAmount']??0);$adjustments=[];$additions=0.0;$deductions=0.0;$loanDeduction=0.0;
                $loanRows=$this->rowsBySql('SELECT * FROM HRLoans WHERE EmployeeID=? AND DealerID=? AND Status="DISBURSED" ORDER BY DisbursedAt,CreatedAt',[$employeeId,$employee['DealerID']]);foreach($loanRows as $loan){$paid=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM HRLoanRepayments WHERE LoanID=? AND Status="PAID"',[$loan['LoanID']]);$pending=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM HRLoanRepayments WHERE LoanID=? AND Status="PENDING"',[$loan['LoanID']]);$available=max(0,(float)$loan['ApprovedAmount']-$paid-$pending);$amount=min($available,(float)$loan['MonthlyInstallment']);if($amount>0){$repaymentId=$this->id('HRLoanRepayments','RepaymentID','LRP');$this->insert('HRLoanRepayments',['RepaymentID'=>$repaymentId,'LoanID'=>$loan['LoanID'],'PayrollID'=>$payrollId,'EmployeeID'=>$employeeId,'DealerID'=>$employee['DealerID'],'PayrollMonth'=>$month,'Amount'=>$amount,'Status'=>'PENDING','PaidAt'=>null,'CreatedAt'=>$now]);$loanDeduction+=$amount;}}
            }else{$calendarDays=(int)$payroll['WorkingDays'];$paidDays=(float)$payroll['PaidDays'];$salary=(float)$payroll['MonthlySalary'];$attendancePay=(float)$payroll['AttendancePay'];$rate=(float)$payroll['CommissionRate'];$tada=(float)$payroll['TADA'];$incentive=(float)$payroll['Incentive'];$additions=(float)$payroll['Additions'];$deductions=(float)$payroll['Deductions'];$loanDeduction=(float)$payroll['LoanDeduction'];$adjustments=json_decode((string)($payroll['AdjustmentDetailsJSON']??''),true)?:[];}
            $commission=round($salesTotal*$rate,2);$performance=array_merge($performance,['TargetAmount'=>$monthTarget,'OnlineAmount'=>(float)$totals['OnlineAmount'],'SalesAmount'=>$salesTotal,'AchievementRate'=>$monthTarget>0?$salesTotal/$monthTarget:null,'CostSalesRate'=>$salesTotal>0?($attendancePay+$tada)/$salesTotal:null,'SuperSmartSales'=>(float)$totals['SuperSmartSales'],'MegaCoilSales'=>(float)$totals['MegaCoilSales'],'DetergentSales'=>(float)$totals['DetergentSales'],'FoodSales'=>(float)$totals['FoodSales'],'SalesReportCount'=>(int)$totals['ReportCount'],'SalesReportMonth'=>$month]);$gross=round($attendancePay+$commission+$tada+$incentive+$additions,2);$net=round($gross-$deductions-$loanDeduction,2);if($net<0)throw new InvalidArgumentException('Payroll deductions exceed payable salary for '.$employee['EmployeeCode'].'.');
            $payrollFields=['WorkingDays'=>$calendarDays,'PaidDays'=>$paidDays,'MonthlySalary'=>$salary,'AttendancePay'=>$attendancePay,'CommissionBase'=>$salesTotal,'CommissionRate'=>$rate,'CommissionAmount'=>$commission,'TADA'=>$tada,'Incentive'=>$incentive,'Additions'=>$additions,'Deductions'=>$deductions,'LoanDeduction'=>$loanDeduction,'NetPay'=>$net,'Status'=>'REVIEW','PerformanceDetailsJSON'=>json_encode($performance,JSON_UNESCAPED_UNICODE),'AdjustmentDetailsJSON'=>$adjustments?json_encode($adjustments,JSON_UNESCAPED_UNICODE):null,'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()];if($payroll)$this->update('HRPayroll','PayrollID',$payrollId,$payrollFields);else $this->insert('HRPayroll',array_merge(['PayrollID'=>$payrollId,'EmployeeID'=>$employeeId,'DealerID'=>$employee['DealerID'],'PayrollMonth'=>$month,'DisbursedAt'=>null,'DisbursedBy'=>null,'DisbursementReference'=>null,'CreatedAt'=>$now,'CreatedBy'=>$this->current()],$payrollFields));
            $this->audit($existing?'UPDATE':'POST','HR Sales Reports',$reportId,'Daily employee sales report '.$reportDate.' posted.');$this->audit($payroll?'UPDATE':'GENERATE','HR Payroll',$payrollId,'Payroll recalculated from '.$totals['ReportCount'].' posted daily sales report(s); sales = Super Smart + Mega Coil + Detergent; Food and online amount are separate.');$this->db->commit();
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        $report=$this->rowsBySql('SELECT s.*,e.EmployeeCode,e.FullName,e.JobTitle,d.DealerName FROM HRSalesReports s JOIN Employees e ON e.EmployeeID=s.EmployeeID LEFT JOIN Dealers d ON d.DealerID=s.DealerID WHERE s.ReportID=?',[$reportId])[0]??[];return ['Report'=>$report,'MonthToDate'=>['PayrollMonth'=>$month,'TargetAmount'=>$monthTarget,'SalesAmount'=>$salesTotal,'OnlineAmount'=>(float)$totals['OnlineAmount'],'AchievementRate'=>$monthTarget>0?$salesTotal/$monthTarget:null,'CostSalesRate'=>$salesTotal>0?($attendancePay+$tada)/$salesTotal:null,'ReportCount'=>(int)$totals['ReportCount'],'NetPay'=>$net,'PayrollStatus'=>'REVIEW']];
    }
    private function generateHRPayroll(string $month): array
    {
        $this->hrActor(true);$month=$this->hrMonth($month);$from=$month.'-01';$to=date('Y-m-t',strtotime($from));$employees=$this->hrEmployeeRows($this->user());$calendarDays=(int)date('t',strtotime($from));$this->db->beginTransaction();
        try{
            foreach($employees as $employee){
                if(($employee['JoiningDate']??'')>$to||$employee['Status']!=='ACTIVE'||empty($employee['DealerID']))continue;$profile=$this->rowsBySql('SELECT * FROM HREmployeeProfiles WHERE EmployeeID=?',[$employee['EmployeeID']])[0]??[];if(!empty($profile['IgnoreForSalary']))continue;
                $attendance=$this->rowsBySql('SELECT AttendanceDate,AttendanceStatus,PaidLeave FROM HRAttendance WHERE EmployeeID=? AND AttendanceDate BETWEEN ? AND ?',[$employee['EmployeeID'],$from,$to]);$paidDates=[];
                foreach($attendance as $day){$date=(string)$day['AttendanceDate'];if(in_array($day['AttendanceStatus'],['PRESENT','HOLIDAY'],true))$paidDates[$date]=1;elseif($day['AttendanceStatus']==='HALF_DAY')$paidDates[$date]=.5;elseif($day['AttendanceStatus']==='LEAVE'&&(int)$day['PaidLeave']===1)$paidDates[$date]=1;}
                $leaveRows=$this->rowsBySql('SELECT r.StartDate,r.EndDate FROM HRLeaveRequests r JOIN HRLeaveTypes t ON t.LeaveTypeID=r.LeaveTypeID WHERE r.EmployeeID=? AND r.Status="APPROVED" AND t.IsPaid=1 AND r.StartDate<=? AND r.EndDate>=?',[$employee['EmployeeID'],$to,$from]);
                foreach($leaveRows as $leave){$cursor=max($from,(string)$leave['StartDate']);$last=min($to,(string)$leave['EndDate']);while($cursor<=$last){if(!isset($paidDates[$cursor]))$paidDates[$cursor]=1;$cursor=date('Y-m-d',strtotime($cursor.' +1 day'));}}
                $paidDays=min((float)$calendarDays,array_sum($paidDates));$salary=(float)($employee['MonthlySalary']??0);$attendancePay=round($salary*$paidDays/$calendarDays,2);
                $existing=$this->value('SELECT PayrollID FROM HRPayroll WHERE EmployeeID=? AND PayrollMonth=?',[$employee['EmployeeID'],$month]);$p=$existing?$this->one('HRPayroll','PayrollID',(string)$existing):null;
                if($p&&in_array(strtoupper((string)$p['Status']),['PAID','APPROVED'],true))continue;
                $id=$p?(string)$existing:$this->id('HRPayroll','PayrollID','PAY');if($p)$this->db->prepare('DELETE FROM HRLoanRepayments WHERE PayrollID=? AND Status="PENDING"')->execute([$id]);
                $source=$this->rowsBySql('SELECT * FROM HRSourceRoster WHERE EmployeeID=? AND SourcePeriod=?',[$employee['EmployeeID'],$month])[0]??[];
                $performance=$p?(json_decode((string)($p['PerformanceDetailsJSON']??''),true)?:[]):[];
                if(!$p&&$source){$performance=['TargetAmount'=>$source['TargetAmount'],'OnlineAmount'=>$source['OnlineAmount'],'SalesAmount'=>$source['SalesAmount'],'AchievementRate'=>$source['AchievementRate'],'CostSalesRate'=>$source['CostSalesRate'],'SourceSalaryTADA'=>$source['SourceSalaryTADA'],'SourceArea'=>$source['SourceArea'],'SourceRowNo'=>$source['SourceRowNo'],'SourceSRName'=>$source['SourceSRName'],'SourcePayeeName'=>$source['SourcePayeeName'],'SourceDealerName'=>$source['SourceDealerName'],'SourceJoiningDate'=>$source['SourceJoiningDate'],'SuperSmartSales'=>$source['SuperSmartSales'],'MegaCoilSales'=>$source['MegaCoilSales'],'DetergentSales'=>$source['DetergentSales'],'FoodSales'=>$source['FoodSales'],'SourceNote'=>$source['SourceNote'],'SourceFile'=>$source['SourceFile'],'SourcePeriod'=>$source['SourcePeriod']];}if(!isset($performance['TargetAmount'])&&!empty($profile['SalesTarget']))$performance['TargetAmount']=(float)$profile['SalesTarget'];
                $loanDeduction=0.0;$loanRows=$this->rowsBySql('SELECT * FROM HRLoans WHERE EmployeeID=? AND DealerID=? AND Status="DISBURSED" ORDER BY DisbursedAt,CreatedAt',[$employee['EmployeeID'],$employee['DealerID']]);
                foreach($loanRows as $loan){$paid=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM HRLoanRepayments WHERE LoanID=? AND Status="PAID"',[$loan['LoanID']]);$pending=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM HRLoanRepayments WHERE LoanID=? AND Status="PENDING"',[$loan['LoanID']]);$available=max(0,(float)$loan['ApprovedAmount']-$paid-$pending);$amount=min($available,(float)$loan['MonthlyInstallment']);if($amount>0){$repaymentId=$this->id('HRLoanRepayments','RepaymentID','LRP');$this->insert('HRLoanRepayments',['RepaymentID'=>$repaymentId,'LoanID'=>$loan['LoanID'],'PayrollID'=>$id,'EmployeeID'=>$employee['EmployeeID'],'DealerID'=>$employee['DealerID'],'PayrollMonth'=>$month,'Amount'=>$amount,'Status'=>'PENDING','PaidAt'=>null,'CreatedAt'=>$this->now()]);$loanDeduction+=$amount;}}
                $base=$p?(float)$p['CommissionBase']:(float)($source['SalesAmount']??$profile['CommissionBase']??0);$rate=$p?(float)$p['CommissionRate']:(float)($profile['CommissionRate']??0)/100;$commission=round($base*$rate,2);$tada=$p?(float)$p['TADA']:(float)($profile['TAAllowance']??0)+(float)($profile['DAAllowance']??0);$incentive=$p?(float)$p['Incentive']:(float)($source['SourceIncentive']??$profile['BonusAmount']??0);$adjustments=$p?(json_decode((string)($p['AdjustmentDetailsJSON']??''),true)?:[]):[];
                if($p&&!$adjustments){if((float)$p['Additions']>0)$adjustments[]=['Type'=>'EARNING','Category'=>'Other addition','Description'=>'Existing monthly adjustment','Amount'=>(float)$p['Additions']];if((float)$p['Deductions']>0)$adjustments[]=['Type'=>'DEDUCTION','Category'=>'Other deduction','Description'=>'Existing monthly adjustment','Amount'=>(float)$p['Deductions']];}
                $additions=0.0;$deductions=0.0;foreach($adjustments as $line){if(strtoupper((string)($line['Type']??''))==='DEDUCTION')$deductions+=(float)($line['Amount']??0);else$additions+=(float)($line['Amount']??0);}
                $gross=round($attendancePay+$commission+$tada+$incentive+$additions,2);$net=round($gross-$deductions-$loanDeduction,2);if($net<0)throw new InvalidArgumentException('Payroll deductions exceed payable salary for '.$employee['EmployeeCode'].'.');
                $fields=['WorkingDays'=>$calendarDays,'PaidDays'=>$paidDays,'MonthlySalary'=>$salary,'AttendancePay'=>$attendancePay,'CommissionBase'=>$base,'CommissionRate'=>$rate,'CommissionAmount'=>$commission,'TADA'=>$tada,'Incentive'=>$incentive,'Additions'=>$additions,'Deductions'=>$deductions,'LoanDeduction'=>$loanDeduction,'NetPay'=>$net,'Status'=>'REVIEW','PerformanceDetailsJSON'=>$performance?json_encode($performance,JSON_UNESCAPED_UNICODE):null,'AdjustmentDetailsJSON'=>$adjustments?json_encode($adjustments,JSON_UNESCAPED_UNICODE):null,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()];
                if($p)$this->update('HRPayroll','PayrollID',$id,$fields);else $this->insert('HRPayroll',array_merge(['PayrollID'=>$id,'EmployeeID'=>$employee['EmployeeID'],'DealerID'=>$employee['DealerID'],'PayrollMonth'=>$month,'Status'=>'REVIEW','DisbursedAt'=>null,'DisbursedBy'=>null,'DisbursementReference'=>null,'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()],$fields));
            }
            $this->audit('GENERATE','HR Payroll',$month,'Monthly payroll generated for review across all registered dealer assignments.');$this->db->commit();
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        return $this->getHRPayroll($month);
    }
    private function updateHRPayroll(string $id,array $input): array
    {
        $this->hrActor(true);$p=$this->one('HRPayroll','PayrollID',$id);if(!$p)throw new RuntimeException('Payroll record was not found.');if($p['Status']==='PAID')throw new RuntimeException('Paid payroll is locked.');
        $performance=json_decode((string)($p['PerformanceDetailsJSON']??''),true)?:[];$submitted=(array)($input['Performance']??[]);
        foreach(['TargetAmount','OnlineAmount','SuperSmartSales','MegaCoilSales','DetergentSales','FoodSales'] as $key){if(array_key_exists($key,$submitted))$performance[$key]=$this->num($submitted[$key]??0,$key,true);}
        if(array_key_exists('SourceNote',$submitted))$performance['SourceNote']=substr($this->text($submitted['SourceNote']),0,1000);
        if(array_intersect(['SuperSmartSales','MegaCoilSales','DetergentSales'],array_keys($submitted))){$performance['SalesAmount']=(float)($performance['SuperSmartSales']??0)+(float)($performance['MegaCoilSales']??0)+(float)($performance['DetergentSales']??0);}
        $target=$this->num($performance['TargetAmount']??'','Monthly target');if($target<=0)throw new InvalidArgumentException('Monthly target is required and must be greater than zero.');$performance['TargetAmount']=$target;
        if(array_key_exists('AchievementRate',$submitted))$performance['AchievementRate']=$this->num($submitted['AchievementRate'],'Achievement percent',true)/100;elseif(!array_key_exists('AchievementRate',$performance))$performance['AchievementRate']=(float)($performance['SalesAmount']??0)/$target;
        $salary=$this->num($input['MonthlySalary']??$p['MonthlySalary'],'Monthly salary');if($salary<=0)throw new InvalidArgumentException('Monthly salary is required and must be greater than zero.');$attendancePay=round($salary*(float)$p['PaidDays']/max(1,(int)$p['WorkingDays']),2);
        $base=$this->num($input['CommissionBase']??(array_key_exists('SalesAmount',$performance)?$performance['SalesAmount']:$p['CommissionBase']),'Commission base',true);$rate=$this->num($input['CommissionRate']??((float)$p['CommissionRate']*100),'Commission rate percent',true);if($rate>100)throw new InvalidArgumentException('Commission rate cannot exceed 100%.');$rate=$rate/100;$commission=round($base*$rate,2);$tada=$this->num($input['TADA']??$p['TADA'],'TA/DA',true);$incentive=$this->num($input['Incentive']??$p['Incentive'],'Incentive / bonus',true);
        $adjustments=(array)($input['AdjustmentDetails']??(json_decode((string)($p['AdjustmentDetailsJSON']??''),true)?:[]));$clean=[];$add=0.0;$ded=0.0;
        foreach($adjustments as $line){if(!is_array($line))continue;$type=strtoupper($this->text($line['Type']??''));if(!in_array($type,['EARNING','DEDUCTION'],true))throw new InvalidArgumentException('Choose an earning or deduction adjustment.');$amount=$this->num($line['Amount']??0,'Adjustment amount',true);if($amount<=0)continue;$category=substr($this->text($line['Category']??''),0,80);if($category==='')throw new InvalidArgumentException('Each adjustment needs a category.');$clean[]=['Type'=>$type,'Category'=>$category,'Description'=>substr($this->text($line['Description']??''),0,300),'Reference'=>substr($this->text($line['Reference']??''),0,120),'Amount'=>$amount];if($type==='EARNING')$add+=$amount;else$ded+=$amount;}
        $gross=round($attendancePay+$commission+$tada+$incentive+$add,2);$loan=(float)($p['LoanDeduction']??0);if($ded+$loan>$gross)throw new InvalidArgumentException('Deductions cannot exceed gross payable.');
        $this->update('HRPayroll','PayrollID',$id,['MonthlySalary'=>$salary,'AttendancePay'=>$attendancePay,'CommissionBase'=>$base,'CommissionRate'=>$rate,'CommissionAmount'=>$commission,'TADA'=>$tada,'Incentive'=>$incentive,'Additions'=>$add,'Deductions'=>$ded,'NetPay'=>round($gross-$ded-$loan,2),'PerformanceDetailsJSON'=>$performance?json_encode($performance,JSON_UNESCAPED_UNICODE):null,'AdjustmentDetailsJSON'=>$clean?json_encode($clean,JSON_UNESCAPED_UNICODE):null,'Status'=>'REVIEW','UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('UPDATE','HR Payroll',$id,'Monthly performance, compensation or itemized adjustment updated; payroll returned to review.');return $this->getHRPayroll((string)$p['PayrollMonth']);
    }
    private function updateHRPayrollBatch(string $month,array $entries): array
    {
        $this->hrActor(true);$month=$this->hrMonth($month);if(!$entries)throw new InvalidArgumentException('Enter monthly salary figures for the employees first.');$seen=[];$this->db->beginTransaction();
        try{foreach($entries as $entry){if(!is_array($entry))continue;$id=$this->text($entry['PayrollID']??'');if(!$id||isset($seen[$id]))throw new InvalidArgumentException('Each payroll row must be selected once.');$seen[$id]=true;$record=$this->one('HRPayroll','PayrollID',$id);if(!$record||(string)$record['PayrollMonth']!==$month)throw new InvalidArgumentException('A payroll row does not belong to the selected month.');$this->updateHRPayroll($id,$entry);}$this->audit('UPDATE','HR Payroll',$month,count($seen).' employee salary rows updated together.');$this->db->commit();}catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}return $this->getHRPayroll($month);
    }
    private function approveHRPayroll(string $month): array
    {
        $this->hrActor(true);$month=$this->hrMonth($month);$rows=$this->rowsBySql('SELECT PayrollID,EmployeeID,MonthlySalary,PerformanceDetailsJSON FROM HRPayroll WHERE PayrollMonth=? AND Status="REVIEW"',[$month]);if(!$rows)throw new RuntimeException('There are no payroll rows waiting for approval.');foreach($rows as $row){$details=json_decode((string)($row['PerformanceDetailsJSON']??''),true)?:[];if((float)$row['MonthlySalary']<=0||(float)($details['TargetAmount']??0)<=0||!array_key_exists('AchievementRate',$details)||$details['AchievementRate']===null)throw new RuntimeException('Enter salary, target and achievement for every employee before approving the month. Employee ID: '.$row['EmployeeID']);}$now=$this->now();$this->db->beginTransaction();try{foreach($rows as$row)$this->update('HRPayroll','PayrollID',(string)$row['PayrollID'],['Status'=>'APPROVED','UpdatedAt'=>$now,'UpdatedBy'=>$this->current()]);$this->audit('APPROVE','HR Payroll',$month,count($rows).' employee salary records approved for payment.');$this->db->commit();}catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}return $this->getHRPayroll($month);
    }
    private function recordHRDisbursement(string $id,array $input): array
    {
        $actor=$this->hrFinanceActor(['DEALER']);$p=$this->one('HRPayroll','PayrollID',$id);if(!$p)throw new RuntimeException('Payroll record was not found.');$this->hrAssertRecordScope($actor,(string)$p['DealerID']);if($p['Status']==='PAID')throw new RuntimeException('Payroll is already marked paid.');if($p['Status']!=='APPROVED')throw new RuntimeException('Central HR must approve the monthly payroll before a dealer records payment.');
        $reference=$this->text($input['Reference']??'');$amount=$this->num($input['Amount']??'','Disbursed amount');$method=strtoupper($this->text($input['Method']??''));if(!$reference||!in_array($method,['BANK','CASH','MOBILE BANKING','OTHER'],true))throw new InvalidArgumentException('Payment method and reference are required.');if(abs($amount-(float)$p['NetPay'])>0.009)throw new InvalidArgumentException('Disbursed amount must match the approved net salary.');
        $this->db->beginTransaction();try{$this->update('HRPayroll','PayrollID',$id,['Status'=>'PAID','DisbursedAmount'=>$amount,'DisbursementMethod'=>$method,'DisbursedAt'=>$this->now(),'DisbursedBy'=>$this->current(),'DisbursementReference'=>$reference,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->db->prepare('UPDATE HRLoanRepayments SET Status="PAID",PaidAt=? WHERE PayrollID=? AND Status="PENDING"')->execute([$this->now(),$id]);$this->audit('DISBURSE','HR Payroll',$id,'Central payroll disbursement recorded; ref '.$reference.'.');$this->db->commit();}catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}return $this->getHRPayroll((string)$p['PayrollMonth']);
    }
    private function hrFinanceActor(array $roles=['ADMIN','DEALER']): array
    {
        $actor=$this->user();$role=$this->normalizedRole((string)$actor['Role']);if(!in_array($role,$roles,true))throw new RuntimeException('This HR action is not available for your account.');if($role==='DEALER'&&!$this->dealerUserId($actor))throw new RuntimeException('Dealer account is not linked to a registered dealer.');return $actor;
    }
    private function hrAssertRecordScope(array $actor,string $dealerId): void
    {
        $role=$this->normalizedRole((string)$actor['Role']);if($role==='ADMIN')return;if($role!=='DEALER'||$dealerId!==$this->dealerUserId($actor))throw new RuntimeException('This HR record is outside your dealer scope.');
    }
    private function hrDateValue(string $value,string $label): string
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException($label.' must be a valid date.');return $value;
    }
    private function getHRLeaveData(array $filter=[]): array
    {
        $actor=$this->user();$role=$this->normalizedRole((string)$actor['Role']);if(!in_array($role,['ADMIN','DEALER','EMPLOYEE'],true))throw new RuntimeException('Leave access is not available.');$params=[];$where=[];if($role==='DEALER'){$where[]='t.DealerID=?';$params[]=$this->dealerUserId($actor);}elseif($role==='EMPLOYEE'){$where[]='t.EmployeeID=?';$params[]=(string)$actor['EmployeeID'];}elseif(!empty($filter['DealerID'])){$where[]='t.DealerID=?';$params[]=$this->text($filter['DealerID']);}$sql='SELECT t.*,e.FullName,e.EmployeeCode,e.JobTitle,lt.TypeName,lt.IsPaid FROM HRLeaveRequests t JOIN Employees e ON e.EmployeeID=t.EmployeeID JOIN HRLeaveTypes lt ON lt.LeaveTypeID=t.LeaveTypeID'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY t.CreatedAt DESC';$requests=$this->rowsBySql($sql,$params);$typeWhere=[];$typeParams=[];if($role==='DEALER'){$typeWhere[]='DealerID=?';$typeParams[]=$this->dealerUserId($actor);}elseif($role==='EMPLOYEE'){$employee=$this->hrEmployee((string)$actor['EmployeeID']);$typeWhere[]='DealerID=?';$typeParams[]=(string)($employee['DealerID']??'');}elseif(!empty($filter['DealerID'])){$typeWhere[]='DealerID=?';$typeParams[]=$this->text($filter['DealerID']);}$types=$this->rowsBySql('SELECT * FROM HRLeaveTypes'.($typeWhere?' WHERE '.implode(' AND ',$typeWhere):'').' ORDER BY TypeName',$typeParams);$balances=[];$year=(int)($filter['Year']??date('Y'));foreach($types as $type){$balanceParams=[$type['LeaveTypeID'],$year];$balanceSql='SELECT EmployeeID,COALESCE(SUM(Days),0) UsedDays FROM HRLeaveRequests WHERE LeaveTypeID=? AND Status="APPROVED" AND YEAR(StartDate)=?';if($role==='DEALER'){$balanceSql.=' AND DealerID=?';$balanceParams[]=$this->dealerUserId($actor);}elseif($role==='EMPLOYEE'){$balanceSql.=' AND EmployeeID=?';$balanceParams[]=(string)$actor['EmployeeID'];}$balanceSql.=' GROUP BY EmployeeID';foreach($this->rowsBySql($balanceSql,$balanceParams) as $used){$balances[]=['EmployeeID'=>$used['EmployeeID'],'LeaveTypeID'=>$type['LeaveTypeID'],'TypeName'=>$type['TypeName'],'Year'=>$year,'Entitlement'=>(float)$type['AnnualEntitlement'],'Used'=>(float)$used['UsedDays'],'Available'=>max(0,(float)$type['AnnualEntitlement']-(float)$used['UsedDays'])];}}return ['Types'=>$types,'Requests'=>$requests,'Balances'=>$balances,'Year'=>$year];
    }
    private function createHRLeaveType(array $input): array
    {
        $this->hrActor(true);$dealerId=$this->text($input['DealerID']??'');$name=$this->text($input['TypeName']??'');$entitlement=$this->num($input['AnnualEntitlement']??0,'Annual entitlement',true);if(!$this->one('Dealers','DealerID',$dealerId)||!$name)throw new InvalidArgumentException('Select a dealer and enter a leave type.');$id=$this->id('HRLeaveTypes','LeaveTypeID','LVT');$this->insert('HRLeaveTypes',['LeaveTypeID'=>$id,'DealerID'=>$dealerId,'TypeName'=>$name,'IsPaid'=>!empty($input['IsPaid'])?1:0,'AnnualEntitlement'=>$entitlement,'Status'=>'ACTIVE','CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()]);$this->audit('CREATE','HR Leave Type',$id,'Dealer leave policy created.');return $this->getHRLeaveData(['DealerID'=>$dealerId]);
    }
    private function createHRLeaveRequest(array $input): array
    {
        $actor=$this->hrFinanceActor(['EMPLOYEE']);$employee=$this->hrEmployee((string)$actor['EmployeeID']);if(!$employee||$employee['Status']!=='ACTIVE')throw new RuntimeException('Active employee profile was not found.');$type=$this->one('HRLeaveTypes','LeaveTypeID',$this->text($input['LeaveTypeID']??''));if(!$type||$type['DealerID']!==$employee['DealerID']||$type['Status']!=='ACTIVE')throw new InvalidArgumentException('Select an active leave type for your dealer.');$start=$this->hrDateValue($this->text($input['StartDate']??''),'Start date');$end=$this->hrDateValue($this->text($input['EndDate']??''),'End date');if($end<$start)throw new InvalidArgumentException('End date cannot be before start date.');$days=(float)(($this->text($input['Days']??''))?:((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days+1));if($days<=0)throw new InvalidArgumentException('Leave days must be greater than zero.');$overlap=(int)$this->value('SELECT COUNT(*) FROM HRLeaveRequests WHERE EmployeeID=? AND Status IN ("PENDING","APPROVED") AND StartDate<=? AND EndDate>=?',[$employee['EmployeeID'],$end,$start]);if($overlap)throw new InvalidArgumentException('This date range overlaps another pending or approved leave request.');$id=$this->id('HRLeaveRequests','LeaveRequestID','LVR');$this->insert('HRLeaveRequests',['LeaveRequestID'=>$id,'EmployeeID'=>$employee['EmployeeID'],'DealerID'=>$employee['DealerID'],'LeaveTypeID'=>$type['LeaveTypeID'],'StartDate'=>$start,'EndDate'=>$end,'Days'=>$days,'Reason'=>$this->text($input['Reason']??''),'Status'=>'PENDING','DecisionNotes'=>null,'DecidedAt'=>null,'DecidedBy'=>null,'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()]);$this->audit('CREATE','HR Leave Request',$id,'Employee leave requested.');return $this->getHRLeaveData();
    }
    private function decideHRLeaveRequest(string $id,array $input): array
    {
        $actor=$this->hrFinanceActor();$request=$this->one('HRLeaveRequests','LeaveRequestID',$id);if(!$request)throw new RuntimeException('Leave request was not found.');$this->hrAssertRecordScope($actor,(string)$request['DealerID']);if($request['Status']!=='PENDING')throw new RuntimeException('Only a pending leave request can be decided.');$decision=strtoupper($this->text($input['Status']??''));if(!in_array($decision,['APPROVED','REJECTED'],true))throw new InvalidArgumentException('Choose approve or reject.');$type=$this->one('HRLeaveTypes','LeaveTypeID',(string)$request['LeaveTypeID']);if($decision==='APPROVED'&&$type&&(int)$type['IsPaid']===1){$used=(float)$this->value('SELECT COALESCE(SUM(Days),0) FROM HRLeaveRequests WHERE EmployeeID=? AND LeaveTypeID=? AND Status="APPROVED" AND YEAR(StartDate)=YEAR(?)',[$request['EmployeeID'],$request['LeaveTypeID'],$request['StartDate']]);if($used+(float)$request['Days']>(float)$type['AnnualEntitlement'])throw new InvalidArgumentException('This approval exceeds the employee leave balance.');}$this->update('HRLeaveRequests','LeaveRequestID',$id,['Status'=>$decision,'DecisionNotes'=>$this->text($input['Notes']??''),'DecidedAt'=>$this->now(),'DecidedBy'=>$this->current()]);$this->audit($decision,'HR Leave Request',$id,'Leave request '.$decision.'.');return $this->getHRLeaveData(['DealerID'=>$request['DealerID']]);
    }
    private function getHRLoans(array $filter=[]): array
    {
        $actor=$this->hrFinanceActor(['ADMIN','DEALER','EMPLOYEE']);$role=$this->normalizedRole((string)$actor['Role']);$where=[];$params=[];if($role==='DEALER'){$where[]='l.DealerID=?';$params[]=$this->dealerUserId($actor);}elseif($role==='EMPLOYEE'){$where[]='l.EmployeeID=?';$params[]=(string)$actor['EmployeeID'];}elseif(!empty($filter['DealerID'])){$where[]='l.DealerID=?';$params[]=$this->text($filter['DealerID']);}$sql='SELECT l.*,e.FullName,e.EmployeeCode,e.JobTitle,COALESCE(l.ApprovedAmount,0)-COALESCE((SELECT SUM(r.Amount) FROM HRLoanRepayments r WHERE r.LoanID=l.LoanID AND r.Status="PAID"),0) AS CurrentBalance FROM HRLoans l JOIN Employees e ON e.EmployeeID=l.EmployeeID'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY l.CreatedAt DESC';return $this->rowsBySql($sql,$params);
    }
    private function createHRLoanRequest(array $input): array
    {
        $actor=$this->hrFinanceActor(['EMPLOYEE']);$employee=$this->hrEmployee((string)$actor['EmployeeID']);if(!$employee||$employee['Status']!=='ACTIVE')throw new RuntimeException('Active employee profile was not found.');$amount=$this->num($input['RequestedAmount']??'','Requested amount');$installment=$this->num($input['MonthlyInstallment']??'','Monthly installment');if($installment>$amount)throw new InvalidArgumentException('Monthly installment cannot exceed the requested amount.');$id=$this->id('HRLoans','LoanID','HRL');$this->insert('HRLoans',['LoanID'=>$id,'EmployeeID'=>$employee['EmployeeID'],'DealerID'=>$employee['DealerID'],'LoanType'=>in_array(strtoupper($this->text($input['LoanType']??'')),['ADVANCE','LOAN'],true)?strtoupper($this->text($input['LoanType'])):'LOAN','RequestedAmount'=>$amount,'ApprovedAmount'=>null,'MonthlyInstallment'=>null,'OutstandingAmount'=>null,'Reason'=>$this->text($input['Reason']??''),'Status'=>'PENDING','DisbursementMethod'=>null,'DisbursementReference'=>null,'DisbursedAt'=>null,'DisbursedBy'=>null,'DecisionNotes'=>null,'DecidedAt'=>null,'DecidedBy'=>null,'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current(),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('CREATE','HR Employee Loan',$id,'Employee requested a dealer-funded loan or advance.');return $this->getHRLoans();
    }
    private function decideHRLoan(string $id,array $input): array
    {
        $actor=$this->hrFinanceActor();$loan=$this->one('HRLoans','LoanID',$id);if(!$loan)throw new RuntimeException('Loan request was not found.');$this->hrAssertRecordScope($actor,(string)$loan['DealerID']);if($loan['Status']!=='PENDING')throw new RuntimeException('Only pending loan requests can be decided.');$decision=strtoupper($this->text($input['Status']??''));if(!in_array($decision,['APPROVED','REJECTED'],true))throw new InvalidArgumentException('Choose approve or reject.');$approved=null;$installment=null;if($decision==='APPROVED'){$approved=$this->num($input['ApprovedAmount']??$loan['RequestedAmount'],'Approved amount');$installment=$this->num($input['MonthlyInstallment']??'','Monthly installment');if($approved>(float)$loan['RequestedAmount']||$installment>$approved)throw new InvalidArgumentException('Approved amount or installment is outside the requested amount.');}$this->update('HRLoans','LoanID',$id,['Status'=>$decision,'ApprovedAmount'=>$approved,'MonthlyInstallment'=>$installment,'DecisionNotes'=>$this->text($input['Notes']??''),'DecidedAt'=>$this->now(),'DecidedBy'=>$this->current(),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit($decision,'HR Employee Loan',$id,'Dealer loan decision '.$decision.'.');return $this->getHRLoans(['DealerID'=>$loan['DealerID']]);
    }
    private function recordHRLoanDisbursement(string $id,array $input): array
    {
        $actor=$this->hrFinanceActor(['DEALER']);$loan=$this->one('HRLoans','LoanID',$id);if(!$loan||$loan['DealerID']!==$this->dealerUserId($actor))throw new RuntimeException('Loan is outside your dealer scope.');if($loan['Status']!=='APPROVED')throw new RuntimeException('Only an approved loan can be disbursed.');$amount=$this->num($input['Amount']??'','Disbursed amount');$method=strtoupper($this->text($input['Method']??''));$reference=$this->text($input['Reference']??'');if(abs($amount-(float)$loan['ApprovedAmount'])>.009||!in_array($method,['BANK','CASH','MOBILE BANKING','OTHER'],true)||!$reference)throw new InvalidArgumentException('Enter the full approved amount, a supported payment method and a reference.');$this->update('HRLoans','LoanID',$id,['Status'=>'DISBURSED','OutstandingAmount'=>$amount,'DisbursementMethod'=>$method,'DisbursementReference'=>$reference,'DisbursedAt'=>$this->now(),'DisbursedBy'=>$this->current(),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);$this->audit('DISBURSE','HR Employee Loan',$id,'Dealer recorded loan disbursement, ref '.$reference.'.');return $this->getHRLoans(['DealerID'=>$loan['DealerID']]);
    }
    private function getHRClaims(array $filter=[]): array
    {
        $actor=$this->hrFinanceActor(['ADMIN','DEALER','EMPLOYEE']);$role=$this->normalizedRole((string)$actor['Role']);$where=[];$params=[];if($role==='DEALER'){$where[]='c.DealerID=?';$params[]=$this->dealerUserId($actor);}elseif($role==='EMPLOYEE'){$where[]='c.EmployeeID=?';$params[]=(string)$actor['EmployeeID'];}elseif(!empty($filter['DealerID'])){$where[]='c.DealerID=?';$params[]=$this->text($filter['DealerID']);}$sql='SELECT c.*,e.FullName,e.EmployeeCode,e.JobTitle FROM HRExpenseClaims c JOIN Employees e ON e.EmployeeID=c.EmployeeID'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY c.ExpenseDate DESC,c.CreatedAt DESC';return $this->rowsBySql($sql,$params);
    }
    private function createHRClaim(array $input): array
    {
        $actor=$this->hrFinanceActor(['EMPLOYEE']);$employee=$this->hrEmployee((string)$actor['EmployeeID']);$category=$this->text($input['Category']??'');if(!$employee||!$category)throw new InvalidArgumentException('Enter a claim category.');$amount=$this->num($input['Amount']??'','Claim amount');$date=$this->hrDateValue($this->text($input['ExpenseDate']??''),'Expense date');$id=$this->id('HRExpenseClaims','ClaimID','CLM');$this->insert('HRExpenseClaims',['ClaimID'=>$id,'EmployeeID'=>$employee['EmployeeID'],'DealerID'=>$employee['DealerID'],'ExpenseDate'=>$date,'Category'=>$category,'Amount'=>$amount,'ApprovedAmount'=>null,'Description'=>$this->text($input['Description']??''),'ReceiptReference'=>$this->text($input['ReceiptReference']??''),'Status'=>'PENDING','PaymentMethod'=>null,'PaymentReference'=>null,'PaidAt'=>null,'PaidBy'=>null,'DecisionNotes'=>null,'DecidedAt'=>null,'DecidedBy'=>null,'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()]);$this->audit('CREATE','HR Expense Claim',$id,'Employee submitted a dealer-scoped reimbursement claim.');return $this->getHRClaims();
    }
    private function decideHRClaim(string $id,array $input): array
    {
        $actor=$this->hrFinanceActor();$claim=$this->one('HRExpenseClaims','ClaimID',$id);if(!$claim)throw new RuntimeException('Expense claim was not found.');$this->hrAssertRecordScope($actor,(string)$claim['DealerID']);if($claim['Status']!=='PENDING')throw new RuntimeException('Only pending claims can be decided.');$decision=strtoupper($this->text($input['Status']??''));if(!in_array($decision,['APPROVED','REJECTED'],true))throw new InvalidArgumentException('Choose approve or reject.');$approved=$decision==='APPROVED'?$this->num($input['ApprovedAmount']??$claim['Amount'],'Approved amount'):null;if($approved!==null&&$approved>(float)$claim['Amount'])throw new InvalidArgumentException('Approved amount cannot exceed the submitted claim.');$this->update('HRExpenseClaims','ClaimID',$id,['Status'=>$decision,'ApprovedAmount'=>$approved,'DecisionNotes'=>$this->text($input['Notes']??''),'DecidedAt'=>$this->now(),'DecidedBy'=>$this->current()]);$this->audit($decision,'HR Expense Claim',$id,'Dealer claim decision '.$decision.'.');return $this->getHRClaims(['DealerID'=>$claim['DealerID']]);
    }
    private function recordHRClaimPayment(string $id,array $input): array
    {
        $actor=$this->hrFinanceActor(['DEALER']);$claim=$this->one('HRExpenseClaims','ClaimID',$id);if(!$claim||$claim['DealerID']!==$this->dealerUserId($actor))throw new RuntimeException('Claim is outside your dealer scope.');if($claim['Status']!=='APPROVED')throw new RuntimeException('Only an approved claim can be paid.');$method=strtoupper($this->text($input['Method']??''));$reference=$this->text($input['Reference']??'');if(!in_array($method,['BANK','CASH','MOBILE BANKING','OTHER'],true)||!$reference)throw new InvalidArgumentException('Payment method and reference are required.');$amount=(float)$claim['ApprovedAmount'];$this->db->beginTransaction();try{if($method==='CASH')$this->addHRPettyCashEntry((string)$claim['DealerID'],'EXPENSE',$amount,(string)$claim['Category'],(string)$claim['EmployeeID'],$reference,'Employee claim '.$id,$id);$this->update('HRExpenseClaims','ClaimID',$id,['Status'=>'PAID','PaymentMethod'=>$method,'PaymentReference'=>$reference,'PaidAt'=>$this->now(),'PaidBy'=>$this->current()]);$this->audit('PAY','HR Expense Claim',$id,'Dealer recorded claim payment, ref '.$reference.'.');$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}return $this->getHRClaims(['DealerID'=>$claim['DealerID']]);
    }
    private function getHRPettyCash(array $filter=[]): array
    {
        $actor=$this->hrFinanceActor();$role=$this->normalizedRole((string)$actor['Role']);$dealerId=$role==='DEALER'?$this->dealerUserId($actor):$this->text($filter['DealerID']??'');if(!$dealerId)throw new InvalidArgumentException('Select a dealer to view its separate petty cash ledger.');$sql='SELECT p.*,e.FullName,e.EmployeeCode FROM HRPettyCashEntries p LEFT JOIN Employees e ON e.EmployeeID=p.EmployeeID';$params=[];if($dealerId){$sql.=' WHERE p.DealerID=?';$params[]=$dealerId;}$rows=$this->rowsBySql($sql.' ORDER BY p.EntryDate DESC,p.CreatedAt DESC',$params);$balance=0.0;foreach(array_reverse($rows) as $row){$balance+=in_array($row['EntryType'],['FUND','RETURN'],true)?(float)$row['Amount']:-((float)$row['Amount']);}return ['Entries'=>$rows,'Balance'=>$balance,'DealerID'=>$dealerId];
    }
    private function addHRPettyCashEntry(string $dealerId,string $type,float $amount,string $category='',string $employeeId='',string $reference='',string $description='',string $claimId=''): array
    {
        if(!$this->one('Dealers','DealerID',$dealerId)||$amount<=0)throw new InvalidArgumentException('A valid dealer and positive petty cash amount are required.');$type=strtoupper($type);if(!in_array($type,['FUND','EXPENSE','RETURN'],true))throw new InvalidArgumentException('Invalid petty cash entry type.');if($type==='EXPENSE'){$balance=(float)$this->value('SELECT COALESCE(SUM(CASE WHEN EntryType IN ("FUND","RETURN") THEN Amount ELSE -Amount END),0) FROM HRPettyCashEntries WHERE DealerID=?',[$dealerId]);if($balance+0.009<$amount)throw new InvalidArgumentException('Petty cash balance is too low for this expense.');}$id=$this->id('HRPettyCashEntries','EntryID','PC');$this->insert('HRPettyCashEntries',['EntryID'=>$id,'DealerID'=>$dealerId,'EntryDate'=>$this->today(),'EntryType'=>$type,'Category'=>$category?:null,'EmployeeID'=>$employeeId?:null,'Amount'=>$amount,'ReferenceNo'=>$reference?:null,'Description'=>$description?:null,'SourceClaimID'=>$claimId?:null,'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()]);$this->audit('CREATE','HR Petty Cash',$id,$type.' entry recorded for '.$dealerId.'.');return $this->one('HRPettyCashEntries','EntryID',$id)??[];
    }
    private function createHRPettyCashEntry(array $input): array
    {
        $actor=$this->hrFinanceActor();$role=$this->normalizedRole((string)$actor['Role']);$dealerId=$role==='DEALER'?$this->dealerUserId($actor):$this->text($input['DealerID']??'');$this->hrAssertRecordScope($actor,$dealerId);$amount=$this->num($input['Amount']??'','Amount');$entry=$this->addHRPettyCashEntry($dealerId,strtoupper($this->text($input['EntryType']??'')),$amount,$this->text($input['Category']??''),$this->text($input['EmployeeID']??''),$this->text($input['ReferenceNo']??''),$this->text($input['Description']??''));return $this->getHRPettyCash(['DealerID'=>$dealerId]);
    }
    private function getHREmployeeProfile(string $requestedMonth=""): array
    {
        $actor=$this->hrActor();$employee=$this->hrEmployee((string)$actor['EmployeeID']);if(!$employee)throw new RuntimeException('Employee profile was not found.');$month=$this->hrMonth($requestedMonth?:date('Y-m'));$attendance=$this->rowsBySql('SELECT * FROM HRAttendance WHERE EmployeeID=? AND DATE_FORMAT(AttendanceDate,"%Y-%m")=? ORDER BY AttendanceDate DESC',[$employee['EmployeeID'],$month]);$achievements=$this->getHRAchievements(['Month'=>$month]);$payroll=$this->rowsBySql('SELECT p.*,e.EmployeeCode,e.FullName,e.JobTitle,e.JoiningDate,d.DealerName,pr.Designation,pr.PositionName,pr.BasicSalary,pr.LivingArea,pr.District FROM HRPayroll p JOIN Employees e ON e.EmployeeID=p.EmployeeID LEFT JOIN Dealers d ON d.DealerID=p.DealerID LEFT JOIN HREmployeeProfiles pr ON pr.EmployeeID=e.EmployeeID WHERE p.EmployeeID=? ORDER BY p.PayrollMonth DESC',[$employee['EmployeeID']]);foreach($payroll as &$row){$row['PerformanceDetails']=json_decode((string)($row['PerformanceDetailsJSON']??''),true)?:[];$row['AdjustmentDetails']=json_decode((string)($row['AdjustmentDetailsJSON']??''),true)?:[];$row['LoanItems']=$this->rowsBySql('SELECT r.RepaymentID,r.LoanID,r.Amount,r.Status,l.Reason AS Purpose,l.ApprovedAmount FROM HRLoanRepayments r LEFT JOIN HRLoans l ON l.LoanID=r.LoanID WHERE r.PayrollID=? ORDER BY r.CreatedAt,r.RepaymentID',[(string)$row['PayrollID']]);}unset($row);$dealer=$this->one('Dealers','DealerID',(string)$employee['DealerID']);$leave=$this->getHRLeaveData();$loans=$this->getHRLoans();$claims=$this->getHRClaims();return ['Employee'=>$employee,'DealerName'=>$dealer['DealerName']??'','Month'=>$month,'Attendance'=>$attendance,'Achievements'=>$achievements,'Payroll'=>$payroll,'Leave'=>$leave,'Loans'=>$loans,'Claims'=>$claims];
    }
}



