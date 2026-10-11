<?php
declare(strict_types=1);

final class AccountingRules
{
    public static function amount(mixed $value, string $label, bool $zero = true): float
    {
        if (!is_numeric($value) || !is_finite((float) $value)) throw new InvalidArgumentException($label . ' must be a valid amount.');
        $amount = round((float) $value, 2);
        if ($amount < 0 || (!$zero && $amount <= 0) || $amount > 999999999999.99) throw new InvalidArgumentException($label . ' is outside the allowed range.');
        return $amount;
    }
    public static function date(mixed $value): string
    {
        $value = trim((string) $value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Select a valid voucher date.');
        return $value;
    }
    public static function accountCode(mixed $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^[1-9][0-9]{3,9}$/', $value)) throw new InvalidArgumentException('Account code must contain 4–10 digits and cannot start with zero.');
        return $value;
    }
    public static function lines(mixed $input): array
    {
        if (!is_array($input) || count($input) < 2 || count($input) > 100) throw new InvalidArgumentException('A voucher needs between 2 and 100 journal lines.');
        $lines = []; $debit = 0.; $credit = 0.;
        foreach ($input as $index => $raw) {
            if (!is_array($raw)) throw new InvalidArgumentException('Invalid journal line.');
            $account = self::accountCode($raw['AccountID'] ?? '');
            $dr = self::amount($raw['Debit'] ?? 0, 'Debit'); $cr = self::amount($raw['Credit'] ?? 0, 'Credit');
            if (($dr > 0 && $cr > 0) || ($dr == 0 && $cr == 0)) throw new InvalidArgumentException('Each journal line requires either a debit or a credit amount.');
            $partyType = strtoupper(trim((string) ($raw['PartyType'] ?? '')));
            if ($partyType !== '' && !in_array($partyType, ['DEALER','SUPPLIER','COMPANY','OUTSIDE'], true)) throw new InvalidArgumentException('Invalid party type.');
            $lines[] = ['AccountID'=>$account, 'Debit'=>$dr, 'Credit'=>$cr, 'PartyType'=>$partyType, 'PartyID'=>trim((string) ($raw['PartyID'] ?? '')),
                'ReferenceNo'=>substr(trim((string) ($raw['ReferenceNo'] ?? '')),0,160), 'Narration'=>substr(trim((string) ($raw['Narration'] ?? '')),0,500)];
            $debit += $dr; $credit += $cr;
        }
        $debit = round($debit, 2); $credit = round($credit, 2);
        if ($debit <= 0 || abs($debit - $credit) > 0.004) throw new InvalidArgumentException('Voucher is not balanced: total debit must equal total credit.');
        return ['Lines'=>$lines, 'TotalDebit'=>$debit, 'TotalCredit'=>$credit];
    }
}
