<?php
/**
 * InstallmentSchedule.php — Credit card installment schedule for a portfolio lot
 * Cybokron Exchange Rate & Portfolio Tracking
 *
 * A lot bought "peşin fiyatına taksitle" is split into equal installments, one per
 * card statement. Nothing is stored per installment: the schedule is derived from
 * the buy date and the card's statement day, and an installment counts as paid once
 * its due date has passed (the card is assumed to be paid in full on time).
 *
 *   statement i  = the (i)th statement on/after the buy date; a purchase made on the
 *                  statement day itself lands on that day's statement
 *   due date i   = statement i + due_days, moved to the next business day when it
 *                  falls on a weekend or a fixed-date national holiday
 *
 * The statement day is clamped to the month's last day (30 → 28/29 Feb). Religious
 * holidays move every year and are not included, so a due date in a bayram can show
 * a few days early; the paid/unpaid split shifts with it.
 */

class InstallmentSchedule
{
    /** Fixed-date national holidays (month-day). */
    private const FIXED_HOLIDAYS = ['01-01', '04-23', '05-01', '05-19', '07-15', '08-30', '10-29'];

    /**
     * @return array{
     *   installments: array<int, array{no: int, statement_date: string, due_date: string, amount: float, paid: bool}>,
     *   count: int, paid_count: int, total: float, paid_total: float, remaining: float,
     *   next_due_date: ?string, next_amount: ?float, active: bool
     * }
     */
    public static function build(
        string $buyDate,
        float $total,
        int $count,
        int $statementDay,
        int $dueDays,
        ?DateTimeImmutable $today = null
    ): array {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $buy = new DateTimeImmutable($buyDate);
        $count = max(1, $count);
        $total = round($total, 2);

        // Equal installments in kuruş; the rounding remainder goes on the last one.
        $totalCents = (int) round($total * 100);
        $baseCents = intdiv($totalCents, $count);

        // First statement on/after the buy date.
        $statement = self::statementInMonth($buy, $statementDay);
        if ($statement < $buy->setTime(0, 0)) {
            $statement = self::statementInMonth($buy->modify('first day of next month'), $statementDay);
        }

        $installments = [];
        $paidCount = 0;
        $paidCents = 0;
        $next = null;

        for ($i = 1; $i <= $count; $i++) {
            $cents = $i === $count ? $totalCents - $baseCents * ($count - 1) : $baseCents;
            $due = self::nextBusinessDay($statement->modify('+' . $dueDays . ' days'));
            $paid = $due < $today;

            if ($paid) {
                $paidCount++;
                $paidCents += $cents;
            } elseif ($next === null) {
                $next = ['date' => $due->format('Y-m-d'), 'amount' => $cents / 100.0];
            }

            $installments[] = [
                'no' => $i,
                'statement_date' => $statement->format('Y-m-d'),
                'due_date' => $due->format('Y-m-d'),
                'amount' => $cents / 100.0,
                'paid' => $paid,
            ];

            $statement = self::statementInMonth($statement->modify('first day of next month'), $statementDay);
        }

        return [
            'installments' => $installments,
            'count' => $count,
            'paid_count' => $paidCount,
            'total' => $totalCents / 100.0,
            'paid_total' => $paidCents / 100.0,
            'remaining' => ($totalCents - $paidCents) / 100.0,
            'next_due_date' => $next['date'] ?? null,
            'next_amount' => $next['amount'] ?? null,
            'active' => $paidCount < $count,
        ];
    }

    /**
     * The statement date in $month's month, clamped to its last day.
     */
    private static function statementInMonth(DateTimeImmutable $month, int $statementDay): DateTimeImmutable
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);
        $day = min(max(1, $statementDay), (int) $first->format('t'));

        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), $day);
    }

    private static function nextBusinessDay(DateTimeImmutable $date): DateTimeImmutable
    {
        while ((int) $date->format('N') >= 6 || in_array($date->format('m-d'), self::FIXED_HOLIDAYS, true)) {
            $date = $date->modify('+1 day');
        }

        return $date;
    }
}
