<?php
declare(strict_types=1);
require_once __DIR__ . '/AccountingRules.php';

trait Accounting
{
    private function ensureAccounting(): void
    {
        try { $this->db->query('SELECT 1 FROM ChartOfAccounts LIMIT 0'); }
        catch (PDOException $error) {
            if ($error->getCode() !== '42S02') throw $error;
            $sql = file_get_contents(__DIR__ . '/../database/accounting.sql');
            if ($sql === false) throw new RuntimeException('Accounting schema is missing.');
            $this->db->exec($sql);
        }
        if (!$this->value('SELECT COUNT(*) FROM ChartOfAccounts')) {
            $now = $this->now();
            foreach ($this->accountingTemplate() as $row) $this->insert('ChartOfAccounts', $row + ['CreatedAt'=>$now,'CreatedBy'=>'system','UpdatedAt'=>$now,'UpdatedBy'=>'system']);
        }
        if (!$this->value('SELECT COUNT(*) FROM AccountingSettings WHERE SettingKey="finance"')) {
            $this->saveAccountingSetting('finance', ['FinancialYearStartMonth'=>7,'Currency'=>'BDT','CostMethod'=>'WEIGHTED_AVERAGE','VatRegistered'=>false,'DefaultVatRate'=>0,'AccountingStartDate'=>'','IntegrationMode'=>'SETUP']);
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS DealerCashDeposits (DepositID VARCHAR(20) PRIMARY KEY, DealerID VARCHAR(20) NOT NULL, DepositDate DATE NOT NULL, AccountID VARCHAR(20) NOT NULL, BankDetails VARCHAR(180) NULL, Amount DECIMAL(16,2) NOT NULL, ReferenceNo VARCHAR(160) NULL, Notes VARCHAR(1000) NULL, VoucherID VARCHAR(20) NOT NULL UNIQUE, CreatedAt DATETIME NOT NULL, CreatedBy VARCHAR(120) NOT NULL, INDEX (DealerID, DepositDate), INDEX (AccountID)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function accountingTemplate(): array
    {
        return [
            ['AccountID'=>'1010','AccountName'=>'Cash in Hand','AccountType'=>'ASSET','AccountGroup'=>'Current Assets','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'CASH'],
            ['AccountID'=>'1020','AccountName'=>'Bank Account','AccountType'=>'ASSET','AccountGroup'=>'Current Assets','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'BANK'],
            ['AccountID'=>'1030','AccountName'=>'Mobile Financial Service','AccountType'=>'ASSET','AccountGroup'=>'Current Assets','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'MFS'],
            ['AccountID'=>'1100','AccountName'=>'Accounts Receivable — Dealers','AccountType'=>'ASSET','AccountGroup'=>'Current Assets','NormalBalance'=>'DEBIT','IsControl'=>1,'Status'=>'ACTIVE','SystemKey'=>'DEALER_RECEIVABLE'],
            ['AccountID'=>'1200','AccountName'=>'Inventory','AccountType'=>'ASSET','AccountGroup'=>'Current Assets','NormalBalance'=>'DEBIT','IsControl'=>1,'Status'=>'ACTIVE','SystemKey'=>'INVENTORY'],
            ['AccountID'=>'1210','AccountName'=>'Input VAT','AccountType'=>'ASSET','AccountGroup'=>'Current Assets','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'INPUT_VAT'],
            ['AccountID'=>'1300','AccountName'=>'Advance & Prepayment','AccountType'=>'ASSET','AccountGroup'=>'Current Assets','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'ADVANCE'],
            ['AccountID'=>'2010','AccountName'=>'Accounts Payable — Suppliers','AccountType'=>'LIABILITY','AccountGroup'=>'Current Liabilities','NormalBalance'=>'CREDIT','IsControl'=>1,'Status'=>'ACTIVE','SystemKey'=>'SUPPLIER_PAYABLE'],
            ['AccountID'=>'2020','AccountName'=>'Output VAT','AccountType'=>'LIABILITY','AccountGroup'=>'Current Liabilities','NormalBalance'=>'CREDIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'OUTPUT_VAT'],
            ['AccountID'=>'2030','AccountName'=>'TDS / AIT Payable','AccountType'=>'LIABILITY','AccountGroup'=>'Current Liabilities','NormalBalance'=>'CREDIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'TDS_PAYABLE'],
            ['AccountID'=>'2040','AccountName'=>'Accrued Expenses','AccountType'=>'LIABILITY','AccountGroup'=>'Current Liabilities','NormalBalance'=>'CREDIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'ACCRUED_EXPENSE'],
            ['AccountID'=>'2050','AccountName'=>'Dealer Incentive Payable','AccountType'=>'LIABILITY','AccountGroup'=>'Current Liabilities','NormalBalance'=>'CREDIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'INCENTIVE_PAYABLE'],
            ['AccountID'=>'3010','AccountName'=>'Owner Capital','AccountType'=>'EQUITY','AccountGroup'=>'Equity','NormalBalance'=>'CREDIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'CAPITAL'],
            ['AccountID'=>'3020','AccountName'=>'Retained Earnings','AccountType'=>'EQUITY','AccountGroup'=>'Equity','NormalBalance'=>'CREDIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'RETAINED_EARNINGS'],
            ['AccountID'=>'4010','AccountName'=>'Sales Revenue','AccountType'=>'INCOME','AccountGroup'=>'Operating Income','NormalBalance'=>'CREDIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'SALES'],
            ['AccountID'=>'4020','AccountName'=>'Other Income','AccountType'=>'INCOME','AccountGroup'=>'Other Income','NormalBalance'=>'CREDIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'OTHER_INCOME'],
            ['AccountID'=>'5010','AccountName'=>'Cost of Goods Sold','AccountType'=>'EXPENSE','AccountGroup'=>'Cost of Sales','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'COGS'],
            ['AccountID'=>'5020','AccountName'=>'Sales Return & Allowance','AccountType'=>'EXPENSE','AccountGroup'=>'Cost of Sales','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'SALES_RETURN'],
            ['AccountID'=>'6010','AccountName'=>'Salary & Wages Expense','AccountType'=>'EXPENSE','AccountGroup'=>'Operating Expenses','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'SALARY'],
            ['AccountID'=>'6020','AccountName'=>'Rent & Utility Expense','AccountType'=>'EXPENSE','AccountGroup'=>'Operating Expenses','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'RENT_UTILITY'],
            ['AccountID'=>'6030','AccountName'=>'Transport & Delivery Expense','AccountType'=>'EXPENSE','AccountGroup'=>'Operating Expenses','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'TRANSPORT'],
            ['AccountID'=>'6040','AccountName'=>'Marketing Expense','AccountType'=>'EXPENSE','AccountGroup'=>'Operating Expenses','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'MARKETING'],
            ['AccountID'=>'6050','AccountName'=>'Inventory Damage / Expiry Loss','AccountType'=>'EXPENSE','AccountGroup'=>'Operating Expenses','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'DAMAGE_LOSS'],
            ['AccountID'=>'6060','AccountName'=>'Dealer Incentive Expense','AccountType'=>'EXPENSE','AccountGroup'=>'Operating Expenses','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'INCENTIVE_EXPENSE'],
            ['AccountID'=>'6090','AccountName'=>'General & Administrative Expense','AccountType'=>'EXPENSE','AccountGroup'=>'Operating Expenses','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'GENERAL_EXPENSE'],
        ];
    }

    private function accountingSetting(string $key, mixed $default): mixed
    {
        $row = $this->one('AccountingSettings','SettingKey',$key);
        return $row ? json_decode((string)$row['SettingValue'], true, 512, JSON_THROW_ON_ERROR) : $default;
    }
    private function saveAccountingSetting(string $key, mixed $value): void
    {
        $row = ['SettingValue'=>json_encode($value, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()];
        if ($this->one('AccountingSettings','SettingKey',$key)) $this->update('AccountingSettings','SettingKey',$key,$row);
        else $this->insert('AccountingSettings',['SettingKey'=>$key]+$row);
    }
    private function financeSetup(): array { $this->user(); $this->ensureAccounting(); return $this->accountingSetting('finance',[]); }
    private function saveFinanceSetup(array $input): array
    {
        $this->user(true); $this->ensureAccounting();
        $month = (int)($input['FinancialYearStartMonth'] ?? 0);
        if ($month < 1 || $month > 12) throw new InvalidArgumentException('Select a valid financial-year start month.');
        $method = strtoupper($this->text($input['CostMethod'] ?? ''));
        if (!in_array($method,['WEIGHTED_AVERAGE','FIFO'],true)) throw new InvalidArgumentException('Select Weighted Average or FIFO costing.');
        $vat = AccountingRules::amount($input['DefaultVatRate'] ?? 0,'Default VAT rate');
        if ($vat > 100) throw new InvalidArgumentException('VAT rate cannot exceed 100%.');
        $date = $this->text($input['AccountingStartDate'] ?? '');
        if ($date !== '') $date = AccountingRules::date($date);
        $row=['FinancialYearStartMonth'=>$month,'Currency'=>'BDT','CostMethod'=>$method,'VatRegistered'=>(bool)($input['VatRegistered']??false),'DefaultVatRate'=>$vat,'AccountingStartDate'=>$date,'IntegrationMode'=>'SETUP'];
        $this->saveAccountingSetting('finance',$row); $this->audit('UPDATE','AccountingSettings','finance','Accounting setup changed.'); return $row;
    }
    private function chartAccounts(): array { $this->user();$this->ensureAccounting();return $this->rows('ChartOfAccounts','1=1 ORDER BY AccountID'); }
    private function saveChartAccount(array $input): array
    {
        $this->user(true);$this->ensureAccounting();$id=AccountingRules::accountCode($input['AccountID']??'');$old=$this->one('ChartOfAccounts','AccountID',$id);
        $name=$this->text($input['AccountName']??'');$type=strtoupper($this->text($input['AccountType']??''));$normal=strtoupper($this->text($input['NormalBalance']??''));$status=strtoupper($this->text($input['Status']??'ACTIVE'));
        if(!$name||strlen($name)>180||!in_array($type,['ASSET','LIABILITY','EQUITY','INCOME','EXPENSE'],true)||!in_array($normal,['DEBIT','CREDIT'],true)||!in_array($status,['ACTIVE','ARCHIVED'],true))throw new InvalidArgumentException('Enter a valid chart-of-accounts record.');
        if($old&&$old['SystemKey']){ $type=$old['AccountType'];$normal=$old['NormalBalance']; }
        if($status==='ARCHIVED'&&$this->value('SELECT COUNT(*) FROM AccountingVoucherLines WHERE AccountID=?',[$id]))throw new RuntimeException('An account with posted or draft journal lines cannot be archived.');
        $row=['AccountName'=>$name,'AccountType'=>$type,'AccountGroup'=>substr($this->text($input['AccountGroup']??''),0,80),'NormalBalance'=>$normal,'IsControl'=>(int)(bool)($input['IsControl']??false),'Status'=>$status,'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()];
        if($old)$this->update('ChartOfAccounts','AccountID',$id,$row);else $this->insert('ChartOfAccounts',['AccountID'=>$id,'SystemKey'=>null,'CreatedAt'=>$this->now(),'CreatedBy'=>$this->current()]+$row);
        $this->audit($old?'UPDATE':'CREATE','ChartOfAccounts',$id,'Chart of account saved.');return $this->one('ChartOfAccounts','AccountID',$id);
    }
    private function accountingOpenPeriod(string $date): void
    {
        $closed=$this->value('SELECT COUNT(*) FROM AccountingPeriods WHERE Status="CLOSED" AND ? BETWEEN StartDate AND EndDate',[$date]);
        if($closed)throw new RuntimeException('The accounting period for this voucher date is closed. Use a reversal in an open period.');
    }
    private function accountingVoucher(string $id): ?array
    {
        $this->user();$this->ensureAccounting();$row=$this->one('AccountingVouchers','VoucherID',$id);if(!$row)return null;
        $row['Lines']=$this->rows('AccountingVoucherLines','VoucherID=? ORDER BY LineNo',[$id]);return$row;
    }
    private function dealerCashDeposits(array $filters=[]): array
    {
        $this->user();$this->ensureAccounting();
        $from=$this->text($filters['From']??'');$to=$this->text($filters['To']??'');$dealerId=$this->text($filters['DealerID']??'');
        if($from)AccountingRules::date($from);if($to)AccountingRules::date($to);if($from&&$to&&$from>$to)throw new InvalidArgumentException('From date must be before To date.');
        $where=['v.Status="POSTED"'];$params=[];if($from){$where[]='d.DepositDate>=?';$params[]=$from;}if($to){$where[]='d.DepositDate<=?';$params[]=$to;}if($dealerId){$where[]='d.DealerID=?';$params[]=$dealerId;}
        $query=$this->db->prepare('SELECT d.DepositID,d.DepositDate,d.DealerID,dr.DealerName,dr.DealerCode,d.AccountID,a.AccountName BankName,d.BankDetails,d.Amount,d.ReferenceNo,d.Notes,d.VoucherID,v.SourceNo VoucherNo,CASE WHEN EXISTS (SELECT 1 FROM AccountingVouchers rv WHERE rv.ReversalOf=v.VoucherID AND rv.Status="POSTED") THEN "REVERSED" ELSE "POSTED" END Status,d.CreatedAt,d.CreatedBy FROM DealerCashDeposits d JOIN Dealers dr ON dr.DealerID=d.DealerID JOIN ChartOfAccounts a ON a.AccountID=d.AccountID JOIN AccountingVouchers v ON v.VoucherID=d.VoucherID WHERE '.implode(' AND ',$where).' ORDER BY d.DepositDate DESC,d.DepositID DESC');
        $query->execute($params);$rows=$query->fetchAll();foreach($rows as&$row)$row['Amount']=(float)$row['Amount'];unset($row);
        $active=array_values(array_filter($rows,static fn($row)=>$row['Status']==='POSTED'));
        return ['rows'=>$rows,'count'=>count($rows),'total'=>round(array_sum(array_column($active,'Amount')),2)];
    }
    private function createDealerCashDeposit(array $input): array
    {
        $this->user(true);$this->ensureAccounting();$dealerId=$this->text($input['DealerID']??'');$dealer=$this->dealer($dealerId);
        if(!$dealer||!in_array($dealer['Status'],['APPROVED','ACTIVE'],true))throw new InvalidArgumentException('Select an approved or active registered dealer.');
        $date=AccountingRules::date($input['DepositDate']??'');$this->accountingOpenPeriod($date);$accountId=AccountingRules::accountCode($input['AccountID']??'');$account=$this->one('ChartOfAccounts','AccountID',$accountId);
        if(!$account||$account['Status']!=='ACTIVE'||$account['AccountType']!=='ASSET'||(int)$account['IsControl']===1||$account['AccountGroup']!=='Current Assets')throw new InvalidArgumentException('Select an active cash, bank, or mobile financial service asset head.');
        $receivable=$this->one('ChartOfAccounts','AccountID','1100');if(!$receivable||$receivable['Status']!=='ACTIVE'||$receivable['AccountType']!=='ASSET'||(int)$receivable['IsControl']!==1)throw new RuntimeException('The active dealer receivable control account (1100) is required before posting deposits.');
        $amount=AccountingRules::amount($input['Amount']??0,'Deposit amount',false);$bankDetails=substr($this->text($input['BankDetails']??''),0,180);$reference=substr($this->text($input['ReferenceNo']??''),0,160);$notes=substr($this->text($input['Notes']??''),0,1000);
        $id=$this->id('DealerCashDeposits','DepositID','DDP');$voucherId=$this->id('AccountingVouchers','VoucherID','VCH');$now=$this->now();$sourceNo=$reference?:$id;
        $lines=AccountingRules::lines([
            ['AccountID'=>$accountId,'Debit'=>$amount,'Credit'=>0,'PartyType'=>'','PartyID'=>'','ReferenceNo'=>$reference,'Narration'=>'Dealer cash deposit received through '.$account['AccountName']],
            ['AccountID'=>'1100','Debit'=>0,'Credit'=>$amount,'PartyType'=>'DEALER','PartyID'=>$dealerId,'ReferenceNo'=>$reference,'Narration'=>'Dealer balance adjusted for cash deposit '.$id],
        ]);
        $this->db->beginTransaction();try{
            $this->insert('AccountingVouchers',['VoucherID'=>$voucherId,'VoucherDate'=>$date,'VoucherType'=>'RV','Status'=>'POSTED','SourceType'=>'DEALER_DEPOSIT','SourceID'=>$id,'SourceNo'=>$sourceNo,'PartyType'=>'DEALER','PartyID'=>$dealerId,'Narration'=>'Dealer cash deposit · '.$dealer['DealerName'],'TotalDebit'=>$lines['TotalDebit'],'TotalCredit'=>$lines['TotalCredit'],'ReversalOf'=>null,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'PostedAt'=>$now,'PostedBy'=>$this->current()]);
            foreach($lines['Lines']as$number=>$line)$this->insert('AccountingVoucherLines',['VoucherLineID'=>$this->id('AccountingVoucherLines','VoucherLineID','VLI'),'VoucherID'=>$voucherId,'LineNo'=>$number+1]+$line+['CreatedAt'=>$now,'CreatedBy'=>$this->current()]);
            $this->insert('DealerCashDeposits',['DepositID'=>$id,'DealerID'=>$dealerId,'DepositDate'=>$date,'AccountID'=>$accountId,'BankDetails'=>$bankDetails?:null,'Amount'=>$amount,'ReferenceNo'=>$reference?:null,'Notes'=>$notes?:null,'VoucherID'=>$voucherId,'CreatedAt'=>$now,'CreatedBy'=>$this->current()]);
            $this->audit('POST','DealerCashDeposits',$id,'Dealer cash deposit posted to '.$account['AccountName'].' and applied against dealer receivable.');$this->db->commit();
        }catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}
        return $this->dealerCashDeposits(['From'=>$date,'To'=>$date,'DealerID'=>$dealerId])['rows'][0]??[];
    }
    private function createAccountingVoucher(array $input): array
    {
        $this->user(true);$this->ensureAccounting();$date=AccountingRules::date($input['VoucherDate']??'');$this->accountingOpenPeriod($date);
        $type=strtoupper($this->text($input['VoucherType']??'JV'));if(!in_array($type,['JV','RV','PV','CV','OB'],true))throw new InvalidArgumentException('Select a valid voucher type.');
        $result=AccountingRules::lines($input['Lines']??[]);$accounts=[];foreach($this->chartAccounts()as$a)$accounts[$a['AccountID']]=$a;
        foreach($result['Lines'] as $line){if(!isset($accounts[$line['AccountID']])||$accounts[$line['AccountID']]['Status']!=='ACTIVE')throw new RuntimeException('Every journal line must use an active account.');if($line['PartyType']==='DEALER'&&!$this->dealer($line['PartyID']))throw new RuntimeException('Select a valid dealer for the journal line.');if($line['PartyType']==='SUPPLIER'&&!$this->agreement($line['PartyID']))throw new RuntimeException('Select a valid supplier for the journal line.');}
        $partyType=strtoupper($this->text($input['PartyType']??''));if($partyType!==''&&!in_array($partyType,['DEALER','SUPPLIER','COMPANY','OUTSIDE'],true))throw new InvalidArgumentException('Invalid voucher party.');
        $id=$this->id('AccountingVouchers','VoucherID','VCH');$now=$this->now();$this->db->beginTransaction();try{
            $header=['VoucherID'=>$id,'VoucherDate'=>$date,'VoucherType'=>$type,'Status'=>'POSTED','SourceType'=>'MANUAL','SourceID'=>'','SourceNo'=>substr($this->text($input['SourceNo']??''),0,160),'PartyType'=>$partyType,'PartyID'=>substr($this->text($input['PartyID']??''),0,40),'Narration'=>substr($this->text($input['Narration']??''),0,2000),'TotalDebit'=>$result['TotalDebit'],'TotalCredit'=>$result['TotalCredit'],'ReversalOf'=>null,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'PostedAt'=>$now,'PostedBy'=>$this->current()];$this->insert('AccountingVouchers',$header);
            foreach($result['Lines']as$number=>$line)$this->insert('AccountingVoucherLines',['VoucherLineID'=>$this->id('AccountingVoucherLines','VoucherLineID','VLI'),'VoucherID'=>$id,'LineNo'=>$number+1]+$line+['CreatedAt'=>$now,'CreatedBy'=>$this->current()]);
            $this->audit('POST','AccountingVouchers',$id,'Manual '.$type.' voucher posted.');$this->db->commit();
        }catch(Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}return$this->accountingVoucher($id);
    }
    private function reverseAccountingVoucher(string $id, string $date): array
    {
        $this->user(true);$this->ensureAccounting();$original=$this->accountingVoucher($id);if(!$original||$original['Status']!=='POSTED')throw new RuntimeException('Only a posted voucher can be reversed.');if($this->value('SELECT COUNT(*) FROM AccountingVouchers WHERE ReversalOf=? AND Status="POSTED"',[$id]))throw new RuntimeException('This voucher already has a posted reversal.');$date=AccountingRules::date($date);$this->accountingOpenPeriod($date);
        $lines=array_map(fn($line)=>['AccountID'=>$line['AccountID'],'Debit'=>$line['Credit'],'Credit'=>$line['Debit'],'PartyType'=>$line['PartyType'],'PartyID'=>$line['PartyID'],'ReferenceNo'=>$line['ReferenceNo'],'Narration'=>'Reversal of '.$id],$original['Lines']);
        $copy=['VoucherDate'=>$date,'VoucherType'=>'RV','PartyType'=>$original['PartyType'],'PartyID'=>$original['PartyID'],'SourceNo'=>'Reversal of '.$id,'Narration'=>'Reversal of voucher '.$id,'Lines'=>$lines];$result=AccountingRules::lines($copy['Lines']);$new=$this->id('AccountingVouchers','VoucherID','VCH');$now=$this->now();$this->db->beginTransaction();try{$this->insert('AccountingVouchers',['VoucherID'=>$new,'VoucherDate'=>$date,'VoucherType'=>'RV','Status'=>'POSTED','SourceType'=>'REVERSAL','SourceID'=>$id,'SourceNo'=>$copy['SourceNo'],'PartyType'=>$copy['PartyType'],'PartyID'=>$copy['PartyID'],'Narration'=>$copy['Narration'],'TotalDebit'=>$result['TotalDebit'],'TotalCredit'=>$result['TotalCredit'],'ReversalOf'=>$id,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'PostedAt'=>$now,'PostedBy'=>$this->current()]);foreach($result['Lines']as$n=>$line)$this->insert('AccountingVoucherLines',['VoucherLineID'=>$this->id('AccountingVoucherLines','VoucherLineID','VLI'),'VoucherID'=>$new,'LineNo'=>$n+1]+$line+['CreatedAt'=>$now,'CreatedBy'=>$this->current()]);$this->audit('REVERSE','AccountingVouchers',$new,'Reversal of '.$id.'.');$this->db->commit();}catch(Throwable$error){if($this->db->inTransaction())$this->db->rollBack();throw$error;}return$this->accountingVoucher($new);
    }
    private function accountingTrialBalance(string $asOf=''): array
    {
        $this->user();$this->ensureAccounting();$asOf=$asOf?$this->text($asOf):date('Y-m-d');if($asOf)AccountingRules::date($asOf);
        $query=$this->db->prepare('SELECT a.AccountID,a.AccountName,a.AccountType,a.AccountGroup,a.NormalBalance,COALESCE(SUM(CASE WHEN v.Status="POSTED" AND v.VoucherDate<=? THEN l.Debit ELSE 0 END),0) Debit,COALESCE(SUM(CASE WHEN v.Status="POSTED" AND v.VoucherDate<=? THEN l.Credit ELSE 0 END),0) Credit FROM ChartOfAccounts a LEFT JOIN AccountingVoucherLines l ON l.AccountID=a.AccountID LEFT JOIN AccountingVouchers v ON v.VoucherID=l.VoucherID WHERE a.Status="ACTIVE" GROUP BY a.AccountID,a.AccountName,a.AccountType,a.AccountGroup,a.NormalBalance ORDER BY a.AccountID');$query->execute([$asOf,$asOf]);$rows=[];foreach($query->fetchAll()as$row){$row['Debit']=(float)$row['Debit'];$row['Credit']=(float)$row['Credit'];$balance=$row['NormalBalance']==='DEBIT'?$row['Debit']-$row['Credit']:$row['Credit']-$row['Debit'];$row['Balance']=round(abs($balance),2);$row['BalanceSide']=$balance>=0?$row['NormalBalance']:($row['NormalBalance']==='DEBIT'?'CREDIT':'DEBIT');$rows[]=$row;}return['AsOf'=>$asOf,'Rows'=>$rows,'TotalDebit'=>array_sum(array_column($rows,'Debit')),'TotalCredit'=>array_sum(array_column($rows,'Credit'))];
    }
    private function accountingAccountLedger(string $account, string $from='', string $to=''): array
    {
        $this->user();$this->ensureAccounting();$account=AccountingRules::accountCode($account);$coa=$this->one('ChartOfAccounts','AccountID',$account);if(!$coa)throw new RuntimeException('Account was not found.');$from=$from?:'2000-01-01';$to=$to?:date('Y-m-d');AccountingRules::date($from);AccountingRules::date($to);if($from>$to)throw new InvalidArgumentException('From date must be before To date.');$sql='SELECT v.VoucherID,v.VoucherDate,v.VoucherType,v.SourceType,v.SourceID,v.SourceNo,v.Narration VoucherNarration,l.LineNo,l.Debit,l.Credit,l.PartyType,l.PartyID,l.ReferenceNo,l.Narration FROM AccountingVoucherLines l JOIN AccountingVouchers v ON v.VoucherID=l.VoucherID WHERE v.Status="POSTED" AND l.AccountID=? AND v.VoucherDate BETWEEN ? AND ? ORDER BY v.VoucherDate,v.VoucherID,l.LineNo';$q=$this->db->prepare($sql);$q->execute([$account,$from,$to]);$balance=0.;$rows=[];foreach($q->fetchAll()as$row){$movement=$coa['NormalBalance']==='DEBIT'?(float)$row['Debit']-(float)$row['Credit']:(float)$row['Credit']-(float)$row['Debit'];$balance=round($balance+$movement,2);$row['Debit']=(float)$row['Debit'];$row['Credit']=(float)$row['Credit'];$row['Balance']=abs($balance);$row['BalanceSide']=$balance>=0?$coa['NormalBalance']:($coa['NormalBalance']==='DEBIT'?'CREDIT':'DEBIT');$rows[]=$row;}return['Account'=>$coa,'From'=>$from,'To'=>$to,'Rows'=>$rows];
    }
    private function accountingPartyLedger(string $partyType, string $partyId, string $from='', string $to=''): array
    {
        $this->user();$this->ensureAccounting();$partyType=strtoupper($this->text($partyType));if(!in_array($partyType,['DEALER','SUPPLIER'],true))throw new InvalidArgumentException('Select a dealer or supplier ledger.');if($partyType==='DEALER'&&!$this->dealer($partyId))throw new RuntimeException('Dealer was not found.');if($partyType==='SUPPLIER'&&!$this->agreement($partyId))throw new RuntimeException('Supplier was not found.');$account=$partyType==='DEALER'?'1100':'2010';$from=$from?:'2000-01-01';$to=$to?:date('Y-m-d');AccountingRules::date($from);AccountingRules::date($to);$q=$this->db->prepare('SELECT v.VoucherID,v.VoucherDate,v.VoucherType,v.SourceType,v.SourceID,v.SourceNo,v.Narration VoucherNarration,l.Debit,l.Credit,l.ReferenceNo,l.Narration FROM AccountingVoucherLines l JOIN AccountingVouchers v ON v.VoucherID=l.VoucherID WHERE v.Status="POSTED" AND l.AccountID=? AND l.PartyType=? AND l.PartyID=? AND v.VoucherDate BETWEEN ? AND ? ORDER BY v.VoucherDate,v.VoucherID,l.LineNo');$q->execute([$account,$partyType,$partyId,$from,$to]);$balance=0.;$rows=[];foreach($q->fetchAll()as$row){$move=$partyType==='DEALER'?(float)$row['Debit']-(float)$row['Credit']:(float)$row['Credit']-(float)$row['Debit'];$balance=round($balance+$move,2);$row['Debit']=(float)$row['Debit'];$row['Credit']=(float)$row['Credit'];$row['Balance']=abs($balance);$row['BalanceSide']=$balance>=0?($partyType==='DEALER'?'DEBIT':'CREDIT'):($partyType==='DEALER'?'CREDIT':'DEBIT');$rows[]=$row;}return['PartyType'=>$partyType,'PartyID'=>$partyId,'From'=>$from,'To'=>$to,'Rows'=>$rows];
    }

    private function supplierPayableLedger(string $supplierId,string $from='',string $to=''):array
    {
        $this->user();$this->ensureAccounting();$supplier=$this->agreement($supplierId);if(!$supplier)throw new RuntimeException('Supplier was not found.');
        $from=$from?:'2000-01-01';$to=$to?:$this->today();AccountingRules::date($from);AccountingRules::date($to);if($from>$to)throw new InvalidArgumentException('From date must be before To date.');
        $events=[];$perOrder=[];
        $stmt=$this->db->prepare('SELECT r.GRNID,r.SupplyID,r.ReceiptDate,COALESCE(SUM(i.BaseAcceptedQuantity*i.UnitCost),0) Amount FROM GoodsReceipts r JOIN GoodsReceiptItems i ON i.GRNID=r.GRNID WHERE r.AgreementID=? AND r.Status="POSTED" AND r.ReceiptDate<=? GROUP BY r.GRNID,r.SupplyID,r.ReceiptDate ORDER BY r.ReceiptDate,r.GRNID');$stmt->execute([$supplierId,$to]);
        foreach($stmt->fetchAll()as$row){$amount=round((float)$row['Amount'],2);if($amount<=0)continue;$po=(string)$row['SupplyID'];$perOrder[$po]??=['SupplyID'=>$po,'Billed'=>0.0,'Returned'=>0.0,'Paid'=>0.0];$perOrder[$po]['Billed']+=$amount;$events[]=['Date'=>$row['ReceiptDate'],'Type'=>'GRN bill','Source'=>$row['GRNID'],'SupplyID'=>$po,'Debit'=>0.0,'Credit'=>$amount,'Narration'=>'Accepted goods received against '.$po];}
        $stmt=$this->db->prepare('SELECT r.ReturnID,r.ReturnDate,COALESCE(NULLIF(r.SupplyID,""),g.SupplyID) SupplyID,r.TotalAmount,r.Reason FROM ProductReturns r LEFT JOIN GoodsReceipts g ON g.GRNID=r.SourceGRNID WHERE r.AgreementID=? AND r.ReturnType="PURCHASE" AND r.Status="POSTED" AND r.ReturnDate<=? ORDER BY r.ReturnDate,r.ReturnID');$stmt->execute([$supplierId,$to]);
        foreach($stmt->fetchAll()as$row){$amount=round((float)$row['TotalAmount'],2);$po=(string)($row['SupplyID']??'');$perOrder[$po]??=['SupplyID'=>$po,'Billed'=>0.0,'Returned'=>0.0,'Paid'=>0.0];$perOrder[$po]['Returned']+=$amount;$events[]=['Date'=>$row['ReturnDate'],'Type'=>'Purchase return','Source'=>$row['ReturnID'],'SupplyID'=>$po,'Debit'=>$amount,'Credit'=>0.0,'Narration'=>$this->text($row['Reason'])?:'Goods returned to supplier'];}
        $stmt=$this->db->prepare('SELECT PaymentID,PaymentDate,SupplyID,Amount,ReferenceNo,Notes,EasyEntryID FROM Payments WHERE AgreementID=? AND PayerType="COMPANY" AND ReceiverType="SELF" AND SupplyID IS NOT NULL AND Status="POSTED" AND PaymentDate<=? ORDER BY PaymentDate,PaymentID');$stmt->execute([$supplierId,$to]);
        foreach($stmt->fetchAll()as$row){$amount=round((float)$row['Amount'],2);$po=(string)($row['SupplyID']??'');$perOrder[$po]??=['SupplyID'=>$po,'Billed'=>0.0,'Returned'=>0.0,'Paid'=>0.0];$perOrder[$po]['Paid']+=$amount;$events[]=['Date'=>$row['PaymentDate'],'Type'=>'Supplier payment','Source'=>$row['ReferenceNo']?:$row['PaymentID'],'SupplyID'=>$po,'Debit'=>$amount,'Credit'=>0.0,'Narration'=>$this->text($row['Notes'])?:'Paid through Easy Accounts','EasyEntryID'=>$row['EasyEntryID']];}
        $rank=['GRN bill'=>0,'Purchase return'=>1,'Supplier payment'=>2];usort($events,static function($a,$b)use($rank){return[$a['Date'],$rank[$a['Type']]??9,$a['Source']]<=>[$b['Date'],$rank[$b['Type']]??9,$b['Source']];});$opening=0.0;$balance=0.0;$rows=[];$billTotal=0.0;$returnTotal=0.0;$paidTotal=0.0;
        foreach($events as$event){$movement=$event['Credit']-$event['Debit'];$balance=round($balance+$movement,2);$billTotal+=$event['Credit'];$returnTotal+=$event['Type']==='Purchase return'?$event['Debit']:0;$paidTotal+=$event['Type']==='Supplier payment'?$event['Debit']:0;if($event['Date']<$from){$opening=$balance;continue;}if($event['Date']<=$to){$event['Balance']=abs($balance);$event['BalanceSide']=$balance>=0?'CREDIT':'DEBIT';$rows[]=$event;}}
        $orders=[];$orderRows=$this->rows('CompanySupplies','AgreementID=? AND Status<>?',[$supplierId,'ARCHIVED']);foreach($orderRows as$order){$id=(string)$order['SupplyID'];$totals=$perOrder[$id]??['Billed'=>0.0,'Returned'=>0.0,'Paid'=>0.0];$due=round($totals['Billed']-$totals['Returned']-$totals['Paid'],2);$orders[]=['SupplyID'=>$id,'SupplyDate'=>$order['SupplyDate'],'Status'=>$order['Status'],'OrderAmount'=>(float)$order['TotalAmount'],'Billed'=>round($totals['Billed'],2),'Returned'=>round($totals['Returned'],2),'Paid'=>round($totals['Paid'],2),'Balance'=>$due,'BalanceSide'=>$due>=0?'CR':'DR'];}
        foreach($perOrder as$id=>$totals)if($id===''||!in_array($id,array_column($orderRows,'SupplyID'),true)){$due=round($totals['Billed']-$totals['Returned']-$totals['Paid'],2);$orders[]=['SupplyID'=>$id?:'Unallocated','SupplyDate'=>'','Status'=>'','OrderAmount'=>0.0,'Billed'=>round($totals['Billed'],2),'Returned'=>round($totals['Returned'],2),'Paid'=>round($totals['Paid'],2),'Balance'=>$due,'BalanceSide'=>$due>=0?'CR':'DR'];}
        usort($orders,static fn($a,$b)=>strcmp((string)$b['SupplyDate'],(string)$a['SupplyDate']));
        $deposit=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM AgreementDeposits WHERE AgreementID=? AND DepositDate IS NOT NULL AND DepositDate<=?',[$supplierId,$to]);$scheduled=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM AgreementDeposits WHERE AgreementID=? AND DepositDate IS NULL AND (DueDate IS NULL OR DueDate<=?)',[$supplierId,$to]);
        return['PartyType'=>'SUPPLIER','PartyID'=>$supplierId,'SupplierName'=>$supplier['Company'],'From'=>$from,'To'=>$to,'OpeningBalance'=>abs($opening),'OpeningBalanceSide'=>$opening>=0?'CR':'DR','ClosingBalance'=>abs($balance),'ClosingBalanceSide'=>$balance>=0?'CR':'DR','Bills'=>round($billTotal,2),'Returns'=>round($returnTotal,2),'Payments'=>round($paidTotal,2),'SecurityDepositPaid'=>round($deposit,2),'SecurityDepositScheduled'=>round($scheduled,2),'SecurityDepositNote'=>'Security deposits are shown separately and are not deducted from payable until they are formally applied.','Orders'=>$orders,'Rows'=>array_values(array_filter($rows,static fn($r)=>$r['Date']<=$to))];
    }
    private function accountingVouchers(string $from='', string $to=''): array
    {
        $this->user();$this->ensureAccounting();$from=$from?:date('Y-m-01');$to=$to?:date('Y-m-d');AccountingRules::date($from);AccountingRules::date($to);$q=$this->db->prepare('SELECT * FROM AccountingVouchers WHERE VoucherDate BETWEEN ? AND ? ORDER BY VoucherDate DESC,VoucherID DESC');$q->execute([$from,$to]);return$q->fetchAll();
    }
    private function accountingDaybook(string $from='',string $to=''):array
    {
        $this->user();$this->ensureAccounting();$from=$from?:date('Y-m-d');$to=$to?:date('Y-m-d');AccountingRules::date($from);AccountingRules::date($to);if($from>$to)throw new InvalidArgumentException('From date must be before To date.');
        $query=$this->db->prepare('SELECT VoucherID,VoucherDate,VoucherType,SourceType,SourceID,SourceNo,PartyType,PartyID,Narration,TotalDebit,TotalCredit FROM AccountingVouchers WHERE Status="POSTED" AND VoucherDate BETWEEN ? AND ? ORDER BY VoucherDate DESC,VoucherID DESC');$query->execute([$from,$to]);$rows=$query->fetchAll();
        foreach($rows as&$row){$row['TotalDebit']=(float)$row['TotalDebit'];$row['TotalCredit']=(float)$row['TotalCredit'];}unset($row);
        $receiptQuery=$this->db->prepare('SELECT r.GRNID,r.SupplyID,r.AgreementID,r.ReceiptDate,a.Company SupplierName,COALESCE(SUM(i.BaseAcceptedQuantity*i.UnitCost),0) AcceptedValue FROM GoodsReceipts r JOIN Agreements a ON a.AgreementID=r.AgreementID JOIN GoodsReceiptItems i ON i.GRNID=r.GRNID WHERE r.Status="POSTED" AND r.ReceiptDate BETWEEN ? AND ? GROUP BY r.GRNID,r.SupplyID,r.AgreementID,r.ReceiptDate,a.Company ORDER BY r.ReceiptDate DESC,r.GRNID DESC');$receiptQuery->execute([$from,$to]);$receipts=$receiptQuery->fetchAll();
        foreach($receipts as&$receipt){$receipt['AcceptedValue']=(float)$receipt['AcceptedValue'];$receipt['Items']=$this->rowsBySql('SELECT i.ProductID,p.ProductName,i.Variant,i.AcceptedQuantity,i.UnitName,i.UnitCost,ROUND(i.BaseAcceptedQuantity*i.UnitCost,2) LineTotal FROM GoodsReceiptItems i JOIN Products p ON p.ProductID=i.ProductID WHERE i.GRNID=? AND i.BaseAcceptedQuantity>0 ORDER BY i.GRNItemID',[$receipt['GRNID']]);foreach($receipt['Items']as&$item){$item['AcceptedQuantity']=(float)$item['AcceptedQuantity'];$item['UnitCost']=(float)$item['UnitCost'];$item['LineTotal']=(float)$item['LineTotal'];}unset($item);}unset($receipt);
        $returnQuery=$this->db->prepare('SELECT r.ReturnID,r.SupplyID,r.SourceGRNID,r.AgreementID,r.ReturnDate,r.TotalAmount,r.Reason,a.Company SupplierName FROM ProductReturns r JOIN Agreements a ON a.AgreementID=r.AgreementID WHERE r.ReturnType="PURCHASE" AND r.Status="POSTED" AND r.ReturnDate BETWEEN ? AND ? ORDER BY r.ReturnDate DESC,r.ReturnID DESC');$returnQuery->execute([$from,$to]);$returns=$returnQuery->fetchAll();
        foreach($returns as&$return){$return['TotalAmount']=(float)$return['TotalAmount'];$return['Items']=$this->rowsBySql('SELECT i.ProductID,p.ProductName,i.Variant,i.Quantity,i.UnitName,i.LineTotal FROM ProductReturnItems i JOIN Products p ON p.ProductID=i.ProductID WHERE i.ReturnID=? ORDER BY i.ReturnItemID',[$return['ReturnID']]);foreach($return['Items']as&$item){$item['Quantity']=(float)$item['Quantity'];$item['LineTotal']=(float)$item['LineTotal'];}unset($item);}unset($return);
        return['From'=>$from,'To'=>$to,'Rows'=>$rows,'TransactionCount'=>count($rows),'TotalDebit'=>round(array_sum(array_column($rows,'TotalDebit')),2),'TotalCredit'=>round(array_sum(array_column($rows,'TotalCredit')),2),'GoodsReceipts'=>$receipts,'GoodsReceiptValue'=>round(array_sum(array_column($receipts,'AcceptedValue')),2),'PurchaseReturns'=>$returns,'PurchaseReturnValue'=>round(array_sum(array_column($returns,'TotalAmount')),2)];
    }
    private function accountingDashboard(): array
    {
        $this->user();$this->ensureAccounting();$setup=$this->accountingSetting('finance',[]);$tb=$this->accountingTrialBalance(date('Y-m-d'));$vouchers=(int)$this->value('SELECT COUNT(*) FROM AccountingVouchers WHERE Status="POSTED"');$debit=(float)$this->value('SELECT COALESCE(SUM(TotalDebit),0) FROM AccountingVouchers WHERE Status="POSTED"');$credit=(float)$this->value('SELECT COALESCE(SUM(TotalCredit),0) FROM AccountingVouchers WHERE Status="POSTED"');return['Setup'=>$setup,'VoucherCount'=>$vouchers,'TotalDebit'=>$debit,'TotalCredit'=>$credit,'IsBalanced'=>abs($debit-$credit)<0.005,'TrialBalance'=>$tb];
    }
}
