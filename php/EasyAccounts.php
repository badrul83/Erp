<?php
declare(strict_types=1);

/**
 * Simple, controlled transaction forms backed by the permanent double-entry
 * accounting journal. Easy Accounts is a front-end workflow, never a second
 * ledger: one Easy entry creates one posted AccountingVoucher in the same
 * database transaction and keeps that voucher after this module is disabled.
 */
trait EasyAccounts
{
    private function ensureEasyAccounts(): void
    {
        $this->ensureAccounting();
        $this->db->exec('CREATE TABLE IF NOT EXISTS EasyAccountEntries (
            EasyEntryID VARCHAR(20) PRIMARY KEY,
            EntryDate DATE NOT NULL,
            EntryType VARCHAR(40) NOT NULL,
            Amount DECIMAL(16,2) NOT NULL,
            PaymentAccountID VARCHAR(20) NULL,
            CounterAccountID VARCHAR(20) NULL,
            OperationalPaymentID VARCHAR(20) NULL UNIQUE,
            PartyType VARCHAR(20) NULL,
            PartyID VARCHAR(40) NULL,
            ReferenceNo VARCHAR(160) NULL,
            Narration TEXT NULL,
            VoucherID VARCHAR(20) NOT NULL UNIQUE,
            Status VARCHAR(20) NOT NULL DEFAULT "POSTED",
            ReversalVoucherID VARCHAR(20) NULL UNIQUE,
            ClientRequestKey VARCHAR(120) NULL UNIQUE,
            CreatedAt DATETIME NOT NULL,
            CreatedBy VARCHAR(120) NOT NULL,
            UpdatedAt DATETIME NOT NULL,
            UpdatedBy VARCHAR(120) NOT NULL,
            INDEX easy_entry_date (EntryDate),
            INDEX easy_entry_type (EntryType),
            INDEX easy_entry_party (PartyType, PartyID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        foreach ([['EasyAccountEntries','OperationalPaymentID','VARCHAR(20) NULL UNIQUE'],['Payments','EasyEntryID','VARCHAR(20) NULL UNIQUE']] as [$table,$column,$definition]) {
            $exists=(int)$this->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
            if(!$exists)$this->db->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` '.$definition);
        }
        // A separate drawings account prevents an owner withdrawal being
        // mistaken for an operating expense or an ordinary cash payment.
        if (!$this->one('ChartOfAccounts', 'AccountID', '3030')) {
            $now = $this->now();
            $this->insert('ChartOfAccounts', ['AccountID'=>'3030','AccountName'=>'Owner Drawings','AccountType'=>'EQUITY','AccountGroup'=>'Equity','NormalBalance'=>'DEBIT','IsControl'=>0,'Status'=>'ACTIVE','SystemKey'=>'OWNER_DRAWINGS','CreatedAt'=>$now,'CreatedBy'=>'system','UpdatedAt'=>$now,'UpdatedBy'=>'system']);
        }
    }

    private function easyActiveAccount(string $id): array
    {
        $account = $this->one('ChartOfAccounts', 'AccountID', $this->text($id));
        if (!$account || strtoupper((string) $account['Status']) !== 'ACTIVE') throw new RuntimeException('Select an active accounting account.');
        return $account;
    }

    private function easyPaymentAccount(string $id): array
    {
        $account = $this->easyActiveAccount($id);
        if (!in_array((string) $account['SystemKey'], ['CASH','BANK','MFS'], true)) {
            throw new InvalidArgumentException('Select Cash, Bank, or Mobile Financial Service as the payment account.');
        }
        return $account;
    }

    private function easyAccountSetup(): array
    {
        $this->user(); $this->ensureEasyAccounts();
        $accounts = $this->rows('ChartOfAccounts', 'Status="ACTIVE" ORDER BY AccountID');
        $payment = array_values(array_filter($accounts, fn(array $row): bool => in_array((string) $row['SystemKey'], ['CASH','BANK','MFS'], true)));
        $expense = array_values(array_filter($accounts, fn(array $row): bool => (string) $row['AccountType'] === 'EXPENSE' && (string) $row['AccountGroup'] === 'Operating Expenses'));
        $equity = array_values(array_filter($accounts, fn(array $row): bool => (string) $row['SystemKey'] === 'CAPITAL'));
        $income = array_values(array_filter($accounts, fn(array $row): bool => (string) $row['SystemKey'] === 'OTHER_INCOME'));
        $commissionDealers=[];
        foreach($this->rows('Dealers','Status IN ("APPROVED","ACTIVE") AND DealerClass="COMMISSION" ORDER BY DealerName') as $dealer){
            $earned=(float)$this->value('SELECT COALESCE(SUM(CommissionAmount),0) FROM DealerSales WHERE DealerID=? AND Status="DELIVERED"',[$dealer['DealerID']]);
            $paid=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM Payments WHERE ReceiverType="DEALER" AND ReceiverID=? AND Status="POSTED"',[$dealer['DealerID']]);
            $commissionDealers[]=['DealerID'=>$dealer['DealerID'],'DealerName'=>$dealer['DealerName'],'Earned'=>$earned,'Paid'=>$paid,'Due'=>max(0,$earned-$paid)];
        }
        return [
            'PaymentAccounts'=>$payment,
            'ExpenseAccounts'=>$expense,
            'CapitalAccounts'=>$equity,
            'IncomeAccounts'=>$income,
            'Dealers'=>$this->rows('Dealers', 'Status IN ("APPROVED","ACTIVE") ORDER BY DealerName'),
            'CommissionDealers'=>$commissionDealers,
            'Suppliers'=>$this->rows('Agreements', 'Status="APPROVED" ORDER BY Company'),
            'Sales'=>array_values(array_filter($this->sales(),fn(array $row):bool=>strtoupper((string)($row['CustomerType']??'DEALER'))==='DEALER'&&in_array(strtoupper((string)$row['Status']),['CONFIRMED','DELIVERED'],true)&&(float)$row['DueAmount']>0)),
            'SupplierOrders'=>$this->rowsBySql('SELECT s.SupplyID,s.AgreementID,s.SupplyDate,s.TotalAmount,s.Status,a.Company,COALESCE((SELECT SUM(p.Amount) FROM Payments p WHERE p.SupplyID=s.SupplyID AND p.Status="POSTED"),0) AS PaidAmount FROM CompanySupplies s JOIN Agreements a ON a.AgreementID=s.AgreementID WHERE s.Status IN ("CONFIRMED","PARTIALLY_RECEIVED","RECEIVED") AND s.TotalAmount>COALESCE((SELECT SUM(p.Amount) FROM Payments p WHERE p.SupplyID=s.SupplyID AND p.Status="POSTED"),0) ORDER BY s.SupplyDate DESC,s.SupplyID'),
            'EntryTypes'=>[
                ['Key'=>'EXPENSE','Label'=>'Bill / Operating Expense'],
                ['Key'=>'DEALER_RECEIPT','Label'=>'Receive Dealer Payment'],
                ['Key'=>'DEALER_COMMISSION_PAYMENT','Label'=>'Pay Dealer Commission'],
                ['Key'=>'SUPPLIER_PAYMENT','Label'=>'Pay Supplier Bill'],
                ['Key'=>'SUPPLIER_RECEIPT','Label'=>'Receive Supplier Commission'],
                ['Key'=>'BANK_TRANSFER','Label'=>'Cash / Bank / MFS Transfer'],
                ['Key'=>'OWNER_CAPITAL','Label'=>'Owner Capital Introduced'],
                ['Key'=>'OWNER_DRAWING','Label'=>'Owner Drawing / Withdrawal'],
                ['Key'=>'OTHER_INCOME','Label'=>'Other Income Received'],
            ],
        ];
    }

    private function easyParty(string $type, string $id): array
    {
        $type = strtoupper($type);
        if ($type === 'DEALER') {
            $row = $this->dealer($id);
            if (!$row || !in_array(strtoupper((string) $row['Status']), ['APPROVED','ACTIVE'], true)) throw new InvalidArgumentException('Select an approved dealer.');
            return $row;
        }
        if ($type === 'SUPPLIER') {
            $row = $this->agreement($id);
            if (!$row || strtoupper((string) $row['Status']) !== 'APPROVED') throw new InvalidArgumentException('Select an approved supplier.');
            return $row;
        }
        throw new InvalidArgumentException('Select a valid transaction party.');
    }

    private function easyLines(array $input, string $entryType, float $amount, string $reference, string $narration): array
    {
        $paymentId = $this->text($input['PaymentAccountID'] ?? '');
        $counterId = $this->text($input['CounterAccountID'] ?? '');
        $partyId = $this->text($input['PartyID'] ?? '');
        $line = fn(string $account, float $debit, float $credit, string $partyType='', string $party='') => ['AccountID'=>$account,'Debit'=>$debit,'Credit'=>$credit,'PartyType'=>$partyType,'PartyID'=>$party,'ReferenceNo'=>$reference,'Narration'=>$narration];
        if ($entryType === 'EXPENSE') {
            $payment = $this->easyPaymentAccount($paymentId); $expense = $this->easyActiveAccount($counterId);
            if ((string) $expense['AccountType'] !== 'EXPENSE') throw new InvalidArgumentException('Select an expense head for this bill or expense.');
            return [$line((string) $expense['AccountID'], $amount, 0), $line((string) $payment['AccountID'], 0, $amount)];
        }
        if ($entryType === 'DEALER_RECEIPT') {
            $payment = $this->easyPaymentAccount($paymentId); $party = $this->easyParty('DEALER', $partyId);
            $saleId=$this->text($input['SaleID']??'');$sale=$saleId?$this->sale($saleId):null;
            if(!$sale||$sale['DealerID']!==$party['DealerID']||!in_array(strtoupper((string)$sale['Status']),['CONFIRMED','DELIVERED'],true))throw new InvalidArgumentException('Select an open confirmed or delivered invoice for this dealer.');
            if((float)$sale['DueAmount']+0.009<$amount)throw new InvalidArgumentException('Payment exceeds the selected invoice due.');
            return [$line((string) $payment['AccountID'], $amount, 0), $line('1100', 0, $amount, 'DEALER', (string) $party['DealerID'])];
        }
        if ($entryType === 'DEALER_COMMISSION_PAYMENT') {
            $payment=$this->easyPaymentAccount($paymentId);$party=$this->easyParty('DEALER',$partyId);
            if(strtoupper((string)($party['DealerClass']??''))!=='COMMISSION')throw new InvalidArgumentException('Choose a commission-based dealer.');
            $earned=(float)$this->value('SELECT COALESCE(SUM(CommissionAmount),0) FROM DealerSales WHERE DealerID=? AND Status="DELIVERED"',[$party['DealerID']]);
            $paid=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM Payments WHERE ReceiverType="DEALER" AND ReceiverID=? AND Status="POSTED"',[$party['DealerID']]);$due=max(0,$earned-$paid);
            if($amount>$due+0.009)throw new InvalidArgumentException('Commission payment exceeds the earned commission due.');
            return [$line('2050',$amount,0,'DEALER',(string)$party['DealerID']),$line((string)$payment['AccountID'],0,$amount)];
        }
        if ($entryType === 'SUPPLIER_PAYMENT') {
            $payment = $this->easyPaymentAccount($paymentId); $party = $this->easyParty('SUPPLIER', $partyId);
            $supplyId=$this->text($input['SupplyID']??'');$supply=$supplyId?$this->one('CompanySupplies','SupplyID',$supplyId):null;
            if(!$supply||$supply['AgreementID']!==$party['AgreementID']||!in_array(strtoupper((string)$supply['Status']),['CONFIRMED','PARTIALLY_RECEIVED','RECEIVED'],true))throw new InvalidArgumentException('Select an open purchase order for this supplier.');
            $due=max(0,(float)$supply['TotalAmount']-$this->supplyPaid($supplyId));if($amount>$due+0.009)throw new InvalidArgumentException('Payment exceeds the selected purchase order due.');
            return [$line('2010', $amount, 0, 'SUPPLIER', (string) $party['AgreementID']), $line((string) $payment['AccountID'], 0, $amount)];
        }
        if ($entryType === 'SUPPLIER_RECEIPT') {
            $payment = $this->easyPaymentAccount($paymentId); $party = $this->easyParty('SUPPLIER', $partyId); $income=$this->easyActiveAccount($counterId);
            if((string)$income['AccountType']!=='INCOME')throw new InvalidArgumentException('Select an income account for supplier commission received.');
            $rate=(float)($party['PurchaseDiscountRate']??$party['CommissionRate']??0);$base=(float)$this->value('SELECT COALESCE(SUM(TotalAmount),0) FROM CompanySupplies WHERE AgreementID=? AND Status<>"CANCELLED"',[$party['AgreementID']]);$received=(float)$this->value('SELECT COALESCE(SUM(Amount),0) FROM Payments WHERE AgreementID=? AND PayerType="COMPANY" AND ReceiverType="SELF" AND Status="POSTED"',[$party['AgreementID']]);$due=max(0,$base*$rate/100-$received);
            if($amount>$due+0.009)throw new InvalidArgumentException('Receipt exceeds the supplier commission due.');
            return [$line((string)$payment['AccountID'],$amount,0,'SUPPLIER',(string)$party['AgreementID']),$line((string)$income['AccountID'],0,$amount,'SUPPLIER',(string)$party['AgreementID'])];
        }
        if ($entryType === 'BANK_TRANSFER') {
            $from = $this->easyPaymentAccount($paymentId); $to = $this->easyPaymentAccount($counterId);
            if ((string) $from['AccountID'] === (string) $to['AccountID']) throw new InvalidArgumentException('Source and destination accounts must be different.');
            return [$line((string) $to['AccountID'], $amount, 0), $line((string) $from['AccountID'], 0, $amount)];
        }
        if ($entryType === 'OWNER_CAPITAL') {
            $payment = $this->easyPaymentAccount($paymentId); $capital = $this->easyActiveAccount($counterId ?: '3010');
            if ((string) $capital['SystemKey'] !== 'CAPITAL') throw new InvalidArgumentException('Select the Owner Capital account for this transaction.');
            return [$line((string) $payment['AccountID'], $amount, 0), $line((string) $capital['AccountID'], 0, $amount)];
        }
        if ($entryType === 'OWNER_DRAWING') {
            $payment = $this->easyPaymentAccount($paymentId); $drawing = $this->easyActiveAccount($counterId ?: '3030');
            if ((string) $drawing['SystemKey'] !== 'OWNER_DRAWINGS') throw new InvalidArgumentException('Owner withdrawal must use the Owner Drawings account.');
            return [$line((string) $drawing['AccountID'], $amount, 0), $line((string) $payment['AccountID'], 0, $amount)];
        }
        if ($entryType === 'OTHER_INCOME') {
            $payment = $this->easyPaymentAccount($paymentId); $income = $this->easyActiveAccount($counterId ?: '4020');
            if ((string) $income['SystemKey'] !== 'OTHER_INCOME') throw new InvalidArgumentException('Select the Other Income account for this transaction.');
            return [$line((string) $payment['AccountID'], $amount, 0), $line((string) $income['AccountID'], 0, $amount)];
        }
        throw new InvalidArgumentException('Select a valid Easy Accounts transaction type.');
    }

    private function easyEntry(string $id): ?array
    {
        $this->user(); $this->ensureEasyAccounts();
        $entry = $this->one('EasyAccountEntries', 'EasyEntryID', $id);
        if (!$entry) return null;
        $entry['Voucher'] = $this->accountingVoucher((string) $entry['VoucherID']);
        return $entry;
    }

    private function createEasyAccountEntry(array $input): array
    {
        $this->user(); $this->ensureEasyAccounts();
        $key = substr($this->text($input['ClientRequestKey'] ?? ''), 0, 120);
        if ($key !== '') {
            $existing = $this->value('SELECT EasyEntryID FROM EasyAccountEntries WHERE ClientRequestKey=?', [$key]);
            if ($existing) return $this->easyEntry((string) $existing) ?? [];
        }
        $entryType = strtoupper($this->text($input['EntryType'] ?? ''));
        $allowed = ['EXPENSE','DEALER_RECEIPT','DEALER_COMMISSION_PAYMENT','SUPPLIER_PAYMENT','SUPPLIER_RECEIPT','BANK_TRANSFER','OWNER_CAPITAL','OWNER_DRAWING','OTHER_INCOME'];
        if (!in_array($entryType, $allowed, true)) throw new InvalidArgumentException('Select a valid Easy Accounts transaction type.');
        $date = AccountingRules::date($input['EntryDate'] ?? ''); $this->accountingOpenPeriod($date);
        $amount = AccountingRules::amount($input['Amount'] ?? 0, 'Amount', false);
        $reference = substr($this->text($input['ReferenceNo'] ?? ''), 0, 160);
        $narration = substr($this->text($input['Narration'] ?? ''), 0, 2000);
        if ($narration === '') throw new InvalidArgumentException('Enter a clear narration for this transaction.');
        $lines = $this->easyLines($input, $entryType, $amount, $reference, $narration);
        $result = AccountingRules::lines($lines); $entryId = $this->id('EasyAccountEntries', 'EasyEntryID', 'EAS'); $voucherId = $this->id('AccountingVouchers', 'VoucherID', 'VCH'); $now = $this->now();
        $partyType = ''; $partyId = '';
        foreach ($result['Lines'] as $line) if ($line['PartyType'] !== '') { $partyType = $line['PartyType']; $partyId = $line['PartyID']; break; }
        $operationalPayment=null;$supplyId='';
        if(in_array($entryType,['DEALER_RECEIPT','DEALER_COMMISSION_PAYMENT','SUPPLIER_PAYMENT','SUPPLIER_RECEIPT'],true)){
            $methodAccount=$this->easyPaymentAccount($this->text($input['PaymentAccountID']??''));$method=match((string)$methodAccount['SystemKey']){'CASH'=>'Cash','BANK'=>'Bank','MFS'=>'Mobile Banking',default=>'Other'};$dealerReceipt=$entryType==='DEALER_RECEIPT';$commissionPayment=$entryType==='DEALER_COMMISSION_PAYMENT';$supplierPayment=$entryType==='SUPPLIER_PAYMENT';$supplierReceipt=$entryType==='SUPPLIER_RECEIPT';$partyRef=$this->text($input['PartyID']??'');$supplyId=$supplierPayment?$this->text($input['SupplyID']??''):'';$operationalPayment=$this->id('Payments','PaymentID','PAY');
        }
        $this->db->beginTransaction();
        try {
            if($operationalPayment)$this->insert('Payments',['PaymentID'=>$operationalPayment,'AgreementID'=>($entryType==='SUPPLIER_PAYMENT'||$entryType==='SUPPLIER_RECEIPT')?$this->text($input['PartyID']??''):null,'PayerType'=>$dealerReceipt?'DEALER':'COMPANY','PayerID'=>$dealerReceipt?$this->text($input['PartyID']??''):null,'ReceiverType'=>$dealerReceipt?'COMPANY':($commissionPayment?'DEALER':'SELF'),'ReceiverID'=>$commissionPayment?$this->text($input['PartyID']??''):null,'PaymentDate'=>$date,'Amount'=>$amount,'PaymentMethod'=>$method,'ReferenceNo'=>$reference?:null,'SaleID'=>$entryType==='DEALER_RECEIPT'?$this->text($input['SaleID']??''):null,'Notes'=>$narration,'Status'=>'POSTED','CreatedAt'=>$now,'CreatedBy'=>$this->current(),'SupplyID'=>$supplyId?:null,'EasyEntryID'=>$entryId]);
            $this->insert('AccountingVouchers', ['VoucherID'=>$voucherId,'VoucherDate'=>$date,'VoucherType'=>in_array($entryType,['DEALER_RECEIPT','SUPPLIER_RECEIPT'],true) ? 'RV' : (in_array($entryType,['SUPPLIER_PAYMENT','EXPENSE','OWNER_DRAWING'],true) ? 'PV' : 'JV'),'Status'=>'POSTED','SourceType'=>'EASY_ACCOUNT','SourceID'=>$entryId,'SourceNo'=>$entryId.($reference ? ' · '.$reference : ''),'PartyType'=>$partyType,'PartyID'=>$partyId,'Narration'=>$narration,'TotalDebit'=>$result['TotalDebit'],'TotalCredit'=>$result['TotalCredit'],'ReversalOf'=>null,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'PostedAt'=>$now,'PostedBy'=>$this->current()]);
            foreach ($result['Lines'] as $number => $line) $this->insert('AccountingVoucherLines', ['VoucherLineID'=>$this->id('AccountingVoucherLines','VoucherLineID','VLI'),'VoucherID'=>$voucherId,'LineNo'=>$number+1]+$line+['CreatedAt'=>$now,'CreatedBy'=>$this->current()]);
            $this->insert('EasyAccountEntries', ['EasyEntryID'=>$entryId,'EntryDate'=>$date,'EntryType'=>$entryType,'Amount'=>$amount,'PaymentAccountID'=>$this->text($input['PaymentAccountID'] ?? '') ?: null,'CounterAccountID'=>$this->text($input['CounterAccountID'] ?? '') ?: null,'OperationalPaymentID'=>$operationalPayment,'PartyType'=>$partyType ?: null,'PartyID'=>$partyId ?: null,'ReferenceNo'=>$reference ?: null,'Narration'=>$narration,'VoucherID'=>$voucherId,'Status'=>'POSTED','ReversalVoucherID'=>null,'ClientRequestKey'=>$key ?: null,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()]);
            if($supplyId){$supply=$this->one('CompanySupplies','SupplyID',$supplyId);if($supply)$this->update('CompanySupplies','SupplyID',$supplyId,['PaymentStatus'=>$this->paymentStatus((float)$supply['TotalAmount'],$this->supplyPaid($supplyId)),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()]);}
            $this->audit('POST', 'EasyAccountEntries', $entryId, 'Easy Accounts entry posted to voucher '.$voucherId.'.');
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $this->easyEntry($entryId) ?? [];
    }

    private function easyEntries(string $from='', string $to=''): array
    {
        $this->user(); $this->ensureEasyAccounts();
        $from = $from ?: date('Y-m-01'); $to = $to ?: date('Y-m-d');
        AccountingRules::date($from); AccountingRules::date($to); if ($from > $to) throw new InvalidArgumentException('From date must be before To date.');
        return $this->rows('EasyAccountEntries', 'EntryDate BETWEEN ? AND ? ORDER BY EntryDate DESC, CreatedAt DESC', [$from,$to]);
    }

    private function easyAccountsDashboard(array $input): array
    {
        $from = $this->text($input['From'] ?? '') ?: date('Y-m-01'); $to = $this->text($input['To'] ?? '') ?: date('Y-m-d');
        $entries = $this->easyEntries($from, $to); $cashIn = 0.0; $cashOut = 0.0; $expense = 0.0; $otherIncome = 0.0;
        foreach ($entries as $entry) {
            if ((string) $entry['Status'] !== 'POSTED') continue;
            $type = (string) $entry['EntryType']; $amount = (float) $entry['Amount'];
            if (in_array($type, ['DEALER_RECEIPT','SUPPLIER_RECEIPT','OWNER_CAPITAL','OTHER_INCOME'], true)) $cashIn += $amount;
            if (in_array($type, ['EXPENSE','DEALER_COMMISSION_PAYMENT','SUPPLIER_PAYMENT','OWNER_DRAWING'], true)) $cashOut += $amount;
            if ($type === 'EXPENSE') $expense += $amount;
            if ($type === 'OTHER_INCOME') $otherIncome += $amount;
        }
        $trial = $this->accountingTrialBalance($to); $income = 0.0; $operatingExpense = 0.0;
        foreach ((array) ($trial['Rows'] ?? []) as $row) {
            $signed = (string) $row['BalanceSide'] === (string) $row['NormalBalance'] ? (float) $row['Balance'] : -(float) $row['Balance'];
            if ((string) $row['AccountType'] === 'INCOME') $income += $signed;
            if ((string) $row['AccountType'] === 'EXPENSE') $operatingExpense += $signed;
        }
        return ['From'=>$from,'To'=>$to,'Entries'=>$entries,'Summary'=>['EntryCount'=>count($entries),'CashIn'=>$cashIn,'CashOut'=>$cashOut,'EasyExpense'=>$expense,'EasyOtherIncome'=>$otherIncome,'AccountingIncome'=>$income,'AccountingExpense'=>$operatingExpense,'AccountingNetProfit'=>$income-$operatingExpense]];
    }

    private function reverseEasyAccountEntry(string $id, string $date): array
    {
        $this->user(true); $this->ensureEasyAccounts(); $entry = $this->easyEntry($id);
        if (!$entry) throw new RuntimeException('Easy Accounts entry was not found.');
        if ((string) $entry['Status'] !== 'POSTED') throw new RuntimeException('This Easy Accounts entry has already been reversed.');
        $reversal = $this->reverseAccountingVoucher((string) $entry['VoucherID'], $date);
        $paymentId=(string)($entry['OperationalPaymentID']??'');
        if($paymentId!==''){
            $payment=$this->one('Payments','PaymentID',$paymentId);
            if($payment&&$payment['Status']==='POSTED'){
                $this->update('Payments','PaymentID',$paymentId,['Status'=>'REVERSED']);
                if(!empty($payment['SupplyID'])){$supply=$this->one('CompanySupplies','SupplyID',(string)$payment['SupplyID']);if($supply)$this->update('CompanySupplies','SupplyID',(string)$payment['SupplyID'],['PaymentStatus'=>$this->paymentStatus((float)$supply['TotalAmount'],$this->supplyPaid((string)$payment['SupplyID'])),'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);}
            }
        }
        $this->update('EasyAccountEntries', 'EasyEntryID', $id, ['Status'=>'REVERSED','ReversalVoucherID'=>$reversal['VoucherID'],'UpdatedAt'=>$this->now(),'UpdatedBy'=>$this->current()]);
        $this->audit('REVERSE', 'EasyAccountEntries', $id, 'Easy Accounts entry reversed by voucher '.$reversal['VoucherID'].'.');
        return $this->easyEntry($id) ?? [];
    }
}
