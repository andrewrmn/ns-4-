<?php

namespace modules;

use craft\commerce\elements\Order as CommerceOrder;
use craft\elements\User;

/**
 * Resolves a non-empty label for the patient in HCP order notification emails.
 * Twig/mail context was often leaving the headline blank (empty order.email string, etc.).
 */
final class HcpPatientOrderEmailHelper
{
    public static function patientDisplayName(CommerceOrder $order, ?User $patient): string
    {
        if ($patient !== null) {
            $fn = trim((string) ($patient->firstName ?? ''));
            $ln = trim((string) ($patient->lastName ?? ''));
            if ($fn !== '' || $ln !== '') {
                return trim($fn . ' ' . $ln);
            }
            $full = trim((string) ($patient->fullName ?? ''));
            if ($full !== '') {
                return $full;
            }
        }

        foreach ([$order->billingAddress ?? null, $order->shippingAddress ?? null] as $addr) {
            if ($addr === null) {
                continue;
            }
            $fn = trim((string) ($addr->firstName ?? ''));
            $ln = trim((string) ($addr->lastName ?? ''));
            if ($fn !== '' || $ln !== '') {
                return trim($fn . ' ' . $ln);
            }
        }

        if ($patient !== null) {
            $u = trim((string) ($patient->username ?? ''));
            if ($u !== '') {
                return $u;
            }
            $e = trim((string) ($patient->email ?? ''));
            if ($e !== '') {
                return $e;
            }
        }

        $orderEmail = trim((string) ($order->email ?? ''));
        if ($orderEmail !== '') {
            return $orderEmail;
        }

        return 'Your patient';
    }
}
