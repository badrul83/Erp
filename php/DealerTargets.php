<?php
declare(strict_types=1);
require_once __DIR__ . '/DealerTargetRules.php';

trait DealerTargets
{
    private function ensureDealerTargets(): void
    {
        try {
            $this->db->query('SELECT 1 FROM DealerMonthlyTargets LIMIT 0');
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S02') throw $error;
            $sql = file_get_contents(__DIR__ . '/../database/dealer-targets.sql');
            if ($sql === false) throw new RuntimeException('Dealer target schema is missing.');
            $this->db->exec($sql);
        }
    }

    private function targetConfigRow(array $row): array
    {
        $row['Tiers'] = json_decode($row['TiersJson'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['TiersJson']);
        $row['TargetAmount'] = (float) $row['TargetAmount'];
        $row['IncrementPercent'] = $row['IncrementPercent'] === null ? null : (float) $row['IncrementPercent'];
        $row['Enabled'] = (bool) $row['Enabled'];
        $row['DeductReturns'] = (bool) $row['DeductReturns'];
        $row['Version'] = (int) $row['Version'];
        return $row;
    }

    private function dealerTargetConfig(string $month, string $dealerId): ?array
    {
        $this->user(); $this->ensureDealerTargets(); DealerTargetRules::month($month);
        $rows = $this->rows('DealerMonthlyTargets', 'Month=? AND DealerID=?', [$month, $dealerId]);
        return $rows ? $this->targetConfigRow($rows[0]) : null;
    }

    private function saveDealerTarget(array $input): array
    {
        $this->user(true); $this->ensureDealerTargets();
        $month = DealerTargetRules::month($this->text($input['Month'] ?? ''));
        $id = $this->text($input['DealerID'] ?? '');
        $dealer = $this->dealer($id);
        if (!$dealer || !in_array($dealer['Status'], ['APPROVED', 'ACTIVE'], true)) throw new InvalidArgumentException('Select an approved or active dealer.');
        $config = DealerTargetRules::config($input);
        $tiers = json_encode($config['Tiers'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        unset($config['Tiers']);
        $this->db->beginTransaction();
        try {
            if ($config['IncrementPercent'] !== null) {
                $previousMonth = (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
                $previous = $this->rows('DealerMonthlyTargets', 'Month=? AND DealerID=?', [$previousMonth, $id]);
                if (!$previous) throw new InvalidArgumentException('Set a previous-month target before using an increment, or leave increment blank.');
                $expected = round((float) $previous[0]['TargetAmount'] * (1 + $config['IncrementPercent'] / 100), 2);
                if (abs($config['TargetAmount'] - $expected) > 0.005) throw new InvalidArgumentException('The previous target changed. Reapply the increment or leave it blank for a manual target.');
            }
            $lock = $this->db->prepare('SELECT * FROM DealerMonthlyTargets WHERE Month=? AND DealerID=? FOR UPDATE');
            $lock->execute([$month, $id]); $old = $lock->fetch();
            if ((int) ($input['Version'] ?? 0) !== (int) ($old['Version'] ?? 0)) throw new RuntimeException('This target changed in another window. Reopen the setup before saving.');
            $row = $config + ['TiersJson' => $tiers, 'Version' => (int) ($old['Version'] ?? 0) + 1, 'UpdatedAt' => $this->now(), 'UpdatedBy' => $this->current()];
            if ($old) {
                $sets = implode(',', array_map(fn($key) => '`' . $key . '`=?', array_keys($row)));
                $this->db->prepare('UPDATE DealerMonthlyTargets SET ' . $sets . ' WHERE Month=? AND DealerID=?')->execute([...array_values($row), $month, $id]);
            } else {
                $this->insert('DealerMonthlyTargets', $row + ['Month' => $month, 'DealerID' => $id, 'CreatedAt' => $this->now(), 'CreatedBy' => $this->current()]);
            }
            $this->audit($old ? 'UPDATE' : 'CREATE', 'DealerMonthlyTargets', $month . ':' . $id,
                json_encode(['Before' => $old ? $this->targetConfigRow($old) : null, 'After' => $row], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->db->commit();
        } catch (Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        return $this->dealerTargetConfig($month, $id);
    }

    private function dealerTargetDashboard(array $filter): array
    {
        $user = $this->user(); $this->ensureDealerTargets(); $range = DealerTargetRules::range($filter);
        $targets = [];
        foreach ($this->rows('DealerMonthlyTargets', 'Month=?', [$range['Month']]) as $row) $targets[$row['DealerID']] = $this->targetConfigRow($row);
        // Stock posting time is the actual delivery time, not the editable invoice date.
        // Old imported sales without posting evidence fall back to their invoice date.
        $salesSql = "SELECT s.DealerID, COALESCE(DATE(d.DeliveredAt), DATE(a.DeliveredAt), s.SaleDate) AS EventDate,
            CASE WHEN d.DeliveredAt IS NULL AND a.DeliveredAt IS NULL THEN 1 ELSE 0 END AS LegacyDate,
            SUM(i.LineTotal) AS Amount FROM DealerSales s
            JOIN DealerSaleItems i ON i.SaleID=s.SaleID AND COALESCE(i.IsGift,0)=0
            LEFT JOIN (SELECT ReferenceID, MIN(CreatedAt) DeliveredAt FROM StockLedger WHERE TransactionType='DEALER_SALE' GROUP BY ReferenceID) d ON d.ReferenceID=s.SaleID
            LEFT JOIN (SELECT RecordID, MIN(Timestamp) DeliveredAt FROM AuditLog WHERE Module='DealerSales' AND Action='DELIVER' GROUP BY RecordID) a ON a.RecordID=s.SaleID
            WHERE s.Status IN ('DELIVERED','POSTED') AND s.CustomerType='DEALER'
            AND COALESCE(DATE(d.DeliveredAt), DATE(a.DeliveredAt), s.SaleDate) BETWEEN ? AND ?
            GROUP BY s.DealerID, EventDate, LegacyDate";
        $query = $this->db->prepare($salesSql); $query->execute([$range['MonthStart'], $range['MonthEnd']]);
        $metrics = []; $legacy = 0;
        foreach ($query->fetchAll() as $event) {
            $id = $event['DealerID']; $amount = (float) $event['Amount'];
            $metrics[$id]['Sales'] = ($metrics[$id]['Sales'] ?? 0) + $amount;
            if ($event['EventDate'] >= $range['From'] && $event['EventDate'] <= $range['To']) $metrics[$id]['PeriodSales'] = ($metrics[$id]['PeriodSales'] ?? 0) + $amount;
            $legacy += (int) $event['LegacyDate'];
        }
        $query = $this->db->prepare("SELECT s.DealerID, r.ReturnDate AS EventDate, SUM(i.LineTotal) AS Amount FROM ProductReturns r
            JOIN DealerSales s ON s.SaleID=r.SaleID AND s.CustomerType='DEALER' AND s.Status IN ('DELIVERED','POSTED')
            JOIN ProductReturnItems i ON i.ReturnID=r.ReturnID AND COALESCE(i.IsGift,0)=0
            WHERE r.ReturnType='SALE' AND r.Status='POSTED' AND r.ReturnDate BETWEEN ? AND ? GROUP BY s.DealerID,r.ReturnDate");
        $query->execute([$range['MonthStart'], $range['MonthEnd']]);
        foreach ($query->fetchAll() as $event) {
            $id = $event['DealerID']; $amount = (float) $event['Amount'];
            $metrics[$id]['Returns'] = ($metrics[$id]['Returns'] ?? 0) + $amount;
            if ($event['EventDate'] >= $range['From'] && $event['EventDate'] <= $range['To']) $metrics[$id]['PeriodReturns'] = ($metrics[$id]['PeriodReturns'] ?? 0) + $amount;
        }
        $rows = [];
        foreach ($this->dealers() as $dealer) {
            $id = $dealer['DealerID']; $m = $metrics[$id] ?? []; $config = $targets[$id] ?? null;
            // Do not send stored photos in every dashboard refresh.
            unset($dealer['DealerPhotoDataUrl']);
            $result = DealerTargetRules::achievement($config, (float) ($m['Sales'] ?? 0), (float) ($m['Returns'] ?? 0));
            $rows[] = $dealer + ['Target' => $config, 'MonthlySales' => round($m['Sales'] ?? 0, 2), 'MonthlyReturns' => round($m['Returns'] ?? 0, 2),
                'PeriodSales' => round($m['PeriodSales'] ?? 0, 2), 'PeriodReturns' => round($m['PeriodReturns'] ?? 0, 2),
                'PeriodNetSales' => round(($m['PeriodSales'] ?? 0) - ((!$config || $config['DeductReturns']) ? ($m['PeriodReturns'] ?? 0) : 0), 2)] + $result;
        }
        return ['Range' => $range, 'Rows' => $rows, 'CanConfigure' => $user['Role'] === 'ADMIN', 'LegacyDateGroups' => $legacy];
    }
}
