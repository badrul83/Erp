<?php
declare(strict_types=1);

/**
 * Dealer-owned SR (Sales Representative) profiles, targets and daily retail
 * sales activity. These records are a dealer performance register: they do
 * not alter company inventory or the dealer-to-company invoice workflow.
 */
trait DealerSalesForce
{
    private function ensureDealerSalesForceSchema(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS DealerSalesRepresentatives (
            SRID VARCHAR(20) PRIMARY KEY,
            DealerID VARCHAR(20) NOT NULL,
            SRCode VARCHAR(30) NOT NULL,
            SRName VARCHAR(160) NOT NULL,
            Mobile VARCHAR(30) NOT NULL,
            Territory VARCHAR(160) NOT NULL,
            RouteName VARCHAR(160) NULL,
            NID VARCHAR(80) NULL,
            JoiningDate DATE NULL,
            Status VARCHAR(20) NOT NULL DEFAULT "ACTIVE",
            CreatedAt DATETIME NOT NULL,
            CreatedBy VARCHAR(120) NOT NULL,
            UpdatedAt DATETIME NOT NULL,
            UpdatedBy VARCHAR(120) NOT NULL,
            UNIQUE KEY dealer_sr_code (DealerID, SRCode),
            INDEX dealer_sr_status (DealerID, Status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS DealerSRTargets (
            TargetID VARCHAR(20) PRIMARY KEY,
            DealerID VARCHAR(20) NOT NULL,
            SRID VARCHAR(20) NOT NULL,
            PeriodType VARCHAR(20) NOT NULL,
            PeriodStart DATE NOT NULL,
            PeriodEnd DATE NOT NULL,
            TargetAmount DECIMAL(16,2) NOT NULL DEFAULT 0,
            TargetCollection DECIMAL(16,2) NOT NULL DEFAULT 0,
            TargetVisits INT NOT NULL DEFAULT 0,
            CreatedAt DATETIME NOT NULL,
            CreatedBy VARCHAR(120) NOT NULL,
            UpdatedAt DATETIME NOT NULL,
            UpdatedBy VARCHAR(120) NOT NULL,
            UNIQUE KEY sr_period_target (SRID, PeriodType, PeriodStart),
            INDEX dealer_target_period (DealerID, PeriodStart, PeriodEnd)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS DealerSRSales (
            SRSaleID VARCHAR(20) PRIMARY KEY,
            DealerID VARCHAR(20) NOT NULL,
            SRID VARCHAR(20) NOT NULL,
            SaleDate DATE NOT NULL,
            OutletName VARCHAR(200) NOT NULL,
            OutletMobile VARCHAR(30) NULL,
            InvoiceRef VARCHAR(100) NULL,
            SalesAmount DECIMAL(16,2) NOT NULL DEFAULT 0,
            CollectionAmount DECIMAL(16,2) NOT NULL DEFAULT 0,
            VisitCount INT NOT NULL DEFAULT 1,
            Notes TEXT NULL,
            Status VARCHAR(20) NOT NULL DEFAULT "POSTED",
            CreatedAt DATETIME NOT NULL,
            CreatedBy VARCHAR(120) NOT NULL,
            INDEX dealer_sr_sale_date (DealerID, SaleDate),
            INDEX sr_sale_date (SRID, SaleDate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    private function salesForceScopeDealer(string $requestedDealerId = '', bool $required = false): string
    {
        $user = $this->user();
        $linkedDealerId = $this->dealerUserId($user);
        if ($linkedDealerId !== '') return $linkedDealerId;
        if ($this->normalizedRole((string) ($user['Role'] ?? '')) !== 'ADMIN') {
            throw new RuntimeException('SR Sales Management is available only to the linked Dealer User or an Administrator.');
        }
        $dealerId = $this->text($requestedDealerId);
        if ($dealerId !== '') $this->validateDealerUser($dealerId);
        if ($required && $dealerId === '') throw new InvalidArgumentException('Select a registered dealer.');
        return $dealerId;
    }

    private function salesForceRepresentative(string $srId, string $dealerId): array
    {
        $row = $this->one('DealerSalesRepresentatives', 'SRID', $srId);
        if (!$row || (string) $row['DealerID'] !== $dealerId) {
            throw new RuntimeException('Sales Representative was not found for this dealer.');
        }
        return $row;
    }

    private function cleanSrMobile(string $mobile): string
    {
        $mobile = preg_replace('/[\s\-()]+/', '', $this->text($mobile)) ?? '';
        if (str_starts_with($mobile, '+880')) $mobile = '0' . substr($mobile, 4);
        if (str_starts_with($mobile, '880')) $mobile = '0' . substr($mobile, 2);
        if (!preg_match('/^01[3-9][0-9]{8}$/', $mobile)) {
            throw new InvalidArgumentException('SR mobile number must be a valid 11 digit Bangladesh mobile number.');
        }
        return $mobile;
    }

    private function targetPeriod(string $type, string $date): array
    {
        $type = strtoupper($this->text($type));
        if (!in_array($type, ['WEEKLY', 'MONTHLY'], true)) throw new InvalidArgumentException('Target period must be Weekly or Monthly.');
        try { $anchor = new DateTimeImmutable($this->today($date)); }
        catch (Throwable) { $anchor = new DateTimeImmutable($this->today()); }
        if ($type === 'WEEKLY') {
            $start = $anchor->modify('monday this week');
            return ['Type'=>$type, 'Start'=>$start->format('Y-m-d'), 'End'=>$start->modify('+6 days')->format('Y-m-d')];
        }
        $start = $anchor->modify('first day of this month');
        return ['Type'=>$type, 'Start'=>$start->format('Y-m-d'), 'End'=>$start->modify('last day of this month')->format('Y-m-d')];
    }

    private function dashboardPeriod(string $period, string $date): array
    {
        $period = strtoupper($this->text($period ?: 'MONTH'));
        try { $anchor = new DateTimeImmutable($this->today($date)); }
        catch (Throwable) { $anchor = new DateTimeImmutable($this->today()); }
        if ($period === 'TODAY') return ['Label'=>'Daily', 'Start'=>$anchor->format('Y-m-d'), 'End'=>$anchor->format('Y-m-d'), 'TargetType'=>'NONE'];
        if ($period === 'WEEK') {
            $start = $anchor->modify('monday this week');
            return ['Label'=>'Weekly', 'Start'=>$start->format('Y-m-d'), 'End'=>$start->modify('+6 days')->format('Y-m-d'), 'TargetType'=>'WEEKLY'];
        }
        $start = $anchor->modify('first day of this month');
        return ['Label'=>'Monthly', 'Start'=>$start->format('Y-m-d'), 'End'=>$start->modify('last day of this month')->format('Y-m-d'), 'TargetType'=>'MONTHLY'];
    }

    private function targetForRange(array $targets, string $srId, array $range): array
    {
        $amount = 0.0; $collection = 0.0; $visits = 0;
        foreach ($targets as $target) {
            if ((string) $target['SRID'] !== $srId) continue;
            if ($range['TargetType'] !== '' && strtoupper((string) $target['PeriodType']) !== $range['TargetType']) continue;
            if ((string) $target['PeriodStart'] > $range['End'] || (string) $target['PeriodEnd'] < $range['Start']) continue;
            $amount += (float) $target['TargetAmount'];
            $collection += (float) $target['TargetCollection'];
            $visits += (int) $target['TargetVisits'];
        }
        return ['Amount'=>$amount, 'Collection'=>$collection, 'Visits'=>$visits];
    }

    private function dealerSalesForceDashboard(array $input): array
    {
        $dealerId = $this->salesForceScopeDealer((string) ($input['DealerID'] ?? ''));
        $range = $this->dashboardPeriod((string) ($input['Period'] ?? 'MONTH'), (string) ($input['Date'] ?? ''));
        $week = $this->dashboardPeriod('WEEK', $range['Start']);
        $month = $this->dashboardPeriod('MONTH', $range['Start']);
        $representatives = $dealerId !== '' ? $this->rows('DealerSalesRepresentatives', 'DealerID=? ORDER BY Status ASC, SRName ASC', [$dealerId]) : $this->rows('DealerSalesRepresentatives', '1=1 ORDER BY DealerID ASC, Status ASC, SRName ASC');
        $targets = $dealerId !== '' ? $this->rows('DealerSRTargets', 'DealerID=?', [$dealerId]) : $this->rows('DealerSRTargets');
        $sales = $dealerId !== '' ? $this->rows('DealerSRSales', 'DealerID=? ORDER BY SaleDate DESC, CreatedAt DESC', [$dealerId]) : $this->rows('DealerSRSales', '1=1 ORDER BY SaleDate DESC, CreatedAt DESC');
        $dealerNames = [];
        foreach ($dealerId !== '' ? [$this->validateDealerUser($dealerId)] : $this->rows('Dealers', 'Status IN ("APPROVED","ACTIVE")') as $dealer) $dealerNames[(string) $dealer['DealerID']] = $dealer;
        $result = []; $summary = ['ActiveSR'=>0,'PeriodSales'=>0.0,'WeeklySales'=>0.0,'MonthlySales'=>0.0,'Collection'=>0.0,'TargetAmount'=>0.0,'TargetCollection'=>0.0,'TargetVisits'=>0,'VisitCount'=>0];
        foreach ($representatives as $sr) {
            $periodSales = 0.0; $periodCollection = 0.0; $periodVisits = 0; $weeklySales = 0.0; $monthlySales = 0.0;
            foreach ($sales as $entry) {
                if ((string) $entry['SRID'] !== (string) $sr['SRID'] || strtoupper((string) $entry['Status']) !== 'POSTED') continue;
                $saleDate = (string) $entry['SaleDate'];
                if ($saleDate >= $range['Start'] && $saleDate <= $range['End']) { $periodSales += (float) $entry['SalesAmount']; $periodCollection += (float) $entry['CollectionAmount']; $periodVisits += (int) $entry['VisitCount']; }
                if ($saleDate >= $week['Start'] && $saleDate <= $week['End']) $weeklySales += (float) $entry['SalesAmount'];
                if ($saleDate >= $month['Start'] && $saleDate <= $month['End']) $monthlySales += (float) $entry['SalesAmount'];
            }
            $target = $this->targetForRange($targets, (string) $sr['SRID'], $range);
            $achievement = $target['Amount'] > 0 ? round(($periodSales / $target['Amount']) * 100, 2) : 0.0;
            if (strtoupper((string) $sr['Status']) === 'ACTIVE') $summary['ActiveSR']++;
            foreach (['PeriodSales'=>$periodSales,'WeeklySales'=>$weeklySales,'MonthlySales'=>$monthlySales,'Collection'=>$periodCollection,'TargetAmount'=>$target['Amount'],'TargetCollection'=>$target['Collection'],'VisitCount'=>$periodVisits] as $key=>$value) $summary[$key] += $value;
            $summary['TargetVisits'] += $target['Visits'];
            $result[] = array_merge($sr, ['DealerName'=>$dealerNames[(string) $sr['DealerID']]['DealerName'] ?? (string) $sr['DealerID'], 'PeriodSales'=>$periodSales, 'WeeklySales'=>$weeklySales, 'MonthlySales'=>$monthlySales, 'Collection'=>$periodCollection, 'VisitCount'=>$periodVisits, 'TargetAmount'=>$target['Amount'], 'TargetCollection'=>$target['Collection'], 'TargetVisits'=>$target['Visits'], 'AchievementPercent'=>$achievement]);
        }
        $summary['AchievementPercent'] = $summary['TargetAmount'] > 0 ? round(($summary['PeriodSales'] / $summary['TargetAmount']) * 100, 2) : 0.0;
        $salesRows = [];
        $srNames = []; foreach ($representatives as $sr) $srNames[(string) $sr['SRID']] = $sr;
        $periodSalesEntries = array_values(array_filter($sales, fn(array $entry): bool => (string) $entry['SaleDate'] >= $range['Start'] && (string) $entry['SaleDate'] <= $range['End']));
        foreach (array_slice($periodSalesEntries, 0, 60) as $entry) {
            $entry['SRName'] = $srNames[(string) $entry['SRID']]['SRName'] ?? (string) $entry['SRID'];
            $entry['SRCode'] = $srNames[(string) $entry['SRID']]['SRCode'] ?? '-';
            $entry['DealerName'] = $dealerNames[(string) $entry['DealerID']]['DealerName'] ?? (string) $entry['DealerID'];
            $salesRows[] = $entry;
        }
        return ['Scope'=>['DealerID'=>$dealerId,'DealerName'=>$dealerId !== '' ? ($dealerNames[$dealerId]['DealerName'] ?? $dealerId) : 'All registered dealers','IsDealerUser'=>$this->dealerUserId($this->user()) !== ''],'Range'=>$range,'Summary'=>$summary,'Representatives'=>$result,'SalesEntries'=>$salesRows,'Dealers'=>array_values($dealerNames)];
    }

    private function saveDealerSalesRepresentative(array $input): array
    {
        $dealerId = $this->salesForceScopeDealer((string) ($input['DealerID'] ?? ''), true);
        $srId = $this->text($input['SRID'] ?? '');
        $name = $this->text($input['SRName'] ?? ''); $territory = $this->text($input['Territory'] ?? '');
        if ($name === '' || $territory === '') throw new InvalidArgumentException('SR name and territory are required.');
        $mobile = $this->cleanSrMobile((string) ($input['Mobile'] ?? ''));
        $status = strtoupper($this->text($input['Status'] ?? 'ACTIVE'));
        if (!in_array($status, ['ACTIVE','INACTIVE'], true)) throw new InvalidArgumentException('SR status must be Active or Inactive.');
        $joining = $this->text($input['JoiningDate'] ?? ''); if ($joining !== '') $joining = $this->today($joining);
        $now = $this->now();
        if ($srId === '') {
            $srId = $this->id('DealerSalesRepresentatives', 'SRID', 'SR');
            $code = 'SR-' . substr($dealerId, -4) . '-' . str_pad(substr($srId, -6), 3, '0', STR_PAD_LEFT);
            $this->insert('DealerSalesRepresentatives', ['SRID'=>$srId,'DealerID'=>$dealerId,'SRCode'=>$code,'SRName'=>$name,'Mobile'=>$mobile,'Territory'=>$territory,'RouteName'=>$this->text($input['RouteName'] ?? '') ?: null,'NID'=>$this->text($input['NID'] ?? '') ?: null,'JoiningDate'=>$joining ?: null,'Status'=>$status,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()]);
            $this->audit('CREATE', 'DealerSalesRepresentatives', $srId, 'SR profile created for dealer '.$dealerId.'.');
        } else {
            $old = $this->salesForceRepresentative($srId, $dealerId);
            $this->update('DealerSalesRepresentatives', 'SRID', $srId, ['SRName'=>$name,'Mobile'=>$mobile,'Territory'=>$territory,'RouteName'=>$this->text($input['RouteName'] ?? '') ?: null,'NID'=>$this->text($input['NID'] ?? '') ?: null,'JoiningDate'=>$joining ?: null,'Status'=>$status,'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()]);
            $this->audit('UPDATE', 'DealerSalesRepresentatives', $srId, 'SR profile updated for dealer '.$old['DealerID'].'.');
        }
        return $this->one('DealerSalesRepresentatives', 'SRID', $srId) ?? [];
    }

    private function saveDealerSRTarget(array $input): array
    {
        $dealerId = $this->salesForceScopeDealer((string) ($input['DealerID'] ?? ''), true);
        $sr = $this->salesForceRepresentative($this->text($input['SRID'] ?? ''), $dealerId);
        if (strtoupper((string) $sr['Status']) !== 'ACTIVE') throw new RuntimeException('Targets can be assigned only to an active SR.');
        $period = $this->targetPeriod((string) ($input['PeriodType'] ?? 'MONTHLY'), (string) ($input['PeriodStart'] ?? ''));
        $amount = $this->num($input['TargetAmount'] ?? 0, 'Sales target', true);
        $collection = $this->num($input['TargetCollection'] ?? 0, 'Collection target', true);
        $visits = (int) ($input['TargetVisits'] ?? 0); if ($visits < 0) throw new InvalidArgumentException('Target visits cannot be negative.');
        if ($amount <= 0 && $collection <= 0 && $visits <= 0) throw new InvalidArgumentException('Enter at least one sales, collection or outlet-visit target.');
        $existing = $this->rows('DealerSRTargets', 'SRID=? AND PeriodType=? AND PeriodStart=?', [(string) $sr['SRID'], $period['Type'], $period['Start']]);
        $now = $this->now();
        if ($existing) {
            $targetId = (string) $existing[0]['TargetID'];
            $this->update('DealerSRTargets', 'TargetID', $targetId, ['PeriodEnd'=>$period['End'],'TargetAmount'=>$amount,'TargetCollection'=>$collection,'TargetVisits'=>$visits,'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()]);
            $this->audit('UPDATE', 'DealerSRTargets', $targetId, $period['Type'].' SR target updated.');
        } else {
            $targetId = $this->id('DealerSRTargets', 'TargetID', 'SRT');
            $this->insert('DealerSRTargets', ['TargetID'=>$targetId,'DealerID'=>$dealerId,'SRID'=>$sr['SRID'],'PeriodType'=>$period['Type'],'PeriodStart'=>$period['Start'],'PeriodEnd'=>$period['End'],'TargetAmount'=>$amount,'TargetCollection'=>$collection,'TargetVisits'=>$visits,'CreatedAt'=>$now,'CreatedBy'=>$this->current(),'UpdatedAt'=>$now,'UpdatedBy'=>$this->current()]);
            $this->audit('CREATE', 'DealerSRTargets', $targetId, $period['Type'].' SR target assigned.');
        }
        return $this->one('DealerSRTargets', 'TargetID', $targetId) ?? [];
    }

    private function recordDealerSRSale(array $input): array
    {
        $dealerId = $this->salesForceScopeDealer((string) ($input['DealerID'] ?? ''), true);
        $sr = $this->salesForceRepresentative($this->text($input['SRID'] ?? ''), $dealerId);
        if (strtoupper((string) $sr['Status']) !== 'ACTIVE') throw new RuntimeException('Sales can be recorded only for an active SR.');
        $outlet = $this->text($input['OutletName'] ?? ''); if ($outlet === '') throw new InvalidArgumentException('Retailer / outlet name is required.');
        $sales = $this->num($input['SalesAmount'] ?? 0, 'Sales amount');
        $collection = $this->num($input['CollectionAmount'] ?? 0, 'Collection amount', true);
        $visits = (int) ($input['VisitCount'] ?? 1); if ($visits < 1 || $visits > 99) throw new InvalidArgumentException('Outlet visits must be between 1 and 99.');
        $mobile = $this->text($input['OutletMobile'] ?? ''); if ($mobile !== '') $mobile = $this->cleanSrMobile($mobile);
        $id = $this->id('DealerSRSales', 'SRSaleID', 'SRS'); $now = $this->now();
        $this->insert('DealerSRSales', ['SRSaleID'=>$id,'DealerID'=>$dealerId,'SRID'=>$sr['SRID'],'SaleDate'=>$this->today($input['SaleDate'] ?? ''),'OutletName'=>$outlet,'OutletMobile'=>$mobile ?: null,'InvoiceRef'=>$this->text($input['InvoiceRef'] ?? '') ?: null,'SalesAmount'=>$sales,'CollectionAmount'=>$collection,'VisitCount'=>$visits,'Notes'=>$this->text($input['Notes'] ?? '') ?: null,'Status'=>'POSTED','CreatedAt'=>$now,'CreatedBy'=>$this->current()]);
        $this->audit('CREATE', 'DealerSRSales', $id, 'SR daily sales activity recorded for '.$sr['SRCode'].'.');
        return $this->one('DealerSRSales', 'SRSaleID', $id) ?? [];
    }
}
