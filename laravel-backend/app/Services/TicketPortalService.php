<?php

namespace App\Services;

use App\Models\TicketOrder;

class TicketPortalService
{
    public function mintToken(string $email, int $ttlSeconds = 172800): string
    {
        $payload = [
            'email' => strtolower(trim($email)),
            'exp' => time() + $ttlSeconds,
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, $this->secret());

        return $body . '.' . $sig;
    }

    public function emailFromToken(?string $token): ?string
    {
        if (!$token || !str_contains($token, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $body, $this->secret());
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = base64_decode(strtr($body, '-_', '+/'));
        $payload = json_decode($json ?: '', true);
        if (!is_array($payload) || empty($payload['email']) || (int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return strtolower((string) $payload['email']);
    }

    public function ordersForEmail(string $email)
    {
        return TicketOrder::with(['tickets.ticketType', 'event', 'items.ticketType'])
            ->whereRaw('lower(buyer_email) = ?', [strtolower($email)])
            ->orderByDesc('created_at')
            ->get();
    }

    public function findCompleted(string $email, ?string $reference = null)
    {
        $query = TicketOrder::query()
            ->whereRaw('lower(buyer_email) = ?', [strtolower($email)])
            ->where('status', 'Completed');
        if ($reference) {
            $query->where('reference', $reference);
        }

        return $query->get();
    }

    protected function secret(): string
    {
        // L-1: a forgeable constant fallback would let anyone mint recovery
        // links. Fail loudly instead — production boot guards already require
        // a strong JWT_SECRET_KEY, and APP_KEY is always set in real installs.
        $secret = (string) (config('roi.jwt_secret_key') ?: config('app.key'));
        if ($secret === '') {
            throw new \RuntimeException(
                'Ticket portal requires JWT_SECRET_KEY or APP_KEY to be configured.'
            );
        }

        return $secret;
    }
}
