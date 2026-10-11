<?php
declare(strict_types=1);

/** Pure rules shared by the target API and its regression checks. */
final class DealerTargetRules
{
    public static function month(string $value): string
    {
        if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $value)) {
            throw new InvalidArgumentException('Select a valid target month (2000–2099).');
        }
        return $value;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Select a valid date.');
        return $date;
    }

    public static function range(array $filter): array
    {
        $month = self::month((string) ($filter['Month'] ?? date('Y-m')));
        $first = self::date($month . '-01');
        $last = $first->modify('last day of this month');
        $preset = strtoupper((string) ($filter['Period'] ?? 'MONTHLY'));
        $weekStart = (int) ($filter['WeekStart'] ?? 6); // Saturday in Bangladesh.
        if ($weekStart < 0 || $weekStart > 6) throw new InvalidArgumentException('Select a valid week start.');
        $from = $first; $to = $last;
        if (in_array($preset, ['DAILY', 'WEEKLY'], true)) {
            $anchor = self::date((string) ($filter['Date'] ?? $month . '-01'));
            if ($anchor->format('Y-m') !== $month) throw new InvalidArgumentException('Date must be inside the selected target month.');
            $from = $to = $anchor;
            if ($preset === 'WEEKLY') {
                $offset = ((int) $anchor->format('w') - $weekStart + 7) % 7;
                $from = $anchor->modify('-' . $offset . ' days');
                $to = $from->modify('+6 days');
                if ($from < $first) $from = $first;
                if ($to > $last) $to = $last;
            }
        } elseif ($preset === 'CUSTOM') {
            $from = self::date((string) ($filter['From'] ?? ''));
            $to = self::date((string) ($filter['To'] ?? ''));
            if ($from > $to || $from < $first || $to > $last) throw new InvalidArgumentException('Custom dates must be in the selected month, with From before To.');
        } elseif ($preset !== 'MONTHLY') {
            throw new InvalidArgumentException('Select Daily, Weekly, Monthly or Custom.');
        }
        return ['Month' => $month, 'Period' => $preset, 'From' => $from->format('Y-m-d'), 'To' => $to->format('Y-m-d'),
            'MonthStart' => $first->format('Y-m-d'), 'MonthEnd' => $last->format('Y-m-d'), 'WeekStart' => $weekStart];
    }

    public static function amount(mixed $value, string $label, float $min = 0, float $max = 999999999999.99): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < $min || (float) $value > $max) {
            throw new InvalidArgumentException($label . ' must be between ' . $min . ' and ' . $max . '.');
        }
        return round((float) $value, 2);
    }

    public static function config(array $input): array
    {
        $target = self::amount($input['TargetAmount'] ?? '', 'Monthly sales target', 0.01);
        $mode = (string) ($input['TierMode'] ?? 'HIGHEST');
        if (!in_array($mode, ['HIGHEST', 'CUMULATIVE'], true)) throw new InvalidArgumentException('Select a valid offer rule.');
        $tiers = $input['Tiers'] ?? [];
        if (!is_array($tiers) || count($tiers) > 12) throw new InvalidArgumentException('Use up to 12 offer tiers.');
        $seen = []; $clean = [];
        foreach ($tiers as $tier) {
            if (!is_array($tier)) throw new InvalidArgumentException('Invalid offer tier.');
            $threshold = self::amount($tier['Threshold'] ?? '', 'Achievement threshold (%)', 0.01, 10000);
            if (isset($seen[(string) $threshold])) throw new InvalidArgumentException('Each achievement threshold must be unique.');
            $seen[(string) $threshold] = true;
            $cashType = (string) ($tier['CashType'] ?? 'FIXED');
            if (!in_array($cashType, ['FIXED', 'PERCENT'], true)) throw new InvalidArgumentException('Select a valid cash offer type.');
            $cash = self::amount($tier['CashValue'] ?? 0, 'Cash offer', 0, $cashType === 'PERCENT' ? 100 : 999999999999.99);
            $gift = trim((string) ($tier['Gift'] ?? ''));
            if (strlen($gift) > 600) throw new InvalidArgumentException('Gift description is too long.');
            $giftQty = $gift === '' ? 0 : self::amount($tier['GiftQuantity'] ?? '', 'Gift quantity', 0.01, 1000000);
            if ($cash <= 0 && $gift === '') throw new InvalidArgumentException('Each offer tier needs a cash amount or gift description.');
            $clean[] = ['Threshold' => $threshold, 'CashType' => $cashType, 'CashValue' => $cash, 'Gift' => $gift, 'GiftQuantity' => $giftQty];
        }
        usort($clean, fn($a, $b) => $a['Threshold'] <=> $b['Threshold']);
        $notes = trim((string) ($input['Notes'] ?? ''));
        if (strlen($notes) > 500) throw new InvalidArgumentException('Notes must be under 500 bytes.');
        foreach (['Enabled', 'DeductReturns'] as $flag) {
            if (isset($input[$flag]) && !in_array($input[$flag], [true, false, 0, 1, '0', '1'], true)) throw new InvalidArgumentException('Invalid ' . $flag . ' setting.');
        }
        $increment = isset($input['IncrementPercent']) && $input['IncrementPercent'] !== '' ? self::amount($input['IncrementPercent'], 'Target increment (%)', 0, 10000) : null;
        return ['TargetAmount' => $target, 'IncrementPercent' => $increment, 'TierMode' => $mode, 'Tiers' => $clean, 'Notes' => $notes,
            'Enabled' => (int) (bool) ($input['Enabled'] ?? true), 'DeductReturns' => (int) (bool) ($input['DeductReturns'] ?? true)];
    }

    public static function achievement(?array $config, float $sales, float $returns): array
    {
        $enabled = $config && (bool) $config['Enabled'];
        $target = $enabled ? (float) $config['TargetAmount'] : 0;
        $net = round($sales - ((!$config || $config['DeductReturns']) ? $returns : 0), 2);
        $cash = 0; $gifts = []; $qualified = [];
        // Compare money, not rounded percentages, so 99.995% cannot qualify as 100%.
        if ($target > 0) foreach ($config['Tiers'] as $tier) {
            if ($net + 0.000001 >= round($target * $tier['Threshold'] / 100, 2)) $qualified[] = $tier;
        }
        if (($config['TierMode'] ?? '') === 'HIGHEST' && $qualified) $qualified = [end($qualified)];
        foreach ($qualified as $tier) {
            $cash += $tier['CashType'] === 'PERCENT' ? round(max(0, $net) * $tier['CashValue'] / 100, 2) : $tier['CashValue'];
            if ($tier['Gift'] !== '') $gifts[] = ['Description' => $tier['Gift'], 'Quantity' => $tier['GiftQuantity']];
        }
        return ['MonthlyNetSales' => $net, 'EffectiveTarget' => $target, 'Achievement' => $target > 0 ? round(max(0, $net) / $target * 100, 2) : 0,
            'Remaining' => $target > 0 ? round(max(0, $target - $net), 2) : 0,
            'TargetStatus' => !$config ? 'NOT_SET' : (!$enabled ? 'PAUSED' : ($net >= $target ? 'ACHIEVED' : 'IN_PROGRESS')),
            'EligibleCash' => round($cash, 2), 'EligibleGifts' => $gifts, 'QualifiedTiers' => count($qualified)];
    }
}
