<?php

namespace App\Support;

class EnrollmentPayments
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CORRECT = 'correct';

    public const STATUS_INVALID = 'invalid';

    public static function normalizeStatus(?string $status, bool $legacyAsCorrect = true): string
    {
        $raw = strtolower(trim((string) ($status ?? '')));
        if (in_array($raw, [self::STATUS_PENDING, self::STATUS_CORRECT, self::STATUS_INVALID], true)) {
            return $raw;
        }

        return $legacyAsCorrect ? self::STATUS_CORRECT : self::STATUS_PENDING;
    }

    public static function isCounted(string $status): bool
    {
        return $status === self::STATUS_CORRECT;
    }

    public static function isPending(string $status): bool
    {
        return $status === self::STATUS_PENDING;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array{verifiedTotal: float, hasUnreviewed: bool}
     */
    public static function summarizeFormDataPayments(array $formData): array
    {
        $verifiedTotal = 0.0;
        $hasUnreviewed = false;

        $downpayment = self::parseMoneyAmount($formData['downpaymentAmount'] ?? null);
        if ($downpayment > 0) {
            $downStatus = self::normalizeStatus($formData['downpaymentVerificationStatus'] ?? null);
            if (self::isPending($downStatus)) {
                $hasUnreviewed = true;
            }
            if (self::isCounted($downStatus)) {
                $verifiedTotal += $downpayment;
            }
        }

        $partialPayments = is_array($formData['partialPayments'] ?? null) ? $formData['partialPayments'] : [];
        foreach ($partialPayments as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $amount = (float) ($entry['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $status = self::normalizeStatus($entry['verificationStatus'] ?? null, false);
            if (self::isPending($status)) {
                $hasUnreviewed = true;
            }
            if (self::isCounted($status)) {
                $verifiedTotal += $amount;
            }
        }

        return [
            'verifiedTotal' => round($verifiedTotal, 2),
            'hasUnreviewed' => $hasUnreviewed,
        ];
    }

    public static function parseMoneyAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return max(0.0, round((float) $value, 2));
        }

        $cleaned = preg_replace('/[^\d.]/', '', (string) $value);
        if (! is_string($cleaned) || $cleaned === '') {
            return 0.0;
        }

        $parsed = (float) $cleaned;

        return $parsed > 0 ? round($parsed, 2) : 0.0;
    }

    /**
     * Flatten enrollment payment rows for admin payment history lists.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paymentHistoryRowsForEnrollment(
        string $enrollmentPublicId,
        ?array $formData,
        ?string $submittedAt,
        bool $hasPaymentProof,
        ?string $paymentProofFileName,
        string $userName = '',
        string $userEmail = '',
        string $programTitle = '',
        string $programId = ''
    ): array {
        $formData = is_array($formData) ? $formData : [];
        $rows = [];

        $initialAmount = self::parseMoneyAmount($formData['downpaymentAmount'] ?? null);
        if ($initialAmount > 0) {
            $status = self::normalizeStatus($formData['downpaymentVerificationStatus'] ?? null);
            $rows[] = [
                'id' => $enrollmentPublicId.'::initial-downpayment',
                'paymentId' => 'initial-downpayment',
                'enrollmentId' => $enrollmentPublicId,
                'userName' => $userName,
                'userEmail' => $userEmail,
                'programId' => $programId,
                'programTitle' => $programTitle,
                'paymentType' => 'initial',
                'label' => 'Initial downpayment',
                'amount' => $initialAmount,
                'paidAt' => $submittedAt,
                'verificationStatus' => $status,
                'hasProof' => $hasPaymentProof,
                'documentKey' => null,
                'originalName' => $paymentProofFileName,
            ];
        }

        $partialPayments = is_array($formData['partialPayments'] ?? null) ? $formData['partialPayments'] : [];
        foreach ($partialPayments as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $amount = (float) ($entry['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $paymentId = (string) ($entry['id'] ?? ('partial-'.($index + 1)));
            $rows[] = [
                'id' => $enrollmentPublicId.'::'.$paymentId,
                'paymentId' => $paymentId,
                'enrollmentId' => $enrollmentPublicId,
                'userName' => $userName,
                'userEmail' => $userEmail,
                'programId' => $programId,
                'programTitle' => $programTitle,
                'paymentType' => 'partial',
                'label' => 'Partial payment '.($index + 1),
                'amount' => round($amount, 2),
                'paidAt' => $entry['paidAt'] ?? null,
                'verificationStatus' => self::normalizeStatus($entry['verificationStatus'] ?? null, false),
                'hasProof' => ! empty($entry['documentKey']),
                'documentKey' => $entry['documentKey'] ?? null,
                'originalName' => $entry['originalName'] ?? null,
            ];
        }

        return $rows;
    }
}
