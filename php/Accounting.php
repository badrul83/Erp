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
    private function accountingVouchers(string $from='', string $to=''): array
    {
        $this->user();$this->ensureAccounting();$from=$from?:date('Y-m-01');$to=$to?:date('Y-m-d');AccountingRules::date($from);AccountingRules::date($to);$q=$this->db->prepare('SELECT * FROM AccountingVouchers WHERE VoucherDate BETWEEN ? AND ? ORDER BY VoucherDate DESC,VoucherID DESC');$q->execute([$from,$to]);return$q->fetchAll();
    }
    private function accountingDashboard(): array
    {
        $this->user();$this->ensureAccounting();$setup=$this->accountingSetting('finance',[]);$tb=$this->accountingTrialBalance(date('Y-m-d'));$vouchers=(int)$this->value('SELECT COUNT(*) FROM AccountingVouchers WHERE Status="POSTED"');$debit=(float)$this->value('SELECT COALESCE(SUM(TotalDebit),0) FROM AccountingVouchers WHERE Status="POSTED"');$credit=(float)$this->value('SELECT COALESCE(SUM(TotalCredit),0) FROM AccountingVouchers WHERE Status="POSTED"');return['Setup'=>$setup,'VoucherCount'=>$vouchers,'TotalDebit'=>$debit,'TotalCredit'=>$credit,'IsBalanced'=>abs($debit-$credit)<0.005,'TrialBalance'=>$tb];
    }
}
